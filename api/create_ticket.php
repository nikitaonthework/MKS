<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/telegram.php';

$user = require_active_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    e_json(array('error' => 'Метод не поддерживается'), 405);
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    e_json(array('error' => 'Сессия истекла, обновите страницу и попробуйте снова.'), 403);
}

$body = trim($_POST['body'] ?? '');
if ($body === '' && empty($_FILES['files'])) {
    e_json(array('error' => 'Введите текст заявки'), 422);
}
if (mb_strlen($body, 'UTF-8') > 4000) {
    e_json(array('error' => 'Текст заявки слишком длинный'), 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $subject = make_subject($body !== '' ? $body : 'Вложение без текста');
    $stmt = $pdo->prepare('INSERT INTO tickets (user_id, status, subject) VALUES (?, ?, ?)');
    $stmt->execute(array($user['id'], 'open', $subject));
    $ticketId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO messages (ticket_id, sender_id, body) VALUES (?, ?, ?)');
    $stmt->execute(array($ticketId, $user['id'], $body !== '' ? $body : null));
    $messageId = (int)$pdo->lastInsertId();

    $uploadResult = process_uploaded_files('files', $ticketId, $messageId, $pdo);

    $pdo->commit();
} catch (Exception $ex) {
    $pdo->rollBack();
    error_log('create_ticket failed: ' . $ex->getMessage());
    e_json(array('error' => 'Не удалось создать заявку'), 500);
}

// Отдаём ответ браузеру сразу — заявка уже сохранена (commit выше), а
// отправка уведомлений в Telegram (через прокси) может быть медленной и
// не должна заставлять сотрудника ждать или упираться в лимит времени
// выполнения скрипта на хостинге.
respond_json_then_continue(array('ok' => true, 'ticket_id' => $ticketId, 'attachment_errors' => $uploadResult['errors']));

$fresh = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
$fresh->execute(array($ticketId));
$ticketRow = $fresh->fetch();
tg_notify_new_ticket($ticketRow, $user['full_name'], $body);
