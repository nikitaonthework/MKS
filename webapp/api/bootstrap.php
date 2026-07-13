<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/telegram.php';

/**
 * Аутентификация сотрудника it-отдела по initData из Telegram Mini App.
 * Возвращает строку users или завершает запрос с 401.
 */
function webapp_auth() {
    $initData = isset($_SERVER['HTTP_X_INIT_DATA']) ? $_SERVER['HTTP_X_INIT_DATA'] : '';
    $tgUser = tg_validate_init_data($initData);
    if (!$tgUser) {
        e_json(array('error' => 'Не удалось подтвердить пользователя Telegram'), 401);
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE telegram_id = ? AND role = 'it'");
    $stmt->execute(array($tgUser['id']));
    $user = $stmt->fetch();
    if (!$user) {
        e_json(array('error' => 'Доступ только для сотрудников IT-отдела'), 403);
    }
    return $user;
}

function ticket_to_array($t) {
    return array(
        'id' => (int)$t['id'],
        'subject' => $t['subject'],
        'status' => $t['status'],
        'author_name' => isset($t['author_name']) ? $t['author_name'] : null,
        'assignee_name' => isset($t['assignee_name']) ? $t['assignee_name'] : null,
        'assignee_color' => isset($t['assignee_color']) ? $t['assignee_color'] : null,
        'updated_at' => $t['updated_at'],
        'created_at' => $t['created_at'],
    );
}
