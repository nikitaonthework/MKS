<?php
/**
 * Диагностика push-уведомлений: показывает, на каком именно шаге рвётся
 * цепочка "разрешил уведомления в браузере -> получил push", вместо того
 * чтобы гадать. Откройте в браузере:
 *   https://ваш-сайт/it/diagnose_push.php?key=ВАШ_TG_WEBHOOK_SECRET
 * Удалите после диагностики (здесь показываются служебные данные — не
 * секретные ключи, но всё же не для посторонних глаз).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ec_math.php';
require_once __DIR__ . '/../includes/webpush.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_GET['key']) || !hash_equals(TG_WEBHOOK_SECRET, $_GET['key'])) {
    http_response_code(403);
    echo "Доступ запрещён. Укажите ?key=ВАШ_TG_WEBHOOK_SECRET (см. config/config.php, TG_WEBHOOK_SECRET)\n";
    exit;
}

$pdo = db();

echo "===== 1. Расширения PHP =====\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "gmp: " . (extension_loaded('gmp') ? 'да' : 'НЕТ — push работать не будет, см. it/generate_vapid_keys.php') . "\n";
echo "openssl: " . (extension_loaded('openssl') ? 'да' : 'НЕТ') . "\n";
echo "curl: " . (extension_loaded('curl') ? 'да' : 'НЕТ') . "\n";
echo "\n";

echo "===== 2. Ключи VAPID (config/config.php) =====\n";
$hasPublic = defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== '';
$hasPrivate = defined('VAPID_PRIVATE_KEY_PEM') && VAPID_PRIVATE_KEY_PEM !== '';
echo "VAPID_PUBLIC_KEY задан: " . ($hasPublic ? 'да (' . strlen(VAPID_PUBLIC_KEY) . ' симв.)' : 'НЕТ') . "\n";
echo "VAPID_PRIVATE_KEY_PEM задан: " . ($hasPrivate ? 'да' : 'НЕТ') . "\n";
if ($hasPrivate) {
    $pkey = @openssl_pkey_get_private(VAPID_PRIVATE_KEY_PEM);
    echo "VAPID_PRIVATE_KEY_PEM читается openssl: " . ($pkey ? 'да' : 'НЕТ — ключ повреждён, перегенерируйте через it/generate_vapid_keys.php') . "\n";
}
if (!$hasPublic || !$hasPrivate) {
    echo "-> Ключи не заданы. Откройте it/generate_vapid_keys.php?key=... и вставьте результат в config/config.php.\n";
}
echo "PUSH_PROXY: " . (defined('PUSH_PROXY') && PUSH_PROXY !== '' ? PUSH_PROXY : '(не задан, прямое соединение)') . "\n";
echo "\n";

echo "===== 3. Подписки браузеров (push_subscriptions) =====\n";
try {
    $subs = $pdo->query(
        "SELECT ps.id, ps.user_id, u.full_name, ps.user_agent, ps.created_at
         FROM push_subscriptions ps JOIN users u ON u.id = ps.user_id
         ORDER BY ps.created_at DESC"
    )->fetchAll();
    if (!$subs) {
        echo "Подписок нет ни у одного сотрудника. Значит браузер ни разу не\n"
            . "дошёл до успешной подписки — проверьте консоль браузера (F12) на\n"
            . "странице it/index.php при нажатии \"Разрешить уведомления\": там\n"
            . "будет видна конкретная ошибка JS (registration.pushManager.subscribe).\n"
            . "Частая причина — VAPID_PUBLIC_KEY из раздела 2 не совпадает с тем,\n"
            . "что было на момент подписки (ключи перегенерировали после того,\n"
            . "как кто-то уже подписался — старые подписки нужно создать заново).\n";
    } else {
        foreach ($subs as $s) {
            echo "  #" . $s['id'] . " — " . $s['full_name'] . " — создана " . $s['created_at'] . "\n";
            echo "      user_agent: " . ($s['user_agent'] !== null ? $s['user_agent'] : '(нет)') . "\n";
        }
    }
} catch (Exception $ex) {
    echo "ОШИБКА чтения push_subscriptions: " . $ex->getMessage() . "\n";
    echo "Скорее всего не выполнена миграция db/migration_3_web_push.sql.\n";
}
echo "\n";

echo "===== 4. Очередь push-уведомлений (push_queue, последние 20) =====\n";
try {
    $hasLastError = false;
    $cols = $pdo->query("SHOW COLUMNS FROM push_queue LIKE 'last_error'")->fetchAll();
    $hasLastError = count($cols) > 0;
    if (!$hasLastError) {
        echo "(колонки last_error ещё нет — выполните db/migration_6_push_last_error.sql,\n"
            . " тогда здесь будет видна причина ошибок отправки)\n\n";
    }

    $selectCols = $hasLastError
        ? 'pq.id, pq.title, pq.status, pq.attempts, pq.last_error, pq.created_at, pq.sent_at'
        : 'pq.id, pq.title, pq.status, pq.attempts, pq.created_at, pq.sent_at';
    $rows = $pdo->query(
        "SELECT $selectCols FROM push_queue pq ORDER BY pq.id DESC LIMIT 20"
    )->fetchAll();

    if (!$rows) {
        echo "Очередь пуста — значит либо ещё не было ни одного события,\n"
            . "требующего уведомления, либо у сотрудника нет активной подписки\n"
            . "(webpush_queue_for_user() тихо ничего не кладёт в очередь, если\n"
            . "подписок нет — см. раздел 3 выше).\n";
    } else {
        foreach ($rows as $r) {
            $line = "  #" . $r['id'] . " [" . $r['status'] . ", попыток: " . $r['attempts'] . "] "
                . $r['title'] . " — создано " . $r['created_at'];
            echo $line . "\n";
            if ($hasLastError && $r['status'] !== 'sent' && !empty($r['last_error'])) {
                echo "      последняя ошибка: " . $r['last_error'] . "\n";
            }
        }
        echo "\nЕсли записи зависли в статусе pending — проверьте, что cron\n"
            . "действительно запускает bot/poll.php (см. README, раздел про cron).\n"
            . "Если status = failed — смотрите на \"последняя ошибка\" выше.\n";
    }
} catch (Exception $ex) {
    echo "ОШИБКА чтения push_queue: " . $ex->getMessage() . "\n";
    echo "Скорее всего не выполнена миграция db/migration_3_web_push.sql.\n";
}
echo "\n";

echo "===== 5. Пробная отправка push (если есть хотя бы одна подписка) =====\n";
if (!extension_loaded('gmp') || !$hasPublic || !$hasPrivate) {
    echo "Пропущено — сначала устраните проблемы из разделов 1-2 выше.\n";
} else {
    try {
        $sub = $pdo->query(
            "SELECT ps.endpoint, ps.p256dh, ps.auth, u.full_name
             FROM push_subscriptions ps JOIN users u ON u.id = ps.user_id
             ORDER BY ps.created_at DESC LIMIT 1"
        )->fetch();
        if (!$sub) {
            echo "Пропущено — нет ни одной подписки (см. раздел 3).\n";
        } else {
            echo "Отправляю тестовое уведомление на последнюю подписку (" . $sub['full_name'] . ")...\n";
            $payload = json_encode(array(
                'title' => 'Тестовое уведомление',
                'body' => 'Если вы это видите — push работает.',
                'url' => rtrim(APP_URL, '/') . '/it/index.php',
            ), JSON_UNESCAPED_UNICODE);
            $pushProxy = defined('PUSH_PROXY') ? PUSH_PROXY : '';
            $result = webpush_send(
                array('endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']),
                $payload, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY_PEM, VAPID_SUBJECT, $pushProxy
            );
            if (!empty($result['ok'])) {
                echo "УСПЕХ (http_code=" . $result['http_code'] . "). Проверьте устройство — уведомление должно прийти в течение секунд.\n"
                    . "Если push-сервис принял его (201), но на устройстве ничего не появилось —\n"
                    . "проблема на стороне ОС/браузера (уведомления выключены в настройках\n"
                    . "системы, PWA не установлено на главный экран — см. README).\n";
            } else {
                echo "ОШИБКА: " . webpush_describe_error($result) . "\n";
                if (!empty($result['error']) && $result['error'] === 'curl') {
                    echo "-> Это сетевая ошибка: push-сервис (Google FCM / Mozilla / Apple) недоступен\n"
                        . "   напрямую с вашего хостинга — та же ситуация, что и с Telegram (см. bot/diagnose.php).\n"
                        . "   Решение: укажите рабочий прокси в PUSH_PROXY в config/config.php.\n";
                } elseif (!empty($result['gone'])) {
                    echo "-> Подписка больше не действительна на стороне браузера (404/410).\n"
                        . "   Откройте it/index.php заново и разрешите уведомления ещё раз.\n";
                }
            }
        }
    } catch (Exception $ex) {
        echo "ОШИБКА при пробной отправке: " . $ex->getMessage() . "\n";
    }
}
