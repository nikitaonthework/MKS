<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$body = trim($_POST['body'] ?? '');

$stmt = db()->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}
if ($ticket['status'] !== 'open') {
    e_json(array('error' => 'Заявка закрыта'), 403);
}
if ($body === '' && empty($_FILES['files'])) {
    e_json(array('error' => 'Введите сообщение'), 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    if (empty($ticket['assigned_to'])) {
        // Атомарное условие в WHERE не даёт двум сотрудникам, ответившим на
        // ещё не закреплённую заявку почти одновременно, оба стать «владельцем».
        $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL')
            ->execute(array($user['id'], $ticketId));
    }

    $mStmt = $pdo->prepare('INSERT INTO messages (ticket_id, sender_id, body) VALUES (?, ?, ?)');
    $mStmt->execute(array($ticketId, $user['id'], $body !== '' ? $body : null));
    $messageId = (int)$pdo->lastInsertId();

    $uploadResult = process_uploaded_files('files', $ticketId, $messageId, $pdo);

    $pdo->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute(array($ticketId));
    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    error_log('webapp send_message failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось отправить сообщение'), 500);
}

e_json(array(
    'ok' => true,
    'message' => array(
        'id' => $messageId,
        'body' => $body,
        'created_at' => date('Y-m-d H:i:s'),
        'sender_id' => (int)$user['id'],
        'sender_name' => $user['full_name'],
        'sender_role' => 'it',
        'attachments' => array_map(function ($a) {
            return array('is_image' => (bool)$a['is_image'], 'url' => UPLOAD_URL . '/' . $a['stored_name'], 'name' => $a['original_name'], 'size' => $a['file_size']);
        }, $uploadResult['saved']),
    ),
    'attachment_errors' => $uploadResult['errors'],
));
