<?php
/**
 * API модуля «Задачи» — личные канбан-доски сотрудников.
 * Подключается из api/index.php. Переменные ($pdo, $endpoint, $subpoint, $method, $body, $uri) через global.
 *
 * Доступ:
 *   - Владелец доски: full
 *   - admin / manager: видят все доски, могут комментировать и менять статусы (двигать карточки),
 *     но не могут удалять чужие доски/карточки и не могут переназначать соисполнителей.
 *
 * Маршруты:
 *   GET    tasks/boards                — список досок (свои + чужие если admin/manager)
 *   POST   tasks/boards                — создать доску
 *   PATCH  tasks/boards/:id            — переименовать / архивировать
 *   DELETE tasks/boards/:id            — удалить доску (только владелец/admin)
 *
 *   GET    tasks/board/:id             — доска со всеми колонками, карточками, метками
 *
 *   POST   tasks/columns               — создать колонку
 *   PATCH  tasks/columns/:id           — переименовать / цвет / wip / done-flag
 *   DELETE tasks/columns/:id           — удалить колонку (если пустая)
 *   POST   tasks/columns/reorder       — изменить порядок колонок: { board_id, ids: [..] }
 *
 *   POST   tasks/cards                 — создать карточку
 *   PATCH  tasks/cards/:id             — обновить (title, description, priority, due_date, is_done)
 *   DELETE tasks/cards/:id             — удалить
 *   POST   tasks/cards/move            — переместить: { card_id, to_column_id, to_index }
 *
 *   POST   tasks/labels                — создать метку
 *   PATCH  tasks/labels/:id            — обновить
 *   DELETE tasks/labels/:id            — удалить
 *   POST   tasks/cards/:id/labels      — назначить метки: { label_ids: [..] } (заменяет полностью)
 *
 *   GET    tasks/cards/:id/checklist   — пункты чек-листа
 *   POST   tasks/cards/:id/checklist   — добавить пункт
 *   PATCH  tasks/checklist/:id         — обновить пункт
 *   DELETE tasks/checklist/:id         — удалить пункт
 *
 *   GET    tasks/cards/:id/comments    — комментарии
 *   POST   tasks/cards/:id/comments    — добавить
 *   PATCH  tasks/comments/:id          — изменить
 *   DELETE tasks/comments/:id          — удалить
 *
 *   GET    tasks/cards/:id/history     — история карточки
 *   POST   tasks/cards/:id/assignees   — соисполнители: { user_names: [..] } (заменяет)
 *   POST   tasks/cards/:id/relations   — связи с сущностями: { relations: [{entity_type, entity_id, entity_label}] }
 *   DELETE tasks/relations/:id         — удалить связь
 *
 *   GET    tasks/users                 — список пользователей-исполнителей (для выпадашки)
 */

if ($endpoint !== 'tasks') return;

// ─── Хелперы ───
function tRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function tRequireUser($pdo) {
    $u = getSessionUser($pdo);
    if (!$u) tRespond(['error' => 'Требуется авторизация'], 401);
    return $u;
}

function tIsManager($u) {
    return ($u['role'] ?? '') === 'admin' || ($u['role'] ?? '') === 'manager';
}

function tGetBoard($pdo, $boardId) {
    $s = $pdo->prepare("SELECT * FROM tasks_boards WHERE id = ?");
    $s->execute([$boardId]);
    return $s->fetch();
}

function tGetCard($pdo, $cardId) {
    $s = $pdo->prepare("SELECT c.*, b.owner_name FROM tasks_cards c JOIN tasks_boards b ON b.id = c.board_id WHERE c.id = ?");
    $s->execute([$cardId]);
    return $s->fetch();
}

function tGetColumn($pdo, $columnId) {
    $s = $pdo->prepare("SELECT col.*, b.owner_name FROM tasks_columns col JOIN tasks_boards b ON b.id = col.board_id WHERE col.id = ?");
    $s->execute([$columnId]);
    return $s->fetch();
}

// Может ли пользователь редактировать структуру доски (создавать/удалять/переименовывать).
// Только владелец или admin.
function tCanEditBoard($u, $board) {
    if (!$board) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    return $board['owner_name'] === $u['name'];
}

// Может ли пользователь работать с карточками (создание, перемещение, комментарии, статусы).
// Владелец, соисполнитель, admin, manager.
function tCanWorkWithBoard($pdo, $u, $board) {
    if (!$board) return false;
    if (tIsManager($u)) return true;
    return $board['owner_name'] === $u['name'];
}

// Запись в историю карточки
function tHistory($pdo, $cardId, $userName, $action, $details = null) {
    $s = $pdo->prepare("INSERT INTO tasks_history (card_id, user_name, action, details) VALUES (?, ?, ?, ?)");
    $s->execute([$cardId, $userName, $action, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
}

function tIsProtocolCard($pdo, $cardId) {
    $s = $pdo->prepare("SELECT 1 FROM protocol_decision_cards WHERE card_id = ?");
    $s->execute([$cardId]);
    return (bool)$s->fetchColumn();
}

// Синхронизация статуса decision на основе всех связанных карточек:
// если все is_done=1 → status='done', иначе 'pending'.
function tCheckCardAutoState($pdo, $cardId, $tUserName) {
    $s = $pdo->prepare("SELECT decision_id FROM protocol_decision_cards WHERE card_id = ?");
    $s->execute([$cardId]);
    $decId = (int)$s->fetchColumn();
    if (!$decId) return ['all_done' => false, 'changed' => false];

    $sums = $pdo->prepare("SELECT COUNT(*) AS total, SUM(c.is_done) AS done FROM protocol_decision_cards pdc JOIN tasks_cards c ON c.id = pdc.card_id WHERE pdc.decision_id = ?");
    $sums->execute([$decId]);
    $r = $sums->fetch();
    $total = (int)$r['total'];
    $done = (int)$r['done'];
    $allDone = ($total > 0 && $done === $total);

    $cur = $pdo->prepare("SELECT status FROM protocol_decisions WHERE id = ?");
    $cur->execute([$decId]);
    $oldStatus = $cur->fetchColumn();
    $newStatus = $allDone ? 'done' : 'pending';
    if ($newStatus !== $oldStatus) {
        $completedAt = $allDone ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE protocol_decisions SET status = ?, completed_at = ? WHERE id = ?")->execute([$newStatus, $completedAt, $decId]);
        return ['all_done' => $allDone, 'changed' => true];
    }
    return ['all_done' => $allDone, 'changed' => false];
}

// Совместимость: тонкая обёртка, чтобы старые вызовы не падали.
function tSyncCardToDecision($pdo, $cardId, $isDone) {
    tCheckCardAutoState($pdo, $cardId, '');
}

// Создание дефолтной доски с колонками для нового пользователя
function tEnsureDefaultBoard($pdo, $userName, $displayName = '') {
    $s = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
    $s->execute([$userName]);
    $row = $s->fetch();
    if ($row) return (int)$row['id'];

    $title = trim($displayName) !== '' ? $displayName : $userName;
    $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$userName, $title]);
    $boardId = (int)$pdo->lastInsertId();
    // Без отдельной колонки «Готово» — карточки сразу едут в «Архив» через чекбокс/drag.
    $cols = [
        ['Сделать',  '#90A4AE',    0, 0, 0],
        ['В работе', '#FFA726',    1, 0, 0],
        ['Архив',    '#9E9E9E', 9999, 0, 1],
    ];
    $ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($cols as $c) $ins->execute([$boardId, $c[0], $c[1], $c[2], $c[3], $c[4]]);
    return $boardId;
}

