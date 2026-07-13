<?php
/**
 * Конфигурация IT Service Desk.
 * Заполните данные вашей базы данных и адрес сайта перед запуском.
 */

// ---- База данных -----------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'itdesk');
define('DB_USER', 'itdesk_user');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

// ---- Telegram-бот ------------------------------------------------------
define('TG_BOT_TOKEN', '8980320974:AAEpOyFGh-QlUaFEViHn0pDfRipfm7FojNw');

// Полный адрес сайта БЕЗ слэша на конце, например https://itdesk.myclinic.ru
// Используется для формирования ссылок в уведомлениях и в webhook/mini app.
define('APP_URL', 'https://example.com');

// Секрет для проверки Telegram webhook (задайте своё случайное значение
// и укажите такое же в bot/setwebhook.php)
define('TG_WEBHOOK_SECRET', 'change-this-secret');

// ---- Прочее -------------------------------------------------------------
define('APP_TIMEZONE', 'Europe/Moscow');
define('UPLOAD_DIR', __DIR__ . '/../storage/uploads');
define('UPLOAD_URL', '/storage/uploads');
define('MAX_UPLOAD_SIZE', 15 * 1024 * 1024); // 15 МБ на файл
define('CLINIC_NAME', 'IT Service Desk');

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
