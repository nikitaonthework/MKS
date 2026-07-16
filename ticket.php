<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();

$ticketId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name
     FROM tickets t
     JOIN users au ON au.id = t.user_id
     LEFT JOIN users it ON it.id = t.assigned_to
     WHERE t.id = ?'
);
$stmt->execute(array($ticketId));
$ticket = $stmt->fetch();
if (!$ticket) {
    http_response_code(404);
    die('Заявка не найдена');
}

$isOwner = (int)$ticket['user_id'] === (int)$user['id'];
$canReply = ($isOwner || $user['role'] === 'it') && $ticket['status'] === 'open';

$msgStmt = db()->prepare('SELECT * FROM messages WHERE ticket_id = ? ORDER BY id ASC');
$msgStmt->execute(array($ticketId));
$messages = $msgStmt->fetchAll();

$attByMsg = array();
if ($messages) {
    $ids = array_column($messages, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = db()->prepare("SELECT * FROM attachments WHERE message_id IN ($in) ORDER BY id ASC");
    $aStmt->execute($ids);
    foreach ($aStmt->fetchAll() as $a) {
        $attByMsg[$a['message_id']][] = $a;
    }
}

$senderIds = array_unique(array_column($messages, 'sender_id'));
$senders = array();
if ($senderIds) {
    $in = implode(',', array_fill(0, count($senderIds), '?'));
    $sStmt = db()->prepare("SELECT id, full_name, role FROM users WHERE id IN ($in)");
    $sStmt->execute($senderIds);
    foreach ($sStmt->fetchAll() as $s) {
        $senders[$s['id']] = $s;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Заявка №<?php echo (int)$ticket['id']; ?> — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php $active = 'my'; include __DIR__ . '/includes/nav.php'; ?>
  <div class="main">
    <a href="my_tickets.php" class="back-link">← Назад к списку</a>

    <div class="chat-header">
      <div class="chat-header-left">
        <div>
          <h1 class="page-title" style="margin-bottom:2px;">Заявка №<?php echo (int)$ticket['id']; ?></h1>
          <div class="page-subtitle" style="margin-bottom:0;">
            Автор: <?php echo h($ticket['author_name']); ?>
            <?php if ($ticket['assignee_name']): ?> · Отвечает: <?php echo h($ticket['assignee_name']); ?><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($ticket['status'] === 'closed'): ?>
        <span class="badge badge-closed" style="font-size:13px; padding:7px 14px;">✔ Закрыта</span>
      <?php else: ?>
        <span class="badge badge-open" style="font-size:13px; padding:7px 14px;">● Открыта</span>
      <?php endif; ?>
    </div>

    <?php if ($ticket['status'] === 'closed'): ?>
      <div class="closed-banner">
        <span>Заявка закрыта. Если проблема повторилась или решена не полностью — откройте заявку снова.</span>
        <?php if ($isOwner): ?>
          <button class="btn btn-outline btn-sm" id="reopen-btn">↺ Открыть заявку</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$isOwner && $user['role'] !== 'it'): ?>
      <div class="readonly-banner">Вы просматриваете заявку другого сотрудника в режиме чтения.</div>
    <?php endif; ?>

    <div class="chat-box">
      <div class="chat-messages" id="chat-messages">
        <?php foreach ($messages as $m):
            $sender = isset($senders[$m['sender_id']]) ? $senders[$m['sender_id']] : null;
            $out = (int)$m['sender_id'] === (int)$user['id'];
            $authorLabel = $sender ? $sender['full_name'] . ($sender['role'] === 'it' ? ' · IT-отдел' : '') : '';
        ?>
          <div class="msg-row <?php echo $out ? 'out' : 'in'; ?>" data-id="<?php echo (int)$m['id']; ?>">
            <div class="msg-author"><?php echo h($authorLabel); ?></div>
            <?php if (trim((string)$m['body']) !== ''): ?>
              <div class="msg-bubble"><?php echo nl2br(h($m['body'])); ?></div>
            <?php endif; ?>
            <?php if (!empty($attByMsg[$m['id']])): ?>
              <div class="msg-attachments">
                <?php foreach ($attByMsg[$m['id']] as $a): ?>
                  <?php if ($a['is_image']): ?>
                    <div class="att-image"><img src="<?php echo h(UPLOAD_URL . '/' . $a['stored_name']); ?>" onclick="openLightbox(this.src)" loading="lazy"></div>
                  <?php else: ?>
                    <a class="att-file" href="<?php echo h(UPLOAD_URL . '/' . $a['stored_name']); ?>" target="_blank">📎 <?php echo h($a['original_name']); ?> <span style="opacity:.6;">(<?php echo h(human_size($a['file_size'])); ?>)</span></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="msg-time"><?php echo h(format_dt($m['created_at'])); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($canReply): ?>
      <div class="chat-composer">
        <div id="form-error" class="error-box" style="display:none;"></div>
        <div class="composer-preview" id="preview"></div>
        <div class="upload-progress" id="upload-progress" style="display:none;"><div class="upload-progress-bar" id="upload-progress-bar"></div></div>
        <div class="composer-row">
          <button type="button" class="attach-btn" id="attach-btn" title="Прикрепить файл">+</button>
          <input type="file" id="file-input" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar,.csv">
          <textarea id="body" placeholder="Написать сообщение…"></textarea>
          <button type="button" class="send-btn" id="send-btn">➤</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <span class="lightbox-close">×</span>
  <img id="lightbox-img" src="" onclick="event.stopPropagation()">
</div>

<script src="assets/js/app.js"></script>
<script>
var CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
initTicketChat({
    ticketId: <?php echo (int)$ticket['id']; ?>,
    canReply: <?php echo $canReply ? 'true' : 'false'; ?>,
    isOwner: <?php echo $isOwner ? 'true' : 'false'; ?>,
    status: '<?php echo h($ticket['status']); ?>',
    lastMessageId: <?php echo $messages ? (int)end($messages)['id'] : 0; ?>,
    currentUserId: <?php echo (int)$user['id']; ?>
});
</script>
</body>
</html>
