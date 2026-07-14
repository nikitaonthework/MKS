<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$active = 'all';

$stmt = db()->prepare(
    'SELECT t.*, au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color
     FROM tickets t
     JOIN users au ON au.id = t.user_id
     LEFT JOIN users it ON it.id = t.assigned_to
     ORDER BY t.updated_at DESC'
);
$stmt->execute();
$tickets = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Все заявки — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="main">
    <h1 class="page-title">Все заявки</h1>
    <p class="page-subtitle">Обращения всех сотрудников клиники — удобно проверить, не решалась ли уже похожая проблема. Ваши заявки выделены цветом.</p>

    <?php if (!$tickets): ?>
      <div class="empty-state card"><div class="icon">📋</div>Заявок пока нет.</div>
    <?php else: ?>
      <div class="ticket-list">
        <?php foreach ($tickets as $t): $mine = (int)$t['user_id'] === (int)$user['id']; ?>
          <a class="ticket-row <?php echo $mine ? 'mine' : ''; ?>" href="ticket.php?id=<?php echo (int)$t['id']; ?>">
            <div class="ticket-id">№<?php echo (int)$t['id']; ?></div>
            <div class="ticket-main">
              <div class="ticket-subject"><?php echo h($t['subject']); ?></div>
              <div class="ticket-meta">
                <span><?php echo h($t['author_name']); ?></span>
                <span>· <?php echo h(format_dt($t['updated_at'])); ?></span>
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
</body>
</html>
