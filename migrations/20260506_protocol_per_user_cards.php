<?php
$envFile = __DIR__ . '/../api/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}

$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'supply_bk';
$DB_USER = $_ENV['DB_USER'] ?? 'siteuser';
$DB_PASS = $_ENV['DB_PASS'] ?? '';

$pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function ensureBoard(PDO $pdo, $userName) {
    $s = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
    $s->execute([$userName]);
    $bid = (int)$s->fetchColumn();
    if ($bid) return $bid;
    $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$userName, $userName]);
    $bid = (int)$pdo->lastInsertId();
    $cols = [
        ['Сделать',  '#90A4AE',    0, 0, 0],
        ['В работе', '#FFA726',    1, 0, 0],
        ['Архив',    '#9E9E9E', 9999, 0, 1],
    ];
    $ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($cols as $c) $ins->execute([$bid, $c[0], $c[1], $c[2], $c[3], $c[4]]);
    return $bid;
}

// 1) Откатить текущие протокольные карточки
$oldIds = $pdo->query("SELECT tasks_card_id FROM protocol_decisions WHERE tasks_card_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$oldIds = array_filter(array_map('intval', $oldIds));
$delCards = 0;
if ($oldIds) {
    $ph = implode(',', array_fill(0, count($oldIds), '?'));
    $delCards = $pdo->prepare("DELETE FROM tasks_cards WHERE id IN ($ph)")->execute($oldIds) ? count($oldIds) : 0;
}
$pdo->exec("UPDATE protocol_decisions SET tasks_card_id = NULL");
$pdo->exec("DELETE FROM protocol_decision_cards");
echo "Откат: удалено старых карточек: $delCards\n";

// 2) Список валидных пользователей
$validUsers = array_flip($pdo->query("SELECT name FROM users")->fetchAll(PDO::FETCH_COLUMN));

// 3) Создать карточки заново
$rows = $pdo->query("
    SELECT pd.id, pd.text, pd.responsible_person, pd.deadline, pd.protocol_id, p.created_by, p.topic, p.meeting_date
    FROM protocol_decisions pd
    JOIN meeting_protocols p ON p.id = pd.protocol_id
    WHERE pd.status IN ('pending','overdue')
    ORDER BY pd.id
")->fetchAll();

$cardsCreated = 0;
$decisionsTouched = 0;
$boardsCreated = 0;

foreach ($rows as $r) {
    $decId = (int)$r['id'];
    $names = array_values(array_filter(array_map('trim', explode(',', (string)$r['responsible_person']))));
    $valid = array_values(array_filter($names, fn($n) => isset($validUsers[$n])));
    if (!$valid) {
        echo "skip dec_id=$decId — нет валидных ответственных\n";
        continue;
    }
    $title = mb_substr((string)$r['text'], 0, 255);
    $cardDue = $r['deadline'] ? ($r['deadline'] . ' 23:59:59') : null;
    $createdBy = $r['created_by'] ?: 'system';
    $entityLabel = mb_substr('Протокол: ' . ($r['topic'] ?? '') . ' от ' . ($r['meeting_date'] ?? ''), 0, 255);
    $firstCardId = null;

    foreach ($valid as $userName) {
        $boardCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM tasks_boards WHERE owner_name = '" . str_replace("'", "''", $userName) . "' AND is_archived = 0")->fetchColumn();
        $boardId = ensureBoard($pdo, $userName);
        if ($boardCountBefore === 0) $boardsCreated++;

        $col = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1");
        $col->execute([$boardId]);
        $columnId = (int)$col->fetchColumn();
        if (!$columnId) {
            echo "skip $userName dec_id=$decId — нет рабочей колонки\n";
            continue;
        }
        $so = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id IS NULL");
        $so->execute([$columnId]);
        $sortOrder = (int)$so->fetchColumn();

        $pdo->prepare("INSERT INTO tasks_cards (board_id, column_id, title, description, priority, due_date, sort_order, is_done, is_archived, created_by) VALUES (?, ?, ?, NULL, 'medium', ?, ?, 0, 0, ?)")
            ->execute([$boardId, $columnId, $title, $cardDue, $sortOrder, $createdBy]);
        $cardId = (int)$pdo->lastInsertId();
        if ($firstCardId === null) $firstCardId = $cardId;

        $pdo->prepare("INSERT INTO protocol_decision_cards (decision_id, card_id, user_name) VALUES (?, ?, ?)")
            ->execute([$decId, $cardId, $userName]);

        $insAss = $pdo->prepare("INSERT IGNORE INTO tasks_assignees (card_id, user_name) VALUES (?, ?)");
        foreach ($valid as $u) $insAss->execute([$cardId, $u]);

        $pdo->prepare("INSERT INTO tasks_relations (card_id, entity_type, entity_id, entity_label) VALUES (?, 'protocol', ?, ?)")
            ->execute([$cardId, (string)$r['protocol_id'], $entityLabel]);

        $cardsCreated++;
        echo "dec_id=$decId → card_id=$cardId (owner=$userName)\n";
    }

    if ($firstCardId) {
        $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = ? WHERE id = ?")->execute([$firstCardId, $decId]);
        $decisionsTouched++;
    }
}

echo "\nИтог: создано карточек: $cardsCreated, decisions затронуто: $decisionsTouched, новых досок: $boardsCreated\n";
