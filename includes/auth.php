<?php
require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const REMEMBER_COOKIE_NAME = 'remember_me';
const REMEMBER_COOKIE_DAYS = 90;

function remember_cookie_is_secure() {
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/**
 * Выдаёт cookie «запомнить меня» (селектор:валидатор — стандартная схема,
 * устойчивая к утечке БД: хранится только хэш валидатора, а не он сам) и
 * записывает соответствующую строку в remember_tokens. Вызывается при
 * каждом успешном входе, чтобы сотруднику не приходилось логиниться заново
 * при каждом визите (особенно важно для установленного на главный экран
 * веб-приложения — там форма входа не должна всплывать при каждом запуске).
 */
function issue_remember_cookie($userId) {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $validatorHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_COOKIE_DAYS * 86400);

    db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute(array($userId, $selector, $validatorHash, $expiresAt));

    setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, array(
        'expires' => time() + REMEMBER_COOKIE_DAYS * 86400,
        'path' => '/',
        'secure' => remember_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

/**
 * Удаляет одну (текущую) или все токены «запомнить меня» пользователя и
 * стирает cookie — вызывается при выходе.
 */
function clear_remember_cookie($allForUserId = null) {
    if ($allForUserId !== null) {
        db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute(array($allForUserId));
    } elseif (isset($_COOKIE[REMEMBER_COOKIE_NAME]) && strpos($_COOKIE[REMEMBER_COOKIE_NAME], ':') !== false) {
        list($selector) = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute(array($selector));
    }
    setcookie(REMEMBER_COOKIE_NAME, '', array(
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => remember_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}

/**
 * Пробует восстановить сессию по cookie «запомнить меня», если активной
 * сессии ещё нет. При успехе выдаёт новый токен взамен использованного
 * (ротация — если старое значение cookie всплывёт повторно, это будет
 * расценено как валидатор не подошедший к селектору, и токен отзовётся).
 */
function try_remember_login() {
    if (empty($_COOKIE[REMEMBER_COOKIE_NAME]) || strpos($_COOKIE[REMEMBER_COOKIE_NAME], ':') === false) {
        return null;
    }
    list($selector, $validator) = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);

    $stmt = db()->prepare('SELECT * FROM remember_tokens WHERE selector = ?');
    $stmt->execute(array($selector));
    $token = $stmt->fetch();

    if (!$token || strtotime($token['expires_at']) < time() || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        clear_remember_cookie();
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute(array($token['user_id']));
    $user = $stmt->fetch();
    if (!$user) {
        clear_remember_cookie();
        return null;
    }

    db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute(array($token['id']));
    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);
    issue_remember_cookie($user['id']);

    return $user;
}

function current_user() {
    static $user = null;
    if ($user !== null) {
        return $user ? $user : null;
    }
    if (empty($_SESSION['user_id'])) {
        $restored = try_remember_login();
        if (!$restored) {
            $user = false;
            return null;
        }
        $user = $restored;
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute(array($_SESSION['user_id']));
    $user = $stmt->fetch();
    if (!$user) {
        $user = false;
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

/**
 * Аналог require_login() для JSON-обработчиков (api/*.php): вместо редиректа
 * возвращает ошибку, если пользователь не авторизован или ещё обязан сменить
 * пароль по умолчанию (иначе это требование можно было бы обойти, обращаясь
 * к API напрямую, минуя страницы).
 */
function require_active_user() {
    $u = current_user();
    if (!$u) {
        e_json(array('error' => 'Требуется авторизация'), 401);
    }
    if ((int)$u['must_change_password'] === 1) {
        e_json(array('error' => 'Сначала необходимо сменить пароль. Обновите страницу.'), 403);
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
    issue_remember_cookie($user['id']);
    return $user;
}

function logout_user() {
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    clear_remember_cookie($userId);
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
