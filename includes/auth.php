<?php
require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute(array($_SESSION['user_id']));
        $user = $stmt->fetch();
        if (!$user) {
            $user = false;
        }
    }
    return $user ? $user : null;
}

function require_login() {
    $u = current_user();
    if (!$u) {
        header('Location: index.php');
        exit;
    }
    if ((int)$u['must_change_password'] === 1 && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        header('Location: change_password.php');
        exit;
    }
    return $u;
}

function attempt_login($fullName, $password) {
    $stmt = db()->prepare('SELECT * FROM users WHERE full_name = ?');
    $stmt->execute(array($fullName));
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);
    return $user;
}

function logout_user() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
