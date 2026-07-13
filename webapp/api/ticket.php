<?php
require_once __DIR__ . '/bootstrap.php';
$user = webapp_auth();

$ticketId = (int)($_GET['id'] ?? 0);
$afterId = (int)($_GET['after_id'] ?? 0);

$stmt = db()->prepare(
    "SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color
     FROM tickets t JOIN users au ON au.id = t.user_id LEFT JOIN users it ON it.id = t.assigned_to
     WHERE t.id = ?"
);
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}

$mStmt = db()->prepare(
    'SELECT m.*, u.full_name AS sender_name, u.role AS sender_role
     FROM messages m JOIN users u ON u.id = m.sender_id
     WHERE m.ticket_id = ? AND m.id > ? ORDER BY m.id ASC'
);
$mStmt->execute(array($ticketId, $afterId));
$rows = $mStmt->fetchAll();

$messages = array();
foreach ($rows as $m) {
    $aStmt = db()->prepare('SELECT * FROM attachments WHERE message_id = ? ORDER BY id ASC');
    $aStmt->execute(array($m['id']));
    $atts = array();
    foreach ($aStmt->fetchAll() as $a) {
        $atts[] = array(
            'is_image' => (bool)$a['is_image'],
            'url' => UPLOAD_URL . '/' . $a['stored_name'],
            'name' => $a['original_name'],
            'size' => $a['file_size'],
        );
    }
    $messages[] = array(
        'id' => (int)$m['id'],
        'body' => $m['body'],
        'created_at' => $m['created_at'],
        'sender_id' => (int)$m['sender_id'],
        'sender_name' => $m['sender_name'],
        'sender_role' => $m['sender_role'],
        'attachments' => $atts,
    );
}

e_json(array('ok' => true, 'ticket' => ticket_to_array($ticket), 'messages' => $messages, 'my_id' => (int)$user['id']));
