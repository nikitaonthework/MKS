<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/webpush.php';

/**
 * Уведомления IT-отдела о заявках — push в браузер (см. includes/webpush.php),
 * отправляются синхронно в момент события, без очереди и cron.
 */

function notify_new_ticket($ticket, $authorName, $bodyText) {
    $preview = make_subject($bodyText !== '' ? $bodyText : 'Вложение без текста', 200);
    $staffIds = array_column(it_staff_list(), 'id');
    webpush_notify_users($staffIds, '🆕 Новая заявка №' . (int)$ticket['id'], $authorName . ': ' . $preview, webpush_ticket_url($ticket['id']));
}

function notify_new_reply($ticket, $authorName) {
    if (empty($ticket['assigned_to'])) {
        return;
    }
    webpush_notify_user($ticket['assigned_to'], '💬 Новый ответ по заявке №' . (int)$ticket['id'], 'От: ' . $authorName, webpush_ticket_url($ticket['id']));
}

/**
 * Уведомление автора заявки (сотрудника клиники) о том, что IT-отдел
 * ответил на его обращение.
 */
function notify_it_reply($ticket, $itAuthorName) {
    webpush_notify_user($ticket['user_id'], '💬 Ответ по заявке №' . (int)$ticket['id'], $itAuthorName . ' ответил(а) на вашу заявку', webpush_employee_ticket_url($ticket['id']));
}

function notify_reopened($ticket, $authorName) {
    if (!empty($ticket['assigned_to'])) {
        $targetIds = array($ticket['assigned_to']);
    } else {
        $targetIds = array_column(it_staff_list(), 'id');
    }
    webpush_notify_users($targetIds, '♻️ Заявка №' . (int)$ticket['id'] . ' открыта повторно', 'От: ' . $authorName, webpush_ticket_url($ticket['id']));
}

/**
 * Уведомление остальных сотрудников IT-отдела о том, что заявку забрал
 * коллега. $excludeUserId — тот, кто уже видит результат сразу в своём
 * интерфейсе, ему уведомление не нужно.
 */
function notify_claimed($ticketId, $claimerName, $excludeUserId) {
    $staffIds = array();
    foreach (it_staff_list() as $s) {
        if ((int)$s['id'] !== (int)$excludeUserId) {
            $staffIds[] = $s['id'];
        }
    }
    webpush_notify_users($staffIds, '✅ Заявка №' . (int)$ticketId . ' закреплена', $claimerName, webpush_ticket_url($ticketId));
}
