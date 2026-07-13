<?php
require_once __DIR__ . '/bootstrap.php';
$user = webapp_auth();

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
if ($ticket['status'] !== 'closed') {
    e_json(array('error' => 'Заявка уже открыта'), 409);
}

$pdo->prepare("UPDATE tickets SET status = 'open', closed_at = NULL, updated_at = NOW() WHERE id = ?")->execute(array($ticketId));

e_json(array('ok' => true));
