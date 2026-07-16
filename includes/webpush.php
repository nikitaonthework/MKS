<?php
/**
 * Web Push (VAPID + шифрование по RFC 8291/8188 "aes128gcm") "с нуля",
 * без Composer/внешних библиотек — на openssl + GMP (см. includes/ec_math.php)
 * + встроенный hash_hkdf() (доступен с PHP 7.1). Позволяет присылать
 * push-уведомления в браузер (иконка на главном экране/десктоп) без
 * какого-либо стороннего SaaS.
 *
 * Требует расширение GMP (для ECDH — openssl_pkey_derive() появился
 * только в PHP 8.1, а хостинг на 7.3). Если GMP недоступен, отправка
 * возвращает ошибку — остальной сайт при этом продолжает работать.
 *
 * Отправка происходит синхронно, прямо в момент события (создание
 * заявки/ответ/закрепление) — без очереди и cron: раньше уведомления
 * копились в таблице и ждали отдельного скрипта по расписанию, из-за
 * чего при сбое cron на хостинге они просто не доходили. Теперь сайт сам
 * шлёт push сразу же (см. webpush_notify_users() ниже), с короткими
 * таймаутами и параллельной отправкой на все подписки разом, чтобы это
 * не задерживало ответ сотруднику.
 */
require_once __DIR__ . '/ec_math.php';
require_once __DIR__ . '/db.php';

/**
 * Отправляет push-уведомление сразу на несколько подписок ОДНОВРЕМЕННО
 * (параллельно, через curl_multi) — так что уведомление 2 сотрудникам IT
 * x несколько устройств занимает по времени как ОДИН самый медленный
 * запрос, а не сумму всех. Шифрование (это CPU, не сеть) при этом всё
 * равно делается по очереди для каждой подписки — оно быстрое
 * (несколько умножений точки эллиптической кривой, миллисекунды).
 *
 * $subscriptions — [['id'=>subscription_id, 'endpoint'=>.., 'p256dh'=>.., 'auth'=>..], ...]
 * Возвращает массив [subscription_id => результат] (формат — как у webpush_send()).
 */
