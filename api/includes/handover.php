<?php
/**
 * API модуля «Передача дел».
 *
 * Документ на время отпуска/отсутствия: кому что передаём, какие приходы
 * ждём, что на контроле. Приходы и позиции заказов портал подтягивает сам
 * из таблицы orders за период, остальное сотрудник дописывает руками.
 *
 * Приходы берём только свои — заказы, которые завёл автор документа
 * (orders.created_by). Иначе в передачу дел попадали все поставки отдела.
 *
 * Снимок, а не «живая» выборка: собранные заявки сохраняются в
 * handover_suppliers.orders_json. Иначе документ, отданный коллегам,
 * менялся бы задним числом при каждой правке заказа.
 *
 * Маршруты:
 *   GET    handover/docs               — список документов
 *   POST   handover/docs               — создать (+ автосборка приходов)
 *   GET    handover/docs/:id           — документ целиком
 *   PATCH  handover/docs/:id           — шапка документа
 *   DELETE handover/docs/:id           — удалить
 *   POST   handover/docs/:id/rebuild   — пересобрать приходы за период
 *   GET    handover/docs/:id/export    — скачать .docx
 *
 *   POST   handover/people             — добавить ответственного
 *   PATCH  handover/people/:id         — изменить
 *   DELETE handover/people/:id         — удалить
 *
 *   PATCH  handover/suppliers/:id      — примечания по поставщику
 *   DELETE handover/suppliers/:id      — убрать поставщика из документа
 *
 *   POST   handover/items              — строка раздела (weekly/topic/payment/control/escalate/file)
 *   PATCH  handover/items/:id          — изменить
 *   DELETE handover/items/:id          — удалить
 */

// Проверка $endpoint — ниже, перед секцией маршрутов: функции модуля нужны
// и при подключении из других мест (экспорт, будущие cron-скрипты).

function hoRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function hoRequireUser($pdo) {
    $u = getSessionUser($pdo);
    if (!$u) hoRespond(['error' => 'Требуется авторизация'], 401);
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    $role = $u['role'] ?? 'user';
    if ($role !== 'admin') {
        $perms = resolvePermissions($role, $u['permissions'] ?? null, $ROLE_TEMPLATES);
        $level = $ACCESS_LEVELS[$perms['handover'] ?? 'none'] ?? 0;
        if ($level < 1) hoRespond(['error' => 'Нет доступа к модулю «Передача дел»'], 403);
    }
    return $u;
}

/**
 * Ограничение длины текстового поля. Колонки теперь TEXT, но вставку
 * гигантского текста (например, случайно скопированного файла) лучше
 * обрезать, чем ронять запрос ошибкой базы.
 */
function hoText($value, int $limit = 20000): string {
    $v = trim((string)$value);
    return mb_strlen($v) > $limit ? mb_substr($v, 0, $limit) : $v;
}

function hoIsManager($u) {
    return in_array($u['role'] ?? '', ['admin', 'manager'], true);
}

function hoUserEntities($u) {
    $e = $u['legal_entities'] ?? [];
    if (is_string($e)) $e = json_decode($e, true) ?: [];
    return is_array($e) ? array_values(array_unique($e)) : [];
}

function hoGetDoc($pdo, $id) {
    $s = $pdo->prepare("SELECT * FROM handover_docs WHERE id = ?");
    $s->execute([(int)$id]);
    return $s->fetch();
}

/**
 * Автор правит свой документ; admin/manager — любой. Остальные только читают.
 * Сверяем по author_login (логин из сессии), а не по видимому author_name:
 * имя в шапке документа сотрудник может переписать вручную.
 */
function hoCanEdit($u, $doc) {
    if (!$doc) return false;
    if (hoIsManager($u)) return true;
    $login = trim((string)($doc['author_login'] ?? ''));
    return $login !== '' && $login === ($u['name'] ?? '');
}

function hoRequireEditableDoc($pdo, $u, $docId) {
    $doc = hoGetDoc($pdo, $docId);
    if (!$doc) hoRespond(['error' => 'Документ не найден'], 404);
    if (!hoCanEdit($u, $doc)) hoRespond(['error' => 'Документ можно править только автору'], 403);
    return $doc;
}

