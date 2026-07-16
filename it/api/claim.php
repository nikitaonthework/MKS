<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}
if (!empty($ticket['assigned_to'])) {
    e_json(array('error' => 'Заявка уже закреплена'), 409);
}

// Атомарное условие в WHERE не даёт двум одновременным запросам оба «выиграть» гонку.
$upd = $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL');
$upd->execute(array($user['id'], $ticketId));
if ($upd->rowCount() === 0) {
    e_json(array('error' => 'Заявка уже закреплена другим сотрудником'), 409);
}

notify_claimed($ticketId, $user['full_name'], $user['id']);

e_json(array('ok' => true));
