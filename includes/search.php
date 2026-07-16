<?php
require_once __DIR__ . '/db.php';

/**
 * Нечёткий (устойчивый к опечаткам) поиск по закрытым заявкам — «как у
 * Google», без внешних сервисов (Elasticsearch/Sphinx и т.п.), на чистом
 * PHP + MySQL. Кандидаты (закрытые заявки + текст переписки) забираются
 * одним запросом, а ранжирование по релевантности считается в PHP через
 * многобайтовое расстояние Левенштейна на уровне слов — это допустимо по
 * скорости для объёма заявок одной клиники (ограничение LIMIT ниже —
 * подстраховка на случай, если заявок накопится очень много).
 */

const SEARCH_CANDIDATE_LIMIT = 3000;
const SEARCH_RESULT_LIMIT = 30;
const SEARCH_FUZZY_THRESHOLD = 0.6;

/**
 * Расстояние Левенштейна, корректное для многобайтовых строк (UTF-8,
 * кириллица) — встроенная levenshtein() в PHP работает побайтово и для
 * не-ASCII текста считает неверно.
 */
function mb_levenshtein($a, $b) {
    $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
    $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    $la = count($a);
    $lb = count($b);
    if ($la === 0) {
        return $lb;
    }
    if ($lb === 0) {
        return $la;
    }
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = array($i);
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $cur;
    }
    return $prev[$lb];
}

/**
 * Разбивает текст на слова (буквы рус./лат. алфавита + цифры), в нижнем регистре.
 */
function fuzzy_tokenize($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    preg_match_all('/[a-zа-яё0-9]+/iu', $text, $m);
    return $m[0];
}

/**
 * Схожесть двух слов от 0 до 1: точное совпадение — 1, одно является
 * подстрокой другого (например «принтер»/«принтера») — высокий балл,
 * иначе — по расстоянию Левенштейна относительно длины слова (терпимо
 * примерно к одной опечатке на каждые 2-3 буквы).
 */
function fuzzy_word_similarity($a, $b) {
    if ($a === $b) {
        return 1.0;
    }
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');
    $maxLen = max($la, $lb);
    if ($maxLen === 0) {
        return 0.0;
    }
    if ($la >= 3 && $lb >= 3 && (mb_strpos($a, $b, 0, 'UTF-8') !== false || mb_strpos($b, $a, 0, 'UTF-8') !== false)) {
        return 0.92;
    }
    $dist = mb_levenshtein($a, $b);
    return 1 - ($dist / $maxLen);
}

/**
 * Суммарная релевантность кандидата запросу: бонус за точное вхождение
 * всей фразы + по лучшему нечёткому совпадению на каждое слово запроса.
 */
function fuzzy_search_score($queryWords, $queryLower, $candidateWords, $candidateTextLower) {
    $score = 0.0;
    if ($queryLower !== '' && mb_strpos($candidateTextLower, $queryLower, 0, 'UTF-8') !== false) {
        $score += 5.0;
    }
    foreach ($queryWords as $qw) {
        $best = 0.0;
        foreach ($candidateWords as $cw) {
            $sim = fuzzy_word_similarity($qw, $cw);
            if ($sim > $best) {
                $best = $sim;
                if ($best >= 0.999) {
                    break;
                }
            }
        }
        if ($best >= SEARCH_FUZZY_THRESHOLD) {
            $score += $best;
        }
    }
    return $score;
}

/**
 * Ищет закрытые заявки по тексту (тема + вся переписка), устойчиво к
 * опечаткам. Возвращает список заявок (с полями как в all_tickets.php),
 * отсортированный по релевантности. Видимость та же, что и у «Все
 * заявки» — все закрытые заявки клиники, а не только свои (цель поиска —
 * не создавать повторное обращение, если проблема уже решалась у кого-то).
 */
function search_closed_tickets($query) {
    $query = trim((string)$query);
    if ($query === '') {
        return array();
    }

    $pdo = db();
    $pdo->exec('SET SESSION group_concat_max_len = 20000');
    $stmt = $pdo->prepare(
        "SELECT t.id, t.subject, t.updated_at, t.closed_at, t.user_id,
                au.full_name AS author_name, it.full_name AS assignee_name, it.badge_color AS assignee_color,
                GROUP_CONCAT(m.body SEPARATOR ' ') AS all_text
         FROM tickets t
         JOIN users au ON au.id = t.user_id
         LEFT JOIN users it ON it.id = t.assigned_to
         LEFT JOIN messages m ON m.ticket_id = t.id
         WHERE t.status = 'closed'
         GROUP BY t.id
         ORDER BY t.closed_at DESC
         LIMIT " . SEARCH_CANDIDATE_LIMIT
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return array();
    }

    $queryWords = fuzzy_tokenize($query);
    $queryLower = mb_strtolower($query, 'UTF-8');

    $scored = array();
    foreach ($rows as $r) {
        $fullText = $r['subject'] . ' ' . (string)$r['all_text'];
        $words = fuzzy_tokenize($fullText);
        $score = fuzzy_search_score($queryWords, $queryLower, $words, mb_strtolower($fullText, 'UTF-8'));
        if ($score > 0) {
            unset($r['all_text']);
            $r['_score'] = $score;
            $scored[] = $r;
        }
    }

    usort($scored, function ($a, $b) {
        return $b['_score'] <=> $a['_score'];
    });

    return array_slice($scored, 0, SEARCH_RESULT_LIMIT);
}
