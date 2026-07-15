<?php
require_once __DIR__ . '/telegram.php';

/**
 * Общая логика обработки апдейтов Telegram-бота — используется и вебхуком
 * (bot/webhook.php), и polling-скриптом (bot/poll.php), чтобы не дублировать код.
 */

function bot_it_user_by_telegram_id($telegramId) {
    $stmt = db()->prepare("SELECT * FROM users WHERE telegram_id = ? AND role = 'it'");
    $stmt->execute(array($telegramId));
    return $stmt->fetch();
}

function bot_handle_message($message) {
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';

    if (str_starts_with($text, '/start')) {
        $itUser = bot_it_user_by_telegram_id($chatId);
        if (!$itUser) {
            tg_send_message($chatId, 'Здравствуйте! Этот бот предназначен для сотрудников IT-отдела клиники.');
            return;
        }
        $keyboard = array(
            'inline_keyboard' => array(
                array(
                    array('text' => '📋 Открыть панель заявок', 'web_app' => array('url' => rtrim(APP_URL, '/') . '/webapp/')),
                ),
            ),
        );
        tg_send_message($chatId, 'Здравствуйте, ' . h($itUser['full_name']) . "!\nЗдесь будут приходить уведомления о новых заявках IT Service Desk.", $keyboard);
    }
}

function bot_handle_callback($cb) {
    $data = isset($cb['data']) ? $cb['data'] : '';
    $fromId = $cb['from']['id'];
    $callbackId = $cb['id'];

    if (!str_starts_with($data, 'claim_')) {
        tg_answer_callback($callbackId);
        return;
    }

    $ticketId = (int)substr($data, strlen('claim_'));
    $itUser = bot_it_user_by_telegram_id($fromId);
    if (!$itUser) {
        tg_answer_callback($callbackId, 'Доступ только для сотрудников IT-отдела', true);
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
    $stmt->execute(array($ticketId));
    $ticket = $stmt->fetch();
    if (!$ticket) {
        tg_answer_callback($callbackId, 'Заявка не найдена', true);
        return;
    }

    if (!empty($ticket['assigned_to'])) {
        $assigneeStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
        $assigneeStmt->execute(array($ticket['assigned_to']));
        $assigneeName = $assigneeStmt->fetchColumn();
        tg_answer_callback($callbackId, 'Заявка уже закреплена за ' . $assigneeName, true);
        return;
    }

    // Атомарное условие в WHERE не даёт двум сотрудникам, нажавшим кнопку
    // почти одновременно, оба «выиграть» гонку за одну заявку.
    $upd = $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL');
    $upd->execute(array($itUser['id'], $ticketId));
    if ($upd->rowCount() === 0) {
        $assigneeStmt = $pdo->prepare('SELECT u.full_name FROM tickets t JOIN users u ON u.id = t.assigned_to WHERE t.id = ?');
        $assigneeStmt->execute(array($ticketId));
        $assigneeName = $assigneeStmt->fetchColumn();
        tg_answer_callback($callbackId, 'Заявка уже закреплена за ' . ($assigneeName ? $assigneeName : 'другим сотрудником'), true);
        return;
    }

    tg_answer_callback($callbackId, 'Заявка №' . $ticketId . ' закреплена за вами');

    $originalText = isset($cb['message']['text']) ? $cb['message']['text'] : ('Заявка №' . $ticketId);
    $newText = $originalText . "\n\n✅ Закреплена за: " . $itUser['full_name'];
    if (isset($cb['message']['message_id'])) {
        tg_edit_message_text($cb['message']['chat']['id'], $cb['message']['message_id'], $newText);
    }

    // Подтверждение всем сотрудникам it-отдела (включая того, кто забрал заявку)
    $staff = it_staff_list();
    foreach ($staff as $s) {
        if ((int)$s['id'] === (int)$itUser['id']) {
            continue; // уже получил ответ через answerCallbackQuery + отредактированное сообщение
        }
        webpush_queue_for_user($s['id'], '✅ Заявка №' . $ticketId . ' закреплена', $itUser['full_name'], webpush_ticket_url($ticketId));
        if (empty($s['telegram_id'])) {
            continue;
        }
        tg_queue_message($s['telegram_id'], '✅ Заявка №' . $ticketId . ' закреплена за ' . h($itUser['full_name']));
    }
}
