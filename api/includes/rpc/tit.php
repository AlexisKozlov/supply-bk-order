<?php
/**
 * RPC модуля «Заявка на пропуск» (tit_*).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

require_once __DIR__ . '/../tit_normalize.php';
require_once __DIR__ . '/../tit_helpers.php';

/**
 * Доступ к модулю — пока что любой аутентифицированный сотрудник.
 * Точечный RBAC заведём отдельной задачей в шаге 9.
 */
$titRequireStaff = function () use ($authUser) {
    if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);
};

/**
 * Соберёт массив строк xlsx по заявке (порядок и формат — как у формы ТиТ).
 * Время сохраняется как Excel-serial-number (день + часть от 24 часов).
 * @return array<int, array{plate_number:string,sms_number:string,start_time:float,end_time:float,status:int,allow_company:int,warehause:int,ramp:string,supplier:string}>
 */
$titBuildXlsxRows = function (int $requestId) use ($pdo): array {
    $req = $pdo->prepare("SELECT id, supplier_name, delivery_date FROM tit_requests WHERE id = ?");
    $req->execute([$requestId]);
    $r = $req->fetch();
    if (!$r) return [];

    $vehicles = $pdo->prepare("
        SELECT plate, phone, warehouse, allow_company, entry_kind, start_time, end_time
        FROM tit_vehicles
        WHERE request_id = ? AND deleted_at IS NULL
        ORDER BY id
    ");
    $vehicles->execute([$requestId]);
    $rows = [];
    $deliveryDate = $r['delivery_date'];
    // Конвертация Unix-timestamp → Excel-serial без подключения PhpSpreadsheet.
    // Epoch Excel: 1899-12-30. 1970-01-01 = 25569 дней от Excel-эпохи.
    // Локальное время — в форматированной строке нет TZ, считаем как локаль сервера.
    $toExcelSerial = function (int $ts): float {
        // Учитываем смещение локальной TZ, чтобы получить число «как видит человек».
        $offset = (int)date('Z', $ts);
        return 25569 + ($ts + $offset) / 86400;
    };
    foreach ($vehicles->fetchAll() as $v) {
        $st = $v['start_time'] ?: ($deliveryDate . ' 09:00:00');
        $en = $v['end_time']   ?: ($deliveryDate . ' 16:00:00');
        $stTs = strtotime($st);
        $enTs = strtotime($en);
        $rows[] = [
            'plate_number'  => (string)$v['plate'],
            'sms_number'    => (string)$v['phone'],
            'start_time'    => $stTs ? $toExcelSerial($stTs) : '',
            'end_time'      => $enTs ? $toExcelSerial($enTs) : '',
            'status'        => (int)$v['entry_kind'],
            'allow_company' => (int)$v['allow_company'],
            'warehause'     => (int)$v['warehouse'],
            'ramp'          => '',
            'supplier'      => (string)$r['supplier_name'],
        ];
    }
    return $rows;
};

/**
 * Собрать готовый xlsx-файл по строкам. Базируется на ОРИГИНАЛЬНОМ шаблоне
 * ТиТ (api/templates/tit_template.xlsx) — это гарантирует, что ширины
 * столбцов, формат ячеек (B = @, C/D = yyyy-mm-dd h:mm:ss), шрифт шапки
 * и прочее визуальное оформление совпадают 1-в-1 с тем, что ожидает
 * охрана и их система.
 *
 * Стратегия: открываем шаблон, чистим тестовую строку R2 (там в файле
 * пример «AM46025 / 375293854780 / …»), вставляем свои строки с R2.
 */
$titRenderXlsx = function (array $rows): string {
    require_once __DIR__ . '/../../../vendor/autoload.php';
    $tplPath = __DIR__ . '/../../templates/tit_template.xlsx';
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tplPath);
    $sh = $ss->getActiveSheet();
    // Сносим всё после шапки — в шаблоне в R2 лежит пример.
    $highest = $sh->getHighestRow();
    if ($highest > 1) {
        $sh->removeRow(2, $highest - 1);
    }
    foreach ($rows as $r => $row) {
        $rIdx = $r + 2;
        // A: plate_number — формат default, но мы хотим как текст не на всю
        //    колонку (формат уже задан в шаблоне). Принудительно текст —
        //    чтобы Excel не интерпретировал «00012» как число.
        $sh->setCellValueExplicit('A' . $rIdx, $row['plate_number'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        // B: sms_number — формат @ из шаблона, ставим как текст.
        $sh->setCellValueExplicit('B' . $rIdx, $row['sms_number'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        // C/D: start/end_time — числа Excel-serial, формат yyyy-mm-dd h:mm:ss
        //      унаследуется из шаблона (заданный в R2, сохраняется при removeRow).
        if ($row['start_time'] !== '') {
            $sh->setCellValueExplicit('C' . $rIdx, $row['start_time'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sh->getStyle('C' . $rIdx)->getNumberFormat()->setFormatCode('yyyy-mm-dd h:mm:ss');
        }
        if ($row['end_time'] !== '') {
            $sh->setCellValueExplicit('D' . $rIdx, $row['end_time'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sh->getStyle('D' . $rIdx)->getNumberFormat()->setFormatCode('yyyy-mm-dd h:mm:ss');
        }
        $sh->setCellValue('E' . $rIdx, (int)$row['status']);
        $sh->setCellValue('F' . $rIdx, (int)$row['allow_company']);
        $sh->setCellValue('G' . $rIdx, (int)$row['warehause']);
        // H — ramp, пусто
        $sh->setCellValue('I' . $rIdx, $row['supplier']);
    }
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
    ob_start();
    $writer->save('php://output');
    return (string)ob_get_clean();
};

// ─────────────────────────────────────────────────────────────
// tit_list — список заявок с фильтрами
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_list') {
    $titRequireStaff();
    $group   = in_array(($body['legal_entity_group'] ?? ''), ['BK_VM','PS'], true) ? $body['legal_entity_group'] : null;
    $status  = trim((string)($body['status'] ?? ''));
    $dateFrom= trim((string)($body['date_from'] ?? ''));
    $dateTo  = trim((string)($body['date_to'] ?? ''));
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    $search  = trim((string)($body['search'] ?? ''));

    $where = ['1=1'];
    $args = [];
    if ($group)    { $where[] = 'r.legal_entity_group = ?'; $args[] = $group; }
    if ($status !== '' && in_array($status, ['WAITING','DATA_RECEIVED','READY','SENT','CANCELLED'], true)) {
        $where[] = 'r.status = ?'; $args[] = $status;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $where[] = 'r.delivery_date >= ?'; $args[] = $dateFrom; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $where[] = 'r.delivery_date <= ?'; $args[] = $dateTo; }
    if ($supplierId !== '') { $where[] = 'r.supplier_id = ?'; $args[] = $supplierId; }
    if ($search !== '')    {
        $where[] = '(r.supplier_name LIKE ? OR EXISTS (SELECT 1 FROM tit_vehicles v WHERE v.request_id = r.id AND v.plate LIKE ?))';
        $args[] = '%' . $search . '%';
        $args[] = '%' . $search . '%';
    }

    $sql = "
        SELECT r.id, r.order_id, r.supplier_id, r.supplier_name, r.legal_entity,
               r.legal_entity_group, r.delivery_date, r.status, r.created_by,
               r.created_at, r.updated_at,
               (SELECT COUNT(*) FROM tit_vehicles v WHERE v.request_id = r.id AND v.deleted_at IS NULL) AS vehicles_count,
               (SELECT COUNT(*) FROM tit_vehicles v WHERE v.request_id = r.id AND v.deleted_at IS NULL AND v.needs_review = 1) AS needs_review_count,
               (SELECT COUNT(*) FROM tit_email_log e WHERE e.request_id = r.id) AS emails_count,
               (SELECT MAX(received_at) FROM tit_email_log e WHERE e.request_id = r.id) AS last_email_at
        FROM tit_requests r
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.delivery_date DESC, r.id DESC
        LIMIT 500
    ";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    respond(['rows' => $st->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────
// tit_get — карточка одной заявки (с машинами, письмами, поставщиком)
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_get') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);

    $r = $pdo->prepare("SELECT * FROM tit_requests WHERE id = ?");
    $r->execute([$id]);
    $req = $r->fetch();
    if (!$req) respond(['error' => 'Заявка не найдена'], 404);

    $vStmt = $pdo->prepare("SELECT * FROM tit_vehicles WHERE request_id = ? AND deleted_at IS NULL ORDER BY id");
    $vStmt->execute([$id]);

    $eStmt = $pdo->prepare("
        SELECT id, message_id, from_email, from_name, subject, received_at,
               body_excerpt, has_attachment, attachment_path,
               parsed_plate, parsed_phone, parsed_via, status
        FROM tit_email_log
        WHERE request_id = ?
        ORDER BY received_at DESC, id DESC
    ");
    $eStmt->execute([$id]);

    // Подсказка «прошлая машина» — если по этому поставщику есть запись
    $defaults = null;
    if (!empty($req['supplier_id'])) {
        $dStmt = $pdo->prepare("SELECT last_plate, last_phone, last_used_at FROM tit_supplier_defaults WHERE supplier_id = ?");
        $dStmt->execute([$req['supplier_id']]);
        $defaults = $dStmt->fetch() ?: null;
    }

    // Подскажем рекомендуемый склад по составу заказа (для подсказки в карточке)
    $warehouses = [6];
    if (!empty($req['order_id']) && !empty($req['legal_entity'])) {
        $warehouses = titDetectWarehousesForOrder($pdo, $req['order_id'], $req['legal_entity']);
    }

    respond([
        'request'          => $req,
        'vehicles'         => $vStmt->fetchAll(),
        'emails'           => $eStmt->fetchAll(),
        'supplier_defaults'=> $defaults,
        'recommended_warehouses' => $warehouses,
    ]);
}

// ─────────────────────────────────────────────────────────────
// tit_unread_count — для бейджа в меню
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_unread_count') {
    $titRequireStaff();
    // Считаем только «полезные» непривязанные письма — те, где парсер нашёл
    // номер машины, телефон или есть вложение (предположительно скан накладной).
    // Шум вроде «принято» от менеджеров поставщиков сюда не попадает.
    $st = $pdo->query("
        SELECT
          (SELECT COUNT(*) FROM tit_requests WHERE status = 'DATA_RECEIVED') AS need_action,
          (SELECT COUNT(*) FROM tit_email_log
             WHERE status = 'UNMATCHED' AND is_ignored = 0
               AND (parsed_plate IS NOT NULL OR parsed_phone IS NOT NULL OR has_attachment = 1)
          ) AS unmatched
    ");
    respond($st->fetch() ?: ['need_action' => 0, 'unmatched' => 0]);
}

// ─────────────────────────────────────────────────────────────
// tit_create_quick — быстрое создание пустой заявки (без модалки).
// Юрлицо — первое доступное юзеру из выбранной группы. Дата —
// завтра. Поставщик пустой, выбирается уже в карточке заявки.
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_create_quick') {
    $titRequireStaff();
    // Дефолтная дата подачи — сегодня.
    $defaultDate = date('Y-m-d');
    $deliveryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($body['delivery_date'] ?? ''))
        ? $body['delivery_date'] : $defaultDate;

    // Приоритет №1: явно указанное юрлицо из сайдбара (orderStore.settings.legalEntity).
    // Если оно валидное — используем его, группу выводим автоматически.
    $explicitLE = trim((string)($body['legal_entity'] ?? ''));
    $validEntities = ['ООО "Бургер БК"', 'ООО "Воглия Матта"', 'ООО "Пицца Стар"'];
    if ($explicitLE !== '' && in_array($explicitLE, $validEntities, true)) {
        $legalEntity = $explicitLE;
        $group = getEntityGroup($legalEntity);
    } else {
        // Фоллбэк: группа из фильтра страницы → первое юрлицо группы.
        $group = in_array(($body['legal_entity_group'] ?? ''), ['BK_VM','PS'], true) ? $body['legal_entity_group'] : 'BK_VM';
        $userEntitiesRaw = $authUser['legal_entities'] ?? '';
        $userEntities = [];
        if (is_array($userEntitiesRaw)) $userEntities = $userEntitiesRaw;
        elseif (is_string($userEntitiesRaw) && $userEntitiesRaw !== '') {
            $dec = json_decode($userEntitiesRaw, true);
            $userEntities = is_array($dec) ? $dec : array_map('trim', explode(',', $userEntitiesRaw));
        }
        $userEntities = array_values(array_filter($userEntities));
        $entitiesInGroup = getEntitiesInGroup($group);
        $legalEntity = '';
        foreach ($entitiesInGroup as $e) {
            if (!$userEntities || in_array($e, $userEntities, true)) { $legalEntity = $e; break; }
        }
        if ($legalEntity === '') {
            $legalEntity = $entitiesInGroup[0] ?? ($group === 'PS' ? 'ООО "Пицца Стар"' : 'ООО "Бургер БК"');
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO tit_requests
            (order_id, supplier_id, supplier_name, supplier_email,
             legal_entity, legal_entity_group, delivery_date, status, created_by)
        VALUES (NULL, NULL, '', '', ?, ?, ?, 'WAITING', ?)
    ");
    $ins->execute([$legalEntity, $group, $deliveryDate, $authUserName]);
    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// ─────────────────────────────────────────────────────────────
// tit_update_basic — поменять поставщика / юрлицо / дату из карточки
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_update_basic') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);

    $current = $pdo->prepare("SELECT * FROM tit_requests WHERE id = ?");
    $current->execute([$id]);
    $row = $current->fetch();
    if (!$row) respond(['error' => 'Заявка не найдена'], 404);
    // После отправки охране править нельзя — это бы расходилось с уже посланным xlsx.
    if ($row['status'] === 'SENT') respond(['error' => 'Заявка уже отправлена охране'], 409);

    $supplierId  = trim((string)($body['supplier_id'] ?? $row['supplier_id'] ?? ''));
    $legalEntity = trim((string)($body['legal_entity'] ?? $row['legal_entity']));
    $deliveryDate= trim((string)($body['delivery_date'] ?? $row['delivery_date']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) respond(['error' => 'Неверная дата'], 400);

    // Если фронт указал нового поставщика — тянем его snapshot (name, email, group).
    $supplierName  = (string)$row['supplier_name'];
    $supplierEmail = (string)$row['supplier_email'];
    $group = (string)$row['legal_entity_group'];
    if ($supplierId !== '' && $supplierId !== ($row['supplier_id'] ?? '')) {
        $s = $pdo->prepare("SELECT id, short_name, full_name, email, legal_entity, legal_entity_group FROM suppliers WHERE id = ?");
        $s->execute([$supplierId]);
        $sup = $s->fetch();
        if (!$sup) respond(['error' => 'Поставщик не найден'], 404);
        $supplierName  = (string)($sup['full_name'] ?: $sup['short_name']);
        $supplierEmail = (string)($sup['email'] ?? '');
        if (!$legalEntity) $legalEntity = (string)($sup['legal_entity'] ?? '');
        $group = (string)($sup['legal_entity_group'] ?: getEntityGroup($legalEntity));
    } elseif ($legalEntity && $legalEntity !== $row['legal_entity']) {
        $group = getEntityGroup($legalEntity);
    }

    $pdo->prepare("
        UPDATE tit_requests
        SET supplier_id = ?, supplier_name = ?, supplier_email = ?,
            legal_entity = ?, legal_entity_group = ?, delivery_date = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $supplierId !== '' ? $supplierId : null,
        $supplierName, $supplierEmail,
        $legalEntity, $group, $deliveryDate,
        $id,
    ]);
    respond(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// tit_create_manual — создание заявки вручную (без email)
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_create_manual') {
    $titRequireStaff();
    $supplierId   = trim((string)($body['supplier_id'] ?? ''));
    $deliveryDate = trim((string)($body['delivery_date'] ?? ''));
    $legalEntity  = trim((string)($body['legal_entity'] ?? ''));
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate) || $legalEntity === '') {
        respond(['error' => 'Укажите поставщика, дату и юрлицо'], 400);
    }
    $sStmt = $pdo->prepare("SELECT id, short_name, full_name, email FROM suppliers WHERE id = ?");
    $sStmt->execute([$supplierId]);
    $sup = $sStmt->fetch();
    if (!$sup) respond(['error' => 'Поставщик не найден'], 404);

    $ins = $pdo->prepare("
        INSERT INTO tit_requests
            (order_id, supplier_id, supplier_name, supplier_email,
             legal_entity, legal_entity_group, delivery_date, status, created_by)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, 'WAITING', ?)
    ");
    $ins->execute([
        $supplierId,
        $sup['full_name'] ?: $sup['short_name'],
        (string)($sup['email'] ?? ''),
        $legalEntity,
        getEntityGroup($legalEntity),
        $deliveryDate,
        $authUserName,
    ]);
    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// ─────────────────────────────────────────────────────────────
// tit_cancel — отменить заявку
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_cancel') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);
    $pdo->prepare("UPDATE tit_requests SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$id]);
    respond(['success' => true]);
}

// Полное удаление заявки. Cascade удаляет машины и лог отправок.
// Письма (tit_email_log) сохраняются — у них request_id обнулится.
// Обновление писем и удаление заявки идут в одной транзакции: иначе при
// сбое второго запроса (FK, тайм-аут) письма уже отвязаны от заявки,
// а сама заявка остаётся — закупщик видит «осиротевшую» строку.
if ($fn === 'tit_delete') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tit_email_log SET request_id = NULL, status = 'UNMATCHED' WHERE request_id = ?")
            ->execute([$id]);
        $pdo->prepare("DELETE FROM tit_requests WHERE id = ?")->execute([$id]);
        $pdo->commit();
        respond(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error' => 'Не удалось удалить: ' . $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────
// tit_vehicle_save — создать или обновить запись о машине в заявке
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_vehicle_save') {
    $titRequireStaff();
    $requestId   = (int)($body['request_id'] ?? 0);
    $vehicleId   = (int)($body['vehicle_id'] ?? 0);
    $plateRaw    = trim((string)($body['plate'] ?? ''));
    $phoneRaw    = trim((string)($body['phone'] ?? ''));
    $warehouse   = (int)($body['warehouse'] ?? 6);
    $entryKind   = (int)($body['entry_kind'] ?? 1);
    $startTime   = trim((string)($body['start_time'] ?? ''));
    $endTime     = trim((string)($body['end_time'] ?? ''));
    $confirm     = !empty($body['confirm']);

    if (!$requestId) respond(['error' => 'Не указана заявка'], 400);
    if (!in_array($warehouse, [1, 6], true)) $warehouse = 6;
    if (!in_array($entryKind, [1, 2], true)) $entryKind = 1;

    $pn = titNormalizePlate($plateRaw);
    $ph = titNormalizePhone($phoneRaw);
    // Допускаем «полупустую» запись только если ничего ещё не подтверждаем
    if ($confirm && (!$pn['valid'] || !$ph['valid'])) {
        respond(['error' => 'Для подтверждения нужны валидные номер машины и телефон'], 400);
    }

    $req = $pdo->prepare("SELECT id, supplier_id, status FROM tit_requests WHERE id = ?");
    $req->execute([$requestId]);
    $r = $req->fetch();
    if (!$r) respond(['error' => 'Заявка не найдена'], 404);

    $allowCompany = titAllowCompanyForWarehouse($warehouse);
    $sttDb = $startTime !== '' ? date('Y-m-d H:i:s', strtotime($startTime)) : null;
    $endDb = $endTime !== ''   ? date('Y-m-d H:i:s', strtotime($endTime)) : null;

    if ($vehicleId) {
        $upd = $pdo->prepare("
            UPDATE tit_vehicles
            SET plate = ?, plate_raw = ?, phone = ?, phone_raw = ?,
                warehouse = ?, allow_company = ?, entry_kind = ?,
                start_time = ?, end_time = ?,
                needs_review = ?, confirmed_by = ?, confirmed_at = ?,
                updated_at = NOW()
            WHERE id = ? AND request_id = ? AND deleted_at IS NULL
        ");
        $upd->execute([
            $pn['plate'], $pn['raw'], $ph['phone'], $ph['raw'],
            $warehouse, $allowCompany, $entryKind,
            $sttDb, $endDb,
            $confirm ? 0 : 1,
            $confirm ? $authUserName : null,
            $confirm ? date('Y-m-d H:i:s') : null,
            $vehicleId, $requestId,
        ]);
        $resultId = $vehicleId;
    } else {
        $ins = $pdo->prepare("
            INSERT INTO tit_vehicles
                (request_id, plate, plate_raw, phone, phone_raw,
                 warehouse, allow_company, entry_kind, start_time, end_time,
                 source, needs_review, confirmed_by, confirmed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MANUAL', ?, ?, ?)
        ");
        $ins->execute([
            $requestId,
            $pn['plate'], $pn['raw'], $ph['phone'], $ph['raw'],
            $warehouse, $allowCompany, $entryKind, $sttDb, $endDb,
            $confirm ? 0 : 1,
            $confirm ? $authUserName : null,
            $confirm ? date('Y-m-d H:i:s') : null,
        ]);
        $resultId = (int)$pdo->lastInsertId();
    }

    // Запоминаем «прошлую машину» по поставщику для подсказки
    if ($pn['valid'] && $ph['valid']) {
        titRememberSupplierDefaults($pdo, $r['supplier_id'] ?? null, $pn['plate'], $ph['phone']);
    }

    // Если статус ещё WAITING — двигаем в DATA_RECEIVED (есть данные)
    if (($r['status'] ?? '') === 'WAITING') {
        $pdo->prepare("UPDATE tit_requests SET status = 'DATA_RECEIVED', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
    }
    respond(['success' => true, 'id' => $resultId]);
}

// ─────────────────────────────────────────────────────────────
// tit_vehicle_delete — soft-delete машины
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_vehicle_delete') {
    $titRequireStaff();
    $vehicleId = (int)($body['vehicle_id'] ?? 0);
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$vehicleId) respond(['error' => 'Не указан id'], 400);
    // Привязка к заявке обязательна: иначе подделанный запрос с чужим
    // vehicle_id мог бы удалить машину из чужой открытой заявки. Если
    // request_id не задан или машина не из той заявки — UPDATE никого не
    // тронет, фронт получит «не найдено».
    if (!$requestId) respond(['error' => 'Не указана заявка'], 400);
    $upd = $pdo->prepare("UPDATE tit_vehicles SET deleted_at = NOW() WHERE id = ? AND request_id = ? AND deleted_at IS NULL");
    $upd->execute([$vehicleId, $requestId]);
    if ($upd->rowCount() === 0) respond(['error' => 'Машина не найдена в этой заявке'], 404);
    respond(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// tit_apply_supplier_default — подставить «прошлую машину» по поставщику
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_apply_supplier_default') {
    $titRequireStaff();
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) respond(['error' => 'Не указана заявка'], 400);
    $req = $pdo->prepare("SELECT supplier_id, status FROM tit_requests WHERE id = ?");
    $req->execute([$requestId]);
    $r = $req->fetch();
    if (!$r) respond(['error' => 'Заявка не найдена'], 404);
    if (!$r['supplier_id']) respond(['error' => 'У заявки нет привязки к поставщику'], 400);

    $d = $pdo->prepare("SELECT last_plate, last_phone FROM tit_supplier_defaults WHERE supplier_id = ?");
    $d->execute([$r['supplier_id']]);
    $def = $d->fetch();
    if (!$def || !$def['last_plate']) respond(['error' => 'По поставщику нет сохранённой машины'], 404);

    // Идемпотентность: двойной клик / параллельные вкладки не должны
    // создавать копии одной и той же машины. Если запись с этой plate уже
    // есть в заявке — возвращаем её id, без второй вставки.
    $existsStmt = $pdo->prepare("SELECT id FROM tit_vehicles WHERE request_id = ? AND plate = ? AND deleted_at IS NULL LIMIT 1");
    $existsStmt->execute([$requestId, (string)$def['last_plate']]);
    $existingId = (int)($existsStmt->fetchColumn() ?: 0);
    if ($existingId) {
        respond(['success' => true, 'id' => $existingId, 'already_existed' => true]);
    }

    $ins = $pdo->prepare("
        INSERT INTO tit_vehicles
            (request_id, plate, plate_raw, phone, phone_raw,
             warehouse, allow_company, entry_kind, source, needs_review)
        VALUES (?, ?, ?, ?, ?, 6, 8, 1, 'SUGGESTION', 1)
    ");
    $ins->execute([
        $requestId,
        $def['last_plate'], (string)$def['last_plate'],
        (string)($def['last_phone'] ?? ''), (string)($def['last_phone'] ?? ''),
    ]);
    if (($r['status'] ?? '') === 'WAITING') {
        $pdo->prepare("UPDATE tit_requests SET status = 'DATA_RECEIVED', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
    }
    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// ─────────────────────────────────────────────────────────────
// tit_preview_xlsx_rows — строки таблицы для превью охране (без файла)
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_preview_xlsx_rows') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);
    respond(['rows' => $titBuildXlsxRows($id)]);
}

// ─────────────────────────────────────────────────────────────
// tit_download_xlsx — отдать xlsx-файл напрямую
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_download_xlsx') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);
    $rows = $titBuildXlsxRows($id);
    if (!$rows) respond(['error' => 'В заявке нет машин для выгрузки'], 400);
    $bin = $titRenderXlsx($rows);
    $req = $pdo->prepare("SELECT supplier_name, delivery_date FROM tit_requests WHERE id = ?");
    $req->execute([$id]);
    $r = $req->fetch() ?: [];
    $fname = 'TiT_' . preg_replace('/[^a-zA-Zа-яА-Я0-9_-]+/u', '_', (string)($r['supplier_name'] ?? '')) . '_' . ($r['delivery_date'] ?? '') . '.xlsx';
    respond(['filename' => $fname, 'content_b64' => base64_encode($bin), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
}

// ─────────────────────────────────────────────────────────────
// tit_send_to_security — собрать xlsx и отправить охране
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_send_to_security') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    $overrideRecipients = $body['recipients'] ?? null; // массив адресов, если пользователь поменял список
    // Опциональные правки темы и тела от пользователя (UI «редактировать
    // письмо перед отправкой»). Если пусто — собираем дефолты ниже.
    $customSubject = trim((string)($body['subject'] ?? ''));
    $customBody    = trim((string)($body['body_text'] ?? ''));
    if (!$id) respond(['error' => 'Не указан id'], 400);

    $rows = $titBuildXlsxRows($id);
    if (!$rows) respond(['error' => 'В заявке нет машин для отправки'], 400);

    // Все ли машины подтверждены?
    $unconfirmed = $pdo->prepare("SELECT COUNT(*) FROM tit_vehicles WHERE request_id = ? AND deleted_at IS NULL AND needs_review = 1");
    $unconfirmed->execute([$id]);
    if ((int)$unconfirmed->fetchColumn() > 0) {
        respond(['error' => 'Сначала подтвердите все машины в заявке'], 400);
    }

    // Настройки модуля
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM tit_settings")->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $securityList = json_decode((string)($settings['security_recipients'] ?? '[]'), true) ?: [];

    if (is_array($overrideRecipients)) {
        $candidates = array_values(array_filter(array_map(function ($e) {
            return filter_var(trim((string)$e), FILTER_VALIDATE_EMAIL) ? trim((string)$e) : null;
        }, $overrideRecipients)));
    } else {
        $candidates = $securityList;
    }
    if (!$candidates) respond(['error' => 'Нет валидных адресов для отправки'], 400);

    $req = $pdo->prepare("SELECT id, supplier_name, delivery_date, created_by, legal_entity FROM tit_requests WHERE id = ?");
    $req->execute([$id]);
    $r = $req->fetch();
    if (!$r) respond(['error' => 'Заявка не найдена'], 404);

    // Email отправителя — в CC
    $senderEmail = '';
    try {
        $eStmt = $pdo->prepare("SELECT email FROM users WHERE name = ? LIMIT 1");
        $eStmt->execute([$authUserName]);
        $senderEmail = (string)($eStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    $bin = $titRenderXlsx($rows);
    $fname = 'TiT_' . preg_replace('/[^a-zA-Zа-яА-Я0-9_-]+/u', '_', (string)$r['supplier_name']) . '_' . $r['delivery_date'] . '.xlsx';

    $diskDir = __DIR__ . '/../../uploads/tit_sent';
    if (!is_dir($diskDir)) @mkdir($diskDir, 0775, true);
    $diskPath = $diskDir . '/' . $id . '_' . date('Ymd_His') . '_' . $fname;
    @file_put_contents($diskPath, $bin);

    require_once __DIR__ . '/../mail_send.php';

    // Тема — пользовательская (если задана) или дефолтная.
    // Маркеры «[ТЕСТ]», «test» в дефолте не используем (SpamAssassin режет).
    $subject = $customSubject !== ''
        ? $customSubject
        : ('Заявка на пропуск — ' . $r['supplier_name'] . ' — ' . $r['delivery_date']);

    $supplierName    = (string)$r['supplier_name'];
    $deliveryDateStr = (string)$r['delivery_date'];
    $legalEntityName = (string)$r['legal_entity'] !== '' ? (string)$r['legal_entity'] : 'Отдел закупок';
    $machinesCount   = count($rows);

    // Plain-текст тела — будем хранить как «исходник» для редактирования
    // через UI. HTML соберём ниже на его основе.
    if ($customBody !== '') {
        $bodyText = $customBody;
    } else {
        $bodyText = implode("\n", [
            'Добрый день!',
            '',
            'Направляем заявку на пропуск транспорта на склад. Подробности во вложении.',
            '',
            'Поставщик: ' . $supplierName,
            'Дата подачи: ' . $deliveryDateStr,
            'Количество машин: ' . $machinesCount,
            '',
            'Заранее спасибо! Хорошего дня.',
            '',
            'С уважением,',
            $legalEntityName,
        ]);
    }

    // МИНИМАЛЬНЫЙ HTML — без <style>, без таблиц, без inline-CSS, без шапок.
    // Просто <p> и <br>, как пишут обычные почтовые клиенты (Outlook, Gmail).
    //
    // Это критично: pure plain text у Burger King MailCleaner проходит мимо
    // SpamAssassin'а (Spamc), и решающим остаётся жёсткий NiceBayes (90%+).
    // Минимальный HTML запускает Spamc, который для нормальных писем даёт
    // «ham decisive» и переопределяет вердикт NiceBayes.
    //
    // Разбиваем plain-текст по двойным переносам строк на параграфы,
    // внутри параграфа одиночные переносы становятся <br>.
    $paragraphs = preg_split('/\r?\n\r?\n+/', $bodyText);
    $bodyHtml = '<html><body>';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $bodyHtml .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    $bodyHtml .= '</body></html>';

    // account=order — отправка ОТ order@supply-department.online.
    // С info@ MailCleaner Burger King режет письма в спам (NiceBayes ~99%);
    // order@ для заказов поставщикам проходит чисто. Пробуем те же письма
    // отправлять с order@, чтобы охрана получала их во входящие.
    // Имя отправителя — стабильное «Отдел закупок» из SMTP_ORDER_FROM_NAME,
    // не меняем динамически на юрлицо. Reply-To — на отправителя.
    $opts = ['account' => 'order'];
    if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $opts['cc'] = [$senderEmail];
        $opts['reply_to'] = $senderEmail;
    }
    $opts['attachments'] = [[
        'filename'    => $fname,
        'content_b64' => base64_encode($bin),
        'mime'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]];

    // Шлём как HTML (минимальный — только <p>/<br>, без стилей и таблиц).
    // SendEmail сам положит plain-text AltBody через htmlEmailToPlainText.
    // ВАЖНО: pure plain text у Burger King MailCleaner НЕ запускает Spamc
    // (SpamAssassin), и решающим остаётся NiceBayes (всегда жёсткий). HTML
    // запускает Spamc → он даёт «ham decisive» → переопределяет NiceBayes.
    $send = sendEmail($candidates, $subject, $bodyHtml, true, $opts);

    $pdo->prepare("
        INSERT INTO tit_send_log
            (request_id, file_path, recipients, cc_email, test_mode, sent_by, smtp_message_id, smtp_error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $id, $diskPath, json_encode($candidates, JSON_UNESCAPED_UNICODE),
        $senderEmail ?: '', 0, $authUserName,
        $send['message_id'] ?? null,
        $send['success'] ? null : mb_substr((string)($send['error'] ?? ''), 0, 500),
    ]);

    if (!$send['success']) {
        respond(['error' => 'Не удалось отправить: ' . ($send['error'] ?? 'неизвестная ошибка')], 500);
    }

    $pdo->prepare("UPDATE tit_requests SET status = 'SENT', updated_at = NOW() WHERE id = ?")->execute([$id]);

    respond([
        'success' => true,
        'recipients' => $candidates,
        'cc' => $senderEmail !== '' ? [$senderEmail] : [],
    ]);
}

// ─────────────────────────────────────────────────────────────
// tit_mark_sent — пометить заявку как отправленную вручную (через свою почту),
// без реальной отправки письма через сайт. Пишет запись в tit_send_log с
// smtp_error='manual', чтобы было видно, что отправка ручная.
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_mark_sent') {
    $titRequireStaff();
    $id = (int)($body['id'] ?? 0);
    if (!$id) respond(['error' => 'Не указан id'], 400);

    $req = $pdo->prepare("SELECT id, status FROM tit_requests WHERE id = ?");
    $req->execute([$id]);
    $r = $req->fetch();
    if (!$r) respond(['error' => 'Заявка не найдена'], 404);
    if ($r['status'] === 'SENT') respond(['error' => 'Заявка уже отмечена как отправленная'], 409);
    if ($r['status'] === 'CANCELLED') respond(['error' => 'Заявка отменена'], 409);

    $unconfirmed = $pdo->prepare("SELECT COUNT(*) FROM tit_vehicles WHERE request_id = ? AND deleted_at IS NULL AND needs_review = 1");
    $unconfirmed->execute([$id]);
    if ((int)$unconfirmed->fetchColumn() > 0) {
        respond(['error' => 'Сначала подтвердите все машины в заявке'], 400);
    }

    $pdo->prepare("
        INSERT INTO tit_send_log
            (request_id, file_path, recipients, cc_email, test_mode, sent_by, smtp_message_id, smtp_error)
        VALUES (?, '', '[]', '', 0, ?, NULL, 'manual')
    ")->execute([$id, $authUserName]);

    $pdo->prepare("UPDATE tit_requests SET status = 'SENT', updated_at = NOW() WHERE id = ?")->execute([$id]);

    respond(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// tit_settings_get / tit_settings_update — настройки модуля
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_settings_get') {
    $titRequireStaff();
    $rows = $pdo->query("SELECT setting_key, setting_value FROM tit_settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    // Парсим JSON-массив адресов для удобства фронта
    if (isset($out['security_recipients'])) {
        $dec = json_decode((string)$out['security_recipients'], true);
        $out['security_recipients'] = is_array($dec) ? $dec : [];
    }
    respond($out);
}

if ($fn === 'tit_settings_update') {
    $titRequireStaff();
    if (!hasRole($authUser, ['admin'])) respond(['error' => 'Доступ только администратору'], 403);
    $allowed = ['test_mode', 'test_email', 'security_recipients', 'email_template_addition', 'imap_poll_minutes'];
    $updates = is_array($body['settings'] ?? null) ? $body['settings'] : [];
    foreach ($updates as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        if ($k === 'security_recipients') {
            $list = is_array($v) ? $v : [];
            $list = array_values(array_filter(array_map(function ($e) {
                return filter_var(trim((string)$e), FILTER_VALIDATE_EMAIL) ? trim((string)$e) : null;
            }, $list)));
            $v = json_encode($list, JSON_UNESCAPED_UNICODE);
        } elseif ($k === 'test_mode' || $k === 'imap_poll_minutes') {
            $v = (string)(int)$v;
        } elseif ($k === 'test_email') {
            $v = filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL) ? trim((string)$v) : '';
        } else {
            $v = (string)$v;
        }
        $pdo->prepare("INSERT INTO tit_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([$k, $v]);
    }
    respond(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// tit_email_link — привязать UNMATCHED-письмо к существующей заявке вручную
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_email_link') {
    $titRequireStaff();
    $emailId   = (int)($body['email_id'] ?? 0);
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$emailId || !$requestId) respond(['error' => 'Укажите email_id и request_id'], 400);

    // Достаём распознанные парсером поля из письма
    $eStmt = $pdo->prepare("SELECT parsed_plate, parsed_phone, parsed_via FROM tit_email_log WHERE id = ?");
    $eStmt->execute([$emailId]);
    $email = $eStmt->fetch();
    if (!$email) respond(['error' => 'Письмо не найдено'], 404);

    $pdo->prepare("UPDATE tit_email_log SET request_id = ?, status = 'MATCHED' WHERE id = ?")
        ->execute([$requestId, $emailId]);

    // Если в письме был распознан номер машины — сразу создаём запись в
    // tit_vehicles, чтобы закупщику не пришлось ещё раз вбивать руками.
    // Машина создаётся в статусе «требует проверки» (закупщик подтвердит).
    $vehicleCreated = false;
    if (!empty($email['parsed_plate'])) {
        $source = match ((string)($email['parsed_via'] ?? '')) {
            'EMAIL_OCR' => 'EMAIL_OCR',
            'BOTH'      => 'EMAIL_TEXT',
            default     => 'EMAIL_TEXT',
        };
        $pdo->prepare("
            INSERT INTO tit_vehicles
                (request_id, plate, plate_raw, phone, phone_raw, source, email_log_id, needs_review)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ")->execute([
            $requestId,
            (string)$email['parsed_plate'], (string)$email['parsed_plate'],
            (string)($email['parsed_phone'] ?? ''), (string)($email['parsed_phone'] ?? ''),
            $source, $emailId,
        ]);
        $vehicleCreated = true;

        // Двигаем статус заявки если она ещё «ждёт»
        $pdo->prepare("UPDATE tit_requests SET status = 'DATA_RECEIVED', updated_at = NOW()
                       WHERE id = ? AND status = 'WAITING'")
            ->execute([$requestId]);
    }

    respond(['success' => true, 'vehicle_created' => $vehicleCreated]);
}

// Пропустить письмо: убирает из «непривязанных» в UI, но не удаляет.
// Сценарий: поставщик сам отправил заявку охране, письмо неинтересно.
if ($fn === 'tit_email_ignore') {
    $titRequireStaff();
    $emailId = (int)($body['email_id'] ?? 0);
    $undo    = !empty($body['undo']);
    if (!$emailId) respond(['error' => 'Не указан email_id'], 400);
    $pdo->prepare("UPDATE tit_email_log SET is_ignored = ? WHERE id = ?")
        ->execute([$undo ? 0 : 1, $emailId]);
    respond(['success' => true, 'ignored' => !$undo]);
}

// ─────────────────────────────────────────────────────────────
// tit_email_log_unmatched — список UNMATCHED-писем для ручной привязки
// ─────────────────────────────────────────────────────────────
if ($fn === 'tit_email_log_unmatched') {
    $titRequireStaff();
    // Показываем только письма с реальной полезной нагрузкой:
    // распознанный номер, телефон или вложение (потенциально скан накладной).
    $rows = $pdo->query("
        SELECT id, from_email, from_name, subject, received_at, has_attachment,
               parsed_plate, parsed_phone, parsed_via, attachment_path, body_excerpt
        FROM tit_email_log
        WHERE status = 'UNMATCHED' AND is_ignored = 0
          AND (parsed_plate IS NOT NULL OR parsed_phone IS NOT NULL OR has_attachment = 1)
        ORDER BY received_at DESC
        LIMIT 200
    ")->fetchAll();
    // Для каждого письма перечисляем все вложения (cron сохраняет их по
    // префиксу id_*) — закупщик сможет скачать любой файл.
    $attachDir = __DIR__ . '/../../uploads/tit_attachments';
    foreach ($rows as &$row) {
        $row['body_excerpt'] = mb_substr((string)$row['body_excerpt'], 0, 500);
        $files = [];
        if ($row['has_attachment']) {
            foreach (glob($attachDir . '/' . (int)$row['id'] . '_*') as $f) {
                $name = basename($f);
                // Из имени вырезаем технический префикс «id_timestamp_hash_»
                $clean = preg_replace('/^\d+_\d+_[a-f0-9]+_/', '', $name);
                $files[] = [
                    'name' => $clean ?: $name,
                    'path' => str_replace('/var/www/bk-calc/', '', $f),
                    'size' => filesize($f) ?: 0,
                ];
            }
        }
        $row['attachments'] = $files;
    }
    unset($row);
    respond(['rows' => $rows]);
}

// Скачивание конкретного вложения письма (создаёт download-токен).
if ($fn === 'tit_email_attachment') {
    $titRequireStaff();
    $emailId = (int)($body['email_id'] ?? 0);
    $idx     = (int)($body['index'] ?? 0);
    if (!$emailId) respond(['error' => 'Не указан email_id'], 400);
    // Защита от обхода: ищем файлы по строгому префиксу id_*
    $attachDir = __DIR__ . '/../../uploads/tit_attachments';
    $files = glob($attachDir . '/' . $emailId . '_*');
    if (!isset($files[$idx])) respond(['error' => 'Файл не найден'], 404);
    $f = $files[$idx];
    $name = basename($f);
    $clean = preg_replace('/^\d+_\d+_[a-f0-9]+_/', '', $name) ?: $name;
    $content = @file_get_contents($f);
    if ($content === false) respond(['error' => 'Не удалось прочитать файл'], 500);
    $mime = match (strtolower(pathinfo($f, PATHINFO_EXTENSION))) {
        'pdf'  => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        default => 'application/octet-stream',
    };
    respond([
        'filename'    => $clean,
        'content_b64' => base64_encode($content),
        'mime'        => $mime,
    ]);
}
