<?php
/**
 * Ожидает переменные: $user (текущий пользователь), $active (имя активного пункта меню)
 */
function nav_initials($fullName) {
    $parts = preg_split('/\s+/u', trim($fullName));
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $s .= mb_substr($p, 0, 1, 'UTF-8');
        }
    }
    return mb_strtoupper($s, 'UTF-8');
}
?>
<div class="sidebar">
  <div class="brand">
    <div class="brand-mark">IT</div>
    <div>
      <div class="brand-name"><?php echo h(CLINIC_NAME); ?></div>
      <div class="brand-sub">Личный кабинет</div>
    </div>
  </div>
  <div class="nav">
    <a href="dashboard.php" class="<?php echo $active === 'new' ? 'active' : ''; ?>"><span class="nav-icon">✚</span> Создать заявку</a>
    <a href="my_tickets.php" class="<?php echo $active === 'my' ? 'active' : ''; ?>"><span class="nav-icon">🗂</span> Мои заявки</a>
    <a href="all_tickets.php" class="<?php echo $active === 'all' ? 'active' : ''; ?>"><span class="nav-icon">📋</span> Все заявки</a>
    <a href="search.php" class="<?php echo $active === 'search' ? 'active' : ''; ?>"><span class="nav-icon">🔍</span> Поиск</a>
  </div>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?php echo h(nav_initials($user['full_name'])); ?></div>
      <div>
        <div class="user-chip-name"><?php echo h($user['full_name']); ?></div>
        <div class="user-chip-role"><?php echo $user['role'] === 'it' ? 'IT-отдел' : 'Сотрудник'; ?></div>
      </div>
    </div>
    <a href="change_password.php" class="logout-link">Сменить пароль</a>
    <a href="logout.php" class="logout-link">Выйти</a>
  </div>
</div>
