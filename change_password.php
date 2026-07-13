<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}

$error = '';
$mandatory = (int)$user['must_change_password'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Сессия истекла, обновите страницу.';
    } else {
        $keepDefault = isset($_POST['keep_default']);
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($keepDefault) {
            $stmt = db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?');
            $stmt->execute(array($user['id']));
            header('Location: dashboard.php');
            exit;
        }

        if (strlen($new) < 6) {
            $error = 'Новый пароль должен содержать не менее 6 символов.';
        } elseif ($new !== $confirm) {
            $error = 'Пароли не совпадают.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
            $stmt->execute(array($hash, $user['id']));
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Смена пароля — <?php echo h(CLINIC_NAME); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-mark">IT</div>
      <h1>Смена пароля</h1>
      <p><?php echo $mandatory ? 'Это ваш первый вход. Установите новый пароль или оставьте пароль по умолчанию.' : 'Вы можете изменить свой пароль.'; ?></p>
    </div>

    <?php if ($error): ?><div class="error-box"><?php echo h($error); ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <div class="field">
        <label for="new_password">Новый пароль</label>
        <input type="password" id="new_password" name="new_password" placeholder="Минимум 6 символов">
      </div>
      <div class="field">
        <label for="confirm_password">Повторите пароль</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Повторите новый пароль">
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-bottom:10px;">Сохранить новый пароль</button>
      <?php if ($mandatory): ?>
        <button type="submit" name="keep_default" value="1" class="btn btn-outline btn-block">Оставить пароль по умолчанию</button>
      <?php else: ?>
        <a href="dashboard.php" class="btn btn-outline btn-block" style="display:block; text-align:center; text-decoration:none;">Отмена</a>
      <?php endif; ?>
    </form>
  </div>
</div>
</body>
</html>
