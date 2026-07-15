<?php
/**
 * Web Push (VAPID + шифрование по RFC 8291/8188 "aes128gcm") "с нуля",
 * без Composer/внешних библиотек — на openssl + GMP (см. includes/ec_math.php)
 * + встроенный hash_hkdf() (доступен с PHP 7.1). Позволяет присылать
 * push-уведомления в браузер (иконка на главном экране/десктоп) без
 * какого-либо стороннего SaaS.
 *
 * Требует расширение GMP (для ECDH — openssl_pkey_derive() появился
 * только в PHP 8.1, а хостинг на 7.3). Если GMP недоступен, webpush_send()
 * возвращает ошибку — остальной сайт при этом продолжает работать.
 */
require_once __DIR__ . '/ec_math.php';
require_once __DIR__ . '/db.php';

/**
 * Ставит push-уведомление в очередь для ВСЕХ подписок конкретного
 * IT-сотрудника (может быть несколько устройств). Мгновенная запись в БД,
 * без сети — реальную отправку делает bot/poll.php по cron, как и для
 * Telegram. Если таблицы push_* ещё не созданы (не выполнена миграция
 * db/migration_3_web_push.sql), тихо пропускает — остальной сайт при этом
 * продолжает работать (та же защита, что и в tg_queue_message()).
 */
function webpush_queue_for_user($itUserId, $title, $body, $url = null) {
    try {
        $stmt = db()->prepare('SELECT id FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute(array($itUserId));
        $subscriptionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$subscriptionIds) {
            return;
        }
        $ins = db()->prepare('INSERT INTO push_queue (subscription_id, title, body, url) VALUES (?, ?, ?, ?)');
        foreach ($subscriptionIds as $subId) {
            $ins->execute(array($subId, $title, $body, $url));
        }
    } catch (Exception $ex) {
        error_log('webpush_queue_for_user failed (is db/migration_3_web_push.sql applied?): ' . $ex->getMessage());
    }
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
 * Превращает результат webpush_send() в короткую человекочитаемую строку
 * для сохранения в push_queue.last_error (см. bot/poll.php, шаг 3) —
 * чтобы при сбое доставки было видно причину, а не только код ошибки.
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
