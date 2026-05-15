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

// Файл подключают и из api/index.php (для роутинга /tasks/...), и из cron-скриптов
// (нужны только хелперы taskPushNotif / tCardRecipients и т.п.).
// Проверка $endpoint срабатывает позже — после объявлений функций, перед роутингом.

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

// Может ли пользователь читать карточку (открыть, увидеть комментарии, написать комментарий).
// Шире, чем tCanWorkWithBoard: даёт доступ соисполнителю даже если карточка лежит на чужой доске.
function tCanAccessCard($pdo, $u, $cardId, $board) {
    if (tCanWorkWithBoard($pdo, $u, $board)) return true;
    $s = $pdo->prepare("SELECT 1 FROM tasks_assignees WHERE card_id = ? AND user_name = ? LIMIT 1");
    $s->execute([(int)$cardId, $u['name']]);
    return (bool)$s->fetchColumn();
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

// Расширенное уведомление модуля задач (этапы 1-3):
// - кладёт запись в tasks_notifications
// - если у получателя привязан telegram_chat_id — сразу шлёт в Telegram
//   (используем готовую sendMessage из bot_rest.php)
function taskPushNotif($pdo, $toUser, $type, $cardId, $boardId, $sourceUser, $extra = []) {
    if (!$toUser || $toUser === $sourceUser) return; // себе не шлём
    try {
        $payload = json_encode($extra, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO tasks_notifications (user_name, source_user, card_id, board_id, type, payload)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$toUser, $sourceUser, $cardId ?: null, $boardId ?: null, $type, $payload]);
    } catch (Exception $e) {
        error_log('[tasks] taskPushNotif insert error: ' . $e->getMessage());
        return;
    }
    // Telegram (best-effort, не валим запрос если что-то пошло не так)
    try {
        $chat = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE name = ? LIMIT 1");
        $chat->execute([$toUser]);
        $chatId = $chat->fetchColumn();
        if (!$chatId) return;

        if (!function_exists('sendMessage')) require_once __DIR__ . '/bot_rest.php';
        if (!function_exists('sendMessage')) return;

        $title = $extra['card_title'] ?? '';
        $board = $extra['board_title'] ?? '';
        // Название доски берём по фактическому board_id уведомления,
        // чтобы соисполнитель видел СВОЮ доску, а не доску автора.
        if ($boardId) {
            try {
                $bt = $pdo->prepare("SELECT title FROM tasks_boards WHERE id = ? LIMIT 1");
                $bt->execute([$boardId]);
                $btv = $bt->fetchColumn();
                if ($btv !== false && $btv !== null) $board = (string)$btv;
            } catch (Exception $e) { /* оставляем board из $extra */ }
        }
        $by    = $sourceUser ? htmlspecialchars($sourceUser, ENT_QUOTES, 'UTF-8') : 'Кто-то';
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $boardEsc = htmlspecialchars($board, ENT_QUOTES, 'UTF-8');
        $boardLine = $board ? "\n📋 <i>" . $boardEsc . "</i>" : '';

        $msg = '';
        switch ($type) {
            case 'assigned':
                $msg = "🔔 <b>{$by}</b> назначил(а) вас на задачу:\n«<b>{$titleEsc}</b>»{$boardLine}"; break;
            case 'comment':
                $preview = htmlspecialchars(mb_substr((string)($extra['preview'] ?? ''), 0, 200), ENT_QUOTES, 'UTF-8');
                $msg = "💬 <b>{$by}</b> в «<b>{$titleEsc}</b>»:{$boardLine}\n<i>{$preview}</i>"; break;
            case 'closed':
                $msg = "✅ <b>{$by}</b> закрыл(а) задачу «<b>{$titleEsc}</b>»{$boardLine}"; break;
            case 'reopened':
                $msg = "↩️ <b>{$by}</b> вернул(а) в работу задачу «<b>{$titleEsc}</b>»{$boardLine}"; break;
            case 'due_changed':
                $due = $extra['due_date'] ? date('d.m.Y H:i', strtotime($extra['due_date'])) : 'снят';
                $msg = "🗓 Срок задачи «<b>{$titleEsc}</b>» {$due}{$boardLine}"; break;
            case 'mention':
                $msg = "👤 <b>{$by}</b> упомянул(а) вас в «<b>{$titleEsc}</b>»{$boardLine}"; break;
            case 'due_soon':
                $due = $extra['due_date'] ? date('d.m.Y', strtotime($extra['due_date'])) : '';
                $msg = "🟡 Завтра срок задачи «<b>{$titleEsc}</b>»" . ($due ? " ({$due})" : '') . $boardLine; break;
            case 'due_today':
                $msg = "🔴 Сегодня срок задачи «<b>{$titleEsc}</b>»{$boardLine}"; break;
            case 'overdue':
                $days = max(1, (int)($extra['overdue_days'] ?? 1));
                $word = ($days === 1) ? 'день' : (($days >= 2 && $days <= 4) ? 'дня' : 'дней');
                $msg = "⚠️ Просрочена задача «<b>{$titleEsc}</b>» на {$days} {$word}{$boardLine}"; break;
            default:
                $msg = "🔔 Событие по задаче «<b>{$titleEsc}</b>»{$boardLine}";
        }
        sendMessage($chatId, $msg);
    } catch (Exception $e) {
        error_log('[tasks] taskPushNotif tg error: ' . $e->getMessage());
    }
}

// Хелпер: список соисполнителей карточки (без указанных исключений)
function tCardRecipients($pdo, $cardId, array $exclude = []) {
    $card = $pdo->prepare("SELECT b.owner_name FROM tasks_cards c JOIN tasks_boards b ON b.id = c.board_id WHERE c.id = ?");
    $card->execute([$cardId]);
    $owner = (string)$card->fetchColumn();
    $a = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ?");
    $a->execute([$cardId]);
    $assignees = array_column($a->fetchAll(), 'user_name');
    $all = array_unique(array_filter(array_merge([$owner], $assignees)));
    return array_values(array_diff($all, $exclude));
}

// Доска, на которой задача отображается у конкретного получателя:
// для соисполнителя — его собственная доска (по его колонке в tasks_assignees),
// для владельца и для упомянутого вне задачи — доска-«дом» карточки ($fallbackBoardId).
function tBoardForRecipient($pdo, $cardId, $userName, $fallbackBoardId) {
    try {
        $s = $pdo->prepare(
            "SELECT col.board_id
               FROM tasks_assignees a
               JOIN tasks_columns col ON col.id = a.column_id
              WHERE a.card_id = ? AND a.user_name = ?
              LIMIT 1"
        );
        $s->execute([$cardId, $userName]);
        $b = $s->fetchColumn();
        if ($b) return (int)$b;
    } catch (Exception $e) {
        error_log('[tasks] tBoardForRecipient error: ' . $e->getMessage());
    }
    return (int)$fallbackBoardId;
}

// ─── Хелперы таймера карточки (C4) ───

