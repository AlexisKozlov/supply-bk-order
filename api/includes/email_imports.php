<?php
/**
 * API модуля «Импорт по email».
 * Подключается из api/index.php. Переменные ($pdo, $endpoint, $subpoint,
 * $method, $body, $parts) — через global.
 *
 * Что делает модуль:
 *   - Очередь писем, скачанных cron-ом cron_email_import.php
 *     (таблица email_imports). Закупщик в админке видит письма,
 *     открывает их через /import (страница импорта по url ?ei=ID).
 *   - Whitelist отправителей (email_import_senders) — управляется
 *     только админом.
 *
 * Маршруты:
 *   GET    email-imports                       — список писем
 *   POST   email-imports/:id/dismiss           — { note? } — пометить пропущенным
 *   POST   email-imports/:id/applied           — { applied_count? } — после импорта
 *   POST   email-imports/:id/file-token        — выдать download-токен
 *
 *   GET    email-imports/senders               — whitelist (только admin)
 *   POST   email-imports/senders               — upsert sender (admin)
 *   DELETE email-imports/senders/:id           — delete sender (admin)
 */

if (!isset($endpoint) || $endpoint !== 'email-imports') return;

require_once __DIR__ . '/helpers.php';

if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
$eiUser = getSessionUser($pdo);
if (!$eiUser) respond(['error' => 'Требуется авторизация'], 401);
$eiUserName = $eiUser['name'];
$eiIsAdmin = ($eiUser['role'] ?? '') === 'admin';

global $ROLE_TEMPLATES, $ACCESS_LEVELS;
$eiPerms = resolvePermissions($eiUser['role'] ?? 'user', $eiUser['permissions'] ?? null, $ROLE_TEMPLATES);

function eiRequireSalesEdit($eiIsAdmin, $eiPerms, $ACCESS_LEVELS, $forLevel = 'edit') {
    if ($eiIsAdmin) return;
    $have = $ACCESS_LEVELS[$eiPerms['restaurant-sales'] ?? 'none'] ?? 0;
    $need = $ACCESS_LEVELS[$forLevel] ?? 0;
    if ($have < $need) respond(['error' => 'Недостаточно прав'], 403);
}

function eiRequireAdmin($eiIsAdmin) {
    if (!$eiIsAdmin) respond(['error' => 'Только для администратора'], 403);
}

// URL формата /api/email-imports/{first}/{second}
//   first === null  → list
//   first === 'senders'   → CRUD whitelist (second = id для DELETE)
//   first === '<digit>'   → действия над письмом (second = dismiss/applied/file-token)
$first  = $subpoint;
$second = isset($parts[2]) ? urldecode($parts[2]) : null;

