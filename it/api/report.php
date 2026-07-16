<?php
require_once __DIR__ . '/bootstrap.php';
$user = it_api_auth();

$stmt = db()->query(
    "SELECT YEARWEEK(work_date, 3) AS yw, it_user_id, SUM(hours) AS total_hours
     FROM duty_hours GROUP BY yw, it_user_id ORDER BY yw DESC"
);
$rows = $stmt->fetchAll();

$staff = it_staff_list();
$staffById = array();
foreach ($staff as $s) {
    $staffById[$s['id']] = $s;
}

$weeks = array();
foreach ($rows as $r) {
    $yw = (string)$r['yw'];
    if (!isset($weeks[$yw])) {
        $year = (int)substr($yw, 0, 4);
        $week = (int)substr($yw, 4, 2);
        $dt = new DateTime();
        $dt->setISODate($year, $week);
        $end = clone $dt;
        $end->modify('+6 days');
        $weeks[$yw] = array(
            'yw' => $yw,
            'label' => $dt->format('d.m') . '–' . $end->format('d.m.Y'),
            'entries' => array(),
            'total' => 0,
        );
    }
    $itUserId = (int)$r['it_user_id'];
    $name = isset($staffById[$itUserId]) ? $staffById[$itUserId]['full_name'] : 'Сотрудник #' . $itUserId;
    $color = isset($staffById[$itUserId]) ? $staffById[$itUserId]['badge_color'] : null;
    $weeks[$yw]['entries'][] = array(
        'it_user_id' => $itUserId,
        'name' => $name,
        'color' => $color,
        'hours' => (float)$r['total_hours'],
    );
    $weeks[$yw]['total'] += (float)$r['total_hours'];
}

e_json(array('ok' => true, 'weeks' => array_values($weeks)));
