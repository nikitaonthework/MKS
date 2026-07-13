<?php
/**
 * Одноразовый скрипт установки Telegram webhook.
 * Откройте в браузере: https://ваш-сайт/bot/setwebhook.php?key=ВАШ_TG_WEBHOOK_SECRET
 * После успешной установки рекомендуется удалить этот файл с сервера.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_GET['key']) || !hash_equals(TG_WEBHOOK_SECRET, $_GET['key'])) {
    http_response_code(403);
    echo "Доступ запрещён. Укажите ?key=ВАШ_TG_WEBHOOK_SECRET (см. config/config.php)\n";
    exit;
}

$url = rtrim(APP_URL, '/') . '/bot/webhook.php';

$result = tg_api('setWebhook', array(
    'url' => $url,
    'secret_token' => TG_WEBHOOK_SECRET,
    'allowed_updates' => json_encode(array('message', 'callback_query')),
));

echo "Webhook URL: $url\n\n";
echo "Ответ Telegram API:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