// Останавливает бегущие таймеры на карточке (когда задача закрыта/в архиве —
// учёт времени логично прекратить). $onlyUser !== null — закрываем только
// запись этого пользователя, иначе все. Идемпотентно: если бегущих нет —
// ничего не делает. Возвращает кол-во остановленных записей.
function tStopCardTimers($pdo, $cardId, $actor, $onlyUser = null) {
    $sql  = "SELECT id, card_id FROM tasks_card_time WHERE card_id = ? AND stopped_at IS NULL";
    $args = [$cardId];
    if ($onlyUser !== null) { $sql .= " AND user_name = ?"; $args[] = $onlyUser; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    if (!$rows) return 0;
    $close = $pdo->prepare("UPDATE tasks_card_time SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?");
    foreach ($rows as $r) {
        $close->execute([(int)$r['id']]);
        tHistory($pdo, $cardId, $actor, 'timer_stopped', ['auto' => true, 'reason' => 'archived']);
    }
    return count($rows);
}

// Запускает таймер пользователя на карточке (например, при возврате задачи
// из архива в работу). Соблюдает правило «один бегущий таймер на человека»:
// прочие открытые записи закрываются. Идемпотентно: если таймер на этой
// карточке уже бежит — ничего не делает.
function tStartCardTimer($pdo, $cardId, $userName) {
    $chk = $pdo->prepare("SELECT 1 FROM tasks_card_time WHERE card_id = ? AND user_name = ? AND stopped_at IS NULL LIMIT 1");
    $chk->execute([$cardId, $userName]);
    if ($chk->fetchColumn()) return false;
    $open = $pdo->prepare("SELECT id, card_id FROM tasks_card_time WHERE user_name = ? AND stopped_at IS NULL");
    $open->execute([$userName]);
    $close = $pdo->prepare("UPDATE tasks_card_time SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?");
    foreach ($open->fetchAll() as $row) {
        $close->execute([(int)$row['id']]);
        tHistory($pdo, (int)$row['card_id'], $userName, 'timer_stopped', ['auto' => true]);
    }
    $pdo->prepare("INSERT INTO tasks_card_time (card_id, user_name, started_at) VALUES (?, ?, NOW())")
        ->execute([$cardId, $userName]);
    tHistory($pdo, $cardId, $userName, 'timer_started', ['auto' => true, 'reason' => 'reopened']);
    return true;
}

// Возвращает { seconds_total, by_user: [{user_name, seconds}], my_running: { started_at } | null,
//              any_running: bool, running_user: ?string }
function tBuildCardTimer($pdo, $cardId, $tUserName) {
    // Суммы закрытых интервалов по пользователям
    $s = $pdo->prepare("SELECT user_name, COALESCE(SUM(seconds), 0) AS sec FROM tasks_card_time WHERE card_id = ? AND stopped_at IS NOT NULL GROUP BY user_name ORDER BY sec DESC");
    $s->execute([$cardId]);
    $byUser = [];
    $total  = 0;
    foreach ($s->fetchAll() as $r) {
        $sec = (int)$r['sec'];
        $byUser[] = ['user_name' => $r['user_name'], 'seconds' => $sec];
        $total += $sec;
    }
    // Открытый интервал у текущего пользователя
    $r = $pdo->prepare("SELECT id, started_at FROM tasks_card_time WHERE card_id = ? AND user_name = ? AND stopped_at IS NULL ORDER BY id DESC LIMIT 1");
    $r->execute([$cardId, $tUserName]);
    $myRow = $r->fetch();
    // Открытый интервал у кого угодно — чтобы канбан показывал «у кого-то идёт»
    $r2 = $pdo->prepare("SELECT user_name FROM tasks_card_time WHERE card_id = ? AND stopped_at IS NULL ORDER BY id DESC LIMIT 1");
    $r2->execute([$cardId]);
    $running = $r2->fetchColumn();
    return [
        'seconds_total' => $total,
        'by_user'       => $byUser,
        'my_running'    => $myRow ? ['id' => (int)$myRow['id'], 'started_at' => $myRow['started_at']] : null,
        'any_running'   => $running !== false && $running !== null,
        'running_user'  => $running !== false && $running !== null ? (string)$running : null,
    ];
}

// Сводка таймеров по массиву карточек (для канбан-доски).
// Возвращает массив [card_id => ['seconds_total' => int, 'any_running' => bool,
//                                 'my_running' => bool, 'running_started_at' => ?string]]
// running_started_at — самый ранний открытый интервал по карточке (для тиканья на канбане).
function tBuildCardsTimerSummary($pdo, array $cardIds, $tUserName) {
    if (!$cardIds) return [];
    $ph = implode(',', array_fill(0, count($cardIds), '?'));
    $s = $pdo->prepare("SELECT card_id, COALESCE(SUM(seconds), 0) AS sec FROM tasks_card_time WHERE card_id IN ($ph) AND stopped_at IS NOT NULL GROUP BY card_id");
    $s->execute($cardIds);
    $sumByCard = [];
    foreach ($s->fetchAll() as $r) $sumByCard[(int)$r['card_id']] = (int)$r['sec'];

    $s = $pdo->prepare("SELECT card_id, user_name, started_at FROM tasks_card_time WHERE card_id IN ($ph) AND stopped_at IS NULL");
    $s->execute($cardIds);
    $runByCard = [];
    $myRunByCard = [];
    $startedByCard = [];
    foreach ($s->fetchAll() as $r) {
        $cid = (int)$r['card_id'];
        $runByCard[$cid] = true;
        if ((string)$r['user_name'] === (string)$tUserName) $myRunByCard[$cid] = true;
        // Самый ранний открытый интервал по карточке
        if (!isset($startedByCard[$cid]) || $r['started_at'] < $startedByCard[$cid]) {
            $startedByCard[$cid] = $r['started_at'];
        }
    }

    $out = [];
    foreach ($cardIds as $cid) {
        $cid = (int)$cid;
        if (!isset($sumByCard[$cid]) && !isset($runByCard[$cid])) continue;
        $out[$cid] = [
            'seconds_total'      => $sumByCard[$cid] ?? 0,
            'any_running'        => !empty($runByCard[$cid]),
            'my_running'         => !empty($myRunByCard[$cid]),
            'running_started_at' => $startedByCard[$cid] ?? null,
        ];
    }
    return $out;
}

// ─── Хелперы повторяющихся задач (этап 6) ───

// Считает следующую дату срабатывания строго ПОСЛЕ $fromDate (YYYY-MM-DD).
// Используется и при сохранении расписания, и в cron после успешного создания.
function tCalcNextRunDate($kind, $weekday, $dayOfMonth, $fromDate) {
    $tz = new DateTimeZone('Europe/Minsk');
    $from = DateTime::createFromFormat('Y-m-d', $fromDate, $tz);
    if (!$from) $from = new DateTime('now', $tz);
    if ($kind === 'daily') {
        $from->modify('+1 day');
        return $from->format('Y-m-d');
    }
    if ($kind === 'weekly') {
        $w = max(1, min(7, (int)$weekday));
        // PHP: 1=Mon..7=Sun (формат N) — совпадает с нашим хранением.
        $cur = (int)$from->format('N');
        $diff = $w - $cur;
        if ($diff <= 0) $diff += 7;
        $from->modify("+{$diff} day");
        return $from->format('Y-m-d');
    }
    if ($kind === 'monthly') {
        $d = max(1, min(31, (int)$dayOfMonth));
        $from->modify('+1 day'); // строго после fromDate
        // Ищем ближайший день месяца. Если в текущем месяце он уже прошёл —
        // переходим на следующий, и так пока не найдём подходящий.
        while (true) {
            $lastDay = (int)$from->format('t');
            $target = min($d, $lastDay);
            $curDay = (int)$from->format('j');
            if ($curDay <= $target) {
                $from->setDate(
                    (int)$from->format('Y'),
                    (int)$from->format('n'),
                    $target
                );
                return $from->format('Y-m-d');
            }
            // Перескакиваем на 1-е следующего месяца.
            $from->modify('first day of next month');
        }
    }
    return $from->format('Y-m-d');
}

// Атомарно создаёт карточку из шаблона+расписания со всем содержимым.
// Возвращает id новой карточки, либо null при ошибке (с лог-записью).
// Источник sourceUser=null → уведомления 'assigned' рассылает «от системы».
function tCreateCardFromTemplate($pdo, $templateId, $schedule, $ownerName) {
    // Подгружаем шаблон + связанные сущности
    $t = $pdo->prepare("SELECT * FROM tasks_card_templates WHERE id = ?");
    $t->execute([(int)$templateId]);
    $tpl = $t->fetch();
    if (!$tpl) return null;

    $a = $pdo->prepare("SELECT user_name FROM tasks_template_assignees WHERE template_id = ?");
    $a->execute([(int)$templateId]);
    $assignees = array_column($a->fetchAll(), 'user_name');

    $c = $pdo->prepare("SELECT title, sort_order FROM tasks_template_checklist WHERE template_id = ? ORDER BY sort_order, id");
    $c->execute([(int)$templateId]);
    $checklist = $c->fetchAll();

    $l = $pdo->prepare("SELECT label_id FROM tasks_template_schedule_labels WHERE schedule_id = ?");
    $l->execute([(int)$schedule['id']]);
    $labels = array_column($l->fetchAll(), 'label_id');

    $boardId  = (int)$schedule['target_board_id'];
    $columnId = (int)$schedule['target_column_id'];
    $due = null;
    if ((int)$schedule['due_offset_days'] >= 0) {
        $tz = new DateTimeZone('Europe/Minsk');
        $dueDt = new DateTime('now', $tz);
        $dueDt->modify('+' . (int)$schedule['due_offset_days'] . ' day');
        $dueDt->setTime(23, 59, 59);
        $due = $dueDt->format('Y-m-d H:i:s');
    }

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id IS NULL");
        $s->execute([$columnId]);
        $so = (int)$s->fetchColumn();

        $pdo->prepare("INSERT INTO tasks_cards (board_id, parent_card_id, column_id, title, description, priority, due_date, sort_order, created_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $boardId,
                $columnId,
                mb_substr($tpl['title'], 0, 255),
                $tpl['description'],
                $tpl['priority'],
                $due,
                $so,
                $ownerName,
            ]);
        $cardId = (int)$pdo->lastInsertId();

        // Ассайни: для каждого находим его первую неархивную доску и колонку
        // (как в /tasks/cards/:id/assignees) — чтобы карточка сразу легла к нему.
        if ($assignees) {
            $boardSt = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
            $colSt   = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND (is_archive_column = 0 OR is_archive_column IS NULL) ORDER BY sort_order, id LIMIT 1");
            $insSt   = $pdo->prepare("INSERT INTO tasks_assignees (card_id, user_name, column_id, sort_order, is_done) VALUES (?, ?, ?, 0, 0)");
            foreach ($assignees as $u) {
                $boardSt->execute([$u]);
                $bId = $boardSt->fetchColumn();
                $colId = null;
                if ($bId) { $colSt->execute([(int)$bId]); $colId = $colSt->fetchColumn() ?: null; }
                $insSt->execute([$cardId, $u, $colId]);
            }
        }

        // Метки: только те, что реально принадлежат целевой доске
        // (фильтр на случай если за время существования расписания что-то
        // удалилось каскадом и осталось в schedule_labels рассогласованным).
        if ($labels) {
            $ph = implode(',', array_fill(0, count($labels), '?'));
            $validSt = $pdo->prepare("SELECT id FROM tasks_labels WHERE board_id = ? AND id IN ($ph)");
            $validSt->execute(array_merge([$boardId], $labels));
            $valid = array_column($validSt->fetchAll(), 'id');
            if ($valid) {
                $insL = $pdo->prepare("INSERT IGNORE INTO tasks_card_labels (card_id, label_id) VALUES (?, ?)");
                foreach ($valid as $lid) $insL->execute([$cardId, (int)$lid]);
            }
        }

        // Чек-лист (без групп — кладём в дефолтную)
        if ($checklist) {
            $insC = $pdo->prepare("INSERT INTO tasks_checklist (card_id, title, sort_order) VALUES (?, ?, ?)");
            foreach ($checklist as $i => $item) {
                $insC->execute([$cardId, mb_substr($item['title'], 0, 255), (int)$item['sort_order']]);
            }
        }

        tHistory($pdo, $cardId, $ownerName, 'created', [
            'title' => $tpl['title'],
            'from_template' => (int)$templateId,
            'schedule_id' => (int)$schedule['id'],
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[tasks] tCreateCardFromTemplate error: ' . $e->getMessage());
        return null;
    }

    // Уведомления исполнителям (assigned) — best-effort, вне транзакции
    $extra = [
        'card_title'  => $tpl['title'],
        'board_title' => '',
    ];
    $b = $pdo->prepare("SELECT title FROM tasks_boards WHERE id = ?");
    $b->execute([$boardId]);
    $extra['board_title'] = (string)$b->fetchColumn();
    foreach ($assignees as $u) {
        if ($u && $u !== $ownerName) {
            taskPushNotif($pdo, $u, 'assigned', $cardId, tBoardForRecipient($pdo, $cardId, $u, $boardId), $ownerName, $extra);
        }
    }
    return $cardId;
}

// Парсер @упоминаний.
// Регулярка ловит «@<имя>» где имя — буквы/цифры/подчёркивания.
// При выборе из поповера фронт заменяет пробелы на «_»; здесь обратно.
function taskParseMentions($pdo, $text) {
    if (!$text) return [];
    $re = '/(?:^|[\s,.!?;:(\[])@([A-Za-zА-Яа-яЁё0-9_]+)/u';
    if (!preg_match_all($re, (string)$text, $m)) return [];
    $candidates = array_unique(array_map(fn($n) => str_replace('_', ' ', $n), $m[1]));
    if (!$candidates) return [];
    $ph = implode(',', array_fill(0, count($candidates), '?'));
    $s = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
    $s->execute($candidates);
    return array_column($s->fetchAll(), 'name');
}

// Здесь начинается роутинг: вне контекста /tasks/... файл просто отдал свои хелперы.
if (!isset($endpoint) || $endpoint !== 'tasks') return;

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

// ─── GET tasks/notifications — мои последние уведомления + счётчик непрочитанных ───
if ($method === 'GET' && $action === 'notifications') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    $s = $pdo->prepare("
        SELECT n.id, n.type, n.source_user, n.card_id, n.board_id, n.payload, n.is_read, n.created_at,
               c.title AS card_title,
               b.title AS board_title
        FROM tasks_notifications n
        LEFT JOIN tasks_cards  c ON c.id = n.card_id
        LEFT JOIN tasks_boards b ON b.id = n.board_id
        WHERE n.user_name = ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT $limit
    ");
    $s->execute([$tUserName]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        if ($r['payload']) $r['payload'] = json_decode($r['payload'], true);
        $r['is_read'] = (int)$r['is_read'];
    }
    $u = $pdo->prepare("SELECT COUNT(*) FROM tasks_notifications WHERE user_name = ? AND is_read = 0");
    $u->execute([$tUserName]);
    $unread = (int)$u->fetchColumn();
    tRespond(['items' => $rows, 'unread' => $unread]);
}

// ─── POST tasks/notifications/mark-read — пометить прочитанными ───
if ($method === 'POST' && $action === 'notifications' && $id === 'mark-read') {
    $ids = $body['ids'] ?? null;
    $all = !empty($body['all']);
    if ($all) {
        $pdo->prepare("UPDATE tasks_notifications SET is_read = 1, read_at = NOW() WHERE user_name = ? AND is_read = 0")
            ->execute([$tUserName]);
        tRespond(['success' => true]);
    }
    if (is_array($ids) && count($ids)) {
        $intIds = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($intIds), '?'));
        $sql = "UPDATE tasks_notifications SET is_read = 1, read_at = NOW() WHERE user_name = ? AND id IN ($ph)";
        $pdo->prepare($sql)->execute(array_merge([$tUserName], $intIds));
        tRespond(['success' => true]);
    }
    tRespond(['error' => 'Нужен ids[] или all=true'], 400);
}

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
        // Расширенные настройки доски
        if (isset($body['auto_timer']))    { $sets[] = 'auto_timer = ?';    $params[] = $body['auto_timer'] ? 1 : 0; }
        if (isset($body['compact_cards'])) { $sets[] = 'compact_cards = ?'; $params[] = $body['compact_cards'] ? 1 : 0; }
        if (array_key_exists('default_priority', $body)) {
            $dp = in_array($body['default_priority'], ['low','medium','high','urgent'], true) ? $body['default_priority'] : null;
            $sets[] = 'default_priority = ?'; $params[] = $dp;
        }
        if (array_key_exists('default_assignee', $body)) {
            $da = trim((string)($body['default_assignee'] ?? ''));
            $sets[] = 'default_assignee = ?'; $params[] = $da !== '' ? mb_substr($da, 0, 100) : null;
        }
        if (array_key_exists('default_column_id', $body)) {
            $dc = (int)($body['default_column_id'] ?? 0);
            // Колонка должна принадлежать этой доске, иначе обнуляем
            if ($dc > 0) {
                $chk = $pdo->prepare("SELECT 1 FROM tasks_columns WHERE id = ? AND board_id = ?");
                $chk->execute([$dc, $boardId]);
                if (!$chk->fetchColumn()) $dc = 0;
            }
            $sets[] = 'default_column_id = ?'; $params[] = $dc > 0 ? $dc : null;
        }
        if (array_key_exists('accent_color', $body)) {
            $ac = trim((string)($body['accent_color'] ?? ''));
            $sets[] = 'accent_color = ?'; $params[] = $ac !== '' ? mb_substr($ac, 0, 20) : null;
        }
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

