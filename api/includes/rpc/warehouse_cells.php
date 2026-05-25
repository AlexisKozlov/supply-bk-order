<?php
/**
 * RPC: складские ячейки и аннотации.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'save_warehouse_cells') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) respond(['error' => 'Нет данных'], 400);
        try {
            // Определяем дату загружаемого файла
            $uploadDate = $items[0]['report_date'] ?? '';
            if (!$uploadDate) respond(['error' => 'Нет даты в данных'], 400);

            // Для каждого юрлица проверяем: не старше ли загружаемая дата максимальной в базе
            $entities = array_unique(array_column($items, 'legal_entity'));
            // Не-админ имеет право грузить только в свои юрлица
            foreach ($entities as $entity) {
                if (!checkLegalEntityAccess($authUser, $entity)) {
                    respond(['error' => "Нет доступа к юр. лицу: {$entity}"], 403);
                }
            }
            $skippedEntities = [];
            foreach ($entities as $entity) {
                $maxSt = $pdo->prepare("SELECT MAX(report_date) FROM warehouse_cells WHERE legal_entity = ?");
                $maxSt->execute([$entity]);
                $maxDate = $maxSt->fetchColumn();
                if ($maxDate && $uploadDate < $maxDate) {
                    $skippedEntities[] = $entity;
                }
            }

            // Записываем только данные для юрлиц, где загружаемая дата >= максимальной
            $inserted = 0;
            $st = $pdo->prepare("INSERT INTO warehouse_cells (report_date, legal_entity, stock_type, cell_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE cell_count = VALUES(cell_count)");
            foreach ($items as $item) {
                if (in_array($item['legal_entity'], $skippedEntities)) continue;
                $st->execute([$item['report_date'], $item['legal_entity'], $item['stock_type'], intval($item['cell_count'])]);
                $inserted++;
            }
            $msg = $inserted > 0 ? 'success' : 'skipped';
            respond(['success' => true, 'count' => $inserted, 'skipped' => count($skippedEntities) > 0 ? $skippedEntities : null]);
        } catch (PDOException $e) {
            error_log("save_warehouse_cells error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    if ($fn === 'get_warehouse_cells_range') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $entity = $body['entity'] ?? '';
        $from = $body['date_from'] ?? '';
        $to = $body['date_to'] ?? '';
        if (!$entity || !$from || !$to) respond(['error' => 'Не указаны обязательные параметры'], 400);
        if (!checkLegalEntityAccess($authUser, $entity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Расширяем диапазон на +3 дня чтобы захватить понедельник для последних выходных месяца
        $st = $pdo->prepare("SELECT report_date, stock_type, cell_count, is_manual FROM warehouse_cells WHERE legal_entity=? AND report_date >= ? AND report_date <= DATE_ADD(?, INTERVAL 3 DAY) AND stock_type IN ('cold','frozen') ORDER BY report_date, stock_type");
        $st->execute([$entity, $from, $to]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ═══ Аналитика ячеек склада (страница /shelf-life/analytics) ═══
    // Возвращает дневные данные за выбранный период, фронт сам агрегирует
    // по неделям/месяцам в зависимости от выбранной гранулярности.
    if ($fn === 'cell_analytics_get') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $start = trim((string)($body['start'] ?? ''));
        $end   = trim((string)($body['end'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            respond(['error' => 'Укажите корректный диапазон дат'], 400);
        }
        // Защита от слишком длинных запросов: максимум 12 месяцев + 30 дней
        // (нужно немного запасу для «сравнения с предыдущим периодом» на фронте).
        $maxDays = 366 + 30;
        $diff = (strtotime($end) - strtotime($start)) / 86400;
        if ($diff < 0) respond(['error' => 'Конец периода раньше начала'], 400);
        if ($diff > $maxDays) respond(['error' => 'Слишком большой период (максимум 12 месяцев)'], 400);

        // Не-админу отдаём только его юрлица.
        // ВАЖНО: в warehouse_cells.legal_entity хранятся короткие имена
        // («Бургер БК»), а у пользователей legal_entities — полные («ООО "Бургер БК"»).
        // Поэтому добавляем сокращённые варианты к списку фильтра.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['rows' => [], 'start' => $start, 'end' => $end]);
            $allForms = [];
            foreach ($userEntities as $e) {
                $allForms[] = $e;
                if (preg_match('/"([^"]+)"/u', $e, $m)) $allForms[] = $m[1]; // выдернуть из «ООО "X"» → «X»
            }
            $allForms = array_values(array_unique($allForms));
            $phLE = implode(',', array_fill(0, count($allForms), '?'));
            $leWhere = " AND legal_entity IN ($phLE)";
            $leArgs = $allForms;
        }

        $st = $pdo->prepare("
            SELECT report_date, legal_entity, stock_type, cell_count, is_manual
            FROM warehouse_cells
            WHERE report_date >= ? AND report_date <= ?{$leWhere}
            ORDER BY report_date, legal_entity, stock_type
        ");
        $st->execute(array_merge([$start, $end], $leArgs));
        $rows = $st->fetchAll();
        respond(['rows' => $rows, 'start' => $start, 'end' => $end]);
    }

    // ─── Аннотации событий на графике аналитики ячеек ───
    if ($fn === 'cell_annotations_list') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $start = trim((string)($body['start'] ?? ''));
        $end   = trim((string)($body['end'] ?? ''));
        $sql = "SELECT id, event_date, label, color, created_by, created_at FROM cell_chart_annotations";
        $args = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $sql .= " WHERE event_date >= ? AND event_date <= ?";
            $args = [$start, $end];
        }
        $sql .= " ORDER BY event_date";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        respond(['rows' => $st->fetchAll()]);
    }

    if ($fn === 'cell_annotation_save') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        $id = (int)($body['id'] ?? 0);
        $date = trim((string)($body['event_date'] ?? ''));
        $label = trim((string)($body['label'] ?? ''));
        $color = trim((string)($body['color'] ?? '#E76F51'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['error' => 'Дата некорректна'], 400);
        if ($label === '') respond(['error' => 'Пустая метка'], 400);
        if (mb_strlen($label) > 255) respond(['error' => 'Метка слишком длинная'], 400);
        if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) $color = '#E76F51';
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE cell_chart_annotations SET event_date = ?, label = ?, color = ? WHERE id = ?")
                    ->execute([$date, $label, $color, $id]);
            } else {
                $pdo->prepare("INSERT INTO cell_chart_annotations (event_date, label, color, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$date, $label, $color, $authUserName]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            error_log('cell_annotation_save: ' . $e->getMessage());
            respond(['error' => 'Не удалось сохранить'], 500);
        }
        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'cell_annotation_delete') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'id обязателен'], 400);
        try {
            $pdo->prepare("DELETE FROM cell_chart_annotations WHERE id = ?")->execute([$id]);
        } catch (Throwable $e) {
            error_log('cell_annotation_delete: ' . $e->getMessage());
            respond(['error' => 'Не удалось удалить'], 500);
        }
        respond(['success' => true]);
    }

    if ($fn === 'get_warehouse_cells') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $days = intval($body['days'] ?? 90);
        if ($days > 365) $days = 365;
        // Не-админу отдаём только его юрлица.
        // ВАЖНО: warehouse_cells.legal_entity хранит короткие имена («Бургер БК»),
        // а user.legal_entities — полные («ООО "Бургер БК"»). Добавляем оба варианта.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond([]);
            $allForms = [];
            foreach ($userEntities as $e) {
                $allForms[] = $e;
                if (preg_match('/"([^"]+)"/u', $e, $m)) $allForms[] = $m[1];
            }
            $allForms = array_values(array_unique($allForms));
            $phLE = implode(',', array_fill(0, count($allForms), '?'));
            $leWhere = " AND legal_entity IN ($phLE)";
            $leArgs = $allForms;
        }
        $st = $pdo->prepare("SELECT report_date, legal_entity, stock_type, cell_count, is_manual FROM warehouse_cells WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY){$leWhere} ORDER BY report_date DESC, legal_entity, stock_type");
        $st->execute(array_merge([$days], $leArgs));
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($fn === 'upsert_warehouse_cell') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['shelf-life'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $date = $body['report_date'] ?? '';
        $entity = $body['legal_entity'] ?? '';
        $type = $body['stock_type'] ?? '';
        $count = intval($body['cell_count'] ?? 0);
        if (!$date || !$entity || !$type) respond(['error' => 'Не указаны обязательные поля'], 400);
        if (!in_array($type, ['cold','frozen','dry','shabany'])) respond(['error' => 'Неверный тип хранения'], 400);
        $existing = $pdo->prepare("SELECT id FROM warehouse_cells WHERE report_date=? AND legal_entity=? AND stock_type=?");
        $existing->execute([$date, $entity, $type]);
        $row = $existing->fetch();
        if ($row) {
            $pdo->prepare("UPDATE warehouse_cells SET cell_count=?, is_manual=1, updated_by=? WHERE id=?")->execute([$count, $caller['name'], $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO warehouse_cells (report_date, legal_entity, stock_type, cell_count, is_manual, updated_by) VALUES (?,?,?,?,1,?)")->execute([$date, $entity, $type, $count, $caller['name']]);
        }
        respond(['ok' => true]);
    }
