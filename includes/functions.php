<?php
require_once __DIR__ . '/db.php';

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function e_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token) {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function format_dt($mysqlDatetime) {
    $ts = strtotime($mysqlDatetime);
    if (!$ts) {
        return '';
    }
    $today = date('Y-m-d');
    $day = date('Y-m-d', $ts);
    if ($day === $today) {
        return 'Сегодня, ' . date('H:i', $ts);
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($day === $yesterday) {
        return 'Вчера, ' . date('H:i', $ts);
    }
    return date('d.m.Y, H:i', $ts);
}

function make_subject($text, $len = 70) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $len) {
        return mb_substr($text, 0, $len, 'UTF-8') . '…';
    }
    if (strlen($text) > $len) {
        return substr($text, 0, $len) . '…';
    }
    return $text;
}

function human_size($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' МБ';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' КБ';
    }
    return $bytes . ' Б';
}

/**
 * Разрешённые типы вложений: изображения и распространённые документы,
 * вместе с ожидаемыми MIME-подписями для каждого расширения (защита от
 * подмены типа файла — например, скрипта, переименованного в .jpg).
 */
function allowed_upload_extensions() {
    return array(
        'jpg'  => array('kind' => 'image', 'mime' => array('image/jpeg')),
        'jpeg' => array('kind' => 'image', 'mime' => array('image/jpeg')),
        'png'  => array('kind' => 'image', 'mime' => array('image/png')),
        'gif'  => array('kind' => 'image', 'mime' => array('image/gif')),
        'webp' => array('kind' => 'image', 'mime' => array('image/webp')),
        'pdf'  => array('kind' => 'file', 'mime' => array('application/pdf')),
        'doc'  => array('kind' => 'file', 'mime' => array('application/msword', 'application/x-ole-storage', 'application/octet-stream')),
        'docx' => array('kind' => 'file', 'mime' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip')),
        'xls'  => array('kind' => 'file', 'mime' => array('application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream')),
        'xlsx' => array('kind' => 'file', 'mime' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip')),
        'txt'  => array('kind' => 'file', 'mime' => array('text/plain')),
        'csv'  => array('kind' => 'file', 'mime' => array('text/plain', 'text/csv', 'application/csv')),
        'zip'  => array('kind' => 'file', 'mime' => array('application/zip', 'application/x-zip-compressed')),
        'rar'  => array('kind' => 'file', 'mime' => array('application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar')),
    );
}

/**
 * Сохраняет один загруженный файл ($_FILES[...] элемент) в UPLOAD_DIR/{ticket_id}/
 * Возвращает массив с данными файла или null при ошибке / недопустимом типе.
 * Причина отказа передаётся через $errorMsg (по ссылке).
 */
function save_uploaded_file($fileTmpPath, $originalName, $ticketId, &$errorMsg = null) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = allowed_upload_extensions();
    if (!isset($allowed[$ext])) {
        $errorMsg = 'недопустимый тип файла';
        return null;
    }
    if (!is_uploaded_file($fileTmpPath)) {
        $errorMsg = 'ошибка загрузки файла';
        return null;
    }
    $size = filesize($fileTmpPath);
    if ($size === false || $size <= 0) {
        $errorMsg = 'файл повреждён или пуст';
        return null;
    }
    if ($size > MAX_UPLOAD_SIZE) {
        $errorMsg = 'файл превышает допустимый размер (' . human_size(MAX_UPLOAD_SIZE) . ')';
        return null;
    }

    $spec = $allowed[$ext];
    $isImage = $spec['kind'] === 'image';

    // Для изображений — надёжная проверка через getimagesize() (не даёт
    // подсунуть произвольное содержимое под видом фото).
    if ($isImage && @getimagesize($fileTmpPath) === false) {
        $errorMsg = 'файл повреждён или не является изображением';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    if (!in_array($mime, $spec['mime'], true)) {
        $errorMsg = 'содержимое файла не соответствует расширению «.' . $ext . '»';
        return null;
    }

    $dir = UPLOAD_DIR . '/' . $ticketId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!move_uploaded_file($fileTmpPath, $dest)) {
        $errorMsg = 'не удалось сохранить файл на сервере';
        return null;
    }

    return array(
        'stored_name' => $ticketId . '/' . $storedName,
        'original_name' => $originalName,
        'mime_type' => $mime,
        'file_size' => $size,
        'is_image' => $isImage ? 1 : 0,
    );
}

function upload_error_message($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'файл слишком большой';
        case UPLOAD_ERR_PARTIAL:
            return 'файл загружен не полностью, попробуйте ещё раз';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'ошибка сервера при загрузке файла';
        default:
            return 'не удалось загрузить файл';
    }
}

/**
 * Обрабатывает $_FILES[$field] (может содержать несколько файлов), сохраняет
 * допустимые файлы через save_uploaded_file() и создаёт записи attachments.
 * Используется всеми обработчиками отправки сообщений/заявок, чтобы логика
 * загрузки не дублировалась. Возвращает array('saved' => [...], 'errors' => [...]) —
 * errors содержит человекочитаемые причины отказа по каждому не сохранённому файлу.
 */
function process_uploaded_files($field, $ticketId, $messageId, $pdo) {
    $result = array('saved' => array(), 'errors' => array());
    if (empty($_FILES[$field])) {
        return $result;
    }
    $files = $_FILES[$field];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count > 10) {
        $count = 10;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO attachments (message_id, stored_name, original_name, mime_type, file_size, is_image) VALUES (?, ?, ?, ?, ?, ?)'
    );
    for ($i = 0; $i < $count; $i++) {
        $name = isset($files['name'][$i]) && $files['name'][$i] !== '' ? $files['name'][$i] : ('файл ' . ($i + 1));
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $result['errors'][] = $name . ': ' . upload_error_message($files['error'][$i]);
            continue;
        }
        $errorMsg = null;
        $info = save_uploaded_file($files['tmp_name'][$i], $files['name'][$i], $ticketId, $errorMsg);
        if (!$info) {
            $result['errors'][] = $name . ': ' . ($errorMsg ? $errorMsg : 'не удалось загрузить файл');
            continue;
        }
        $stmt->execute(array(
            $messageId, $info['stored_name'], $info['original_name'], $info['mime_type'], $info['file_size'], $info['is_image'],
        ));
        $result['saved'][] = $info;
    }
    return $result;
}

function it_staff_list() {
    $stmt = db()->query("SELECT id, full_name, telegram_id, badge_color FROM users WHERE role = 'it' ORDER BY id");
    return $stmt->fetchAll();
}

function badge_color_hex($color) {
    if ($color === 'purple') {
        return '#8b5cf6';
    }
    if ($color === 'blue') {
        return '#38bdf8';
    }
    return '#94a3b8';
}
