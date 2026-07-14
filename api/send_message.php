<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/telegram.php';

$user = require_active_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    e_json(array('error' => 'Сессия истекла, обновите страницу и попробуйте снова.'), 403);
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$body = trim($_POST['body'] ?? '');

$stmt = db()->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}

$isOwner = (int)$ticket['user_id'] === (int)$user['id'];
$canReply = ($isOwner || $user['role'] === 'it') && $ticket['status'] === 'open';
if (!$canReply) {
    e_json(array('error' => 'Заявка закрыта или у вас нет доступа для ответа'), 403);
}

if ($body === '' && empty($_FILES['files'])) {
    e_json(array('error' => 'Введите сообщение'), 422);
}
if (mb_strlen($body, 'UTF-8') > 4000) {
    e_json(array('error' => 'Сообщение слишком длинное'), 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $mStmt = $pdo->prepare('INSERT INTO messages (ticket_id, sender_id, body) VALUES (?, ?, ?)');
    $mStmt->execute(array($ticketId, $user['id'], $body !== '' ? $body : null));
    $messageId = (int)$pdo->lastInsertId();

    $uploadResult = process_uploaded_files('files', $ticketId, $messageId, $pdo);

    $pdo->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute(array($ticketId));

    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    error_log('send_message failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось отправить сообщение'), 500);
}

respond_json_then_continue(array(
    'ok' => true,
    'message' => array(
        'id' => $messageId,
        'body' => $body,
        'created_at' => date('Y-m-d H:i:s'),
        'sender_id' => (int)$user['id'],
        'sender_name' => $user['full_name'],
        'sender_role' => $user['role'],
        'attachments' => array_map('map_attachment_for_json', $uploadResult['saved']),
    ),
    'attachment_errors' => $uploadResult['errors'],
));

if ($isOwner) {
    tg_notify_new_reply($ticket, $user['full_name']);
}

function map_attachment_for_json($a) {
    return array(
        'is_image' => (bool)$a['is_image'],
        'url' => UPLOAD_URL . '/' . $a['stored_name'],
        'name' => $a['original_name'],
        'size' => $a['file_size'],
    );
}