function webpush_send_parallel($subscriptions, $payload, $vapidPublicKey, $vapidPrivateKeyPem, $vapidSubject, $proxy = '') {
    $results = array();
    if (!$subscriptions) {
        return $results;
    }
    if (EC_MATH_GMP_MISSING) {
        foreach ($subscriptions as $s) {
            $results[$s['id']] = array('ok' => false, 'error' => 'gmp_missing');
        }
        return $results;
    }

    $mh = curl_multi_init();
    $handles = array();

    foreach ($subscriptions as $s) {
        $body = webpush_encrypt_payload($payload, $s['p256dh'], $s['auth']);
        if ($body === null) {
            $results[$s['id']] = array('ok' => false, 'error' => 'encrypt_failed');
            continue;
        }
        $urlParts = parse_url($s['endpoint']);
        if (!$urlParts || empty($urlParts['scheme']) || empty($urlParts['host'])) {
            $results[$s['id']] = array('ok' => false, 'error' => 'bad_endpoint');
            continue;
        }
        $audience = $urlParts['scheme'] . '://' . $urlParts['host'];
        $jwt = webpush_vapid_jwt($audience, $vapidSubject, $vapidPrivateKeyPem);
        if ($jwt === null) {
            $results[$s['id']] = array('ok' => false, 'error' => 'jwt_failed');
            continue;
        }

        $ch = curl_init($s['endpoint']);
        $options = array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            // Короткие таймауты специально: эта отправка теперь происходит
            // прямо во время запроса сотрудника (создание заявки/ответ), без
            // очереди и cron — значит, недоступный push-сервис не должен
            // заставлять сотрудника долго ждать ответа страницы.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 604800',
                'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicKey,
            ),
        );
        if ($proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($mh, $ch);
        $handles[$s['id']] = $ch;
    }

    if ($handles) {
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $subId => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if ($response === false || $response === null || $httpCode === 0) {
                $results[$subId] = array('ok' => false, 'error' => 'curl', 'curl_error' => $err !== '' ? $err : 'нет ответа от push-сервиса');
            } else {
                $results[$subId] = array(
                    'ok' => $httpCode === 201,
                    'http_code' => $httpCode,
                    'gone' => ($httpCode === 404 || $httpCode === 410),
                    'body' => $response,
                );
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * Отправляет push-уведомление сразу нескольким сотрудникам IT-отдела (по
 * id пользователя, у каждого может быть несколько подписок/устройств) —
 * прямо сейчас, синхронно, без очереди и cron (см. webpush_send_parallel
 * выше — все подписки отправляются параллельно). Устаревшие подписки
 * (браузер отписался/приложение удалено — 404/410) удаляются сразу.
 * Каждая попытка логируется в push_queue — не для повторной отправки
 * (очереди больше нет), а как история для диагностики
 * (см. it/diagnose_push.php). Если push не настроен (нет ключей VAPID)
 * или таблицы push_* отсутствуют — тихо ничего не делает, остальной
 * сайт продолжает работать как обычно.
 */
function webpush_notify_users($userIds, $title, $body, $url = null) {
    $userIds = array_values(array_unique(array_filter(array_map('intval', (array)$userIds))));
    if (!$userIds) {
        return;
    }
    if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '' || !defined('VAPID_PRIVATE_KEY_PEM') || VAPID_PRIVATE_KEY_PEM === '') {
        return;
    }
    try {
        $pdo = db();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ($in)");
        $stmt->execute($userIds);
        $subs = $stmt->fetchAll();
        if (!$subs) {
            return;
        }

        $payload = json_encode(array('title' => $title, 'body' => $body, 'url' => $url), JSON_UNESCAPED_UNICODE);
        $pushProxy = defined('PUSH_PROXY') ? PUSH_PROXY : '';
        $results = webpush_send_parallel($subs, $payload, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY_PEM, VAPID_SUBJECT, $pushProxy);

        $logStmt = $pdo->prepare(
            'INSERT INTO push_queue (subscription_id, title, body, url, status, attempts, last_error, sent_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $goneIds = array();
        foreach ($subs as $s) {
            $r = isset($results[$s['id']]) ? $results[$s['id']] : array('ok' => false, 'error' => 'unknown');
            if (!empty($r['ok'])) {
                $logStmt->execute(array($s['id'], $title, $body, $url, 'sent', null, date('Y-m-d H:i:s')));
            } elseif (!empty($r['gone'])) {
                $goneIds[] = $s['id'];
            } else {
                $logStmt->execute(array($s['id'], $title, $body, $url, 'failed', webpush_describe_error($r), null));
            }
        }
        if ($goneIds) {
            $inGone = implode(',', array_fill(0, count($goneIds), '?'));
            $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($inGone)")->execute($goneIds);
        }
    } catch (Exception $ex) {
        error_log('webpush_notify_users failed: ' . $ex->getMessage());
    }
}

function webpush_notify_user($userId, $title, $body, $url = null) {
    webpush_notify_users(array($userId), $title, $body, $url);
}

/**
 * Абсолютная ссылка на заявку в отдельном веб-приложении IT-отдела —
 * используется как url клика в push-уведомлении. Абсолютный путь нужен,
 * т.к. Service Worker резолвит относительные URL относительно своего
 * scope (/it/), а не корня сайта.
 */
function webpush_ticket_url($ticketId) {
    return rtrim(APP_URL, '/') . '/it/index.php?ticket=' . (int)$ticketId;
}

/**
 * То же самое, но для обычного веб-кабинета сотрудника (не /it/) — Service
 * Worker там зарегистрирован с корневым scope, поэтому и ссылка ведёт на
 * корневую страницу заявки.
 */
function webpush_employee_ticket_url($ticketId) {
    return rtrim(APP_URL, '/') . '/ticket.php?id=' . (int)$ticketId;
}

function webpush_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_base64url_decode($data) {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data);
}

/**
 * Генерирует пару ключей VAPID. Возвращает array('publicKey' => base64url,
 * 'privateKeyPem' => PEM-строка). publicKey отдаётся один раз в
 * config/config.php и в JS (applicationServerKey), privateKeyPem — только
 * в config/config.php, используется для подписи JWT.
 */
function webpush_vapid_generate_keys() {
    $res = openssl_pkey_new(array(
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ));
    if (!$res) {
        return null;
    }
    $details = openssl_pkey_get_details($res);
    openssl_pkey_export($res, $privPem);
    $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    return array(
        'publicKey' => webpush_base64url_encode($publicRaw),
        'privateKeyPem' => $privPem,
    );
}

// ---- ASN.1 DER <-> "raw" (r||s) конвертация подписи для JOSE/ES256 ----
// (openssl_sign отдаёт DER, JWT ES256 требует конкатенацию r||s по 32 байта — RFC 7518 §3.4)

function webpush_asn1_length($der, &$pos) {
    $b = ord($der[$pos]);
    $pos++;
    if ($b < 0x80) {
        return $b;
    }
    $n = $b & 0x7f;
    $len = 0;
    for ($i = 0; $i < $n; $i++) {
        $len = ($len << 8) | ord($der[$pos]);
        $pos++;
    }
    return $len;
}

function webpush_asn1_read_integer($der, &$pos) {
    if (ord($der[$pos]) !== 0x02) {
        throw new Exception('DER: expected INTEGER');
    }
    $pos++;
    $len = webpush_asn1_length($der, $pos);
    $bytes = substr($der, $pos, $len);
    $pos += $len;
    while (strlen($bytes) > 1 && ord($bytes[0]) === 0x00 && (ord($bytes[1]) & 0x80)) {
        $bytes = substr($bytes, 1);
    }
    return $bytes;
}

function webpush_der_to_raw_signature($der) {
    $pos = 0;
    if (ord($der[$pos]) !== 0x30) {
        throw new Exception('DER: expected SEQUENCE');
    }
    $pos++;
    webpush_asn1_length($der, $pos);
    $r = webpush_asn1_read_integer($der, $pos);
    $s = webpush_asn1_read_integer($der, $pos);
    return ec_gmp_to_fixed_bytes(gmp_init(bin2hex($r), 16), 32) . ec_gmp_to_fixed_bytes(gmp_init(bin2hex($s), 16), 32);
}

/**
 * Собирает и подписывает VAPID JWT (ES256) для конкретного push-эндпоинта.
 * $audience — схема+хост эндпоинта (например https://fcm.googleapis.com).
 */
function webpush_vapid_jwt($audience, $subject, $privateKeyPem) {
    $header = webpush_base64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $payload = webpush_base64url_encode(json_encode(array(
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    )));
    $unsigned = $header . '.' . $payload;

    $pkey = openssl_pkey_get_private($privateKeyPem);
    if (!$pkey) {
        return null;
    }
    openssl_sign($unsigned, $derSignature, $pkey, OPENSSL_ALGO_SHA256);
    $rawSignature = webpush_der_to_raw_signature($derSignature);

    return $unsigned . '.' . webpush_base64url_encode($rawSignature);
}

/**
 * Шифрует полезную нагрузку по RFC 8291 (ECDH+HKDF) и RFC 8188
 * ("aes128gcm" content-encoding). $p256dhB64/$authB64 — ключи подписки
 * браузера (PushSubscription.getKey('p256dh'/'auth'), как их прислал JS).
 * Возвращает бинарную строку тела запроса или null при ошибке.
 */
function webpush_encrypt_payload($payload, $p256dhB64, $authB64) {
    if (EC_MATH_GMP_MISSING) {
        return null;
    }

    $uaPublicRaw = webpush_base64url_decode($p256dhB64);
    $authSecret = webpush_base64url_decode($authB64);
    $uaPoint = ec_point_from_uncompressed($uaPublicRaw);
    if ($uaPoint === null) {
        return null; // невалидный публичный ключ подписки
    }

    // Эфемерная пара ключей — своя для каждого отправляемого сообщения.
    $ephRes = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
    if (!$ephRes) {
        return null;
    }
    $ephDetails = openssl_pkey_get_details($ephRes);
    $asPublicRaw = "\x04" . $ephDetails['ec']['x'] . $ephDetails['ec']['y'];
    $asPrivate = gmp_init(bin2hex($ephDetails['ec']['d']), 16);

    $sharedPoint = ec_scalar_mul($asPrivate, $uaPoint);
    $ecdhSecret = ec_gmp_to_fixed_bytes($sharedPoint[0], 32);

    // RFC 8291 §3.3/3.4: получаем IKM для второй (aes128gcm) стадии HKDF.
    $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $asPublicRaw;
    $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);

    // RFC 8188 §2.1: соль записи — случайные 16 байт на сообщение.
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // Плейнтекст с завершающим разделителем записи (0x02 — единственная/
    // последняя запись, доп. паддинг не добавляем — сообщения короткие).
    $plaintext = $payload . "\x02";

    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return null;
    }

    $recordSize = 4096;
    $header = $salt
        . pack('N', $recordSize)
        . chr(strlen($asPublicRaw))
        . $asPublicRaw;

    return $header . $ciphertext . $tag;
}

