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
$stmt = db()->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}

$isOwner = (int)$ticket['user_id'] === (int)$user['id'];
if (!$isOwner || $ticket['status'] !== 'closed') {
    e_json(array('error' => 'Недостаточно прав или заявка уже открыта'), 403);
}

db()->prepare("UPDATE tickets SET status = 'open', closed_at = NULL, updated_at = NOW() WHERE id = ?")->execute(array($ticketId));

tg_notify_reopened($ticket, $user['full_name']);

e_json(array('ok' => true));
