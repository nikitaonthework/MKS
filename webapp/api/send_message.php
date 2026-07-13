<?php
require_once __DIR__ . '/bootstrap.php';
$user = webapp_auth();

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
        $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute(array($user['id'], $ticketId));
    }

    $mStmt = $pdo->prepare('INSERT INTO messages (ticket_id, sender_id, body) VALUES (?, ?, ?)');
    $mStmt->execute(array($ticketId, $user['id'], $body !== '' ? $body : null));
    $messageId = (int)$pdo->lastInsertId();

    $attachments = array();
    if (!empty($_FILES['files'])) {
        $files = $_FILES['files'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        if ($count > 10) {
            $count = 10;
        }
        $aStmt = $pdo->prepare(
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
            $aStmt->execute(array(
                $messageId, $info['stored_name'], $info['original_name'], $info['mime_type'], $info['file_size'], $info['is_image'],
            ));
            $attachments[] = $info;
        }
    }

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
        }, $attachments),
    ),
));
