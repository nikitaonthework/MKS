<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bot_handlers.php';

// Проверка секретного токена вебхука (устанавливается в setwebhook.php)
$secretHeader = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if (!hash_equals(TG_WEBHOOK_SECRET, $secretHeader)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) {
    http_response_code(200);
    exit;
}

if (isset($update['message'])) {
    bot_handle_message($update['message']);
} elseif (isset($update['callback_query'])) {
    bot_handle_callback($update['callback_query']);
}

http_response_code(200);
exit;
