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

// Если ваш хостинг блокирует исходящие HTTPS-запросы к api.telegram.org
// (проверяется через bot/diagnose.php), укажите здесь адрес прокси-сервера,
// через который PHP будет обращаться к Telegram, например:
//   'http://login:pass@1.2.3.4:8080'  — HTTP(S) прокси
//   'socks5://1.2.3.4:1080'           — SOCKS5 прокси
// Оставьте пустой строкой, если прокси не нужен (соединение работает напрямую).
define('TG_PROXY', 'socks5h://109.120.157.74:1080');

// ---- Push-уведомления в браузер (отдельное веб-приложение /it/) --------
// Сгенерируйте один раз через it/generate_vapid_keys.php и вставьте сюда.
// Пока пусто — push-уведомления просто отключены, остальной сайт работает.
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY_PEM', '');
// Контакт для push-сервисов (Google/Mozilla/Apple), обычно mailto: или
// ссылка на сайт клиники. Не показывается пользователям.
define('VAPID_SUBJECT', 'mailto:admin@example.com');
// Если push-уведомления тоже не проходят напрямую (та же блокировка, что
// и с Telegram) — можно направить их через тот же или другой прокси:
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