// ─── GET tasks/board/:id/time-report — сводка по времени (таймер) ───
// Должен идти ПЕРЕД роутом полной доски: тот не проверяет $action2.
if ($action === 'board' && $id && $action2 === 'time-report' && $method === 'GET') {
    $boardId = (int)$id;
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if (!tCanWorkWithBoard($pdo, $tUser, $board)) tRespond(['error' => 'Нет доступа'], 403);

    // Открытый интервал (stopped_at IS NULL) считаем до текущего момента.
    $secExpr = "COALESCE(t.seconds, TIMESTAMPDIFF(SECOND, t.started_at, NOW()))";

    $byUser = $pdo->prepare("
        SELECT t.user_name,
               SUM($secExpr) AS seconds,
               COUNT(DISTINCT t.card_id) AS cards
        FROM tasks_card_time t
        JOIN tasks_cards c ON c.id = t.card_id
        WHERE c.board_id = ?
        GROUP BY t.user_name
        ORDER BY seconds DESC");
    $byUser->execute([$boardId]);
    $usersR = [];
    $total = 0;
    foreach ($byUser->fetchAll() as $r) {
        $sec = (int)$r['seconds'];
        $total += $sec;
        $usersR[] = ['user_name' => $r['user_name'], 'seconds' => $sec, 'cards' => (int)$r['cards']];
    }

    $byCard = $pdo->prepare("
        SELECT c.id, c.title, c.is_archived,
               SUM($secExpr) AS seconds
        FROM tasks_card_time t
        JOIN tasks_cards c ON c.id = t.card_id
        WHERE c.board_id = ?
        GROUP BY c.id, c.title, c.is_archived
        ORDER BY seconds DESC");
    $byCard->execute([$boardId]);
    $cardsR = [];
    foreach ($byCard->fetchAll() as $r) {
        $cardsR[] = [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'is_archived' => (int)$r['is_archived'],
            'seconds'     => (int)$r['seconds'],
        ];
    }

    tRespond(['by_user' => $usersR, 'by_card' => $cardsR, 'total_seconds' => $total]);
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
        // Внешние карточки, где я соисполнитель — НО исключаем «протокольные»
        // копии, если у меня уже есть собственная карточка по тому же решению
        // (иначе на моей доске был бы дубль).
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
              AND NOT EXISTS (
                SELECT 1
                FROM protocol_decision_cards pdc1
                JOIN protocol_decision_cards pdc2 ON pdc2.decision_id = pdc1.decision_id AND pdc2.card_id != pdc1.card_id
                WHERE pdc1.card_id = c.id AND pdc2.user_name = ?
              )
        ");
        $s->execute([$tUserName, $boardId, $tUserName]);
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

        // Таймер: сумма + признак «у кого-то идёт» (для значка часов на канбан-карточке)
        $timerSummary = tBuildCardsTimerSummary($pdo, $ids, $tUserName);

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
            $c['timer']       = $timerSummary[$cid] ?? null;
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
        if (array_key_exists('wip_limit', $body)) { $sets[] = 'wip_limit = ?'; $params[] = $body['wip_limit'] && (int)$body['wip_limit'] > 0 ? (int)$body['wip_limit'] : null; }
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

        // Приоритет: явный из тела > приоритет доски по умолчанию (только для
        // корневых задач) > 'medium'.
        $validPri = ['low','medium','high','urgent'];
        $priority = in_array($body['priority'] ?? '', $validPri, true)
            ? $body['priority']
            : (!$parentId && in_array($board['default_priority'] ?? '', $validPri, true) ? $board['default_priority'] : 'medium');
        $due = !empty($body['due_date']) ? $body['due_date'] : null;
        $desc = isset($body['description']) ? mb_substr((string)$body['description'], 0, 5000) : null;
        $color = !empty($body['color']) ? mb_substr(trim((string)$body['color']), 0, 20) : null;
        $autoAssignee = null;
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id " . ($parentId ? "= ?" : "IS NULL"));
            $s->execute($parentId ? [$columnId, $parentId] : [$columnId]);
            $so = (int)$s->fetchColumn();
            $pdo->prepare("INSERT INTO tasks_cards (board_id, parent_card_id, column_id, title, description, priority, color, due_date, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$boardId, $parentId, $columnId, mb_substr($title, 0, 255), $desc, $priority, $color, $due, $so, $tUserName]);
            $cardId = (int)$pdo->lastInsertId();
            tHistory($pdo, $cardId, $tUserName, 'created', ['title' => $title, 'parent_card_id' => $parentId]);

            // ─── Настройки доски: применяем только к корневым задачам ───
            if (!$parentId) {
                // Исполнитель по умолчанию: добавляем в tasks_assignees.
                $da = trim((string)($board['default_assignee'] ?? ''));
                if ($da !== '') {
                    $boardSt = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
                    $boardSt->execute([$da]);
                    $bId = $boardSt->fetchColumn();
                    $aColId = null;
                    if ($bId) {
                        $colSt = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND (is_archive_column = 0 OR is_archive_column IS NULL) ORDER BY sort_order, id LIMIT 1");
                        $colSt->execute([(int)$bId]);
                        $aColId = $colSt->fetchColumn() ?: null;
                    }
                    $pdo->prepare("INSERT INTO tasks_assignees (card_id, user_name, column_id, sort_order, is_done) VALUES (?, ?, ?, 0, 0)")
                        ->execute([$cardId, $da, $aColId]);
                    tHistory($pdo, $cardId, $tUserName, 'assignees_changed', ['user_names' => [$da]]);
                    $autoAssignee = $da;
                }
                // Авто-таймер: запускаем таймер создателя на новой задаче.
                // Срабатывает только при ручном создании задачи (этот роут);
                // задачи из автоповторов создаются другим путём и таймер не
                // получают. У одного человека бежит лишь один таймер — поэтому
                // сначала закрываем все прочие открытые записи этого пользователя.
                if (!empty($board['auto_timer'])) {
                    $openT = $pdo->prepare("SELECT id, card_id FROM tasks_card_time WHERE user_name = ? AND stopped_at IS NULL");
                    $openT->execute([$tUserName]);
                    $closeT = $pdo->prepare("UPDATE tasks_card_time SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?");
                    foreach ($openT->fetchAll() as $row) {
                        $closeT->execute([(int)$row['id']]);
                        tHistory($pdo, (int)$row['card_id'], $tUserName, 'timer_stopped', ['auto' => true]);
                    }
                    $pdo->prepare("INSERT INTO tasks_card_time (card_id, user_name, started_at) VALUES (?, ?, NOW())")
                        ->execute([$cardId, $tUserName]);
                    tHistory($pdo, $cardId, $tUserName, 'timer_started', ['auto' => true]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            tRespond(['error' => 'Ошибка создания карточки'], 500);
        }
        // Уведомление исполнителю по умолчанию (после коммита, неблокирующе).
        if ($autoAssignee && $autoAssignee !== $tUserName) {
            taskPushNotif($pdo, $autoAssignee, 'assigned', $cardId,
                tBoardForRecipient($pdo, $cardId, $autoAssignee, $boardId), $tUserName,
                ['card_title' => $title, 'board_title' => $board['title'] ?? '']);
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
        $a = $pdo->prepare("SELECT is_done FROM tasks_assignees WHERE card_id = ? AND user_name = ?");
        $a->execute([$cardId, $tUserName]);
        $aRow = $a->fetch();
        if ($aRow === false) tRespond(['error' => 'Нет прав на эту карточку'], 403);
        $wasAssigneeDone = (int)($aRow['is_done'] ?? 0) === 1;
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
                // Соисполнитель закрыл свою часть — останавливаем его таймер на карточке.
                tStopCardTimers($pdo, $cardId, $tUserName, $tUserName);
            } else {
                // Возврат из архива в работу: сбрасываем «выполнил».
                $pdo->prepare("UPDATE tasks_assignees SET is_done = 0, done_at = NULL WHERE card_id = ? AND user_name = ? AND is_done = 1")
                    ->execute([$cardId, $tUserName]);
                tHistory($pdo, $cardId, $tUserName, 'assignee_moved', ['user' => $tUserName, 'to_column' => $toColumnId]);
                // Соисполнитель вернул свою копию из архива в работу — возобновляем его таймер.
                if ($wasAssigneeDone) tStartCardTimer($pdo, $cardId, $tUserName);
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

        // Задача закрыта (попала в done/архив) — останавливаем бегущие таймеры
        // на ней: учёт времени по выполненной задаче продолжаться не должен.
        if ((int)$card['is_done'] !== 1 && $newIsDone === 1) {
            tStopCardTimers($pdo, $cardId, $tUserName);
        }
        // Задача возвращена из done/архива в работу — возобновляем таймер.
        if ((int)$card['is_done'] === 1 && $newIsDone === 0) {
            tStartCardTimer($pdo, $cardId, $tUserName);
        }

        if ($fromCol !== $toColumnId) {
            $s = $pdo->prepare("SELECT id FROM tasks_cards WHERE column_id = ? ORDER BY sort_order, id");
            $s->execute([$fromCol]);
            $upd2 = $pdo->prepare("UPDATE tasks_cards SET sort_order = ? WHERE id = ?");
            foreach (array_column($s->fetchAll(), 'id') as $i => $iid) $upd2->execute([$i, (int)$iid]);
        }

        if ($isProto) tCheckCardAutoState($pdo, $cardId, $tUserName);

        $pdo->commit();

        // Уведомления: задача закрыта (попала в done/archive) или возвращена в работу
        $wasDone = (int)$card['is_done'] === 1;
        $nowDone = (int)$newIsDone === 1;
        if ($wasDone !== $nowDone) {
            $type = $nowDone ? 'closed' : 'reopened';
            $extra = ['card_title' => $card['title'], 'board_title' => $toBoard['title'] ?? ''];
            foreach (tCardRecipients($pdo, $cardId, [$tUserName]) as $t) {
                taskPushNotif($pdo, $t, $type, $cardId, tBoardForRecipient($pdo, $cardId, $t, $card['board_id']), $tUserName, $extra);
            }
        }
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
        if (!tCanAccessCard($pdo, $tUser, $cardId, $board)) tRespond(['error' => 'Нет доступа'], 403);

        // Для соисполнителя (не владельца доски-источника) подменяем колонку
        // на ЕГО колонку из tasks_assignees — иначе окно задачи не находит
        // колонку оригинала среди колонок доски соисполнителя и показывает «».
        if ($card['owner_name'] !== $tUserName) {
            $asg = $pdo->prepare("SELECT column_id FROM tasks_assignees WHERE card_id = ? AND user_name = ? LIMIT 1");
            $asg->execute([$cardId, $tUserName]);
            $asgCol = $asg->fetchColumn();
            if ($asgCol) $card['column_id'] = (int)$asgCol;
        }

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

        // Таймер: суммы по пользователям + открытый интервал
        $timer = tBuildCardTimer($pdo, $cardId, $tUserName);

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
            'timer'       => $timer,
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
        if (array_key_exists('color', $body)) {
            $newColor = $body['color'] ? mb_substr(trim((string)$body['color']), 0, 20) : null;
            if ($newColor !== $card['color']) $changes['color'] = ['from' => $card['color'], 'to' => $newColor];
            $sets[] = 'color = ?'; $params[] = $newColor;
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
        // Задача отмечена выполненной — останавливаем бегущие таймеры на ней.
        if (isset($body['is_done']) && $body['is_done'] && (int)$card['is_done'] !== 1) {
            tStopCardTimers($pdo, $cardId, $tUserName);
        }
        // Задача возвращена в работу (снята отметка «выполнено») — возобновляем таймер.
        if (isset($body['is_done']) && !$body['is_done'] && (int)$card['is_done'] === 1) {
            tStartCardTimer($pdo, $cardId, $tUserName);
        }
        if (isset($body['is_done']) && tIsProtocolCard($pdo, $cardId)) {
            tCheckCardAutoState($pdo, $cardId, $tUserName);
        }
        // Уведомления: смена дедлайна и закрытие/возврат через чекбокс
        $extraBase = ['card_title' => $card['title'], 'board_title' => $board['title'] ?? ''];
        // @упоминания в описании — только новые (которых не было в старом тексте)
        if (array_key_exists('description', $body)) {
            $oldM = taskParseMentions($pdo, (string)$card['description']);
            $newM = taskParseMentions($pdo, (string)$body['description']);
            $added = array_values(array_diff($newM, $oldM, [$tUserName]));
            $extraM = $extraBase + ['preview' => 'упомянул(а) вас в описании'];
            foreach ($added as $m) {
                taskPushNotif($pdo, $m, 'mention', $cardId, tBoardForRecipient($pdo, $cardId, $m, $card['board_id']), $tUserName, $extraM);
            }
        }
        if (isset($changes['due_date'])) {
            $extra = $extraBase + ['due_date' => $changes['due_date']['to']];
            foreach (tCardRecipients($pdo, $cardId, [$tUserName]) as $t) {
                taskPushNotif($pdo, $t, 'due_changed', $cardId, tBoardForRecipient($pdo, $cardId, $t, $card['board_id']), $tUserName, $extra);
            }
        }
        if (isset($body['is_done'])) {
            $wasDone = (int)$card['is_done'] === 1;
            $nowDone = $body['is_done'] ? true : false;
            if ($wasDone !== $nowDone) {
                $type = $nowDone ? 'closed' : 'reopened';
                foreach (tCardRecipients($pdo, $cardId, [$tUserName]) as $t) {
                    taskPushNotif($pdo, $t, $type, $cardId, tBoardForRecipient($pdo, $cardId, $t, $card['board_id']), $tUserName, $extraBase);
                }
            }
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
    if (!tCanAccessCard($pdo, $tUser, $cardId, $board)) tRespond(['error' => 'Нет прав'], 403);
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
        // @упоминания: точечные уведомления, не дублируются с обычным «comment»
        $mentions = taskParseMentions($pdo, $body_text);
        $mentionSet = array_flip($mentions);
        unset($mentionSet[$tUserName]);
        $extra = [
            'card_title'  => $card['title'],
            'board_title' => $board['title'] ?? '',
            'preview'     => mb_substr($body_text, 0, 200),
        ];
        foreach (array_keys($mentionSet) as $m) {
            taskPushNotif($pdo, $m, 'mention', $cardId, tBoardForRecipient($pdo, $cardId, $m, $card['board_id']), $tUserName, $extra);
        }
        // Уведомление автору карточки и соисполнителям (кроме себя и уже упомянутых)
        $targets = tCardRecipients($pdo, $cardId, array_merge([$tUserName], array_keys($mentionSet)));
        foreach ($targets as $t) taskPushNotif($pdo, $t, 'comment', $cardId, tBoardForRecipient($pdo, $cardId, $t, $card['board_id']), $tUserName, $extra);
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
    if (!tCanAccessCard($pdo, $tUser, $cardId, $board)) tRespond(['error' => 'Нет доступа'], 403);
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
        $extra = [
            'card_title'  => $card['title'],
            'board_title' => $board['title'] ?? '',
        ];
        foreach ($added as $u) {
            if ($u !== $tUserName) taskPushNotif($pdo, $u, 'assigned', $cardId, tBoardForRecipient($pdo, $cardId, $u, $card['board_id']), $tUserName, $extra);
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

// ─── TIMER (учёт времени работы по карточке, C4) ───
// POST /tasks/cards/:id/timer  body: { op: 'start' | 'stop' }
// GET  /tasks/cards/:id/timer  — отдать актуальное состояние (опционально, не используется UI)
if ($action === 'cards' && $id && $action2 === 'timer') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanAccessCard($pdo, $tUser, $cardId, $board)) tRespond(['error' => 'Нет доступа'], 403);

    if ($method === 'GET') {
        tRespond(['timer' => tBuildCardTimer($pdo, $cardId, $tUserName)]);
    }
    if ($method !== 'POST') tRespond(['error' => 'Method not allowed'], 405);

    $op = $body['op'] ?? '';
    if (!in_array($op, ['start', 'stop'], true)) tRespond(['error' => "op должно быть 'start' или 'stop'"], 400);

    $pdo->beginTransaction();
    try {
        // Лок открытой записи текущего пользователя на этой карточке
        $s = $pdo->prepare("SELECT id, started_at FROM tasks_card_time WHERE card_id = ? AND user_name = ? AND stopped_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $s->execute([$cardId, $tUserName]);
        $open = $s->fetch();

        if ($op === 'start') {
            if ($open) {
                // Уже бежит — идемпотентно отдаём текущее состояние
                $pdo->commit();
                tRespond(['timer' => tBuildCardTimer($pdo, $cardId, $tUserName)]);
            }
            // Закрываем все остальные открытые таймеры этого пользователя на других карточках
            // (чтобы у одного человека одновременно бежал только один таймер).
            // Время пишем через MySQL NOW() — PHP работает в UTC, а MySQL в +03:00,
            // фронт же парсит DATETIME как локальное; единый источник времени = MySQL.
            $other = $pdo->prepare("SELECT id, card_id, started_at FROM tasks_card_time WHERE user_name = ? AND stopped_at IS NULL FOR UPDATE");
            $other->execute([$tUserName]);
            $closeStmt = $pdo->prepare("UPDATE tasks_card_time SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?");
            $foundOnSameCard = false;
            foreach ($other->fetchAll() as $row) {
                if ((int)$row['card_id'] === $cardId) {
                    // Параллельный запрос уже создал открытую запись на этой же карточке
                    // (gap-lock с NULL в индексе не гарантирует уникальности) —
                    // не плодим дубль, возвращаем текущее состояние.
                    $foundOnSameCard = true;
                    continue;
                }
                $closeStmt->execute([(int)$row['id']]);
                tHistory($pdo, (int)$row['card_id'], $tUserName, 'timer_stopped', ['auto' => true]);
            }
            if ($foundOnSameCard) {
                $pdo->commit();
                tRespond(['timer' => tBuildCardTimer($pdo, $cardId, $tUserName)]);
            }
            $pdo->prepare("INSERT INTO tasks_card_time (card_id, user_name, started_at) VALUES (?, ?, NOW())")
                ->execute([$cardId, $tUserName]);
            tHistory($pdo, $cardId, $tUserName, 'timer_started', null);
        } else {
            // stop
            if (!$open) {
                $pdo->commit();
                tRespond(['timer' => tBuildCardTimer($pdo, $cardId, $tUserName)]);
            }
            $pdo->prepare("UPDATE tasks_card_time SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?")
                ->execute([(int)$open['id']]);
            // Длительность для истории
            $dur = $pdo->prepare("SELECT seconds FROM tasks_card_time WHERE id = ?");
            $dur->execute([(int)$open['id']]);
            $sec = (int)$dur->fetchColumn();
            tHistory($pdo, $cardId, $tUserName, 'timer_stopped', ['seconds' => $sec]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка таймера: ' . $e->getMessage()], 500);
    }
    tRespond(['timer' => tBuildCardTimer($pdo, $cardId, $tUserName)]);
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

// ═══════════════════════════════════════════════════════
// ПОВТОРЯЮЩИЕСЯ ЗАДАЧИ — ШАБЛОНЫ И РАСПИСАНИЯ
// ═══════════════════════════════════════════════════════

// Маленькие хелперы маршрутов
function tplLoadOwned($pdo, $tplId, $userName, $isAdmin) {
    $s = $pdo->prepare("SELECT * FROM tasks_card_templates WHERE id = ?");
    $s->execute([(int)$tplId]);
    $tpl = $s->fetch();
    if (!$tpl) tRespond(['error' => 'Шаблон не найден'], 404);
    if (!$isAdmin && $tpl['owner_name'] !== $userName) tRespond(['error' => 'Нет прав на чужой шаблон'], 403);
    return $tpl;
}

function tplScheduleLoadOwned($pdo, $schedId, $userName, $isAdmin) {
    $s = $pdo->prepare("SELECT sch.*, tpl.owner_name FROM tasks_template_schedules sch JOIN tasks_card_templates tpl ON tpl.id = sch.template_id WHERE sch.id = ?");
    $s->execute([(int)$schedId]);
    $row = $s->fetch();
    if (!$row) tRespond(['error' => 'Расписание не найдено'], 404);
    if (!$isAdmin && $row['owner_name'] !== $userName) tRespond(['error' => 'Нет прав'], 403);
    return $row;
}

// Валидирует пару (board, column) для расписания шаблона:
// 1) доска существует и не архивирована;
// 2) колонка относится к этой доске и НЕ архивная;
// 3) владелец шаблона имеет права работать с доской (а не текущий пользователь —
//    важно для случая, когда админ редактирует чужой шаблон).
// При ошибке валидации сам делает tRespond(...) и не возвращает управление.
function tplValidateTarget($pdo, $boardId, $columnId, $ownerName) {
    $board = tGetBoard($pdo, $boardId);
    if (!$board) tRespond(['error' => 'Доска не найдена'], 404);
    if ($board['is_archived']) tRespond(['error' => 'Доска в архиве'], 400);
    $owner = null;
    $ownU = $pdo->prepare("SELECT name, role FROM users WHERE name = ? LIMIT 1");
    $ownU->execute([$ownerName]);
    $owner = $ownU->fetch();
    if (!$owner || !tCanWorkWithBoard($pdo, $owner, $board)) {
        tRespond(['error' => 'У владельца шаблона нет прав на эту доску'], 403);
    }
    $col = tGetColumn($pdo, $columnId);
    if (!$col || (int)$col['board_id'] !== (int)$boardId) tRespond(['error' => 'Колонка не относится к доске'], 400);
    if (!empty($col['is_archive_column'])) tRespond(['error' => 'Нельзя направлять расписание в архивную колонку'], 400);
    return [$board, $col];
}

$isAdmin = ($tUser['role'] ?? '') === 'admin';

// ─── GET /tasks/templates — мои шаблоны (с краткой инфой по расписаниям) ───
if ($action === 'templates' && !$id && $method === 'GET') {
    $s = $pdo->prepare("SELECT id, title, priority, is_archived, created_at, updated_at FROM tasks_card_templates WHERE owner_name = ? AND is_archived = 0 ORDER BY updated_at DESC, id DESC");
    $s->execute([$tUserName]);
    $rows = $s->fetchAll();
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Кол-во расписаний и активных
        $sc = $pdo->prepare("SELECT template_id, COUNT(*) AS total, SUM(is_active) AS active FROM tasks_template_schedules WHERE template_id IN ($ph) GROUP BY template_id");
        $sc->execute($ids);
        $by = [];
        foreach ($sc->fetchAll() as $r) $by[(int)$r['template_id']] = $r;
        // Кол-во ассайни
        $ac = $pdo->prepare("SELECT template_id, COUNT(*) AS cnt FROM tasks_template_assignees WHERE template_id IN ($ph) GROUP BY template_id");
        $ac->execute($ids);
        $byA = [];
        foreach ($ac->fetchAll() as $r) $byA[(int)$r['template_id']] = (int)$r['cnt'];
        foreach ($rows as &$r) {
            $tid = (int)$r['id'];
            $r['schedules_total']  = isset($by[$tid]) ? (int)$by[$tid]['total']  : 0;
            $r['schedules_active'] = isset($by[$tid]) ? (int)$by[$tid]['active'] : 0;
            $r['assignees_count']  = $byA[$tid] ?? 0;
        }
    }
    tRespond(['items' => $rows]);
}

// ─── POST /tasks/templates — создать пустой шаблон ───
if ($action === 'templates' && !$id && $method === 'POST') {
    $title = trim($body['title'] ?? '');
    if ($title === '') tRespond(['error' => 'title обязателен'], 400);
    $priority = in_array($body['priority'] ?? '', ['low','medium','high','urgent']) ? $body['priority'] : 'medium';
    $desc = isset($body['description']) ? mb_substr((string)$body['description'], 0, 5000) : null;
    $pdo->prepare("INSERT INTO tasks_card_templates (owner_name, title, description, priority) VALUES (?, ?, ?, ?)")
        ->execute([$tUserName, mb_substr($title, 0, 255), $desc, $priority]);
    tRespond(['id' => (int)$pdo->lastInsertId()]);
}

// ─── GET /tasks/templates/:id — детальная карточка ───
if ($action === 'templates' && $id && !$action2 && $method === 'GET') {
    $tpl = tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $a = $pdo->prepare("SELECT user_name FROM tasks_template_assignees WHERE template_id = ?");
    $a->execute([(int)$id]);
    $tpl['assignees'] = array_column($a->fetchAll(), 'user_name');
    $c = $pdo->prepare("SELECT id, title, sort_order FROM tasks_template_checklist WHERE template_id = ? ORDER BY sort_order, id");
    $c->execute([(int)$id]);
    $tpl['checklist'] = $c->fetchAll();
    $sch = $pdo->prepare("SELECT * FROM tasks_template_schedules WHERE template_id = ? ORDER BY id");
    $sch->execute([(int)$id]);
    $schedules = $sch->fetchAll();
    if ($schedules) {
        $schedIds = array_column($schedules, 'id');
        $ph = implode(',', array_fill(0, count($schedIds), '?'));
        $ll = $pdo->prepare("SELECT schedule_id, label_id FROM tasks_template_schedule_labels WHERE schedule_id IN ($ph)");
        $ll->execute($schedIds);
        $labelsBySched = [];
        foreach ($ll->fetchAll() as $r) {
            $labelsBySched[(int)$r['schedule_id']][] = (int)$r['label_id'];
        }
        foreach ($schedules as &$s) {
            $s['label_ids'] = $labelsBySched[(int)$s['id']] ?? [];
        }
    }
    $tpl['schedules'] = $schedules;
    tRespond($tpl);
}

// ─── PATCH /tasks/templates/:id — обновить тело ───
if ($action === 'templates' && $id && !$action2 && $method === 'PATCH') {
    tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $sets = []; $params = [];
    if (array_key_exists('title', $body)) {
        $t = trim((string)$body['title']);
        if ($t === '') tRespond(['error' => 'title не может быть пустым'], 400);
        $sets[] = 'title = ?'; $params[] = mb_substr($t, 0, 255);
    }
    if (array_key_exists('description', $body)) {
        $sets[] = 'description = ?';
        $params[] = $body['description'] !== null ? mb_substr((string)$body['description'], 0, 5000) : null;
    }
    if (array_key_exists('priority', $body)) {
        if (!in_array($body['priority'], ['low','medium','high','urgent'])) tRespond(['error' => 'неверный priority'], 400);
        $sets[] = 'priority = ?'; $params[] = $body['priority'];
    }
    if (array_key_exists('is_archived', $body)) {
        $sets[] = 'is_archived = ?'; $params[] = $body['is_archived'] ? 1 : 0;
    }
    if (!$sets) tRespond(['success' => true]);
    $params[] = (int)$id;
    $pdo->prepare("UPDATE tasks_card_templates SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    tRespond(['success' => true]);
}

// ─── DELETE /tasks/templates/:id ───
if ($action === 'templates' && $id && !$action2 && $method === 'DELETE') {
    tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $pdo->prepare("DELETE FROM tasks_card_templates WHERE id = ?")->execute([(int)$id]);
    tRespond(['success' => true]);
}

// ─── POST /tasks/templates/:id/assignees — заменить состав ассайни ───
if ($action === 'templates' && $id && $action2 === 'assignees' && $method === 'POST') {
    tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $names = array_values(array_unique(array_filter(array_map('strval', $body['user_names'] ?? []))));
    if ($names) {
        $ph = implode(',', array_fill(0, count($names), '?'));
        $chk = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
        $chk->execute($names);
        $names = array_column($chk->fetchAll(), 'name');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tasks_template_assignees WHERE template_id = ?")->execute([(int)$id]);
        if ($names) {
            $ins = $pdo->prepare("INSERT INTO tasks_template_assignees (template_id, user_name) VALUES (?, ?)");
            foreach ($names as $u) $ins->execute([(int)$id, $u]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка сохранения исполнителей'], 500);
    }
    tRespond(['success' => true]);
}

// ─── POST /tasks/templates/:id/checklist — заменить весь чек-лист ───
if ($action === 'templates' && $id && $action2 === 'checklist' && $method === 'POST') {
    tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tasks_template_checklist WHERE template_id = ?")->execute([(int)$id]);
        $ins = $pdo->prepare("INSERT INTO tasks_template_checklist (template_id, title, sort_order) VALUES (?, ?, ?)");
        foreach ($items as $i => $it) {
            $t = trim((string)($it['title'] ?? ''));
            if ($t === '') continue;
            $ins->execute([(int)$id, mb_substr($t, 0, 255), $i]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка сохранения чек-листа'], 500);
    }
    tRespond(['success' => true]);
}

// ─── POST /tasks/templates/:id/schedules — создать расписание ───
if ($action === 'templates' && $id && $action2 === 'schedules' && $method === 'POST') {
    $tpl = tplLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $boardId  = (int)($body['target_board_id'] ?? 0);
    $columnId = (int)($body['target_column_id'] ?? 0);
    $kind     = $body['recurrence_kind'] ?? '';
    if (!in_array($kind, ['daily','weekly','monthly'])) tRespond(['error' => 'recurrence_kind должен быть daily/weekly/monthly'], 400);
    $weekday  = $kind === 'weekly' ? max(1, min(7, (int)($body['weekday'] ?? 1))) : null;
    $dayOfM   = $kind === 'monthly' ? max(1, min(31, (int)($body['day_of_month'] ?? 1))) : null;
    $leadD    = max(0, (int)($body['lead_days'] ?? 0));
    $dueOff   = max(0, (int)($body['due_offset_days'] ?? 0));
    if (!$boardId || !$columnId) tRespond(['error' => 'target_board_id и target_column_id обязательны'], 400);

    // Проверяем права ВЛАДЕЛЬЦА шаблона (важно когда админ редактирует чужой)
    // и валидируем целевую колонку (не архивная, относится к доске).
    [$board, $col] = tplValidateTarget($pdo, $boardId, $columnId, $tpl['owner_name']);

    $tz = new DateTimeZone('Europe/Minsk');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $next = tCalcNextRunDate($kind, $weekday, $dayOfM, $today);

    $labels = is_array($body['label_ids'] ?? null) ? array_map('intval', $body['label_ids']) : [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tasks_template_schedules (template_id, target_board_id, target_column_id, recurrence_kind, weekday, day_of_month, lead_days, due_offset_days, next_run_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute([(int)$id, $boardId, $columnId, $kind, $weekday, $dayOfM, $leadD, $dueOff, $next]);
        $schedId = (int)$pdo->lastInsertId();
        if ($labels) {
            // Только метки целевой доски
            $ph = implode(',', array_fill(0, count($labels), '?'));
            $vl = $pdo->prepare("SELECT id FROM tasks_labels WHERE board_id = ? AND id IN ($ph)");
            $vl->execute(array_merge([$boardId], $labels));
            $valid = array_column($vl->fetchAll(), 'id');
            if ($valid) {
                $ins = $pdo->prepare("INSERT IGNORE INTO tasks_template_schedule_labels (schedule_id, label_id) VALUES (?, ?)");
                foreach ($valid as $lid) $ins->execute([$schedId, (int)$lid]);
            }
        }
        $pdo->commit();
        tRespond(['id' => $schedId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка создания расписания'], 500);
    }
}

// ─── PATCH /tasks/template-schedules/:id ───
if ($action === 'template-schedules' && $id && !$action2 && $method === 'PATCH') {
    $sch = tplScheduleLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $sets = []; $params = [];
    $needRecalc = false;
    $kind = $sch['recurrence_kind'];
    $weekday = $sch['weekday'];
    $dayOfM = $sch['day_of_month'];

    if (array_key_exists('recurrence_kind', $body)) {
        if (!in_array($body['recurrence_kind'], ['daily','weekly','monthly'])) tRespond(['error' => 'неверный recurrence_kind'], 400);
        $kind = $body['recurrence_kind'];
        $sets[] = 'recurrence_kind = ?'; $params[] = $kind;
        $needRecalc = true;
    }
    if (array_key_exists('weekday', $body)) {
        $weekday = $body['weekday'] !== null ? max(1, min(7, (int)$body['weekday'])) : null;
        $sets[] = 'weekday = ?'; $params[] = $weekday;
        $needRecalc = true;
    }
    if (array_key_exists('day_of_month', $body)) {
        $dayOfM = $body['day_of_month'] !== null ? max(1, min(31, (int)$body['day_of_month'])) : null;
        $sets[] = 'day_of_month = ?'; $params[] = $dayOfM;
        $needRecalc = true;
    }
    if (array_key_exists('lead_days', $body))       { $sets[] = 'lead_days = ?';       $params[] = max(0, (int)$body['lead_days']); }
    if (array_key_exists('due_offset_days', $body)) { $sets[] = 'due_offset_days = ?'; $params[] = max(0, (int)$body['due_offset_days']); }
    if (array_key_exists('is_active', $body)) {
        $sets[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0;
        if ($body['is_active']) { $sets[] = 'deactivated_reason = NULL'; }
    }
    if (array_key_exists('target_board_id', $body) || array_key_exists('target_column_id', $body)) {
        $newBoard  = (int)($body['target_board_id']  ?? $sch['target_board_id']);
        $newColumn = (int)($body['target_column_id'] ?? $sch['target_column_id']);
        // Проверяем права ВЛАДЕЛЬЦА (важно когда админ редактирует чужой шаблон)
        // + не архивная доска + не архивная колонка.
        tplValidateTarget($pdo, $newBoard, $newColumn, $sch['owner_name']);
        $sets[] = 'target_board_id = ?';  $params[] = $newBoard;
        $sets[] = 'target_column_id = ?'; $params[] = $newColumn;
        // Метки чужой доски — удалить (каскадом нет; чистим вручную)
        if ($newBoard !== (int)$sch['target_board_id']) {
            $pdo->prepare("DELETE FROM tasks_template_schedule_labels WHERE schedule_id = ?")->execute([(int)$id]);
        }
    }
    if ($needRecalc) {
        $tz = new DateTimeZone('Europe/Minsk');
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $next = tCalcNextRunDate($kind, $weekday, $dayOfM, $today);
        $sets[] = 'next_run_date = ?'; $params[] = $next;
        $sets[] = 'last_run_date = NULL';
    }

    // Метки — отдельный массив, замещает существующие
    if (array_key_exists('label_ids', $body)) {
        $newLabels = is_array($body['label_ids']) ? array_map('intval', $body['label_ids']) : [];
        $boardId = (int)($body['target_board_id'] ?? $sch['target_board_id']);
        $pdo->beginTransaction();
        try {
            if ($sets) {
                $params[] = (int)$id;
                $pdo->prepare("UPDATE tasks_template_schedules SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            }
            $pdo->prepare("DELETE FROM tasks_template_schedule_labels WHERE schedule_id = ?")->execute([(int)$id]);
            if ($newLabels) {
                $ph = implode(',', array_fill(0, count($newLabels), '?'));
                $vl = $pdo->prepare("SELECT id FROM tasks_labels WHERE board_id = ? AND id IN ($ph)");
                $vl->execute(array_merge([$boardId], $newLabels));
                $valid = array_column($vl->fetchAll(), 'id');
                if ($valid) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO tasks_template_schedule_labels (schedule_id, label_id) VALUES (?, ?)");
                    foreach ($valid as $lid) $ins->execute([(int)$id, (int)$lid]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            tRespond(['error' => 'Ошибка обновления расписания'], 500);
        }
        tRespond(['success' => true]);
    }

    if (!$sets) tRespond(['success' => true]);
    $params[] = (int)$id;
    $pdo->prepare("UPDATE tasks_template_schedules SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    tRespond(['success' => true]);
}

// ─── DELETE /tasks/template-schedules/:id ───
if ($action === 'template-schedules' && $id && !$action2 && $method === 'DELETE') {
    tplScheduleLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $pdo->prepare("DELETE FROM tasks_template_schedules WHERE id = ?")->execute([(int)$id]);
    tRespond(['success' => true]);
}

// ─── GET /tasks/template-schedules/:id/preview — следующие 5 дат ───
if ($action === 'template-schedules' && $id && $action2 === 'preview' && $method === 'GET') {
    $sch = tplScheduleLoadOwned($pdo, $id, $tUserName, $isAdmin);
    $dates = [];
    $cur = $sch['next_run_date'] ?: (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('Y-m-d');
    // Первая дата = next_run_date. Дальше — серия.
    $tz = new DateTimeZone('Europe/Minsk');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    if ($cur < $today) $cur = $today; // на всякий случай
    $dates[] = $cur;
    for ($i = 1; $i < 5; $i++) {
        $cur = tCalcNextRunDate($sch['recurrence_kind'], $sch['weekday'], $sch['day_of_month'], $cur);
        $dates[] = $cur;
    }
    tRespond(['dates' => $dates]);
}

// ─── POST /tasks/template-schedules/:id/run-now — создать карточку немедленно ───
if ($action === 'template-schedules' && $id && $action2 === 'run-now' && $method === 'POST') {
    $sch = tplScheduleLoadOwned($pdo, $id, $tUserName, $isAdmin);
    if (!$sch['is_active']) tRespond(['error' => 'Расписание не активно'], 400);
    // Проверка доступа: владелец шаблона должен иметь право на доску
    $board = tGetBoard($pdo, $sch['target_board_id']);
    if (!$board) tRespond(['error' => 'Целевая доска не найдена'], 404);
    // Загружаем профиль владельца, чтобы проверить tCanWorkWithBoard (для не-админа)
    $ownU = $pdo->prepare("SELECT name, role FROM users WHERE name = ?");
    $ownU->execute([$sch['owner_name']]);
    $owner = $ownU->fetch();
    if (!$owner || !tCanWorkWithBoard($pdo, $owner, $board)) {
        $pdo->prepare("UPDATE tasks_template_schedules SET is_active = 0, deactivated_reason = 'no_access' WHERE id = ?")->execute([(int)$sch['id']]);
        tRespond(['error' => 'У владельца нет доступа к доске. Расписание деактивировано.'], 403);
    }
    $cardId = tCreateCardFromTemplate($pdo, (int)$sch['template_id'], $sch, $sch['owner_name']);
    if (!$cardId) tRespond(['error' => 'Не удалось создать карточку'], 500);
    tRespond(['card_id' => $cardId]);
}

// ─── POST /tasks/cards/:id/save-as-template — превратить карточку в шаблон ───
if ($action === 'cards' && $id && $action2 === 'save-as-template' && $method === 'POST') {
    $cardId = (int)$id;
    $card = tGetCard($pdo, $cardId);
    if (!$card) tRespond(['error' => 'Карточка не найдена'], 404);
    $board = tGetBoard($pdo, $card['board_id']);
    if (!tCanAccessCard($pdo, $tUser, $cardId, $board)) tRespond(['error' => 'Нет доступа к карточке'], 403);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tasks_card_templates (owner_name, title, description, priority) VALUES (?, ?, ?, ?)")
            ->execute([$tUserName, mb_substr($card['title'], 0, 255), $card['description'], $card['priority']]);
        $tplId = (int)$pdo->lastInsertId();

        // Ассайни
        $a = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ?");
        $a->execute([$cardId]);
        $names = array_column($a->fetchAll(), 'user_name');
        if ($names) {
            $insA = $pdo->prepare("INSERT INTO tasks_template_assignees (template_id, user_name) VALUES (?, ?)");
            foreach ($names as $u) $insA->execute([$tplId, $u]);
        }

        // Чек-лист (плоский, без групп)
        $c = $pdo->prepare("SELECT title, sort_order FROM tasks_checklist WHERE card_id = ? ORDER BY sort_order, id");
        $c->execute([$cardId]);
        $items = $c->fetchAll();
        if ($items) {
            $insC = $pdo->prepare("INSERT INTO tasks_template_checklist (template_id, title, sort_order) VALUES (?, ?, ?)");
            foreach ($items as $i => $it) $insC->execute([$tplId, $it['title'], (int)$it['sort_order']]);
        }

        $pdo->commit();
        tRespond(['template_id' => $tplId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        tRespond(['error' => 'Ошибка сохранения шаблона'], 500);
    }
}

// Если не попали ни в один маршрут — возвращаем 404
tRespond(['error' => 'Не найдено: tasks/' . $action], 404);
