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
 * Разрешённые типы вложений: изображения и распространённые документы.
 */
function allowed_upload_extensions() {
    return array(
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'pdf' => 'file', 'doc' => 'file', 'docx' => 'file', 'xls' => 'file', 'xlsx' => 'file',
        'txt' => 'file', 'zip' => 'file', 'rar' => 'file', 'csv' => 'file',
    );
}

/**
 * Сохраняет один загруженный файл ($_FILES[...] элемент) в UPLOAD_DIR/{ticket_id}/
 * Возвращает массив с данными файла или null при ошибке / недопустимом типе.
 */
function save_uploaded_file($fileTmpPath, $originalName, $ticketId) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = allowed_upload_extensions();
    if (!isset($allowed[$ext])) {
        return null;
    }
    if (!is_uploaded_file($fileTmpPath)) {
        return null;
    }
    $size = filesize($fileTmpPath);
    if ($size === false || $size <= 0 || $size > MAX_UPLOAD_SIZE) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    $dir = UPLOAD_DIR . '/' . $ticketId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!move_uploaded_file($fileTmpPath, $dest)) {
        return null;
    }

    return array(
        'stored_name' => $ticketId . '/' . $storedName,
        'original_name' => $originalName,
        'mime_type' => $mime ? $mime : 'application/octet-stream',
        'file_size' => $size,
        'is_image' => $allowed[$ext] === 'image' ? 1 : 0,
    );
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
