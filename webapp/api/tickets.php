<?php
require_once __DIR__ . '/bootstrap.php';
$user = webapp_auth();

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'new';

if ($tab === 'my') {
    $stmt = db()->prepare(
        "SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color
         FROM tickets t JOIN users au ON au.id = t.user_id LEFT JOIN users it ON it.id = t.assigned_to
         WHERE t.status = 'open' AND t.assigned_to = ? ORDER BY t.updated_at DESC"
    );
    $stmt->execute(array($user['id']));
} elseif ($tab === 'closed') {
    $stmt = db()->query(
        "SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color
         FROM tickets t JOIN users au ON au.id = t.user_id LEFT JOIN users it ON it.id = t.assigned_to
         WHERE t.status = 'closed' ORDER BY t.closed_at DESC, t.updated_at DESC"
    );
} else {
    $stmt = db()->query(
        "SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color
         FROM tickets t JOIN users au ON au.id = t.user_id LEFT JOIN users it ON it.id = t.assigned_to
         WHERE t.status = 'open' ORDER BY t.updated_at DESC"
    );
}

$rows = $stmt->fetchAll();
$out = array_map('ticket_to_array', $rows);
e_json(array('ok' => true, 'tickets' => $out));
