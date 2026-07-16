<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/search.php';

$user = require_active_user();

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($query, 'UTF-8') > 200) {
    $query = mb_substr($query, 0, 200, 'UTF-8');
}

$results = $query !== '' ? search_closed_tickets($query) : array();

$out = array_map(function ($t) use ($user) {
    return array(
        'id' => (int)$t['id'],
        'subject' => $t['subject'],
        'author_name' => $t['author_name'],
        'mine' => (int)$t['user_id'] === (int)$user['id'],
        'assignee_name' => $t['assignee_name'],
        'assignee_color' => $t['assignee_color'],
        'closed_at' => $t['closed_at'],
        'updated_at' => $t['updated_at'],
    );
}, $results);

e_json(array('ok' => true, 'tickets' => $out));
