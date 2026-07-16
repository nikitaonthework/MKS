<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$active = 'search';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Поиск по закрытым заявкам — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="main">
    <h1 class="page-title">Поиск по закрытым заявкам</h1>
    <p class="page-subtitle">Проверьте, не решалась ли уже похожая проблема — поиск находит нужное, даже если в слове есть опечатка.</p>

    <div class="card" style="margin-bottom:18px;">
      <div class="field" style="margin-bottom:0;">
        <input type="text" id="search-input" placeholder="Например: принтер не печатает…" autocomplete="off" autofocus>
      </div>
    </div>

    <div id="search-loader" class="page-subtitle" style="display:none;">Ищем…</div>
    <div class="ticket-list" id="search-results"></div>
    <div class="empty-state card" id="search-empty">
      <div class="icon">🔍</div>
      <span id="search-empty-text">Начните вводить запрос — например, название устройства или суть проблемы.</span>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>initSearchPage();</script>
</body>
</html>
