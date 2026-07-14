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

echo "Webhook URL: $url\n";
if (strpos(APP_URL, 'index.php') !== false) {
    echo "\n⚠️  APP_URL в config/config.php содержит «index.php» — это почти\n"
        . "   наверняка неверно. APP_URL должен быть просто адресом папки,\n"
        . "   куда вы загрузили файлы сайта (например https://site.ru/mks,\n"
        . "   БЕЗ /index.php и БЕЗ слэша на конце).\n";
}
echo "\n";

$result = tg_api('setWebhook', array(
    'url' => $url,
    'secret_token' => TG_WEBHOOK_SECRET,
    'allowed_updates' => json_encode(array('message', 'callback_query')),
));

echo "Ответ Telegram API:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (isset($result['error_source']) && $result['error_source'] === 'curl') {
    echo "\nСервер не смог отправить запрос к api.telegram.org (ошибка cURL).\n"
        . "Частые причины: хостинг блокирует исходящие HTTPS-запросы,\n"
        . "устаревший набор корневых SSL-сертификатов на сервере, или\n"
        . "отключено PHP-расширение curl. Обратитесь в поддержку хостинга\n"
        . "с текстом ошибки curl_error выше.\n";
}
