<?php
/**
 * Одноразовый скрипт генерации ключей VAPID для push-уведомлений.
 * Откройте в браузере: https://ваш-сайт/it/generate_vapid_keys.php?key=ВАШ_TG_WEBHOOK_SECRET
 * (используется тот же секрет, что и для bot/setwebhook.php — отдельный
 * заводить не нужно). Полученные строки вставьте в config/config.php
 * (VAPID_PUBLIC_KEY и VAPID_PRIVATE_KEY_PEM), затем удалите этот файл —
 * он не должен оставаться на сервере после использования.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ec_math.php';
require_once __DIR__ . '/../includes/webpush.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_GET['key']) || !hash_equals(TG_WEBHOOK_SECRET, $_GET['key'])) {
    http_response_code(403);
    echo "Доступ запрещён. Укажите ?key=ВАШ_TG_WEBHOOK_SECRET (см. config/config.php, TG_WEBHOOK_SECRET)\n";
    exit;
}

if (!extension_loaded('gmp')) {
    echo "⚠️  Расширение PHP GMP не установлено на сервере — оно нужно для\n"
        . "   шифрования push-уведомлений (ECDH). Обычно включается в панели\n"
        . "   хостинга одной галочкой (\"Select PHP Extensions\" → gmp) или\n"
        . "   через поддержку хостинга. Ключи ниже всё равно сгенерированы\n"
        . "   и рабочие, но отправка push не заработает, пока GMP не будет включён.\n\n";
}

$keys = webpush_vapid_generate_keys();
if (!$keys) {
    echo "Не удалось сгенерировать ключи (нет поддержки EC-ключей в openssl на сервере).\n";
    exit;
}

echo "Готово! Вставьте эти строки в config/config.php (заменив текущие пустые\n";
echo "значения VAPID_PUBLIC_KEY и VAPID_PRIVATE_KEY_PEM):\n\n";
echo "define('VAPID_PUBLIC_KEY', '" . $keys['publicKey'] . "');\n\n";
// PEM многострочный — оборачиваем в двойные кавычки с \n, чтобы PHP
// правильно восстановил реальные переводы строк при чтении config.php
// (в одинарных кавычках \n остался бы как два символа "обратный слэш + n").
echo "define('VAPID_PRIVATE_KEY_PEM', \"" . str_replace("\n", '\n', trim($keys['privateKeyPem'])) . "\");\n\n";
echo "После сохранения config/config.php удалите этот файл (it/generate_vapid_keys.php)\n";
echo "с сервера — приватный ключ VAPID больше нигде показываться не должен.\n";
