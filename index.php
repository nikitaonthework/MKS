<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
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
        if ($user) {
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Неверное ФИО или пароль.';
    }
}

$names = db()->query('SELECT full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-mark">IT</div>
      <h1><?php echo h(CLINIC_NAME); ?></h1>
      <p>Служба поддержки IT-отдела клиники</p>
    </div>

    <?php if ($error): ?><div class="error-box"><?php echo h($error); ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <div class="field">
        <label for="full_name">ФИО сотрудника</label>
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
var activeIndex = -1;

function renderList(items) {
    list.innerHTML = '';
    if (!items.length) { list.classList.remove('show'); return; }
    items.slice(0, 8).forEach(function (name, i) {
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
    activeIndex = -1;
    if (!q) { list.classList.remove('show'); return; }
    var matches = EMPLOYEES.filter(function (n) { return n.toLowerCase().indexOf(q) !== -1; });
    renderList(matches);
});

document.addEventListener('click', function (e) {
    if (!list.contains(e.target) && e.target !== input) {
        list.classList.remove('show');
    }
});
</script>
</body>
</html>