// Уведомление в systems-таблицу notifications
function tNotify($pdo, $userName, $title, $message, $cardId) {
    try {
        $pdo->prepare("INSERT INTO notifications (target_user, title, message, entity_type, entity_id, type)
                       VALUES (?, ?, ?, 'task', ?, 'task')")
            ->execute([$userName, $title, $message, $cardId]);
    } catch (Exception $e) {
        error_log('[tasks] tNotify error: ' . $e->getMessage());
    }
}

// Аутентификация — все маршруты требуют сессию
$tUser = tRequireUser($pdo);
$tUserName = $tUser['name'];

// ─── Проверка доступа к модулю tasks ───
// Уровни: GET → view, POST/PATCH/PUT → edit, DELETE → full.
// Админ всегда пропускается без проверки.
if (($tUser['role'] ?? '') !== 'admin') {
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    $tPerms = resolvePermissions($tUser['role'] ?? 'user', $tUser['permissions'] ?? null, $ROLE_TEMPLATES);
    $tActualLevel = $ACCESS_LEVELS[$tPerms['tasks'] ?? 'none'] ?? 0;
    if ($method === 'DELETE') {
        $tRequired = $ACCESS_LEVELS['full'];
    } elseif (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
        $tRequired = $ACCESS_LEVELS['edit'];
    } else {
        $tRequired = $ACCESS_LEVELS['view'];
    }
    if ($tActualLevel < $tRequired) {
        tRespond(['error' => 'Недостаточно прав для модуля «Задачи»'], 403);
    }
}

// ═══════════════════════════════════════════════════════
// МАРШРУТИЗАЦИЯ
// ═══════════════════════════════════════════════════════

$action = $subpoint;
$id = isset($parts[2]) ? urldecode($parts[2]) : null;
$action2 = isset($parts[3]) ? urldecode($parts[3]) : null;

// ─── GET tasks/users — список пользователей для выпадашки исполнителей ───
if ($method === 'GET' && $action === 'users') {
    // Требуется право tasks на уровне edit или выше (не только view).
    // Админ уже пропущен выше.
    if (($tUser['role'] ?? '') !== 'admin') {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $tPermsU = resolvePermissions($tUser['role'] ?? 'user', $tUser['permissions'] ?? null, $ROLE_TEMPLATES);
        $tLevelU  = $ACCESS_LEVELS[$tPermsU['tasks'] ?? 'none'] ?? 0;
        if ($tLevelU < $ACCESS_LEVELS['edit']) {
            tRespond(['error' => 'Недостаточно прав для просмотра списка пользователей'], 403);
        }
    }
    $s = $pdo->query("SELECT name, COALESCE(display_role, role) AS role FROM users WHERE role IN ('admin','manager','user') ORDER BY name");
    tRespond(['users' => $s->fetchAll()]);
}

// ─── GET tasks/search?q=... — поиск по карточкам всех доступных досок ───
if ($method === 'GET' && $action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) tRespond(['results' => []]);
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $isMgr = tIsManager($tUser);

    // Карточки + название доски + соисполнители
    $sql = "
        SELECT c.id, c.board_id, c.parent_card_id, c.title, c.description, c.priority,
               c.due_date, c.is_done, c.is_archived, c.column_id, c.updated_at,
               b.title AS board_title, b.owner_name,
               col.title AS column_title, col.is_done_column
        FROM tasks_cards c
        JOIN tasks_boards b ON b.id = c.board_id
        LEFT JOIN tasks_columns col ON col.id = c.column_id
        WHERE (c.title LIKE ? OR c.description LIKE ?)
    ";
    $params = [$like, $like];
    if (!$isMgr) {
        $sql .= " AND b.owner_name = ?";
        $params[] = $tUserName;
    }
    $sql .= " ORDER BY c.updated_at DESC LIMIT 50";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll();
    // Подрезаем описание для preview
    foreach ($rows as &$r) {
        if ($r['description']) {
            $d = strip_tags((string)$r['description']);
            $r['description'] = mb_strlen($d) > 120 ? mb_substr($d, 0, 120) . '…' : $d;
        }
    }
    tRespond(['results' => $rows]);
}

