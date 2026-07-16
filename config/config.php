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

// Полный адрес сайта БЕЗ слэша на конце, например https://itdesk.myclinic.ru
// Используется для формирования ссылок в уведомлениях.
define('APP_URL', 'https://example.com');

// Секрет для одноразовых служебных скриптов (it/generate_vapid_keys.php,
// it/diagnose_push.php) — придумайте своё случайное значение.
define('ADMIN_SECRET', 'change-this-secret');

// ---- Push-уведомления в браузер (отдельное веб-приложение /it/) --------
// Сгенерируйте один раз через it/generate_vapid_keys.php и вставьте сюда.
// Пока пусто — push-уведомления просто отключены, остальной сайт работает.
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY_PEM', '');
// Контакт для push-сервисов (Google/Mozilla/Apple), обычно mailto: или
// ссылка на сайт клиники. Не показывается пользователям.
define('VAPID_SUBJECT', 'mailto:admin@example.com');
// Если ваш хостинг блокирует исходящие HTTPS-запросы к push-сервисам
// (Google/Mozilla/Apple) — можно направить их через прокси-сервер:
//   'http://логин:пароль@адрес:порт'  — HTTP(S) прокси
//   'socks5h://адрес:порт'            — SOCKS5 прокси
// Оставьте пустой строкой, если прокси не нужен (соединение работает напрямую).
define('PUSH_PROXY', '');

// ---- Прочее -------------------------------------------------------------
define('APP_TIMEZONE', 'Europe/Moscow');
define('UPLOAD_DIR', __DIR__ . '/../storage/uploads');
// UPLOAD_URL вычисляется из APP_URL, чтобы корректно работать и при
// установке в корень домена, и в подпапку (например https://site.ru/mks) —
// иначе ссылки на вложения будут вести мимо подпапки.
define('UPLOAD_URL', rtrim(parse_url(APP_URL, PHP_URL_PATH), '/') . '/storage/uploads');
define('MAX_UPLOAD_SIZE', 15 * 1024 * 1024); // 15 МБ на файл
define('CLINIC_NAME', 'IT Service Desk');

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