/**
 * Превращает результат отправки в короткую человекочитаемую строку для
 * сохранения в push_queue.last_error — чтобы при сбое доставки было видно
 * причину, а не только код ошибки.
 */
function webpush_describe_error($result) {
    if (!empty($result['error'])) {
        switch ($result['error']) {
            case 'gmp_missing':
                return 'На сервере не установлено расширение PHP GMP';
            case 'encrypt_failed':
                return 'Не удалось зашифровать сообщение (неверные ключи подписки?)';
            case 'jwt_failed':
                return 'Не удалось подписать VAPID JWT (проверьте VAPID_PRIVATE_KEY_PEM в config.php)';
            case 'curl':
                return 'Сеть: ' . (isset($result['curl_error']) ? $result['curl_error'] : 'ошибка соединения');
        }
    }
    if (isset($result['http_code'])) {
        $text = 'HTTP ' . $result['http_code'];
        if (!empty($result['body'])) {
            $text .= ': ' . substr($result['body'], 0, 300);
        }
        return $text;
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * Полностью отправляет push-уведомление на один эндпоинт подписки.
 * $subscription — array('endpoint'=>.., 'p256dh'=>.., 'auth'=>..).
 * $payload — произвольная строка (у нас — JSON {title, body, url}).
 * Возвращает array('ok'=>bool, 'http_code'=>int, 'body'=>string, ...).
 */
function webpush_send($subscription, $payload, $vapidPublicKey, $vapidPrivateKeyPem, $vapidSubject, $proxy = '') {
    if (EC_MATH_GMP_MISSING) {
        return array('ok' => false, 'error' => 'gmp_missing');
    }

    $body = webpush_encrypt_payload($payload, $subscription['p256dh'], $subscription['auth']);
    if ($body === null) {
        return array('ok' => false, 'error' => 'encrypt_failed');
    }

    $endpoint = $subscription['endpoint'];
    $urlParts = parse_url($endpoint);
    $audience = $urlParts['scheme'] . '://' . $urlParts['host'];

    $jwt = webpush_vapid_jwt($audience, $vapidSubject, $vapidPrivateKeyPem);
    if ($jwt === null) {
        return array('ok' => false, 'error' => 'jwt_failed');
    }

    $ch = curl_init($endpoint);
    $options = array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 604800',
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicKey,
        ),
    );
    if ($proxy !== '') {
        $options[CURLOPT_PROXY] = $proxy;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return array('ok' => false, 'error' => 'curl', 'curl_error' => $err);
    }

    // 201 — принято. 404/410 — подписка больше не существует на стороне
    // браузера (пользователь удалил PWA/очистил данные) — нужно удалить
    // её из push_subscriptions, это решает вызывающий код.
    return array(
        'ok' => $httpCode === 201,
        'http_code' => $httpCode,
        'gone' => ($httpCode === 404 || $httpCode === 410),
        'body' => $response,
    );
}