/**
 * «80 000 шт · 80 кор.» — как в привычном бумажном документе.
 *
 * Осторожно с размером коробки. У части карточек qty_per_box заполнен
 * фасовкой (в коробке 1000 шт), а у части стоит 1, и тогда настоящий размер
 * коробки лежит в кратности заказа: 2592 шт при кратности 12 — это 216
 * коробок, а не 2592. Если и кратность единичная, коробки не выдумываем.
 */
function hoFormatQty(array $item) {
    $perBox = (float)($item['qty_per_box'] ?? 0);
    $mult   = (float)($item['multiplicity'] ?? 0);
    $final  = (float)($item['final_order'] ?? 0);
    $inBoxes = ($item['unit_of_measure'] ?? '') === 'boxes';

    $num = fn($v) => rtrim(rtrim(number_format($v, 2, '.', ' '), '0'), '.');

    if ($perBox > 1) {
        // Количество указано коробками, в коробке $perBox штук.
        $boxes  = $inBoxes ? $final : $final / $perBox;
        $pieces = $inBoxes ? $final * $perBox : $final;
    } elseif ($mult > 1) {
        // Количество указано штуками, коробка равна кратности заказа.
        $pieces = $final;
        $boxes  = $final / $mult;
    } else {
        return $num($final) . ' шт';
    }

    return $num($pieces) . ' шт · ' . $num($boxes) . ' кор.';
}

/**
 * Собирает приходы за период: заказы закупки, сгруппированные по поставщику.
 * Юрлица берём у автора документа — чужие данные в документ не попадают.
 *
 * Приходы — только свои: берём заказы, которые завёл сам автор документа
 * (orders.created_by). Раньше в документ падали все поставки отдела за период,
 * и человек получал список чужих приходов вперемешку со своими.
 *
 * $author пустой — фильтр не применяем: у старых документов автора могло не
 * быть, и молча обнулять им список приходов нельзя.
 */