// ─── GET tasks/my-cards — задачи где я владелец/создатель/соисполнитель ───
if ($method === 'GET' && $action === 'my-cards') {
    $userName = $tUserName;
    // Все карточки на моих досках (где я owner) + где я соисполнитель
    $sql = "
        SELECT c.id, c.board_id, c.parent_card_id, c.title, c.priority, c.due_date, c.is_done,
               c.column_id, c.created_by, b.title AS board_title, b.owner_name AS board_owner,
               col.title AS column_title, col.is_done_column
        FROM tasks_cards c
        JOIN tasks_boards b ON b.id = c.board_id
        LEFT JOIN tasks_columns col ON col.id = c.column_id
        WHERE b.is_archived = 0
          AND c.is_archived = 0
          AND (
            b.owner_name = ?
            OR c.created_by = ?
            OR EXISTS (SELECT 1 FROM tasks_assignees ta WHERE ta.card_id = c.id AND ta.user_name = ?)
          )
        ORDER BY c.is_done ASC,
                 (c.due_date IS NULL),
                 c.due_date ASC,
                 c.priority DESC
        LIMIT 500
    ";
    $s = $pdo->prepare($sql);
    $s->execute([$userName, $userName, $userName]);
    $rows = $s->fetchAll();
    // Соисполнители одним запросом
    $byId = [];
    foreach ($rows as &$r) { $byId[(int)$r['id']] = &$r; $r['assignees'] = []; }
    if ($byId) {
        $ids = array_keys($byId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $a = $pdo->prepare("SELECT card_id, user_name FROM tasks_assignees WHERE card_id IN ($ph)");
        $a->execute($ids);
        foreach ($a->fetchAll() as $r2) {
            $cid = (int)$r2['card_id'];
            if (isset($byId[$cid])) $byId[$cid]['assignees'][] = $r2['user_name'];
        }
    }
    unset($r);
    tRespond(['cards' => $rows]);
}

// ─── BOARDS ───
if ($action === 'boards' && !$id) {
    if ($method === 'GET') {
        // Свои + (если admin/manager) чужие
        if (tIsManager($tUser)) {
            $s = $pdo->query("SELECT id, owner_name, title, sort_order, is_archived, created_at, updated_at FROM tasks_boards ORDER BY owner_name, sort_order, id");
        } else {
            $s = $pdo->prepare("SELECT id, owner_name, title, sort_order, is_archived, created_at, updated_at FROM tasks_boards WHERE owner_name = ? ORDER BY sort_order, id");
            $s->execute([$tUserName]);
        }
        $boards = $s->fetchAll();
        // Авто-создание дефолтной доски
        if (empty(array_filter($boards, fn($b) => $b['owner_name'] === $tUserName))) {
            $displayName = $tUser['display_name'] ?? '';
            tEnsureDefaultBoard($pdo, $tUserName, $displayName !== '' ? $displayName : $tUserName);
            // Перечитать
            if (tIsManager($tUser)) {
                $s = $pdo->query("SELECT id, owner_name, title, sort_order, is_archived, created_at, updated_at FROM tasks_boards ORDER BY owner_name, sort_order, id");
            } else {
                $s = $pdo->prepare("SELECT id, owner_name, title, sort_order, is_archived, created_at, updated_at FROM tasks_boards WHERE owner_name = ? ORDER BY sort_order, id");
                $s->execute([$tUserName]);
            }
            $boards = $s->fetchAll();
        }
        tRespond(['boards' => $boards]);
    }
    if ($method === 'POST') {
        $title = trim($body['title'] ?? '');
        if ($title === '') tRespond(['error' => 'Название обязательно'], 400);
        $owner = $tUserName;
        // admin может создавать доску для другого пользователя
        if (($tUser['role'] ?? '') === 'admin' && !empty($body['owner_name'])) {
            $owner = trim($body['owner_name']);
        }
        $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$owner, mb_substr($title, 0, 150)]);
        $boardId = (int)$pdo->lastInsertId();
        // Дефолтные колонки + системная «Архив». «Готово» намеренно не создаём — её роль выполняет «Архив».
        $cols = [
            ['Сделать','#90A4AE',0,0,0],
            ['В работе','#FFA726',1,0,0],
            ['Архив','#9E9E9E',9999,0,1],
        ];
        $ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($cols as $c) $ins->execute([$boardId, $c[0], $c[1], $c[2], $c[3], $c[4]]);
        tRespond(['id' => $boardId]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

if ($action === 'boards' && $id) {
    $boardId = (int)$id;
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if ($method === 'PATCH') {
        if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
        $sets = [];
        $params = [];
        if (isset($body['title']))       { $sets[] = 'title = ?';       $params[] = mb_substr(trim($body['title']), 0, 150); }
        if (isset($body['is_archived'])) { $sets[] = 'is_archived = ?'; $params[] = $body['is_archived'] ? 1 : 0; }
        if (isset($body['sort_order']))  { $sets[] = 'sort_order = ?';  $params[] = (int)$body['sort_order']; }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $boardId;
        $pdo->prepare("UPDATE tasks_boards SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
        $pdo->prepare("DELETE FROM tasks_boards WHERE id = ?")->execute([$boardId]);
        tRespond(['success' => true]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

// ─── GET tasks/board/:id — полная доска ───
if ($action === 'board' && $id && $method === 'GET') {
    $boardId = (int)$id;
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет доступа'], 403);

    $s = $pdo->prepare("SELECT * FROM tasks_columns WHERE board_id = ? ORDER BY sort_order, id");
    $s->execute([$boardId]);
    $columns = $s->fetchAll();

    // Все корневые карточки (включая архивные — они отрисуются в архив-колонке)
    $s = $pdo->prepare("SELECT * FROM tasks_cards WHERE board_id = ? AND parent_card_id IS NULL ORDER BY column_id, sort_order, id");
    $s->execute([$boardId]);
    $cards = $s->fetchAll();
    foreach ($cards as &$cc) { $cc['is_external'] = 0; $cc['external_board_owner'] = null; $cc['external_board_id'] = null; }
    unset($cc);

    // Чужие карточки, где владелец доски (= я) состоит в assignees.
    // Колонку и порядок берём из tasks_assignees; если column_id невалидна
    // на МОЕЙ доске — fallback: незакрытые → первая обычная, закрытые →
    // моя архив-колонка. is_done/is_archived подменяем на assignee.is_done,
    // чтобы фронт видел статус моей части (а не оригинала автора).
    // c.is_archived НЕ фильтруем: если автор отправил свой оригинал
    // в архив, моё назначение остаётся в силе и должно быть видно у меня.
    // Если автор удалил карточку — каскад из FK в tasks_assignees уберёт
    // её и у меня. Архив доски (b.is_archived=1) — отдельная история,
    // её всё-таки прячем, иначе ассайни висят на «выключенной» доске.
    $firstNormalColId = null;
    $archiveColId     = null;
    foreach ($columns as $col) {
        if (empty($col['is_archive_column'])) {
            if ($firstNormalColId === null) $firstNormalColId = (int)$col['id'];
        } else {
            if ($archiveColId === null) $archiveColId = (int)$col['id'];
        }
    }
    $myColIds = array_map(fn($c) => (int)$c['id'], $columns);
    if ($board['owner_name'] === $tUserName && $firstNormalColId !== null) {
        $s = $pdo->prepare("
            SELECT c.*, ta.column_id AS assignee_column_id, ta.sort_order AS assignee_sort_order,
                   ta.is_done AS assignee_is_done, ta.done_at AS assignee_done_at,
                   b.owner_name AS external_board_owner, b.id AS external_board_id
            FROM tasks_cards c
            JOIN tasks_assignees ta ON ta.card_id = c.id
            JOIN tasks_boards b     ON b.id = c.board_id
            WHERE ta.user_name = ?
              AND c.board_id != ?
              AND c.parent_card_id IS NULL
              AND b.is_archived = 0
        ");
        $s->execute([$tUserName, $boardId]);
        foreach ($s->fetchAll() as $extCard) {
            $assignedCol = $extCard['assignee_column_id'] !== null ? (int)$extCard['assignee_column_id'] : null;
            $isDoneForMe = (int)$extCard['assignee_is_done'];
            // Если колонка не валидна на моей доске — кладём по статусу:
            // закрыл → архив, ещё в работе → первая обычная.
            if ($assignedCol === null || !in_array($assignedCol, $myColIds, true)) {
                $assignedCol = ($isDoneForMe && $archiveColId !== null) ? $archiveColId : $firstNormalColId;
            }
            $extCard['column_id']    = $assignedCol;
            $extCard['sort_order']   = (int)($extCard['assignee_sort_order'] ?? 0);
            $extCard['is_external']  = 1;
            // Персональный статус: для меня карточка «закрыта», даже если
            // у автора оригинал ещё открыт. Фронт по этим полям рисует архив.
            $extCard['is_done']      = $isDoneForMe;
            $extCard['is_archived']  = $isDoneForMe;
            $extCard['completed_at'] = $extCard['assignee_done_at'];
            unset($extCard['assignee_column_id'], $extCard['assignee_sort_order'], $extCard['assignee_is_done'], $extCard['assignee_done_at']);
            $cards[] = $extCard;
        }
    }

    $s = $pdo->prepare("SELECT * FROM tasks_labels WHERE board_id = ? ORDER BY sort_order, id");
    $s->execute([$boardId]);
    $labels = $s->fetchAll();

    // Метки на карточках
    $cardLabels = [];
    if ($cards) {
        $ids = array_column($cards, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT card_id, label_id FROM tasks_card_labels WHERE card_id IN ($ph)");
        $s->execute($ids);
        foreach ($s->fetchAll() as $r) {
            $cardLabels[(int)$r['card_id']][] = (int)$r['label_id'];
        }
        // Кол-во чек-листа и комментариев
        $s = $pdo->prepare("SELECT card_id, SUM(is_done) AS done, COUNT(*) AS total FROM tasks_checklist WHERE card_id IN ($ph) GROUP BY card_id");
        $s->execute($ids);
        $checkSummary = [];
        foreach ($s->fetchAll() as $r) $checkSummary[(int)$r['card_id']] = ['done' => (int)$r['done'], 'total' => (int)$r['total']];

        $s = $pdo->prepare("SELECT card_id, COUNT(*) AS cnt FROM tasks_comments WHERE card_id IN ($ph) GROUP BY card_id");
        $s->execute($ids);
        $cmtCount = [];
        foreach ($s->fetchAll() as $r) $cmtCount[(int)$r['card_id']] = (int)$r['cnt'];

        $s = $pdo->prepare("SELECT card_id, COUNT(*) AS cnt FROM tasks_attachments WHERE card_id IN ($ph) GROUP BY card_id");
        $s->execute($ids);
        $attCount = [];
        foreach ($s->fetchAll() as $r) $attCount[(int)$r['card_id']] = (int)$r['cnt'];

        $s = $pdo->prepare("SELECT card_id, user_name, is_done FROM tasks_assignees WHERE card_id IN ($ph)");
        $s->execute($ids);
        $assg = [];
        $assgDone = [];
        foreach ($s->fetchAll() as $r) {
            $cid = (int)$r['card_id'];
            $assg[$cid][] = $r['user_name'];
            if ((int)$r['is_done']) $assgDone[$cid][] = $r['user_name'];
        }

        // Подзадачи всех корневых карточек (одним запросом)
        $s = $pdo->prepare("SELECT id, parent_card_id, title, is_done, priority, due_date, sort_order FROM tasks_cards WHERE parent_card_id IN ($ph) ORDER BY parent_card_id, sort_order, id");
        $s->execute($ids);
        $subRows = $s->fetchAll();
        $subsByParent = [];
        $subsCount = [];
        $subsDone = [];
        foreach ($subRows as $sr) {
            $pid = (int)$sr['parent_card_id'];
            $subsByParent[$pid][] = $sr;
            $subsCount[$pid] = ($subsCount[$pid] ?? 0) + 1;
            if ($sr['is_done']) $subsDone[$pid] = ($subsDone[$pid] ?? 0) + 1;
        }

        foreach ($cards as &$c) {
            $cid = (int)$c['id'];
            $c['label_ids']   = $cardLabels[$cid] ?? [];
            $c['checklist']   = $checkSummary[$cid] ?? ['done' => 0, 'total' => 0];
            $c['comments']    = $cmtCount[$cid] ?? 0;
            $c['attachments'] = $attCount[$cid] ?? 0;
            $c['assignees']   = $assg[$cid] ?? [];
            $c['assignees_done'] = $assgDone[$cid] ?? [];
            $c['subtasks']    = $subsByParent[$cid] ?? [];
            $c['subtasks_total'] = $subsCount[$cid] ?? 0;
            $c['subtasks_done']  = $subsDone[$cid] ?? 0;
        }
        unset($c);
    }

    tRespond([
        'board'   => $board,
        'columns' => $columns,
        'cards'   => $cards,
        'labels'  => $labels,
        'can_edit_structure' => tCanEditBoard($tUser, $board),
        'is_owner' => $board['owner_name'] === $tUserName,
    ]);
}

// ─── COLUMNS ───
if ($action === 'columns' && !$id) {
    if ($method === 'POST') {
        $boardId = (int)($body['board_id'] ?? 0);
        $title   = trim($body['title'] ?? '');
        if (!$boardId || $title === '') tRespond(['error' => 'board_id и title обязательны'], 400);
        $board = tGetBoard($pdo, $boardId);
        if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
        if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS so FROM tasks_columns WHERE board_id = ?");
        $s->execute([$boardId]);
        $so = (int)$s->fetchColumn();
        $color = mb_substr(trim($body['color'] ?? '#9E9E9E'), 0, 20);
        $isDone = !empty($body['is_done_column']) ? 1 : 0;
        $wip = isset($body['wip_limit']) && $body['wip_limit'] > 0 ? (int)$body['wip_limit'] : null;
        $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, wip_limit) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$boardId, mb_substr($title, 0, 100), $color, $so, $isDone, $wip]);
        tRespond(['id' => (int)$pdo->lastInsertId()]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

if ($action === 'columns' && $id && $action2 === 'reorder') {
    // POST tasks/columns/X/reorder — не используется
    tRespond(['error' => 'Bad route'], 400);
}

if ($action === 'columns' && $id === 'reorder' && $method === 'POST') {
    $boardId = (int)($body['board_id'] ?? 0);
    $ids = $body['ids'] ?? [];
    if (!$boardId || !is_array($ids)) tRespond(['error' => 'board_id и ids обязательны'], 400);
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $upd = $pdo->prepare("UPDATE tasks_columns SET sort_order = ? WHERE id = ? AND board_id = ?");
    foreach ($ids as $i => $colId) $upd->execute([(int)$i, (int)$colId, $boardId]);
    tRespond(['success' => true]);
}

if ($action === 'columns' && $id && $id !== 'reorder') {
    $colId = (int)$id;
    $col = tGetColumn($pdo, $colId);
    if (!$col) tRespond(['error' => 'Колонка не найдена'], 404);
    $board = tGetBoard($pdo, $col['board_id']);
    if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);

    if ($method === 'PATCH') {
        if (!empty($col['is_archive_column'])) tRespond(['error' => 'Системная колонка «Архив» не редактируется'], 400);
        $sets = [];
        $params = [];
        if (isset($body['title']))          { $sets[] = 'title = ?';          $params[] = mb_substr(trim($body['title']), 0, 100); }
        if (isset($body['color']))          { $sets[] = 'color = ?';          $params[] = mb_substr($body['color'], 0, 20); }
        if (isset($body['wip_limit']))      { $sets[] = 'wip_limit = ?';      $params[] = $body['wip_limit'] > 0 ? (int)$body['wip_limit'] : null; }
        if (isset($body['is_done_column'])) { $sets[] = 'is_done_column = ?'; $params[] = $body['is_done_column'] ? 1 : 0; }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $colId;
        $pdo->prepare("UPDATE tasks_columns SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        if (!empty($col['is_archive_column'])) tRespond(['error' => 'Системная колонка «Архив» не удаляется'], 400);
        // Проверяем, что колонка пустая
        $s = $pdo->prepare("SELECT COUNT(*) FROM tasks_cards WHERE column_id = ?");
        $s->execute([$colId]);
        if ((int)$s->fetchColumn() > 0) tRespond(['error' => 'Колонка не пуста — сначала перенесите карточки'], 400);
        $pdo->prepare("DELETE FROM tasks_columns WHERE id = ?")->execute([$colId]);
        tRespond(['success' => true]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

// ─── CARDS ───
if ($action === 'cards' && !$id) {
    if ($method === 'POST') {
        $boardId  = (int)($body['board_id'] ?? 0);
        $columnId = (int)($body['column_id'] ?? 0);
        $title    = trim($body['title'] ?? '');
        $parentId = !empty($body['parent_card_id']) ? (int)$body['parent_card_id'] : null;
        if ($title === '') tRespond(['error' => 'title обязателен'], 400);

        // Если передан parent_card_id — это подзадача. board_id и column_id берём из родителя.
        if ($parentId) {
            $parent = tGetCard($pdo, $parentId);
            if (!$parent) tRespond(['error' => 'Родительская карточка не найдена'], 404);
            if (!empty($parent['parent_card_id'])) tRespond(['error' => 'Подзадачи не могут иметь подзадач'], 400);
            $boardId = (int)$parent['board_id'];
            $columnId = (int)$parent['column_id'];
        }

        if (!$boardId || !$columnId) tRespond(['error' => 'board_id и column_id обязательны'], 400);
        $board = tGetBoard($pdo, $boardId);
        if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
        if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
        $col = tGetColumn($pdo, $columnId);
        if (!$col || (int)$col['board_id'] !== $boardId) tRespond(['error' => 'Колонка не относится к доске'], 400);

        $priority = in_array($body['priority'] ?? '', ['low','medium','high','urgent']) ? $body['priority'] : 'medium';
        $due = !empty($body['due_date']) ? $body['due_date'] : null;
        $desc = isset($body['description']) ? mb_substr((string)$body['description'], 0, 5000) : null;
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id " . ($parentId ? "= ?" : "IS NULL"));
            $s->execute($parentId ? [$columnId, $parentId] : [$columnId]);
            $so = (int)$s->fetchColumn();
            $pdo->prepare("INSERT INTO tasks_cards (board_id, parent_card_id, column_id, title, description, priority, due_date, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$boardId, $parentId, $columnId, mb_substr($title, 0, 255), $desc, $priority, $due, $so, $tUserName]);
            $cardId = (int)$pdo->lastInsertId();
            tHistory($pdo, $cardId, $tUserName, 'created', ['title' => $title, 'parent_card_id' => $parentId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            tRespond(['error' => 'Ошибка создания карточки'], 500);
        }
        tRespond(['id' => $cardId]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

if ($action === 'cards' && $id === 'move' && $method === 'POST') {
    $cardId      = (int)($body['card_id'] ?? 0);
    $toColumnId  = (int)($body['to_column_id'] ?? 0);
    $toIndex     = isset($body['to_index']) ? (int)$body['to_index'] : 0;
    if (!$cardId || !$toColumnId) tRespond(['error' => 'card_id и to_column_id обязательны'], 400);
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $toCol = tGetColumn($pdo, $toColumnId);
    if (!$toCol) tRespond(['error' => 'Колонка не найдена'], 400);
    $toBoard = tGetBoard($pdo, $toCol['board_id']);
    if (!$toBoard) tRespond(['error' => 'Доска целевой колонки не найдена'], 400);

    // Внешний кейс: карточка лежит на ЧУЖОЙ доске, но целевая колонка
    // — на МОЕЙ. Я двигаю не саму карточку, а свою запись в tasks_assignees:
    // меняется только моё представление карточки, у автора оригинал не
    // двигается. Архив-колонка моей доски → ставлю себе is_done=1, и
    // карточка пропадает с моей доски (но у автора остаётся).
    $isExternal = ((int)$card['board_id'] !== (int)$toCol['board_id']);
    if ($isExternal) {
        if ($toBoard['owner_name'] !== $tUserName) tRespond(['error' => 'Между чужими досками двигать нельзя'], 403);
        // Ассайни — независимо от is_done. Если уже закрыл, может вытащить
        // карточку из своей архив-колонки обратно в работу — это сбросит
        // is_done и галочку «выполнил» у автора.
        $a = $pdo->prepare("SELECT 1 FROM tasks_assignees WHERE card_id = ? AND user_name = ?");
        $a->execute([$cardId, $tUserName]);
        if (!$a->fetchColumn()) tRespond(['error' => 'Нет прав на эту карточку'], 403);
    } else {
        $cardBoard = tGetBoard($pdo, $card['board_id']);
        if (!tCanWorkWithBoard($pdo, $tUser, $cardBoard)) tRespond(['error' => 'Нет прав'], 403);
    }

    $pdo->beginTransaction();
    try {
        $isToArchive = !empty($toCol['is_archive_column']) ? 1 : 0;

        if ($isExternal) {
            // Локи: свои карточки и свои tasks_assignees-строки в целевой колонке.
            $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id = ? FOR UPDATE")->execute([$toColumnId]);
            $pdo->prepare("SELECT card_id FROM tasks_assignees WHERE user_name = ? AND column_id = ? FOR UPDATE")
                ->execute([$tUserName, $toColumnId]);

            // Собираем порядок: свои карточки + мои внешние в этой колонке
            // (включая закрытые в архиве — они тоже видны на доске).
            $items = []; // [['cid'=>id, 'sort'=>n, 'src'=>'own'|'ext'], ...]
            $s = $pdo->prepare("SELECT id, sort_order FROM tasks_cards WHERE column_id = ? AND id <> ?");
            $s->execute([$toColumnId, $cardId]);
            foreach ($s->fetchAll() as $r) $items[] = ['cid' => (int)$r['id'], 'sort' => (int)$r['sort_order'], 'src' => 'own'];
            $s = $pdo->prepare("SELECT card_id, sort_order FROM tasks_assignees WHERE user_name = ? AND column_id = ? AND card_id <> ?");
            $s->execute([$tUserName, $toColumnId, $cardId]);
            foreach ($s->fetchAll() as $r) $items[] = ['cid' => (int)$r['card_id'], 'sort' => (int)$r['sort_order'], 'src' => 'ext'];
            usort($items, fn($a, $b) => ($a['sort'] - $b['sort']) ?: ($a['cid'] - $b['cid']));
            $toIndex = max(0, min($toIndex, count($items)));
            array_splice($items, $toIndex, 0, [['cid' => $cardId, 'sort' => 0, 'src' => 'ext']]);

            $updOwn = $pdo->prepare("UPDATE tasks_cards SET sort_order = ? WHERE id = ?");
            $updExt = $pdo->prepare("UPDATE tasks_assignees SET column_id = ?, sort_order = ? WHERE card_id = ? AND user_name = ?");
            foreach ($items as $i => $it) {
                if ($it['src'] === 'own') $updOwn->execute([$i, $it['cid']]);
                else                       $updExt->execute([$toColumnId, $i, $it['cid'], $tUserName]);
            }

            if ($isToArchive) {
                $pdo->prepare("UPDATE tasks_assignees SET is_done = 1, done_at = NOW() WHERE card_id = ? AND user_name = ?")
                    ->execute([$cardId, $tUserName]);
                tHistory($pdo, $cardId, $tUserName, 'assignee_done', ['user' => $tUserName]);
            } else {
                // Возврат из архива в работу: сбрасываем «выполнил».
                $pdo->prepare("UPDATE tasks_assignees SET is_done = 0, done_at = NULL WHERE card_id = ? AND user_name = ? AND is_done = 1")
                    ->execute([$cardId, $tUserName]);
                tHistory($pdo, $cardId, $tUserName, 'assignee_moved', ['user' => $tUserName, 'to_column' => $toColumnId]);
            }

            $pdo->commit();
            tRespond(['success' => true]);
        }

        // Свой кейс: обычное перемещение в той же доске.
        $fromCol     = (int)$card['column_id'];
        $newIsDone   = ($isToArchive || !empty($toCol['is_done_column'])) ? 1 : 0;
        $isProto     = tIsProtocolCard($pdo, $cardId);

        if ($fromCol !== $toColumnId) {
            $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id IN (?, ?) FOR UPDATE")
                ->execute([$fromCol, $toColumnId]);
        } else {
            $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id = ? FOR UPDATE")
                ->execute([$fromCol]);
        }

        $s = $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id = ? AND id <> ? ORDER BY sort_order, id");
        $s->execute([$toColumnId, $cardId]);
        $ids = array_column($s->fetchAll(), 'id');
        $toIndex = max(0, min($toIndex, count($ids)));
        array_splice($ids, $toIndex, 0, [$cardId]);

        $upd = $pdo->prepare("UPDATE tasks_cards SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $iid) $upd->execute([$i, (int)$iid]);

        $completedAt = $newIsDone ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE tasks_cards SET column_id = ?, is_done = ?, is_archived = ?, completed_at = ? WHERE id = ?")
            ->execute([$toColumnId, $newIsDone, $isToArchive, $completedAt, $cardId]);
        tHistory($pdo, $cardId, $tUserName, 'moved', ['from_column' => $fromCol, 'to_column' => $toColumnId]);

        if ($fromCol !== $toColumnId) {
            $s = $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id = ? ORDER BY sort_order, id");
            $s->execute([$fromCol]);
            $upd2 = $pdo->prepare("UPDATE tasks_cards SET sort_order = ? WHERE id = ?");
            foreach (array_column($s->fetchAll(), 'id') as $i => $iid) $upd2->execute([$i, (int)$iid]);
        }

        if ($isProto) tCheckCardAutoState($pdo, $cardId, $tUserName);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка: ' . $e->getMessage()], 500);
    }
    tRespond(['success' => true]);
}

if ($action === 'cards' && $id && $id !== 'move' && !$action2) {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);

    if ($method === 'GET') {
        if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет доступа'], 403);
        // Полная карточка: + чек-лист, комментарии, история, метки, соисполнители, связи, вложения
        $s = $pdo->prepare("SELECT id, title, is_done, sort_order, checklist_id FROM tasks_checklist WHERE card_id = ? ORDER BY sort_order, id");
        $s->execute([$cardId]);
        $checklist = $s->fetchAll();

        // Группы чек-листов и их пункты (новая модель: несколько чек-листов на карточке)
        $gs = $pdo->prepare("SELECT id, title, sort_order FROM tasks_checklists WHERE card_id = ? ORDER BY sort_order, id");
        $gs->execute([$cardId]);
        $checklistGroups = $gs->fetchAll();
        $itemsByGroup = [];
        foreach ($checklist as $it) {
            $g = $it['checklist_id'] ? (int)$it['checklist_id'] : 0;
            if (!isset($itemsByGroup[$g])) $itemsByGroup[$g] = [];
            $itemsByGroup[$g][] = $it;
        }
        foreach ($checklistGroups as &$g) {
            $g['items'] = $itemsByGroup[(int)$g['id']] ?? [];
        }
        unset($g);

        $s = $pdo->prepare("SELECT id, author_name, body, created_at, edited_at FROM tasks_comments WHERE card_id = ? ORDER BY created_at, id");
        $s->execute([$cardId]);
        $comments = $s->fetchAll();

        $s = $pdo->prepare("SELECT id, user_name, action, details, created_at FROM tasks_history WHERE card_id = ? ORDER BY created_at DESC, id DESC LIMIT 100");
        $s->execute([$cardId]);
        $history = $s->fetchAll();
        foreach ($history as &$h) {
            if ($h['details']) $h['details'] = json_decode($h['details'], true);
        }
        unset($h);

        $s = $pdo->prepare("SELECT label_id FROM tasks_card_labels WHERE card_id = ?");
        $s->execute([$cardId]);
        $labelIds = array_map('intval', array_column($s->fetchAll(), 'label_id'));

        $s = $pdo->prepare("SELECT user_name, is_done FROM tasks_assignees WHERE card_id = ? ORDER BY added_at, user_name");
        $s->execute([$cardId]);
        $assigneeRows = $s->fetchAll();
        $assignees = array_column($assigneeRows, 'user_name');
        $assigneesDone = [];
        foreach ($assigneeRows as $r) if ((int)$r['is_done']) $assigneesDone[] = $r['user_name'];

        $s = $pdo->prepare("SELECT id, entity_type, entity_id, entity_label, created_at FROM tasks_relations WHERE card_id = ?");
        $s->execute([$cardId]);
        $relations = $s->fetchAll();

        $s = $pdo->prepare("SELECT id, file_name, file_path, file_size, mime_type, uploaded_by, uploaded_at FROM tasks_attachments WHERE card_id = ? ORDER BY uploaded_at DESC");
        $s->execute([$cardId]);
        $attachments = $s->fetchAll();

        // Подзадачи: компактно (с минимумом полей для отображения в списке)
        $s = $pdo->prepare("SELECT id, title, is_done, priority, due_date, sort_order FROM tasks_cards WHERE parent_card_id = ? ORDER BY sort_order, id");
        $s->execute([$cardId]);
        $subtasks = $s->fetchAll();
        // Соисполнители подзадач (для отображения аватарок)
        if ($subtasks) {
            $sids = array_column($subtasks, 'id');
            $ph = implode(',', array_fill(0, count($sids), '?'));
            $s = $pdo->prepare("SELECT card_id, user_name FROM tasks_assignees WHERE card_id IN ($ph)");
            $s->execute($sids);
            $sub_a = [];
            foreach ($s->fetchAll() as $r) $sub_a[(int)$r['card_id']][] = $r['user_name'];
            foreach ($subtasks as &$st) $st['assignees'] = $sub_a[(int)$st['id']] ?? [];
            unset($st);
        }

        // Если эта карточка сама — подзадача, добавим title родителя
        $parentInfo = null;
        if (!empty($card['parent_card_id'])) {
            $s = $pdo->prepare("SELECT id, title FROM tasks_cards WHERE id = ?");
            $s->execute([$card['parent_card_id']]);
            $parentInfo = $s->fetch() ?: null;
        }

        // Соисполнители из протокола: если карточка пришла из решения протокола,
        // у каждого ответственного — своя копия на своей доске. На текущей карточке
        // покажем имена ОСТАЛЬНЫХ ответственных по тому же решению (read-only).
        $protocolCoAssignees = [];
        try {
            $pca = $pdo->prepare("
                SELECT DISTINCT pdc2.user_name
                FROM protocol_decision_cards pdc1
                JOIN protocol_decision_cards pdc2 ON pdc2.decision_id = pdc1.decision_id
                WHERE pdc1.card_id = ? AND pdc2.user_name != COALESCE((SELECT b.owner_name FROM tasks_cards c JOIN tasks_boards b ON b.id = c.board_id WHERE c.id = pdc1.card_id), '')
            ");
            $pca->execute([$cardId]);
            $protocolCoAssignees = array_column($pca->fetchAll(), 'user_name');
        } catch (\Throwable $e) { /* таблицы протоколов могут отсутствовать в части окружений */ }

        tRespond([
            'card'        => $card,
            'checklist'   => $checklist,           // плоский (для обратной совместимости)
            'checklists'  => $checklistGroups,     // группы с items[] — новый формат
            'comments'    => $comments,
            'history'     => $history,
            'label_ids'   => $labelIds,
            'assignees'   => $assignees,
            'assignees_done' => $assigneesDone,
            'relations'   => $relations,
            'attachments' => $attachments,
            'subtasks'    => $subtasks,
            'parent'      => $parentInfo,
            'protocol_co_assignees' => $protocolCoAssignees,
        ]);
    }

    if ($method === 'PATCH') {
        if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
        $sets = [];
        $params = [];
        $changes = [];
        if (isset($body['title']))       { $new = mb_substr(trim((string)$body['title']), 0, 255); if ($new !== $card['title']) $changes['title'] = ['from' => $card['title'], 'to' => $new]; $sets[] = 'title = ?'; $params[] = $new; }
        if (array_key_exists('description', $body)) { $new = $body['description'] === null ? null : mb_substr((string)$body['description'], 0, 5000); $sets[] = 'description = ?'; $params[] = $new; }
        if (isset($body['priority']) && in_array($body['priority'], ['low','medium','high','urgent'])) {
            if ($body['priority'] !== $card['priority']) $changes['priority'] = ['from' => $card['priority'], 'to' => $body['priority']];
            $sets[] = 'priority = ?'; $params[] = $body['priority'];
        }
        if (array_key_exists('due_date', $body)) {
            $new = $body['due_date'] ? $body['due_date'] : null;
            if ($new !== $card['due_date']) $changes['due_date'] = ['from' => $card['due_date'], 'to' => $new];
            $sets[] = 'due_date = ?'; $params[] = $new;
        }
        if (isset($body['is_done'])) {
            $newDone = $body['is_done'] ? 1 : 0;
            $sets[] = 'is_done = ?'; $params[] = $newDone;
            $sets[] = 'completed_at = ?'; $params[] = $newDone ? date('Y-m-d H:i:s') : null;
        }
        if (isset($body['is_archived'])) {
            $newArchived = $body['is_archived'] ? 1 : 0;
            $sets[] = 'is_archived = ?'; $params[] = $newArchived;
            if ($newArchived) $changes['archived'] = true;
        }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $cardId;
        $pdo->prepare("UPDATE tasks_cards SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        if ($changes) tHistory($pdo, $cardId, $tUserName, 'updated', $changes);
        if (isset($body['is_done']) && tIsProtocolCard($pdo, $cardId)) {
            tCheckCardAutoState($pdo, $cardId, $tUserName);
        }
        tRespond(['success' => true]);
    }

    if ($method === 'DELETE') {
        // Удалять может только владелец доски или admin.
        // Создание карточки менеджером на чужой доске не даёт права на удаление.
        $canDelete = tCanEditBoard($tUser, $board);
        if (!$canDelete) tRespond(['error' => 'Нет прав'], 403);
        $pdo->prepare("DELETE FROM tasks_cards WHERE id = ?")->execute([$cardId]);
        tRespond(['success' => true]);
    }
    tRespond(['error' => 'Method not allowed'], 405);
}

// ─── LABELS ───
if ($action === 'labels' && !$id && $method === 'POST') {
    $boardId = (int)($body['board_id'] ?? 0);
    $title   = trim($body['title'] ?? '');
    $color   = mb_substr(trim($body['color'] ?? '#9E9E9E'), 0, 20);
    if (!$boardId || $title === '') tRespond(['error' => 'board_id и title обязательны'], 400);
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_labels WHERE board_id = ?");
    $s->execute([$boardId]);
    $so = (int)$s->fetchColumn();
    $pdo->prepare("INSERT INTO tasks_labels (board_id, title, color, sort_order) VALUES (?, ?, ?, ?)")
        ->execute([$boardId, mb_substr($title, 0, 80), $color, $so]);
    tRespond(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'labels' && $id) {
    $labelId = (int)$id;
    $s = $pdo->prepare("SELECT l.*, b.owner_name FROM tasks_labels l JOIN tasks_boards b ON b.id = l.board_id WHERE l.id = ?");
    $s->execute([$labelId]);
    $label = $s->fetch();
    if (!$label) tRespond(['error' => 'Метка не найдена'], 404);
    $board = tGetBoard($pdo, $label['board_id']);
    if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'PATCH') {
        $sets = []; $params = [];
        if (isset($body['title'])) { $sets[] = 'title = ?'; $params[] = mb_substr(trim($body['title']), 0, 80); }
        if (isset($body['color'])) { $sets[] = 'color = ?'; $params[] = mb_substr($body['color'], 0, 20); }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $labelId;
        $pdo->prepare("UPDATE tasks_labels SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM tasks_labels WHERE id = ?")->execute([$labelId]);
        tRespond(['success' => true]);
    }
}

// ─── tasks/cards/:id/labels ───
if ($action === 'cards' && $id && $action2 === 'labels' && $method === 'POST') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $labelIds = array_map('intval', $body['label_ids'] ?? []);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tasks_card_labels WHERE card_id = ?")->execute([$cardId]);
        if ($labelIds) {
            $ins = $pdo->prepare("INSERT IGNORE INTO tasks_card_labels (card_id, label_id) VALUES (?, ?)");
            foreach ($labelIds as $lid) $ins->execute([$cardId, $lid]);
        }
        tHistory($pdo, $cardId, $tUserName, 'labels_changed', ['label_ids' => $labelIds]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => $e->getMessage()], 500);
    }
    tRespond(['success' => true]);
}

// ─── CHECKLIST ───
if ($action === 'cards' && $id && $action2 === 'checklist') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'GET') {
        $s = $pdo->prepare("SELECT * FROM tasks_checklist WHERE card_id = ? ORDER BY sort_order, id");
        $s->execute([$cardId]);
        tRespond(['items' => $s->fetchAll()]);
    }
    if ($method === 'POST') {
        $title = trim($body['title'] ?? '');
        if ($title === '') tRespond(['error' => 'title обязателен'], 400);
        // Опционально: явно указанная группа. Иначе кладём в первую группу карточки
        // (если групп нет — создаём дефолтную «Чек-лист»).
        $groupId = isset($body['checklist_id']) ? (int)$body['checklist_id'] : 0;
        if (!$groupId) {
            $gs = $pdo->prepare("SELECT id FROM tasks_checklists WHERE card_id = ? ORDER BY sort_order, id LIMIT 1");
            $gs->execute([$cardId]);
            $groupId = (int)($gs->fetchColumn() ?: 0);
            if (!$groupId) {
                $pdo->prepare("INSERT INTO tasks_checklists (card_id, title, sort_order) VALUES (?, 'Чек-лист', 0)")
                    ->execute([$cardId]);
                $groupId = (int)$pdo->lastInsertId();
            }
        } else {
            // Проверка: группа принадлежит этой карточке
            $chk = $pdo->prepare("SELECT 1 FROM tasks_checklists WHERE id = ? AND card_id = ?");
            $chk->execute([$groupId, $cardId]);
            if (!$chk->fetchColumn()) tRespond(['error' => 'Группа не принадлежит карточке'], 400);
        }
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_checklist WHERE checklist_id = ?");
        $s->execute([$groupId]);
        $so = (int)$s->fetchColumn();
        $pdo->prepare("INSERT INTO tasks_checklist (card_id, checklist_id, title, sort_order) VALUES (?, ?, ?, ?)")
            ->execute([$cardId, $groupId, mb_substr($title, 0, 255), $so]);
        tRespond(['id' => (int)$pdo->lastInsertId(), 'checklist_id' => $groupId]);
    }
}

// ─── CHECKLIST GROUPS (несколько чек-листов на карточке) ───
if ($action === 'cards' && $id && $action2 === 'checklists') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);

    if ($method === 'GET') {
        $s = $pdo->prepare("SELECT id, title, sort_order FROM tasks_checklists WHERE card_id = ? ORDER BY sort_order, id");
        $s->execute([$cardId]);
        tRespond(['groups' => $s->fetchAll()]);
    }
    if ($method === 'POST') {
        $title = trim($body['title'] ?? 'Чек-лист');
        if ($title === '') $title = 'Чек-лист';
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_checklists WHERE card_id = ?");
        $s->execute([$cardId]);
        $so = (int)$s->fetchColumn();
        $pdo->prepare("INSERT INTO tasks_checklists (card_id, title, sort_order) VALUES (?, ?, ?)")
            ->execute([$cardId, mb_substr($title, 0, 255), $so]);
        tRespond(['id' => (int)$pdo->lastInsertId(), 'title' => mb_substr($title, 0, 255), 'sort_order' => $so]);
    }
}

if ($action === 'checklists' && $id) {
    $groupId = (int)$id;
    $s = $pdo->prepare("SELECT cg.*, b.owner_name FROM tasks_checklists cg JOIN tasks_cards c ON c.id = cg.card_id JOIN tasks_boards b ON b.id = c.board_id WHERE cg.id = ?");
    $s->execute([$groupId]);
    $group = $s->fetch();
    if (!$group) tRespond(['error' => 'Группа не найдена'], 404);
    $board = ['id' => null, 'owner_name' => $group['owner_name']];
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'PATCH') {
        $sets = []; $params = [];
        if (isset($body['title']))      { $sets[] = 'title = ?';      $params[] = mb_substr(trim($body['title']) ?: 'Чек-лист', 0, 255); }
        if (isset($body['sort_order'])) { $sets[] = 'sort_order = ?'; $params[] = (int)$body['sort_order']; }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $groupId;
        $pdo->prepare("UPDATE tasks_checklists SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        // FK ON DELETE CASCADE сам уберёт пункты группы
        $pdo->prepare("DELETE FROM tasks_checklists WHERE id = ?")->execute([$groupId]);
        tRespond(['success' => true]);
    }
}

if ($action === 'checklist' && $id) {
    $itemId = (int)$id;
    $s = $pdo->prepare("SELECT chk.*, b.owner_name FROM tasks_checklist chk JOIN tasks_cards c ON c.id = chk.card_id JOIN tasks_boards b ON b.id = c.board_id WHERE chk.id = ?");
    $s->execute([$itemId]);
    $item = $s->fetch();
    if (!$item) tRespond(['error' => 'Пункт не найден'], 404);
    $board = ['id' => null, 'owner_name' => $item['owner_name']];
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'PATCH') {
        $sets = []; $params = [];
        if (isset($body['title']))   { $sets[] = 'title = ?';   $params[] = mb_substr(trim($body['title']), 0, 255); }
        if (isset($body['is_done'])) { $sets[] = 'is_done = ?'; $params[] = $body['is_done'] ? 1 : 0; }
        if (!$sets) tRespond(['error' => 'Нет полей для обновления'], 400);
        $params[] = $itemId;
        $pdo->prepare("UPDATE tasks_checklist SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM tasks_checklist WHERE id = ?")->execute([$itemId]);
        tRespond(['success' => true]);
    }
}

// ─── COMMENTS ───
if ($action === 'cards' && $id && $action2 === 'comments') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'GET') {
        $s = $pdo->prepare("SELECT * FROM tasks_comments WHERE card_id = ? ORDER BY created_at, id");
        $s->execute([$cardId]);
        tRespond(['items' => $s->fetchAll()]);
    }
    if ($method === 'POST') {
        $body_text = trim($body['body'] ?? '');
        if ($body_text === '') tRespond(['error' => 'body обязателен'], 400);
        $pdo->prepare("INSERT INTO tasks_comments (card_id, author_name, body) VALUES (?, ?, ?)")
            ->execute([$cardId, $tUserName, mb_substr($body_text, 0, 5000)]);
        tHistory($pdo, $cardId, $tUserName, 'comment', ['preview' => mb_substr($body_text, 0, 80)]);
        // Уведомление владельцу доски и соисполнителям (кроме себя)
        $targets = [$card['owner_name']];
        $a = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ?");
        $a->execute([$cardId]);
        foreach ($a->fetchAll() as $r) $targets[] = $r['user_name'];
        $targets = array_unique(array_filter($targets, fn($n) => $n !== $tUserName));
        foreach ($targets as $t) tNotify($pdo, $t, 'Новый комментарий', $tUserName . ': ' . mb_substr($body_text, 0, 100), $cardId);
        tRespond(['id' => (int)$pdo->lastInsertId()]);
    }
}

if ($action === 'comments' && $id) {
    $cmtId = (int)$id;
    $s = $pdo->prepare("SELECT cmt.*, c.board_id, b.owner_name FROM tasks_comments cmt JOIN tasks_cards c ON c.id = cmt.card_id JOIN tasks_boards b ON b.id = c.board_id WHERE cmt.id = ?");
    $s->execute([$cmtId]);
    $cmt = $s->fetch();
    if (!$cmt) tRespond(['error' => 'Комментарий не найден'], 404);
    // Редактировать/удалять может: автор или admin
    $isAuthor = $cmt['author_name'] === $tUserName;
    $isAdmin = ($tUser['role'] ?? '') === 'admin';
    if (!$isAuthor && !$isAdmin) tRespond(['error' => 'Нет прав'], 403);
    if ($method === 'PATCH') {
        $body_text = trim($body['body'] ?? '');
        if ($body_text === '') tRespond(['error' => 'body обязателен'], 400);
        $pdo->prepare("UPDATE tasks_comments SET body = ?, edited_at = NOW() WHERE id = ?")
            ->execute([mb_substr($body_text, 0, 5000), $cmtId]);
        tRespond(['success' => true]);
    }
    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM tasks_comments WHERE id = ?")->execute([$cmtId]);
        tRespond(['success' => true]);
    }
}

// ─── HISTORY (только GET) ───
if ($action === 'cards' && $id && $action2 === 'history' && $method === 'GET') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет доступа'], 403);
    $s = $pdo->prepare("SELECT id, user_name, action, details, created_at FROM tasks_history WHERE card_id = ? ORDER BY created_at DESC, id DESC LIMIT 200");
    $s->execute([$cardId]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        if ($r['details']) $r['details'] = json_decode($r['details'], true);
    }
    tRespond(['items' => $rows]);
}

// ─── ASSIGNEES (POST tasks/cards/:id/assignees) ───
if ($action === 'cards' && $id && $action2 === 'assignees' && $method === 'POST') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanEditBoard($tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $names = array_values(array_unique(array_filter(array_map('strval', $body['user_names'] ?? []))));
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ?");
        $s->execute([$cardId]);
        $current = array_column($s->fetchAll(), 'user_name');
        $added   = array_values(array_diff($names, $current));
        $removed = array_values(array_diff($current, $names));

        // Сохраняем уже существующие назначения вместе с их column_id, sort_order
        // и is_done (иначе при каждом «save assignees» сбрасывался бы статус
        // и положение чужой карточки на доске исполнителя).
        if ($removed) {
            $ph = implode(',', array_fill(0, count($removed), '?'));
            $pdo->prepare("DELETE FROM tasks_assignees WHERE card_id = ? AND user_name IN ($ph)")
                ->execute(array_merge([$cardId], $removed));
        }
        // Новых добавляем с column_id = первая обычная колонка их основной
        // доски — чтобы карточка сразу легла в нужное место. Если своей
        // доски нет — column_id NULL, бэк при GET положит в первую колонку.
        if ($added) {
            $boardSt = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
            $colSt   = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND (is_archive_column = 0 OR is_archive_column IS NULL) ORDER BY sort_order, id LIMIT 1");
            $insSt   = $pdo->prepare("INSERT INTO tasks_assignees (card_id, user_name, column_id, sort_order, is_done) VALUES (?, ?, ?, 0, 0)");
            foreach ($added as $u) {
                $boardSt->execute([$u]);
                $bId = $boardSt->fetchColumn();
                $colId = null;
                if ($bId) { $colSt->execute([(int)$bId]); $colId = $colSt->fetchColumn() ?: null; }
                $insSt->execute([$cardId, $u, $colId]);
            }
        }
        tHistory($pdo, $cardId, $tUserName, 'assignees_changed', ['user_names' => $names]);
        $pdo->commit();
        foreach ($added as $u) {
            if ($u !== $tUserName) tNotify($pdo, $u, 'Вас добавили в задачу', $card['title'], $cardId);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => $e->getMessage()], 500);
    }
    tCheckCardAutoState($pdo, $cardId, $tUserName);
    tRespond(['success' => true]);
}

// ─── RELATIONS (связи с сущностями) ───
if ($action === 'cards' && $id && $action2 === 'relations' && $method === 'POST') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $rels = $body['relations'] ?? [];
    $allowedTypes = ['order','supplier','product','pricing','plan','so_order','protocol'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tasks_relations WHERE card_id = ?")->execute([$cardId]);
        if (is_array($rels)) {
            $ins = $pdo->prepare("INSERT IGNORE INTO tasks_relations (card_id, entity_type, entity_id, entity_label) VALUES (?, ?, ?, ?)");
            foreach ($rels as $r) {
                $type = $r['entity_type'] ?? '';
                if (!in_array($type, $allowedTypes)) continue;
                $eid = (string)($r['entity_id'] ?? '');
                if ($eid === '') continue;
                // Для связи card-card проверяем, что карточка существует
                if ($type === 'card') {
                    $chk = $pdo->prepare("SELECT id FROM tasks_cards WHERE id = ? LIMIT 1");
                    $chk->execute([(int)$eid]);
                    if (!$chk->fetch()) {
                        $pdo->rollBack();
                        tRespond(['error' => 'Карточка не найдена: ' . $eid], 400);
                    }
                }
                $label = isset($r['entity_label']) ? mb_substr((string)$r['entity_label'], 0, 255) : null;
                $ins->execute([$cardId, $type, mb_substr($eid, 0, 64), $label]);
            }
        }
        tHistory($pdo, $cardId, $tUserName, 'relations_changed', ['relations' => $rels]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => $e->getMessage()], 500);
    }
    tRespond(['success' => true]);
}

if ($action === 'relations' && $id && $method === 'DELETE') {
    $relId = (int)$id;
    $s = $pdo->prepare("SELECT r.*, c.board_id, b.owner_name FROM tasks_relations r JOIN tasks_cards c ON c.id = r.card_id JOIN tasks_boards b ON b.id = c.board_id WHERE r.id = ?");
    $s->execute([$relId]);
    $rel = $s->fetch();
    if (!$rel) tRespond(['error' => 'Связь не найдена'], 404);
    $board = tGetBoard($pdo, $rel['board_id']);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет прав'], 403);
    $pdo->prepare("DELETE FROM tasks_relations WHERE id = ?")->execute([$relId]);
    tRespond(['success' => true]);
}

// Если не попали ни в один маршрут — возвращаем 404
tRespond(['error' => 'Не найдено: tasks/' . $action], 404);
