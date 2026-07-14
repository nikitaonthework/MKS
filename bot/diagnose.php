<?php
/**
 * Автономная диагностика: может ли сервер достучаться до api.telegram.org.
 * Показывает "сырую" правду напрямую через curl, даже если где-то на сервере
 * лежит старая версия других файлов или включён opcache. Если в config.php
 * задан TG_PROXY, дополнительно проверяет соединение через него.
 * Откройте в браузере: https://ваш-сайт/bot/diagnose.php
 * Удалите после диагностики.
 */
header('Content-Type: text/plain; charset=utf-8');

$proxy = '';
$configPath = __DIR__ . '/../config/config.php';
if (is_file($configPath)) {
    require_once $configPath;
    if (defined('TG_PROXY')) {
        $proxy = TG_PROXY;
    }
}

echo "PHP version: " . PHP_VERSION . "\n";
echo "curl extension: " . (extension_loaded('curl') ? 'да' : 'НЕТ — обратитесь в поддержку хостинга') . "\n";
echo "openssl extension: " . (extension_loaded('openssl') ? 'да' : 'НЕТ') . "\n";
if (function_exists('curl_version')) {
    $v = curl_version();
    echo "curl version: " . $v['version'] . ", ssl: " . $v['ssl_version'] . "\n";
}
echo "\n";

if (!extension_loaded('curl')) {
    exit;
}

$ch = curl_init('https://api.telegram.org');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_NOBODY => true,
));
$ok = curl_exec($ch);
$errno = curl_errno($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "Подключение к https://api.telegram.org:\n";
echo "  успех: " . ($ok !== false ? 'да' : 'НЕТ') . "\n";
echo "  curl_errno: $errno\n";
echo "  curl_error: " . ($err !== '' ? $err : '(нет)') . "\n";
echo "  http_code: " . (isset($info['http_code']) ? $info['http_code'] : '-') . "\n";
echo "\n";

if ($ok === false) {
    echo "Диагноз по коду ошибки:\n";
    switch ($errno) {
        case 6:
            echo "  6 = не удалось разрешить DNS-имя api.telegram.org.\n"
                . "  Хостинг либо блокирует DNS для внешних доменов, либо есть проблема с сетью.\n";
            break;
        case 7:
            echo "  7 = не удалось установить соединение (порт 443 заблокирован фаерволом хостинга).\n";
            break;
        case 28:
            echo "  28 = таймаut — сервер пытался подключиться, но не дождался ответа.\n"
                . "  Похоже на блокировку исходящих соединений хостингом.\n";
            break;
        case 35:
        case 60:
        case 77:
            echo "  $errno = проблема с SSL-сертификатами (устаревший набор корневых\n"
                . "  сертификатов на сервере). Это самая частая причина на старых\n"
                . "  хостингах — лечится обновлением ca-bundle средствами хостинга,\n"
                . "  либо явным указанием файла сертификатов в коде (я могу это добавить).\n";
            break;
        default:
            echo "  Отправьте этот текст целиком в поддержку хостинга — они смогут сказать,\n"
                . "  блокируют ли они исходящие HTTPS-запросы с сервера.\n";
    }
    echo "\nЕсли хостинг подтвердит блокировку и не сможет её снять — можно\n"
        . "направить запросы к Telegram через прокси-сервер: укажите его\n"
        . "адрес в константе TG_PROXY в config/config.php и обновите эту\n"
        . "страницу — ниже появится проверка соединения через прокси.\n";
} else {
    echo "Прямое соединение с Telegram работает. Значит, дело не в сети/SSL,\n"
        . "а в чём-то другом (например, ещё не обновлён файл includes/telegram.php\n"
        . "на сервере, или неверный APP_URL — см. bot/setwebhook.php).\n";
}

if ($proxy !== '') {
    echo "\n----------------------------------------\n";
    echo "TG_PROXY задан в config.php, проверяю соединение через прокси…\n\n";
    $ch2 = curl_init('https://api.telegram.org');
    curl_setopt_array($ch2, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOBODY => true,
        CURLOPT_PROXY => $proxy,
    ));
    $ok2 = curl_exec($ch2);
    $errno2 = curl_errno($ch2);
    $err2 = curl_error($ch2);
    $info2 = curl_getinfo($ch2);
    curl_close($ch2);

    echo "Подключение к https://api.telegram.org через прокси:\n";
    echo "  успех: " . ($ok2 !== false ? 'да' : 'НЕТ') . "\n";
    echo "  curl_errno: $errno2\n";
    echo "  curl_error: " . ($err2 !== '' ? $err2 : '(нет)') . "\n";
    echo "  http_code: " . (isset($info2['http_code']) ? $info2['http_code'] : '-') . "\n";
    if ($ok2 !== false) {
        echo "\nОтлично, через прокси соединение работает — бот будет использовать\n"
            . "его автоматически для всех запросов к Telegram.\n";
    }
}
