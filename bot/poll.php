<?php
/**
 * Альтернатива вебхуку: получает новые сообщения/нажатия кнопок у Telegram
 * через getUpdates и обрабатывает их — без необходимости, чтобы Telegram
 * сам стучался на ваш сайт. Запускайте этот скрипт регулярно через
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
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bot_handlers.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!isset($_GET['key']) || !hash_equals(TG_WEBHOOK_SECRET, $_GET['key'])) {
        http_response_code(403);
        echo "Доступ запрещён. Укажите ?key=ВАШ_TG_WEBHOOK_SECRET\n";
        exit;
    }
}

function out($text) {
    global $isCli;
    echo $text . ($GLOBALS['isCli'] ? PHP_EOL : "\n");
}

$pdo = db();
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
    exit;
}

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

out('Обработано обновлений: ' . $count);
