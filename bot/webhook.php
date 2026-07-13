<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';

// Проверка секретного токена вебхука (устанавливается в setwebhook.php)
$secretHeader = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
if (!hash_equals(TG_WEBHOOK_SECRET, $secretHeader)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) {
    http_response_code(200);
    exit;
}

if (isset($update['message'])) {
    handle_message($update['message']);
} elseif (isset($update['callback_query'])) {
    handle_callback($update['callback_query']);
}

http_response_code(200);
exit;

function it_user_by_telegram_id($telegramId) {
    $stmt = db()->prepare("SELECT * FROM users WHERE telegram_id = ? AND role = 'it'");
    $stmt->execute(array($telegramId));
    return $stmt->fetch();
}

function handle_message($message) {
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';

    if (str_starts_with($text, '/start')) {
        $itUser = it_user_by_telegram_id($chatId);
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

function handle_callback($cb) {
    $data = isset($cb['data']) ? $cb['data'] : '';
    $fromId = $cb['from']['id'];
    $callbackId = $cb['id'];

    if (!str_starts_with($data, 'claim_')) {
        tg_answer_callback($callbackId);
        return;
    }

    $ticketId = (int)substr($data, strlen('claim_'));
    $itUser = it_user_by_telegram_id($fromId);
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

    $pdo->prepare('UPDATE tickets SET assigned_to = ? WHERE id = ?')->execute(array($itUser['id'], $ticketId));

    tg_answer_callback($callbackId, 'Заявка №' . $ticketId . ' закреплена за вами');

    $originalText = isset($cb['message']['text']) ? $cb['message']['text'] : ('Заявка №' . $ticketId);
    $newText = $originalText . "\n\n✅ Закреплена за: " . $itUser['full_name'];
    if (isset($cb['message']['message_id'])) {
        tg_edit_message_text($cb['message']['chat']['id'], $cb['message']['message_id'], $newText);
    }

    // Подтверждение всем сотрудникам it-отдела (включая того, кто забрал заявку)
    $staff = it_staff_list();
    foreach ($staff as $s) {
        if (empty($s['telegram_id'])) {
            continue;
        }
        if ((int)$s['id'] === (int)$itUser['id']) {
            continue; // уже получил ответ через answerCallbackQuery + отредактированное сообщение
        }
        tg_send_message($s['telegram_id'], '✅ Заявка №' . $ticketId . ' закреплена за ' . h($itUser['full_name']));
    }
}
