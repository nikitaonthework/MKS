<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$active = 'new';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Новая заявка — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="main">
    <h1 class="page-title">Создать заявку в IT-отдел</h1>
    <p class="page-subtitle">Опишите проблему как можно подробнее — это ускорит её решение. Можно приложить фото или скриншот.</p>

    <div class="card">
      <div id="form-error" class="error-box" style="display:none;"></div>
      <form id="new-ticket-form">
        <div class="field">
          <label for="body">Текст заявки</label>
          <textarea id="body" name="body" placeholder="Опишите проблему…" required></textarea>
        </div>
        <div class="composer-preview" id="preview"></div>
        <div style="display:flex; align-items:center; gap:12px; margin-top:14px;">
          <button type="button" class="attach-btn" id="attach-btn" title="Прикрепить файл">+</button>
          <input type="file" id="file-input" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar,.csv">
          <button type="submit" class="btn btn-primary" id="submit-btn" style="margin-left:auto;">Отправить заявку</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
var CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
initNewTicketForm();
</script>
</body>
</html>
