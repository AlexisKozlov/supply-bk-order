<?php
/**
 * RPC: баг-репорты («Сообщить об ошибке»).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn (определены в rpc.php).
 *
 * Звонит сюда как закупка (через staff-сессию), так и ресторан (через
 * ro_session cookie — список разрешённых функций см. RO_ALLOWED_RPC в rpc.php).
 */

// ═══ Баг-репорты: создать ═══
// Универсальный caller для баг-репорта: закупка ИЛИ ресторан.
// Возвращает ['name' => ..., 'role' => 'admin'|'user', 'legal_entity' => ?].
// Для ресторана name = 'ro:<номер>', role = 'user' — отдельной адмки нет,
// в /admin?tab=feedback закупка видит все обращения как админ.
$bugReportCaller = function() use ($pdo) {
    $supply = getSessionUser($pdo);
    if ($supply) {
        return [
            'name' => $supply['name'] ?? 'unknown',
            'role' => $supply['role'] ?? 'user',
            'legal_entity' => '',
        ];
    }
    if (!function_exists('roGetRestaurantSession')) {
        require_once __DIR__ . '/../restaurant_orders.php';
    }
    $ro = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
    if ($ro) {
        $group = $ro['legal_entity_group'] ?? 'BK_VM';
        $le = $group === 'PS' ? 'ООО "Пицца Стар"' : 'ООО "Бургер БК"';
        return [
            'name' => 'ro:' . ($ro['restaurant_number'] ?? ''),
            'role' => 'user',
            'legal_entity' => $le,
        ];
    }
    return null;
};

if ($fn === 'create_bug_report') {
    $caller = $bugReportCaller();
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
    $title = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $screenshots = $body['screenshots'] ?? [];
    $actionLog = trim($body['action_log'] ?? '');
    $pageUrl = trim($body['page_url'] ?? '');
    // Юрлицо: у ресторана подставляем из сессии (фронту его передавать нечем),
    // у закупки уважаем то, что прислал фронт.
    $le = $caller['legal_entity'] !== '' ? $caller['legal_entity'] : ($body['legal_entity'] ?? '');
    $browserInfo = trim($body['browser_info'] ?? '');
    if (!$title) respond(['error' => 'Укажите тему сообщения'], 400);
    $stmt = $pdo->prepare("INSERT INTO bug_reports (title, description, screenshots, action_log, page_url, created_by, legal_entity, browser_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$title, $description, json_encode($screenshots), $actionLog, $pageUrl, $caller['name'], $le, $browserInfo]);
    $id = $pdo->lastInsertId();
    respond(['success' => true, 'id' => intval($id)]);
}

// ═══ Баг-репорты: список (для админа — все, для юзера — свои) ═══
if ($fn === 'get_bug_reports') {
    $caller = $bugReportCaller();
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
    $isAdmin = ($caller['role'] ?? '') === 'admin';
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT br.*, (SELECT COUNT(*) FROM bug_report_replies WHERE report_id=br.id) as reply_count FROM bug_reports br ORDER BY FIELD(br.status,'new','in_progress','resolved','closed'), br.created_at DESC");
        $s->execute();
    } else {
        $s = $pdo->prepare("SELECT br.*, (SELECT COUNT(*) FROM bug_report_replies WHERE report_id=br.id) as reply_count FROM bug_reports br WHERE br.created_by=? ORDER BY br.created_at DESC");
        $s->execute([$caller['name']]);
    }
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['screenshots'] = json_decode($r['screenshots'] ?: '[]', true);
    }
    respond(['reports' => $rows]);
}

