<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

$id = (int)($_POST['id'] ?? 0);
$fullName = trim($_POST['full_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = (string)($_POST['password'] ?? '');
$forceChange = !empty($_POST['force_change']);
$role = ($_POST['role'] ?? 'employee') === 'it' ? 'it' : 'employee';
$badgeColor = $_POST['badge_color'] ?? '';
$isActive = isset($_POST['is_active']) ? (!empty($_POST['is_active']) ? 1 : 0) : 1;

if ($fullName === '') {
    e_json(array('error' => 'Укажите ФИО'), 422);
}
if (mb_strlen($fullName, 'UTF-8') > 255) {
    e_json(array('error' => 'ФИО слишком длинное'), 422);
}
if ($phone !== '' && mb_strlen($phone, 'UTF-8') > 30) {
    e_json(array('error' => 'Телефон слишком длинный'), 422);
}
if (!in_array($badgeColor, array('', 'purple', 'blue'), true)) {
    $badgeColor = '';
}
if ($role !== 'it') {
    $badgeColor = '';
}

$isSelf = $id > 0 && $id === (int)$user['id'];
if ($isSelf && ($role !== 'it' || $isActive === 0)) {
    e_json(array('error' => 'Нельзя понизить роль или деактивировать самого себя'), 422);
}

$pdo = db();

try {
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute(array($id));
        $existing = $stmt->fetch();
        if (!$existing) {
            e_json(array('error' => 'Сотрудник не найден'), 404);
        }

        if ($password !== '') {
            $pdo->prepare(
                'UPDATE users SET full_name = ?, phone = ?, password_hash = ?, must_change_password = ?, role = ?, badge_color = ?, is_active = ? WHERE id = ?'
            )->execute(array(
                $fullName, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT), $forceChange ? 1 : 0,
                $role, $badgeColor !== '' ? $badgeColor : null, $isActive, $id,
            ));
        } else {
            $pdo->prepare(
                'UPDATE users SET full_name = ?, phone = ?, role = ?, badge_color = ?, is_active = ? WHERE id = ?'
            )->execute(array(
                $fullName, $phone !== '' ? $phone : null, $role, $badgeColor !== '' ? $badgeColor : null, $isActive, $id,
            ));
        }

        if ($isActive === 0) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute(array($id));
            $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute(array($id));
        }

        e_json(array('ok' => true, 'id' => $id));
    } else {
        if ($password === '') {
            $password = '123456789';
            $forceChange = true;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, phone, password_hash, must_change_password, role, badge_color, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute(array(
            $fullName, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT), $forceChange ? 1 : 0,
            $role, $badgeColor !== '' ? $badgeColor : null,
        ));
        e_json(array('ok' => true, 'id' => (int)$pdo->lastInsertId()));
    }
} catch (PDOException $ex) {
    if ((int)$ex->getCode() === 23000 || strpos($ex->getMessage(), '1062') !== false) {
        e_json(array('error' => 'Сотрудник с таким ФИО уже есть в системе'), 409);
    }
    error_log('users_save failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось сохранить сотрудника'), 500);
}
