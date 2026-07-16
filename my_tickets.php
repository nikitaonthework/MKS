<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$active = 'my';

$stmt = db()->prepare(
    'SELECT t.*, u.full_name AS assignee_name, u.badge_color AS assignee_color
     FROM tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE t.user_id = ?
     ORDER BY t.updated_at DESC'
);
$stmt->execute(array($user['id']));
$tickets = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мои заявки — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="main">
    <h1 class="page-title">Мои заявки</h1>
    <p class="page-subtitle">Список ваших обращений в IT-отдел и статус их обработки.</p>

    <?php if (!$tickets): ?>
      <div class="empty-state card">
        <div class="icon">📭</div>
        У вас пока нет заявок. <a href="dashboard.php" style="color:var(--primary); font-weight:700;">Создать первую заявку</a>
      </div>
    <?php else: ?>
      <div class="ticket-list">
        <?php foreach ($tickets as $t): ?>
          <a class="ticket-row mine" href="ticket.php?id=<?php echo (int)$t['id']; ?>">
            <div class="ticket-id">№<?php echo (int)$t['id']; ?></div>
            <div class="ticket-main">
              <div class="ticket-subject"><?php echo h($t['subject']); ?></div>
              <div class="ticket-meta">
                <span><?php echo h(format_dt($t['updated_at'])); ?></span>
                <?php if ($t['assignee_name']): ?>
                  <span>· Отвечает: <?php echo h($t['assignee_name']); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($t['status'] === 'closed'): ?>
              <span class="badge badge-closed">✔ Закрыта</span>
            <?php else: ?>
              <span class="badge badge-open">● Открыта</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
