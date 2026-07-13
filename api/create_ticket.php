<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/telegram.php';

$user = current_user();
if (!$user) {
    e_json(array('error' => 'Требуется авторизация'), 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$body = trim($_POST['body'] ?? '');
if ($body === '' && empty($_FILES['files'])) {
    e_json(array('error' => 'Введите текст заявки'), 422);
}
if (mb_strlen($body, 'UTF-8') > 4000) {
    e_json(array('error' => 'Текст заявки слишком длинный'), 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $subject = make_subject($body !== '' ? $body : 'Вложение без текста');
    $stmt = $pdo->prepare('INSERT INTO tickets (user_id, status, subject) VALUES (?, ?, ?)');
    $stmt->execute(array($user['id'], 'open', $subject));
    $ticketId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO messages (ticket_id, sender_id, body) VALUES (?, ?, ?)');
    $stmt->execute(array($ticketId, $user['id'], $body !== '' ? $body : null));
    $messageId = (int)$pdo->lastInsertId();

    $savedFiles = save_files_from_request('files', $ticketId, $messageId, $pdo);

    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    error_log('create_ticket failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось создать заявку'), 500);
}

$fresh = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
$fresh->execute(array($ticketId));
$ticketRow = $fresh->fetch();
tg_notify_new_ticket($ticketRow, $user['full_name']);

e_json(array('ok' => true, 'ticket_id' => $ticketId));

/**
 * Сохраняет загруженные файлы из $_FILES[$field] (может быть массив) и создаёт записи attachments.
 */
function save_files_from_request($field, $ticketId, $messageId, $pdo) {
    $saved = array();
    if (empty($_FILES[$field])) {
        return $saved;
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
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $info = save_uploaded_file($files['tmp_name'][$i], $files['name'][$i], $ticketId);
        if (!$info) {
            continue;
        }
        $stmt->execute(array(
            $messageId, $info['stored_name'], $info['original_name'], $info['mime_type'], $info['file_size'], $info['is_image'],
        ));
        $saved[] = $info;
    }
    return $saved;
}
