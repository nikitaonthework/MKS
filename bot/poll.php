<?php
/**
 * Альтернатива вебхуку: получает новые сообщения/нажатия кнопок у Telegram
 * через getUpdates и обрабатывает их, а также отправляет накопившиеся в
 * очереди уведомления (notification_queue) — без необходимости, чтобы
 * Telegram сам стучался на ваш сайт, и без прямых обращений к Telegram API
 * из веб-запросов сотрудников. Запускайте этот скрипт регулярно через
 * Cron Jobs хостинга (например, раз в минуту):
 *
 *   * * * * * php /home/user/public_html/mks/bot/poll.php >/dev/null 2>&1
 *
 * (путь замените на реальный путь к папке сайта на вашем хостинге).
 *
 * Также можно запускать вручную из браузера:
 *   https://ваш-сайт/bot/poll.php?key=ВАШ_TG_WEBHOOK_SECRET
 *
 * ВАЖНО: если на сервере уже установлен вебхук (через bot/setwebhook.php),
 * Telegram не будет отдавать обновления через getUpdates. Перед тем как
 * использовать polling, снимите вебхук — откройте один раз:
 *   https://ваш-сайт/bot/setwebhook.php?key=ВАШ_TG_WEBHOOK_SECRET&remove=1
 *
 * Если таблица notification_queue ещё не создана (не выполнена миграция
 * db/migration_2_notification_queue.sql), шаг отправки уведомлений просто
 * пропускается с понятным сообщением — остальная часть скрипта продолжает
 * работать.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bot_handlers.php';
require_once __DIR__ . '/../includes/webpush.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!isset($_GET['key']) || !hash_equals(TG_WEBHOOK_SECRET, $_GET['key'])) {
        http_response_code(403);
        echo "Доступ запрещён. Укажите ?key=ВАШ_TG_WEBHOOK_SECRET\n";
        exit;
    }
    // Этот скрипт защищён секретным ключом (браузер) или доступен только с
    // самого сервера (cron/CLI), так что можно безопасно показывать текст
    // ошибок прямо тут — иначе при сбое видна голая страница 500 без
    // единой подсказки, что именно пошло не так.
    ini_set('display_errors', '1');
}

function out($text) {
    echo $text . ($GLOBALS['isCli'] ? PHP_EOL : "\n");
}

$pdo = db();

// ---- 1. Входящие апдейты (сообщения боту, нажатия кнопок) -------------
try {
    $stmt = $pdo->prepare('SELECT value FROM bot_state WHERE name = ?');
    $stmt->execute(array('update_offset'));
    $offsetValue = $stmt->fetchColumn();
    if ($offsetValue === false) {
        // Таблица bot_state существует, но строки ещё нет (например, миграция
        // применялась вручную без INSERT) — создаём запись сейчас.
        $pdo->prepare("INSERT INTO bot_state (name, value) VALUES ('update_offset', '0')")->execute();
        $offset = 0;
    } else {
        $offset = (int)$offsetValue;
    }

    $result = tg_api('getUpdates', array(
        'offset' => $offset,
        'timeout' => 0,
        'allowed_updates' => json_encode(array('message', 'callback_query')),
    ));

    if (empty($result['ok']) || !isset($result['result'])) {
        out('Ошибка получения обновлений от Telegram: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    } else {
        $count = 0;
        foreach ($result['result'] as $update) {
            if (isset($update['message'])) {
                bot_handle_message($update['message']);
            } elseif (isset($update['callback_query'])) {
                bot_handle_callback($update['callback_query']);
            }
            $offset = (int)$update['update_id'] + 1;
            $count++;
        }
        $pdo->prepare('UPDATE bot_state SET value = ? WHERE name = ?')->execute(array((string)$offset, 'update_offset'));
        out('Обработано входящих обновлений: ' . $count);
    }
} catch (Exception $ex) {
    out('ОШИБКА при обработке входящих обновлений: ' . $ex->getMessage());
    out('Если ошибка про таблицу bot_state — выполните db/migration_1_bot_polling.sql через phpMyAdmin.');
}

// ---- 2. Исходящие уведомления из очереди -------------------------------
// Независимо от результата шага 1 выше — отправляем то, что успели
// накопить веб-запросы сотрудников (создание заявки, ответ, закрытие,
// закрепление и т.п.), пока этот скрипт не запускался.
try {
    $pending = $pdo->query(
        "SELECT * FROM notification_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 50"
    )->fetchAll();

    $sentCount = 0;
    $failedCount = 0;
    foreach ($pending as $n) {
        $replyMarkup = $n['reply_markup'] !== null ? json_decode($n['reply_markup'], true) : null;
        $sendResult = tg_send_message($n['chat_id'], $n['text'], $replyMarkup);

        if (!empty($sendResult['ok'])) {
            $pdo->prepare("UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")
                ->execute(array($n['id']));
            $sentCount++;
        } else {
            $attempts = (int)$n['attempts'] + 1;
            // После нескольких неудачных попыток помечаем как failed, чтобы не
            // пытаться бесконечно (например, сотрудник ни разу не писал боту
            // /start, и Telegram отказывается доставить сообщение).
            $newStatus = $attempts >= 5 ? 'failed' : 'pending';
            $pdo->prepare('UPDATE notification_queue SET attempts = ?, status = ? WHERE id = ?')
                ->execute(array($attempts, $newStatus, $n['id']));
            $failedCount++;
            out('Не удалось отправить уведомление #' . $n['id'] . ': ' . json_encode($sendResult, JSON_UNESCAPED_UNICODE));
        }
    }

    out('Отправлено уведомлений из очереди: ' . $sentCount . ($failedCount ? (', с ошибкой: ' . $failedCount) : ''));
} catch (Exception $ex) {
    out('ОШИБКА при отправке очереди уведомлений: ' . $ex->getMessage());
    out('Скорее всего, не выполнена миграция db/migration_2_notification_queue.sql — примените её через phpMyAdmin (вкладка «SQL»).');
}

// ---- 3. Push-уведомления в браузер (отдельное веб-приложение /it/) -----
try {
    if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '') {
        out('Push-уведомления не настроены (нет ключей VAPID в config.php — см. it/generate_vapid_keys.php).');
    } elseif (EC_MATH_GMP_MISSING) {
        out('Push-уведомления недоступны: расширение PHP GMP не установлено на сервере.');
    } else {
        $pendingPush = $pdo->query(
            "SELECT pq.*, ps.endpoint, ps.p256dh, ps.auth, ps.id AS sub_id
             FROM push_queue pq
             JOIN push_subscriptions ps ON ps.id = pq.subscription_id
             WHERE pq.status = 'pending'
             ORDER BY pq.id ASC LIMIT 50"
        )->fetchAll();

        $pushSent = 0;
        $pushFailed = 0;
        $pushProxy = defined('PUSH_PROXY') ? PUSH_PROXY : '';

        foreach ($pendingPush as $n) {
            $payload = json_encode(array(
                'title' => $n['title'],
                'body' => $n['body'],
                'url' => $n['url'],
            ), JSON_UNESCAPED_UNICODE);

            $subscription = array('endpoint' => $n['endpoint'], 'p256dh' => $n['p256dh'], 'auth' => $n['auth']);
            $result = webpush_send($subscription, $payload, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY_PEM, VAPID_SUBJECT, $pushProxy);

            if (!empty($result['ok'])) {
                $pdo->prepare("UPDATE push_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute(array($n['id']));
                $pushSent++;
            } elseif (!empty($result['gone'])) {
                // Подписка на стороне браузера больше не существует
                // (приложение удалено/данные очищены) — удаляем её; все
                // связанные записи push_queue удалятся каскадно.
                $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute(array($n['sub_id']));
                $pushFailed++;
            } else {
                $attempts = (int)$n['attempts'] + 1;
                $newStatus = $attempts >= 5 ? 'failed' : 'pending';
                $errorText = webpush_describe_error($result);
                $pdo->prepare('UPDATE push_queue SET attempts = ?, status = ?, last_error = ? WHERE id = ?')
                    ->execute(array($attempts, $newStatus, $errorText, $n['id']));
                $pushFailed++;
                out('Не удалось отправить push #' . $n['id'] . ': ' . $errorText);
            }
        }

        out('Отправлено push-уведомлений: ' . $pushSent . ($pushFailed ? (', с ошибкой: ' . $pushFailed) : ''));
    }
} catch (Exception $ex) {
    out('ОШИБКА при отправке push-уведомлений: ' . $ex->getMessage());
    out('Скорее всего, не выполнена миграция db/migration_3_web_push.sql — примените её через phpMyAdmin (вкладка «SQL»).');
}
