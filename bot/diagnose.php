<?php
/**
 * Автономная диагностика: может ли сервер достучаться до api.telegram.org.
 * Не зависит от config.php/includes — показывает "сырую" правду, даже если
 * где-то на сервере лежит старая версия других файлов или включён opcache.
 * Откройте в браузере: https://ваш-сайт/bot/diagnose.php
 * Удалите после диагностики.
 */
header('Content-Type: text/plain; charset=utf-8');

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
} else {
    echo "Соединение с Telegram работает. Значит, дело не в сети/SSL, а в чём-то\n"
        . "другом (например, ещё не обновлён файл includes/telegram.php на сервере).\n";
}
