<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

$stmt = db()->query(
    'SELECT id, full_name, phone, job_title, role, badge_color, is_active, must_change_password, created_at
     FROM users
     ORDER BY is_active DESC, full_name ASC'
);
$rows = $stmt->fetchAll();

$out = array_map(function ($u) {
    return array(
        'id' => (int)$u['id'],
        'full_name' => $u['full_name'],
        'phone' => $u['phone'],
        'job_title' => $u['job_title'],
        'role' => $u['role'],
        'badge_color' => $u['badge_color'],
        'is_active' => (bool)$u['is_active'],
        'must_change_password' => (bool)$u['must_change_password'],
        'created_at' => $u['created_at'],
    );
}, $rows);

e_json(array('ok' => true, 'users' => $out));
