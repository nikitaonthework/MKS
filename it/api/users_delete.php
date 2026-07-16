<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$ids = isset($data['ids']) && is_array($data['ids']) ? array_map('intval', $data['ids']) : array();
$ids = array_values(array_unique(array_filter($ids)));

if (!$ids) {
    e_json(array('error' => 'Не выбрано ни одного сотрудника'), 422);
}

$pdo = db();
$deleted = array();
$deactivated = array();
$skipped = array();

foreach ($ids as $id) {
    if ($id === (int)$user['id']) {
        $skipped[] = $id;
        continue;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute(array($id));
    if (!$stmt->fetch()) {
        $skipped[] = $id;
        continue;
    }

    $refStmt = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM tickets WHERE user_id = ? OR assigned_to = ?)
         + (SELECT COUNT(*) FROM messages WHERE sender_id = ?)
         + (SELECT COUNT(*) FROM duty_hours WHERE it_user_id = ?) AS refs'
    );
    $refStmt->execute(array($id, $id, $id, $id));
    $refs = (int)$refStmt->fetchColumn();

    if ($refs === 0) {
        // Ничего не ссылается на сотрудника — можно полностью удалить
        // (remember_tokens/push_subscriptions удалятся каскадно).
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($id));
        $deleted[] = $id;
    } else {
        // Есть история заявок/сообщений/дежурств — удалить нельзя, не
        // потеряв её (внешние ключи и не позволят), поэтому деактивируем:
        // войти в систему сотрудник больше не сможет, история сохранится.
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute(array($id));
        $deactivated[] = $id;
    }
}

e_json(array('ok' => true, 'deleted' => $deleted, 'deactivated' => $deactivated, 'skipped' => $skipped));