// ─── GET email-imports — список писем ───
if ($method === 'GET' && ($first === null || $first === '')) {
    eiRequireSalesEdit($eiIsAdmin, $eiPerms, $ACCESS_LEVELS, 'view');

    $allowedStatus = ['pending', 'applied', 'dismissed', 'rejected', 'error'];
    $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $where = '';
    $args = [];
    if ($status && in_array($status, $allowedStatus, true)) {
        $where = "WHERE status = ?";
        $args[] = $status;
    }
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

    $s = $pdo->prepare("
        SELECT id, message_id, from_email, from_name, subject, received_at,
               type, legal_entity, file_name, file_path, size_bytes, status,
               applied_by, applied_at, applied_count, notes, created_at
        FROM email_imports
        $where
        ORDER BY received_at DESC, id DESC
        LIMIT $limit
    ");
    $s->execute($args);
    $rows = $s->fetchAll();

    $counts = $pdo->query("SELECT status, COUNT(*) AS cnt FROM email_imports GROUP BY status")->fetchAll();
    $countsByStatus = [];
    foreach ($counts as $r) $countsByStatus[$r['status']] = (int)$r['cnt'];

    respond(['items' => $rows, 'counts' => $countsByStatus]);
}

// ─── GET email-imports/senders — whitelist ───
if ($method === 'GET' && $first === 'senders') {
    eiRequireAdmin($eiIsAdmin);
    $rows = $pdo->query("SELECT id, email, type, legal_entity, is_active, note, created_by, created_at
                         FROM email_import_senders ORDER BY email")->fetchAll();
    respond(['items' => $rows]);
}

// ─── POST email-imports/senders — upsert ───
if ($method === 'POST' && $first === 'senders' && !$second) {
    eiRequireAdmin($eiIsAdmin);
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $type  = trim((string)($body['type'] ?? 'restaurant_sales'));
    $le    = trim((string)($body['legal_entity'] ?? ''));
    $isActive = !empty($body['is_active']) ? 1 : 0;
    $note  = trim((string)($body['note'] ?? ''));
    $id    = isset($body['id']) ? (int)$body['id'] : 0;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Некорректный email'], 400);
    if (!in_array($type, ['restaurant_sales', 'stock_1c', 'analysis', 'shelf_life'], true)) respond(['error' => 'Неизвестный тип'], 400);

    if ($id > 0) {
        $upd = $pdo->prepare("UPDATE email_import_senders SET email=?, type=?, legal_entity=?, is_active=?, note=? WHERE id=?");
        $upd->execute([$email, $type, $le ?: null, $isActive, $note ?: null, $id]);
    } else {
        try {
            $ins = $pdo->prepare("INSERT INTO email_import_senders (email, type, legal_entity, is_active, note, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$email, $type, $le ?: null, $isActive, $note ?: null, $eiUserName]);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) respond(['error' => 'Такой email уже в списке'], 409);
            throw $e;
        }
    }
    respond(['success' => true]);
}

// ─── DELETE email-imports/senders/:id ───
if ($method === 'DELETE' && $first === 'senders' && $second) {
    eiRequireAdmin($eiIsAdmin);
    $id = (int)$second;
    if ($id <= 0) respond(['error' => 'Некорректный id'], 400);
    $pdo->prepare("DELETE FROM email_import_senders WHERE id=?")->execute([$id]);
    respond(['success' => true]);
}

// ─── Действия над одной записью: dismiss / applied / file-token ───
if ($first && ctype_digit($first) && $method === 'POST') {
    $id = (int)$first;
    $row = $pdo->prepare("SELECT * FROM email_imports WHERE id = ? LIMIT 1");
    $row->execute([$id]);
    $ei = $row->fetch();
    if (!$ei) respond(['error' => 'Запись не найдена'], 404);

    // dismiss
    if ($second === 'dismiss') {
        eiRequireSalesEdit($eiIsAdmin, $eiPerms, $ACCESS_LEVELS, 'edit');
        if (!in_array($ei['status'], ['pending'], true)) respond(['error' => 'Можно отклонять только pending'], 409);
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        $upd = $pdo->prepare("UPDATE email_imports SET status='dismissed', applied_by=?, applied_at=NOW(), notes=? WHERE id=?");
        $upd->execute([$eiUserName, $note ?: 'dismissed by user', $id]);
        respond(['success' => true]);
    }

    // applied
    if ($second === 'applied') {
        eiRequireSalesEdit($eiIsAdmin, $eiPerms, $ACCESS_LEVELS, 'edit');
        if (!in_array($ei['status'], ['pending'], true)) respond(['error' => 'Можно применять только pending'], 409);
        $cnt = isset($body['applied_count']) ? max(0, (int)$body['applied_count']) : null;
        $upd = $pdo->prepare("UPDATE email_imports SET status='applied', applied_by=?, applied_at=NOW(), applied_count=? WHERE id=?");
        $upd->execute([$eiUserName, $cnt, $id]);
        respond(['success' => true]);
    }

    // file-token — выдать download-токен на скачивание файла
    if ($second === 'file-token') {
        eiRequireSalesEdit($eiIsAdmin, $eiPerms, $ACCESS_LEVELS, 'view');
        if (!$ei['file_path']) respond(['error' => 'У письма нет файла'], 404);
        try { $pdo->prepare("DELETE FROM download_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY")->execute(); } catch (Throwable $e) {}
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO download_tokens (token, user_name, file_path, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 15 MINUTE)")
            ->execute([$token, $eiUserName, $ei['file_path']]);
        respond([
            'token' => $token,
            'url' => '/api/' . $ei['file_path'] . '?dl=' . $token,
            'file_name' => $ei['file_name'],
            'expires_in' => 15 * 60,
        ]);
    }
}

respond(['error' => 'Неизвестный маршрут email-imports'], 404);
