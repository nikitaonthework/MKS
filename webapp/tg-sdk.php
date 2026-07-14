<?php
/**
 * Отдаёт telegram-web-app.js со своего домена вместо прямой ссылки на
 * telegram.org — если сеть клиента (или сеть хостинга) режет доступ к
 * telegram.org напрямую, страница мини-аппа всё равно сможет загрузить SDK,
 * т.к. сервер сам подтягивает и кэширует файл (через TG_PROXY, если задан).
 */
require_once __DIR__ . '/../config/config.php';

$cacheFile = __DIR__ . '/../storage/cache/telegram-web-app.js';
$maxAge = 3600; // раз в час обновляем кэш

$needsFetch = !is_file($cacheFile) || (time() - filemtime($cacheFile)) > $maxAge;

if ($needsFetch) {
    $ch = curl_init('https://telegram.org/js/telegram-web-app.js');
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    );
    if (defined('TG_PROXY') && TG_PROXY !== '') {
        $opts[CURLOPT_PROXY] = TG_PROXY;
    }
    curl_setopt_array($ch, $opts);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($content !== false && $httpCode === 200 && strlen($content) > 100) {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, $content);
    }
}

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=1800');

if (is_file($cacheFile)) {
    readfile($cacheFile);
} else {
    // Не удалось получить свежую копию и нет старого кэша — отдаём пустой
    // скрипт, чтобы страница не «висела» на битом <script>, а корректно
    // показала сообщение «откройте через Telegram-бота».
    header('X-Tg-Sdk-Fallback: 1');
    echo '/* telegram-web-app.js unavailable */';
}