// ═══ Баг-репорты: получить один с ответами ═══
if ($fn === 'get_bug_report') {
    $caller = $bugReportCaller();
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
    $id = intval($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан ID'], 400);
    $isAdmin = ($caller['role'] ?? '') === 'admin';
    $s = $pdo->prepare("SELECT * FROM bug_reports WHERE id=?"); $s->execute([$id]);
    $report = $s->fetch();
    if (!$report) respond(['error' => 'Не найдено'], 404);
    if (!$isAdmin && $report['created_by'] !== $caller['name']) respond(['error' => 'Нет доступа'], 403);
    $report['screenshots'] = json_decode($report['screenshots'] ?: '[]', true);
    $rs = $pdo->prepare("SELECT * FROM bug_report_replies WHERE report_id=? ORDER BY created_at ASC"); $rs->execute([$id]);
    $replies = $rs->fetchAll();
    respond(['report' => $report, 'replies' => $replies]);
}

// ═══ Баг-репорты: ответить ═══
if ($fn === 'reply_bug_report') {
    $caller = $bugReportCaller();
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
    $reportId = intval($body['report_id'] ?? 0);
    $message = trim($body['message'] ?? '');
    if (!$reportId || !$message) respond(['error' => 'Укажите ID и сообщение'], 400);
    $s = $pdo->prepare("SELECT * FROM bug_reports WHERE id=?"); $s->execute([$reportId]);
    $report = $s->fetch();
    if (!$report) respond(['error' => 'Не найдено'], 404);
    $isAdmin = ($caller['role'] ?? '') === 'admin';
    if (!$isAdmin && $report['created_by'] !== $caller['name']) respond(['error' => 'Нет доступа'], 403);
    $pdo->prepare("INSERT INTO bug_report_replies (report_id, message, created_by, is_admin) VALUES (?, ?, ?, ?)")
        ->execute([$reportId, $message, $caller['name'], $isAdmin ? 1 : 0]);
    // Если админ ответил — статус «в работе»
    if ($isAdmin && $report['status'] === 'new') {
        $pdo->prepare("UPDATE bug_reports SET status='in_progress' WHERE id=?")->execute([$reportId]);
    }
    // Push ресторану — если ответил админ И обращение от ресторана (created_by вида 'ro:NUM').
    if ($isAdmin && function_exists('pushSendToRestaurant') === false) {
        require_once __DIR__ . '/../push_send.php';
    }
    if ($isAdmin && preg_match('/^ro:(\d+)$/', (string)$report['created_by'], $rm)) {
        try {
            $roNum = (int)$rm[1];
            // Группу юрлиц ищем по номеру: если 1000+ — PS, иначе BK_VM (как в formatRestaurantNumber).
            $roGroup = $roNum >= 1000 ? 'PS' : 'BK_VM';
            $preview = mb_substr($message, 0, 80);
            pushSendToRestaurant($pdo, $roNum, $roGroup, [
                'title' => '💬 Ответ от закупки',
                'body'  => $preview . (mb_strlen($message) > 80 ? '…' : ''),
                'url'   => '/restaurant?bug=' . (int)$reportId,
                'tag'   => 'bug-reply-' . (int)$reportId,
            ]);
        } catch (Throwable $e) {
            error_log('[reply_bug_report] push failed: ' . $e->getMessage());
        }
    }
    respond(['success' => true]);
}

// ═══ Баг-репорты: сменить статус (только админ) ═══
if ($fn === 'update_bug_report_status') {
    $caller = getSessionUser($pdo);
    if (!$caller || ($caller['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
    $id = intval($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$id || !in_array($status, ['new','in_progress','resolved','closed'])) respond(['error' => 'Неверные параметры'], 400);
    $pdo->prepare("UPDATE bug_reports SET status=? WHERE id=?")->execute([$status, $id]);
    respond(['success' => true]);
}

// ═══ Баг-репорты: удалить (только админ) ═══
if ($fn === 'delete_bug_report') {
    $caller = getSessionUser($pdo);
    if (!$caller || ($caller['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
    $id = intval($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан ID'], 400);
    // Удалить скриншоты с диска
    $s = $pdo->prepare("SELECT screenshots FROM bug_reports WHERE id=?"); $s->execute([$id]);
    $row = $s->fetch();
    if ($row && $row['screenshots']) {
        $paths = json_decode($row['screenshots'], true) ?: [];
        foreach ($paths as $p) {
            $fp = __DIR__ . '/../../uploads/bugs/' . basename($p);
            if (file_exists($fp)) @unlink($fp);
        }
    }
    $pdo->prepare("DELETE FROM bug_reports WHERE id=?")->execute([$id]);
    respond(['success' => true]);
}

// ═══ Баг-репорты: количество новых (для бейджа админа) ═══
if ($fn === 'get_bug_reports_count') {
    $caller = $bugReportCaller();
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
    $isAdmin = ($caller['role'] ?? '') === 'admin';
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM bug_reports WHERE status IN ('new','in_progress')"); $s->execute();
        $count = intval($s->fetchColumn());
        $newCount = intval($pdo->query("SELECT COUNT(*) FROM bug_reports WHERE status='new'")->fetchColumn());
    } else {
        // Для обычного пользователя: количество непрочитанных ответов админа
        $s = $pdo->prepare("SELECT COUNT(DISTINCT br.id) FROM bug_reports br JOIN bug_report_replies brr ON brr.report_id=br.id WHERE br.created_by=? AND brr.is_admin=1 AND brr.created_at > br.updated_at");
        $s->execute([$caller['name']]);
        $count = intval($s->fetchColumn());
        $newCount = $count;
    }
    respond(['count' => $count, 'new_count' => $newCount]);
}
