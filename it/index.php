<?php
require_once __DIR__ . '/../includes/auth.php';

$user = current_user();
if (!$user || $user['role'] !== 'it') {
    header('Location: login.php');
    exit;
}
if ((int)$user['must_change_password'] === 1) {
    header('Location: ../change_password.php');
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<title>IT Service Desk — панель</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#10142a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="IT Desk">
<link rel="apple-touch-icon" href="icon-192.png">
<link rel="stylesheet" href="assets/css/app-base.css">
<link rel="stylesheet" href="assets/css/app-extra.css">
</head>
<body>
<div id="app">

  <div class="topbar">
    <button class="icon-btn" id="back-btn" style="display:none;">←</button>
    <div class="topbar-title" id="topbar-title">Новые заявки</div>
    <button class="icon-btn" id="menu-btn">⋮</button>
  </div>

  <div class="push-banner" id="push-banner" style="display:none;">
    <span>Включить уведомления о новых заявках?</span>
    <div class="push-banner-actions">
      <button type="button" class="btn btn-sm btn-outline" id="push-dismiss">Не сейчас</button>
      <button type="button" class="btn btn-sm btn-gold" id="push-enable">Включить</button>
    </div>
  </div>

  <div class="menu-sheet" id="menu-sheet">
    <div class="sheet-backdrop" id="menu-backdrop"></div>
    <div class="sheet-body">
      <div class="sheet-item" data-action="report">📊 Отчёт по дежурствам</div>
      <div class="sheet-item" data-action="notifications">🔔 Уведомления</div>
      <div class="sheet-item" data-action="logout">🚪 Выйти (<?php echo h($user['full_name']); ?>)</div>
      <div class="sheet-item" data-action="close-menu">Закрыть</div>
    </div>
  </div>

  <div class="view" id="view-list">
    <div class="ticket-list" id="ticket-list"></div>
    <div class="empty-state" id="list-empty" style="display:none;">
      <div class="icon">📭</div>
      <div id="list-empty-text">Заявок нет</div>
    </div>
    <div class="loader" id="list-loader">Загрузка…</div>
  </div>

  <div class="view" id="view-ticket" style="display:none;">
    <div class="ticket-info-bar" id="ticket-info-bar"></div>
    <div class="chat-messages" id="chat-messages"></div>
    <div class="chat-actions" id="chat-actions"></div>
    <div class="chat-composer" id="chat-composer">
      <div class="composer-preview" id="preview"></div>
      <div class="upload-progress" id="upload-progress" style="display:none;"><div class="upload-progress-bar" id="upload-progress-bar"></div></div>
      <div class="composer-row">
        <button type="button" class="attach-btn" id="attach-btn">+</button>
        <input type="file" id="file-input" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar,.csv">
        <textarea id="body" placeholder="Сообщение…"></textarea>
        <button type="button" class="send-btn" id="send-btn">➤</button>
      </div>
    </div>
  </div>

  <div class="view" id="view-report" style="display:none;">
    <div class="report-wrap" id="report-wrap"></div>
  </div>

  <div class="tabbar" id="tabbar">
    <div class="tab active" data-tab="new"><span class="tab-icon">🆕</span><span>Новые</span></div>
    <div class="tab" data-tab="my"><span class="tab-icon">🗂</span><span>Мои</span></div>
    <div class="tab" data-tab="closed"><span class="tab-icon">✅</span><span>Закрытые</span></div>
  </div>

  <div class="close-sheet" id="close-sheet">
    <div class="sheet-backdrop" id="close-backdrop"></div>
    <div class="sheet-body">
      <div class="sheet-title">Закрыть заявку</div>
      <label class="checkbox-row">
        <input type="checkbox" id="weekend-duty-check">
        <span>Дежурство в выходные</span>
      </label>
      <div class="hours-stepper" id="hours-stepper" style="display:none;">
        <span>Затрачено часов</span>
        <div class="stepper-controls">
          <button type="button" class="stepper-btn" id="hours-minus">−</button>
          <span id="hours-value">1</span>
          <button type="button" class="stepper-btn" id="hours-plus">+</button>
        </div>
      </div>
      <button class="btn btn-primary btn-block" id="confirm-close-btn" style="margin-top:16px;">Подтвердить закрытие</button>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close">×</span>
    <img id="lightbox-img" src="" onclick="event.stopPropagation()">
  </div>

</div>
<script>
var VAPID_PUBLIC_KEY = <?php echo json_encode(defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : ''); ?>;
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