function hoCollectOrders($pdo, $from, $to, array $entities, $author = '') {
    if (!$entities) return [];
    $ph = implode(',', array_fill(0, count($entities), '?'));
    $author = trim((string)$author);
    $byAuthor = $author !== '' ? ' AND created_by = ?' : '';
    $s = $pdo->prepare("
        SELECT id, supplier, delivery_date, legal_entity, note
        FROM orders
        WHERE delivery_date BETWEEN ? AND ?
          AND legal_entity IN ($ph)$byAuthor
        ORDER BY supplier, delivery_date");
    $params = array_merge([$from, $to], $entities);
    if ($author !== '') $params[] = $author;
    $s->execute($params);
    $orders = $s->fetchAll();
    if (!$orders) return [];

    $ids = array_column($orders, 'id');
    $iph = implode(',', array_fill(0, count($ids), '?'));
    $si = $pdo->prepare("
        SELECT order_id, sku, name, final_order, qty_boxes, qty_per_box, unit_of_measure, multiplicity
        FROM order_items
        WHERE order_id IN ($iph) AND COALESCE(final_order, 0) > 0
        ORDER BY sort_order, name");
    $si->execute($ids);
    $itemsByOrder = [];
    foreach ($si->fetchAll() as $it) {
        $itemsByOrder[$it['order_id']][] = [
            'sku'   => $it['sku'],
            'name'  => $it['name'],
            'qty'   => hoFormatQty($it),
        ];
    }

    $bySupplier = [];
    foreach ($orders as $o) {
        $name = trim((string)$o['supplier']);
        if ($name === '') $name = 'Без поставщика';
        $bySupplier[$name][] = [
            'date'         => $o['delivery_date'],
            'legal_entity' => $o['legal_entity'],
            'note'         => $o['note'],
            'items'        => $itemsByOrder[$o['id']] ?? [],
        ];
    }
    ksort($bySupplier, SORT_LOCALE_STRING);
    return $bySupplier;
}

/** Контакты поставщика из справочника — чтобы не искать их вручную. */
function hoSupplierContacts($pdo, $supplierName) {
    $s = $pdo->prepare("
        SELECT short_name, full_name, email, telegram, viber, whatsapp, payment_delay_days
        FROM suppliers
        WHERE short_name = ? AND is_active = 1
        LIMIT 1");
    $s->execute([$supplierName]);
    $row = $s->fetch();
    if (!$row) return '';
    $parts = [];
    foreach (['telegram' => 'телеграм', 'viber' => 'вайбер', 'whatsapp' => 'whatsapp', 'email' => 'почта'] as $col => $label) {
        $v = trim((string)($row[$col] ?? ''));
        if ($v !== '') $parts[] = "$label: $v";
    }
    if (!empty($row['payment_delay_days'])) $parts[] = 'отсрочка ' . (int)$row['payment_delay_days'] . ' дн.';
    return implode(', ', $parts);
}

/** Заполняет документ снимком приходов. Ручные примечания сохраняются. */
function hoRebuildSuppliers($pdo, array $doc) {
    $entities = json_decode((string)($doc['legal_entities'] ?? ''), true) ?: [];
    $collected = hoCollectOrders($pdo, $doc['date_from'], $doc['date_to'], $entities, $doc['author_login'] ?? '');

    $s = $pdo->prepare("SELECT * FROM handover_suppliers WHERE doc_id = ?");
    $s->execute([(int)$doc['id']]);
    $existing = [];
    foreach ($s->fetchAll() as $row) $existing[$row['supplier_name']] = $row;

    $ins = $pdo->prepare("
        INSERT INTO handover_suppliers (doc_id, supplier_name, contacts, orders_json, sort_order)
        VALUES (?, ?, ?, ?, ?)");
    $upd = $pdo->prepare("UPDATE handover_suppliers SET orders_json = ?, sort_order = ? WHERE id = ?");

    $order = 0;
    foreach ($collected as $name => $orders) {
        $json = json_encode($orders, JSON_UNESCAPED_UNICODE);
        if (isset($existing[$name])) {
            $upd->execute([$json, $order, (int)$existing[$name]['id']]);
        } else {
            $ins->execute([(int)$doc['id'], $name, hoSupplierContacts($pdo, $name), $json, $order]);
        }
        $order++;
    }

    // Поставщик, у которого приходов больше нет (заказ перенесли/удалили),
    // остаётся в документе только если по нему уже написали примечание.
    foreach ($existing as $name => $row) {
        if (isset($collected[$name])) continue;
        $hasNotes = trim((string)$row['attention']) !== ''
            || trim((string)$row['correction_rule']) !== ''
            || trim((string)$row['docs_rule']) !== '';
        if (!$hasNotes) {
            $pdo->prepare("DELETE FROM handover_suppliers WHERE id = ?")->execute([(int)$row['id']]);
        } else {
            $pdo->prepare("UPDATE handover_suppliers SET orders_json = ? WHERE id = ?")
                ->execute(['[]', (int)$row['id']]);
        }
    }
    return count($collected);
}

/**
 * Список приходов в документе — снимок на момент сборки, а не живая выборка:
 * иначе примечания к поставщику разъезжались бы с данными. Обратная сторона —
 * снимок устаревает молча. Так в передаче дел №5 осталась «БелАсва»: когда
 * документ собирали, её приход стоял на 11 августа, потом заказ передвинули
 * на 6-е, а в документе он висел как будущий.
 *
 * Сравниваем состав «поставщик → даты приходов» и говорим фронту, что пора
 * нажать «Обновить приходы».
 */
function hoSuppliersStale($pdo, array $doc, array $stored): bool {
    $entities = json_decode((string)($doc['legal_entities'] ?? ''), true) ?: [];
    $fresh = hoCollectOrders($pdo, $doc['date_from'], $doc['date_to'], $entities, $doc['author_login'] ?? '');

    $shape = function (array $bySupplier): array {
        $out = [];
        foreach ($bySupplier as $name => $orders) {
            $dates = array_map(fn($o) => (string)($o['date'] ?? ''), $orders);
            sort($dates);
            $out[$name] = $dates;
        }
        ksort($out);
        return $out;
    };
    $storedShape = [];
    foreach ($stored as $s) {
        // Поставщик без приходов — добавлен руками, его в сравнение не берём.
        if (empty($s['orders'])) continue;
        $storedShape[$s['supplier_name']] = $s['orders'];
    }
    return $shape($fresh) !== $shape($storedShape);
}

function hoLoadFull($pdo, array $doc) {
    $id = (int)$doc['id'];
    $people = $pdo->prepare("SELECT * FROM handover_people WHERE doc_id = ? ORDER BY sort_order, id");
    $people->execute([$id]);

    $sup = $pdo->prepare("SELECT * FROM handover_suppliers WHERE doc_id = ? ORDER BY sort_order, supplier_name");
    $sup->execute([$id]);
    $suppliers = [];
    foreach ($sup->fetchAll() as $row) {
        $row['orders'] = json_decode((string)$row['orders_json'], true) ?: [];
        unset($row['orders_json']);
        $row['included'] = (int)$row['included'] === 1;
        $suppliers[] = $row;
    }

    $items = $pdo->prepare("SELECT * FROM handover_items WHERE doc_id = ? ORDER BY kind, sort_order, id");
    $items->execute([$id]);
    $byKind = [];
    foreach ($items->fetchAll() as $it) {
        $it['done'] = (int)$it['done'] === 1;
        $byKind[$it['kind']][] = $it;
    }

    $doc['legal_entities'] = json_decode((string)$doc['legal_entities'], true) ?: [];
    return [
        'doc'       => $doc,
        'people'    => $people->fetchAll(),
        'suppliers' => $suppliers,
        'items'     => $byKind,
    ];
}

// ═══════════════════════ Маршруты ═══════════════════════

if (($endpoint ?? '') !== 'handover') return;

$hoParts  = explode('/', trim($uri, '/'));
$hoAction = $hoParts[1] ?? '';
$hoId     = $hoParts[2] ?? null;
$hoSub    = $hoParts[3] ?? null;

$hoUser = hoRequireUser($pdo);

// ─── Список документов ───
if ($hoAction === 'docs' && $hoId === null && $method === 'GET') {
    $s = $pdo->prepare("
        SELECT d.*,
               (SELECT COUNT(*) FROM handover_suppliers hs WHERE hs.doc_id = d.id) AS supplier_count,
               (SELECT COUNT(*) FROM handover_people hp WHERE hp.doc_id = d.id) AS people_count
        FROM handover_docs d
        ORDER BY d.date_from DESC, d.id DESC");
    $s->execute();
    $rows = [];
    foreach ($s->fetchAll() as $row) {
        $row['can_edit'] = hoCanEdit($hoUser, $row);
        $row['legal_entities'] = json_decode((string)$row['legal_entities'], true) ?: [];
        $rows[] = $row;
    }
    hoRespond(['docs' => $rows]);
}

// ─── Создать документ ───
if ($hoAction === 'docs' && $hoId === null && $method === 'POST') {
    $from = trim((string)($body['date_from'] ?? ''));
    $to   = trim((string)($body['date_to'] ?? ''));
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) hoRespond(['error' => 'Нужны даты начала и конца периода'], 400);
    }
    if ($to < $from) hoRespond(['error' => 'Дата окончания раньше даты начала'], 400);

    $entities = hoUserEntities($hoUser);
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        $title = 'Передача дел ' . date('d.m.Y', strtotime($from)) . ' — ' . date('d.m.Y', strtotime($to));
    }

    $ins = $pdo->prepare("
        INSERT INTO handover_docs (title, author_login, author_name, author_role, date_from, date_to,
                                   return_date, emergency_note, legal_entities)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $title,
        $hoUser['name'] ?? '',
        $hoUser['name'] ?? '',
        $hoUser['display_role'] ?? '',
        $from, $to,
        $body['return_date'] ?: null,
        trim((string)($body['emergency_note'] ?? '')),
        json_encode($entities, JSON_UNESCAPED_UNICODE),
    ]);
    $docId = (int)$pdo->lastInsertId();
    $doc = hoGetDoc($pdo, $docId);
    $count = hoRebuildSuppliers($pdo, $doc);

    // Стартовый набор дней недели — чтобы таблица не была пустой.
    $days = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Ежедневно'];
    $iw = $pdo->prepare("INSERT INTO handover_items (doc_id, kind, c1, sort_order) VALUES (?, 'weekly', ?, ?)");
    foreach ($days as $i => $d) $iw->execute([$docId, $d, $i]);

    hoRespond(['doc' => hoLoadFull($pdo, hoGetDoc($pdo, $docId)), 'suppliers_found' => $count], 201);
}

// ─── Документ целиком ───
if ($hoAction === 'docs' && $hoId !== null && $hoSub === null && $method === 'GET') {
    $doc = hoGetDoc($pdo, $hoId);
    if (!$doc) hoRespond(['error' => 'Документ не найден'], 404);
    $full = hoLoadFull($pdo, $doc);
    $full['can_edit'] = hoCanEdit($hoUser, $doc);
    $full['suppliers_stale'] = hoSuppliersStale($pdo, $doc, $full['suppliers']);
    hoRespond($full);
}

// ─── Шапка документа ───
if ($hoAction === 'docs' && $hoId !== null && $hoSub === null && $method === 'PATCH') {
    $doc = hoRequireEditableDoc($pdo, $hoUser, $hoId);
    $fields = [];
    $params = [];
    foreach (['title', 'author_name', 'author_role', 'emergency_note', 'status'] as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f = ?"; $params[] = trim((string)$body[$f]); }
    }
    foreach (['date_from', 'date_to', 'return_date'] as $f) {
        if (array_key_exists($f, $body)) {
            $v = trim((string)$body[$f]);
            $fields[] = "$f = ?";
            $params[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        }
    }
    if (!$fields) hoRespond(['error' => 'Нечего менять'], 400);
    $params[] = (int)$hoId;
    $pdo->prepare("UPDATE handover_docs SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // Сдвинули период — пересобираем приходы сразу. Раньше список оставался от
    // старых дат, и человек видел поставки, которых в его отсутствие не будет.
    $datesChanged = false;
    foreach (['date_from', 'date_to'] as $f) {
        if (array_key_exists($f, $body) && (string)$body[$f] !== (string)($doc[$f] ?? '')) $datesChanged = true;
    }
    $updated = hoGetDoc($pdo, $hoId);
    if ($datesChanged) hoRebuildSuppliers($pdo, $updated);

    $full = hoLoadFull($pdo, hoGetDoc($pdo, $hoId));
    $full['can_edit'] = hoCanEdit($hoUser, $updated);
    $full['suppliers_stale'] = hoSuppliersStale($pdo, $updated, $full['suppliers']);
    hoRespond(['doc' => $full, 'suppliers_rebuilt' => $datesChanged]);
}

// ─── Удалить документ ───
if ($hoAction === 'docs' && $hoId !== null && $hoSub === null && $method === 'DELETE') {
    hoRequireEditableDoc($pdo, $hoUser, $hoId);
    $pdo->prepare("DELETE FROM handover_docs WHERE id = ?")->execute([(int)$hoId]);
    hoRespond(['ok' => true]);
}

// ─── Пересобрать приходы ───
if ($hoAction === 'docs' && $hoId !== null && $hoSub === 'rebuild' && $method === 'POST') {
    $doc = hoRequireEditableDoc($pdo, $hoUser, $hoId);
    $count = hoRebuildSuppliers($pdo, $doc);
    hoRespond(['doc' => hoLoadFull($pdo, hoGetDoc($pdo, $hoId)), 'suppliers_found' => $count]);
}

// ─── Экспорт в Word ───
if ($hoAction === 'docs' && $hoId !== null && $hoSub === 'export' && $method === 'GET') {
    $doc = hoGetDoc($pdo, $hoId);
    if (!$doc) hoRespond(['error' => 'Документ не найден'], 404);
    require_once __DIR__ . '/handover_docx.php';
    hoExportDocx($pdo, hoLoadFull($pdo, $doc));
}

// ─── Ответственные ───
if ($hoAction === 'people' && $method === 'POST') {
    $docId = (int)($body['doc_id'] ?? 0);
    hoRequireEditableDoc($pdo, $hoUser, $docId);
    $s = $pdo->prepare("
        INSERT INTO handover_people (doc_id, user_id, name, zone, scope, contact, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $s->execute([
        $docId,
        $body['user_id'] ?? null,
        trim((string)($body['name'] ?? '')),
        trim((string)($body['zone'] ?? '')),
        trim((string)($body['scope'] ?? '')),
        trim((string)($body['contact'] ?? '')),
        (int)($body['sort_order'] ?? 0),
    ]);
    hoRespond(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($hoAction === 'people' && $hoId !== null && in_array($method, ['PATCH', 'DELETE'], true)) {
    $s = $pdo->prepare("SELECT doc_id FROM handover_people WHERE id = ?");
    $s->execute([(int)$hoId]);
    $docId = $s->fetchColumn();
    if (!$docId) hoRespond(['error' => 'Строка не найдена'], 404);
    hoRequireEditableDoc($pdo, $hoUser, (int)$docId);

    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM handover_people WHERE id = ?")->execute([(int)$hoId]);
        hoRespond(['ok' => true]);
    }
    $fields = [];
    $params = [];
    foreach (['name', 'zone', 'scope', 'contact'] as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f = ?"; $params[] = hoText($body[$f], 2000); }
    }
    if (array_key_exists('sort_order', $body)) { $fields[] = "sort_order = ?"; $params[] = (int)$body['sort_order']; }
    if (array_key_exists('user_id', $body)) { $fields[] = "user_id = ?"; $params[] = $body['user_id'] ?: null; }
    if (!$fields) hoRespond(['error' => 'Нечего менять'], 400);
    $params[] = (int)$hoId;
    $pdo->prepare("UPDATE handover_people SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    hoRespond(['ok' => true]);
}

// ─── Поставщики документа ───
if ($hoAction === 'suppliers' && $hoId !== null && in_array($method, ['PATCH', 'DELETE'], true)) {
    $s = $pdo->prepare("SELECT doc_id FROM handover_suppliers WHERE id = ?");
    $s->execute([(int)$hoId]);
    $docId = $s->fetchColumn();
    if (!$docId) hoRespond(['error' => 'Поставщик не найден'], 404);
    hoRequireEditableDoc($pdo, $hoUser, (int)$docId);

    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM handover_suppliers WHERE id = ?")->execute([(int)$hoId]);
        hoRespond(['ok' => true]);
    }
    $fields = [];
    $params = [];
    foreach (['contacts', 'correction_rule', 'docs_rule', 'attention'] as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f = ?"; $params[] = hoText($body[$f]); }
    }
    if (array_key_exists('person_id', $body)) { $fields[] = "person_id = ?"; $params[] = $body['person_id'] ? (int)$body['person_id'] : null; }
    if (array_key_exists('included', $body)) { $fields[] = "included = ?"; $params[] = $body['included'] ? 1 : 0; }
    if (!$fields) hoRespond(['error' => 'Нечего менять'], 400);
    $params[] = (int)$hoId;
    $pdo->prepare("UPDATE handover_suppliers SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    hoRespond(['ok' => true]);
}

// ─── Строки разделов ───
$HO_KINDS = ['weekly', 'topic', 'payment', 'control', 'escalate', 'file'];

if ($hoAction === 'items' && $method === 'POST') {
    $docId = (int)($body['doc_id'] ?? 0);
    hoRequireEditableDoc($pdo, $hoUser, $docId);
    $kind = (string)($body['kind'] ?? '');
    if (!in_array($kind, $HO_KINDS, true)) hoRespond(['error' => 'Неизвестный раздел'], 400);
    $s = $pdo->prepare("
        INSERT INTO handover_items (doc_id, kind, c1, c2, c3, c4, c5, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $s->execute([
        $docId, $kind,
        hoText($body['c1'] ?? ''), hoText($body['c2'] ?? ''),
        hoText($body['c3'] ?? ''), hoText($body['c4'] ?? ''),
        hoText($body['c5'] ?? ''), (int)($body['sort_order'] ?? 0),
    ]);
    hoRespond(['id' => (int)$pdo->lastInsertId()], 201);
}

if ($hoAction === 'items' && $hoId !== null && in_array($method, ['PATCH', 'DELETE'], true)) {
    $s = $pdo->prepare("SELECT doc_id FROM handover_items WHERE id = ?");
    $s->execute([(int)$hoId]);
    $docId = $s->fetchColumn();
    if (!$docId) hoRespond(['error' => 'Строка не найдена'], 404);
    hoRequireEditableDoc($pdo, $hoUser, (int)$docId);

    if ($method === 'DELETE') {
        $pdo->prepare("DELETE FROM handover_items WHERE id = ?")->execute([(int)$hoId]);
        hoRespond(['ok' => true]);
    }
    $fields = [];
    $params = [];
    foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $f) {
        if (array_key_exists($f, $body)) { $fields[] = "$f = ?"; $params[] = hoText($body[$f]); }
    }
    if (array_key_exists('done', $body)) { $fields[] = "done = ?"; $params[] = $body['done'] ? 1 : 0; }
    if (array_key_exists('sort_order', $body)) { $fields[] = "sort_order = ?"; $params[] = (int)$body['sort_order']; }
    if (!$fields) hoRespond(['error' => 'Нечего менять'], 400);
    $params[] = (int)$hoId;
    $pdo->prepare("UPDATE handover_items SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    hoRespond(['ok' => true]);
}

hoRespond(['error' => 'Неизвестный маршрут модуля «Передача дел»'], 404);
