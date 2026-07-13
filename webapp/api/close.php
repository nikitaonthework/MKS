<?php
require_once __DIR__ . '/bootstrap.php';
$user = webapp_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$weekendDuty = !empty($_POST['weekend_duty']);
$hours = isset($_POST['hours']) ? (float)$_POST['hours'] : 1.0;
if ($hours < 0.5) {
    $hours = 0.5;
}
if ($hours > 24) {
    $hours = 24;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    e_json(array('error' => 'Заявка не найдена'), 404);
}
if ($ticket['status'] === 'closed') {
    e_json(array('error' => 'Заявка уже закрыта'), 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW(), assigned_to = COALESCE(assigned_to, ?) WHERE id = ?")
        ->execute(array($user['id'], $ticketId));

    if ($weekendDuty) {
        $pdo->prepare('INSERT INTO duty_hours (ticket_id, it_user_id, hours, work_date) VALUES (?, ?, ?, CURDATE())')
            ->execute(array($ticketId, $user['id'], $hours));
    }

    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    error_log('close ticket failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось закрыть заявку'), 500);
}

e_json(array('ok' => true));
