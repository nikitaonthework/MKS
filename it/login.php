<?php
require_once __DIR__ . '/../includes/auth.php';

$existing = current_user();
if ($existing && $existing['role'] === 'it') {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Сессия истекла, обновите страницу и попробуйте снова.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $user = $fullName !== '' && $password !== '' ? attempt_login($fullName, $password) : false;
        if ($user && $user['role'] === 'it') {
            header('Location: index.php');
            exit;
        }
        if ($user) {
            // Пароль верный, но это не сотрудник IT-отдела — это приложение не для него.
            logout_user();
            $error = 'Это приложение доступно только сотрудникам IT-отдела.';
        } else {
            $error = 'Неверное ФИО или пароль.';
        }
    }
}

$names = db()->query("SELECT full_name FROM users WHERE role = 'it' ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — <?php echo h(CLINIC_NAME); ?> IT</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#10142a">
<link rel="apple-touch-icon" href="icon-192.png">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-mark">IT</div>
      <h1>Панель IT-отдела</h1>
      <p><?php echo h(CLINIC_NAME); ?> — обработка заявок</p>
    </div>

    <?php if ($error): ?><div class="error-box"><?php echo h($error); ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <div class="field">
        <label for="full_name">ФИО</label>
        <input type="text" id="full_name" name="full_name" placeholder="Начните вводить фамилию…" required
               value="<?php echo h($_POST['full_name'] ?? ''); ?>">
        <div class="autocomplete-list" id="ac-list"></div>
      </div>
      <div class="field">
        <label for="password">Пароль</label>
        <input type="password" id="password" name="password" placeholder="Пароль" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Войти</button>
    </form>
  </div>
</div>

<script>
var EMPLOYEES = <?php echo json_encode($names, JSON_UNESCAPED_UNICODE); ?>;
var input = document.getElementById('full_name');
var list = document.getElementById('ac-list');

function renderList(items) {
    list.innerHTML = '';
    if (!items.length) { list.classList.remove('show'); return; }
    items.slice(0, 8).forEach(function (name) {
        var div = document.createElement('div');
        div.className = 'autocomplete-item';
        div.textContent = name;
        div.addEventListener('mousedown', function (e) {
            e.preventDefault();
            input.value = name;
            list.classList.remove('show');
        });
        list.appendChild(div);
    });
    list.classList.add('show');
}

input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    if (!q) { list.classList.remove('show'); return; }
    renderList(EMPLOYEES.filter(function (n) { return n.toLowerCase().indexOf(q) !== -1; }));
});

document.addEventListener('click', function (e) {
    if (!list.contains(e.target) && e.target !== input) {
        list.classList.remove('show');
    }
});

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
}
</script>
</body>
</html>
