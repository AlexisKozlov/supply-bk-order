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

// 1. Очистить связи и удалить старые доски «Из протоколов»
$pdo->exec("UPDATE protocol_decisions SET tasks_card_id = NULL WHERE tasks_card_id IS NOT NULL");
$del = $pdo->exec("DELETE FROM tasks_boards WHERE title = 'Из протоколов'");
echo "Удалено досок «Из протоколов»: $del\n";

// 2. Для каждого пользователя без неархивной доски — создать стандартную
$users = $pdo->query("SELECT name FROM users ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$boardsCreated = 0;
$ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($users as $uname) {
    $s = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 LIMIT 1");
    $s->execute([$uname]);
    if ($s->fetchColumn()) continue;
    $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$uname, $uname]);
    $bid = (int)$pdo->lastInsertId();
    $cols = [
        ['Сделать',  '#90A4AE',    0, 0, 0],
        ['В работе', '#FFA726',    1, 0, 0],
        ['Архив',    '#9E9E9E', 9999, 0, 1],
    ];
    foreach ($cols as $c) $ins->execute([$bid, $c[0], $c[1], $c[2], $c[3], $c[4]]);
    echo "Создана доска для пользователя: $uname\n";
    $boardsCreated++;
}

// 3. Пересоздать карточки для незакрытых задач
$s = $pdo->query(
    "SELECT pd.id, pd.text, pd.responsible_person, pd.deadline, pd.status, pd.protocol_id,
            p.created_by
     FROM protocol_decisions pd
     JOIN meeting_protocols p ON p.id = pd.protocol_id
     WHERE pd.status IN ('pending','overdue') AND pd.tasks_card_id IS NULL
     ORDER BY pd.id"
);
$decisions = $s->fetchAll();

$created = 0;
$scol = $pdo->prepare("SELECT name FROM users WHERE name IN (%s)");
$sboard = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
$scolumn = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1");
$sso = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id IS NULL");
$scard = $pdo->prepare("INSERT INTO tasks_cards (board_id, column_id, title, description, priority, due_date, sort_order, is_done, is_archived, created_by, completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$sass = $pdo->prepare("INSERT IGNORE INTO tasks_assignees (card_id, user_name) VALUES (?, ?)");
$supd = $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = ? WHERE id = ?");

foreach ($decisions as $dec) {
    $decId = (int)$dec['id'];
    $rawNames = array_values(array_filter(array_map('trim', explode(',', (string)$dec['responsible_person']))));
    if (!$rawNames) {
        echo "skip dec_id=$decId — нет имён\n";
        continue;
    }
    $ph = implode(',', array_fill(0, count($rawNames), '?'));
    $su = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
    $su->execute($rawNames);
    $existing = array_column($su->fetchAll(), 'name');
    $validUsers = array_values(array_filter($rawNames, fn($n) => in_array($n, $existing, true)));
    if (!$validUsers) {
        echo "skip dec_id=$decId — нет валидных пользователей\n";
        continue;
    }

    $owner = $validUsers[0];

    $sboard->execute([$owner]);
    $boardId = (int)$sboard->fetchColumn();
    if (!$boardId) {
        echo "skip dec_id=$decId — нет доски у owner=$owner\n";
        continue;
    }

    $scolumn->execute([$boardId]);
    $columnId = (int)$scolumn->fetchColumn();
    if (!$columnId) {
        echo "skip dec_id=$decId — нет рабочей колонки в board=$boardId\n";
        continue;
    }

    $sso->execute([$columnId]);
    $sortOrder = (int)$sso->fetchColumn();

    $title = mb_substr((string)$dec['text'], 0, 255);
    $cardDue = $dec['deadline'] ? ($dec['deadline'] . ' 23:59:59') : null;
    $createdBy = $dec['created_by'] ?: 'system';

    $scard->execute([$boardId, $columnId, $title, null, 'medium', $cardDue, $sortOrder, 0, 0, $createdBy, null]);
    $cardId = (int)$pdo->lastInsertId();

    foreach ($validUsers as $u) $sass->execute([$cardId, $u]);

    $supd->execute([$cardId, $decId]);

    echo "migrated dec_id=$decId → card_id=$cardId (board_owner=$owner)\n";
    $created++;
}

echo "\nИтог: создано $created карточек, удалено $del старых досок, создано $boardsCreated новых дефолтных досок.\n";
