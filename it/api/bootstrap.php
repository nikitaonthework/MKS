<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notify.php';
require_once __DIR__ . '/../../includes/auth.php';

/**
 * Аутентификация сотрудника it-отдела для эндпоинтов отдельного
 * веб-приложения /it/ — обычная веб-сессия (ФИО+пароль), как и у
 * остального сайта.
 */
function it_api_auth() {
    $user = current_user();
    if (!$user) {
        e_json(array('error' => 'Требуется авторизация'), 401);
    }
    if ($user['role'] !== 'it') {
        e_json(array('error' => 'Доступ только для сотрудников IT-отдела'), 403);
    }
    if ((int)$user['must_change_password'] === 1) {
        e_json(array('error' => 'Сначала необходимо сменить пароль'), 403);
    }
    return $user;
}

function ticket_to_array($t) {
    return array(
        'id' => (int)$t['id'],
        'subject' => $t['subject'],
        'status' => $t['status'],
        'author_name' => isset($t['author_name']) ? $t['author_name'] : null,
        'author_phone' => isset($t['author_phone']) ? $t['author_phone'] : null,
        'author_job_title' => isset($t['author_job_title']) ? $t['author_job_title'] : null,
        'assignee_name' => isset($t['assignee_name']) ? $t['assignee_name'] : null,
        'assignee_color' => isset($t['assignee_color']) ? $t['assignee_color'] : null,
        'updated_at' => $t['updated_at'],
        'created_at' => $t['created_at'],
    );
}
