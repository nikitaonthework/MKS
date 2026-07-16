<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_active_user();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    e_json(array('error' => 'Некорректные данные подписки'), 422);
}

$endpoint = substr($data['endpoint'], 0, 500);
$p256dh = substr($data['keys']['p256dh'], 0, 255);
$auth = substr($data['keys']['auth'], 0, 255);
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id FROM push_subscriptions WHERE endpoint = ?');
$stmt->execute(array($endpoint));
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare('UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ? WHERE id = ?')
        ->execute(array($user['id'], $p256dh, $auth, $userAgent, $existing['id']));
} else {
    $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?)')
        ->execute(array($user['id'], $endpoint, $p256dh, $auth, $userAgent));
}

e_json(array('ok' => true));
