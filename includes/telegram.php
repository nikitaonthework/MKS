<?php
require_once __DIR__ . '/functions.php';

define('TG_API_BASE', 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/');

/**
 * Низкоуровневый вызов Telegram Bot API.
 */
function tg_api($method, $params = array()) {
    $ch = curl_init(TG_API_BASE . $method);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ));
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($response === false) {
        error_log('Telegram API error (' . $method . '): ' . $err);
        // Возвращаем причину сбоя вместо null, чтобы её можно было увидеть
        // прямо в ответе bot/setwebhook.php, без доступа к логам хостинга.
        return array('ok' => false, 'error_source' => 'curl', 'curl_errno' => $errno, 'curl_error' => $err);
    }
    $data = json_decode($response, true);
    if ($data === null) {
        error_log('Telegram API returned invalid response (' . $method . '): ' . $response);
        return array('ok' => false, 'error_source' => 'invalid_response', 'raw_response' => $response);
    }
    if (empty($data['ok'])) {
        error_log('Telegram API failed (' . $method . '): ' . $response);
    }
    return $data;
}

function tg_send_message($chatId, $text, $replyMarkup = null) {
    $params = array(
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    );
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return tg_api('sendMessage', $params);
}

function tg_edit_message_text($chatId, $messageId, $text, $replyMarkup = null) {
    $params = array(
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    );
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return tg_api('editMessageText', $params);
}

function tg_answer_callback($callbackId, $text = '', $showAlert = false) {
    return tg_api('answerCallbackQuery', array(
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $showAlert ? 'true' : 'false',
    ));
}

/**
 * Отправляет уведомление о новой заявке всем сотрудникам it-отдела,
 * с кнопкой "Забрать заявку себе". $bodyText — исходный (неусечённый) текст
 * заявки, а не ticket['subject'] (который уже обрезан до ~70 символов для
 * списков в веб-кабинете) — иначе превью в уведомлении получится короче
 * задуманного.
 */
function tg_notify_new_ticket($ticket, $authorName, $bodyText) {
    $staff = it_staff_list();
    $preview = make_subject($bodyText !== '' ? $bodyText : 'Вложение без текста', 200);
    $text = "🆕 <b>Новая заявка!</b>\n\n"
        . "От: " . h($authorName) . "\n"
        . "№" . (int)$ticket['id'] . "\n\n"
        . h($preview);

    $keyboard = array(
        'inline_keyboard' => array(
            array(
                array('text' => '📥 Забрать заявку себе', 'callback_data' => 'claim_' . $ticket['id']),
            ),
            array(
                array('text' => '📋 Открыть в приложении', 'web_app' => array('url' => rtrim(APP_URL, '/') . '/webapp/?ticket=' . $ticket['id'])),
            ),
        ),
    );

    foreach ($staff as $s) {
        if (empty($s['telegram_id'])) {
            continue;
        }
        tg_send_message($s['telegram_id'], $text, $keyboard);
    }
}

/**
 * Уведомление ответственному it-сотруднику о новом сообщении сотрудника клиники.
 */
function tg_notify_new_reply($ticket, $authorName) {
    if (empty($ticket['assigned_to'])) {
        return;
    }
    $stmt = db()->prepare("SELECT telegram_id FROM users WHERE id = ? AND role = 'it'");
    $stmt->execute(array($ticket['assigned_to']));
    $telegramId = $stmt->fetchColumn();
    if (!$telegramId) {
        return;
    }
    $text = "💬 <b>Новый ответ по заявке №" . (int)$ticket['id'] . "</b>\nОт: " . h($authorName);
    tg_send_message($telegramId, $text);
}

function tg_notify_reopened($ticket, $authorName) {
    $staff = it_staff_list();
    $text = "♻️ <b>Заявка №" . (int)$ticket['id'] . " открыта повторно</b>\n\n"
        . "От: " . h($authorName);
    foreach ($staff as $s) {
        if (empty($s['telegram_id'])) {
            continue;
        }
        if (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] !== (int)$s['id']) {
            continue;
        }
        tg_send_message($s['telegram_id'], $text);
    }
}

/**
 * Проверка подлинности initData из Telegram Mini App.
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
function tg_validate_init_data($initData) {
    if (!$initData) {
        return null;
    }
    parse_str($initData, $data);
    if (!isset($data['hash'])) {
        return null;
    }
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);

    $pairs = array();
    foreach ($data as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $dataCheckString = implode("\n", $pairs);

    $secretKey = hash_hmac('sha256', TG_BOT_TOKEN, 'WebAppData', true);
    $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    if (!hash_equals($computedHash, $hash)) {
        return null;
    }

    if (isset($data['auth_date']) && (time() - (int)$data['auth_date']) > 86400) {
        return null;
    }

    $user = null;
    if (isset($data['user'])) {
        $user = json_decode($data['user'], true);
    }
    if (!$user || empty($user['id'])) {
        return null;
    }
    return $user;
}
