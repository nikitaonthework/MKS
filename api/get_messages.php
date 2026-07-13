<?php
require_once __DIR__ . '/../includes/auth.php';

$user = current_user();
if (!$user) {
    e_json(array('error' => 'Требуется авторизация'), 401);
}

$ticketId = (int)($_GET['ticket_id'] ?? 0);
$afterId = (int)($_GET['after_id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM tickets WHERE id = ?');
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

$attByMsg = array();
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = db()->prepare("SELECT * FROM attachments WHERE message_id IN ($in) ORDER BY id ASC");
    $aStmt->execute($ids);
    foreach ($aStmt->fetchAll() as $a) {
        $attByMsg[$a['message_id']][] = array(
            'is_image' => (bool)$a['is_image'],
            'url' => UPLOAD_URL . '/' . $a['stored_name'],
            'name' => $a['original_name'],
            'size' => $a['file_size'],
        );
    }
}

$out = array();
foreach ($rows as $m) {
    $out[] = array(
        'id' => (int)$m['id'],
        'body' => $m['body'],
        'created_at' => $m['created_at'],
        'sender_id' => (int)$m['sender_id'],
        'sender_name' => $m['sender_name'],
        'sender_role' => $m['sender_role'],
        'attachments' => isset($attByMsg[$m['id']]) ? $attByMsg[$m['id']] : array(),
    );
}

e_json(array('ok' => true, 'messages' => $out, 'status' => $ticket['status']));
