<?php
/**
 * Контакты поставщиков для кабинетов ресторанов.
 *
 * Под каждую пару «ресторан + поставщик» — отдельный список карточек контактов
 * (имя, должность, телефон, мессенджеры). Редактирование — только закупка
 * (роли с full-доступом к модулю restaurant-orders). Ресторан только читает.
 *
 * Маршруты:
 *   GET  restaurant-supplier-contacts/list?restaurant_id=N
 *        Список групп (поставщики + «свой склад») с контактами.
 *        Для роли ресторана restaurant_id игнорируется — берётся из сессии.
 *        Для закупки restaurant_id обязателен.
 *
 *   GET  restaurant-supplier-contacts/manager-overview
 *        Для UI закупки: все рестораны + их поставщики + кол-во контактов.
 *
 *   POST restaurant-supplier-contacts/save
 *        Создать / обновить контакт. { id?, restaurant_id, kind, supplier_id?,
 *        entity_group?, name, role?, phone?, email?, telegram?, whatsapp?,
 *        viber?, notes?, tags?, is_primary?, sort_order? }
 *
 *   POST restaurant-supplier-contacts/delete
 *        { id } — удалить контакт.
 */

if ($endpoint !== 'restaurant-supplier-contacts') return;

function rscRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ───────────────────────────────────────────────────────────────────────────
// Авторизация: сначала проверяем staff-токен (X-Session-Token), потом ro-сессию.
// Так у админа, у которого остался cookie ро-сессии после тестов кабинета,
// всё равно сработает доступ закупки — приоритет за X-Session-Token.
// ───────────────────────────────────────────────────────────────────────────
$rscStaffUser = getSessionUser($pdo);
$rscRoUser = $rscStaffUser ? null : roGetRestaurantSession($pdo);

$rscIsStaff = false;
$rscCanEdit = false;

if ($rscStaffUser) {
    $rscIsStaff = true;
    // Управление контактами — у тех, у кого full-доступ к модулю restaurant-orders.
    // В дефолтных ролях это admin / manager / user; индивидуальные permissions
    // могут перекрыть на 'view'/'none', и hidden_modules полностью закрывает модуль.
    $role = $rscStaffUser['role'] ?? '';
    $perms = [];
    if (!empty($rscStaffUser['permissions'])) {
        $p = is_string($rscStaffUser['permissions'])
            ? json_decode($rscStaffUser['permissions'], true)
            : $rscStaffUser['permissions'];
        if (is_array($p)) $perms = $p;
    }
    $hidden = [];
    if (!empty($rscStaffUser['hidden_modules'])) {
        $h = is_string($rscStaffUser['hidden_modules'])
            ? json_decode($rscStaffUser['hidden_modules'], true)
            : $rscStaffUser['hidden_modules'];
        if (is_array($h)) $hidden = $h;
    }
    $defaultPerm = in_array($role, ['admin', 'manager', 'user'], true) ? 'full' : 'none';
    $modulePerm = $perms['restaurant-orders'] ?? $defaultPerm;
    $isHidden   = in_array('restaurant-orders', $hidden, true);
    $rscCanEdit = ($modulePerm === 'full') && !$isHidden;
}

if (!$rscRoUser && !$rscIsStaff) {
    rscRespond(['error' => 'Требуется авторизация'], 401);
}

// ───────────────────────────────────────────────────────────────────────────
// Утилиты
// ───────────────────────────────────────────────────────────────────────────

/** Нормализуем телефон в E.164. Возвращаем '' если неверный формат. */
function rscNormalizePhone($raw) {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;
    // Оставляем только цифры и ведущий +
    $hasPlus = ($s[0] === '+');
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') return null;
    // Если первая цифра 8 и длина 11 (РФ-стиль) — конвертим в +7
    if (!$hasPlus && strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
        $hasPlus = true;
    }
    // Минимум 10 цифр (страна+номер)
    if (strlen($digits) < 10 || strlen($digits) > 15) return null;
    return '+' . $digits;
}

/**
 * Telegram → либо username (без @ и URL), либо телефон в E.164.
 * Хранит как есть, на фронте по виду подбирает правильную ссылку.
 * Возвращаем null если пусто/мусор.
 */
function rscNormalizeTelegram($raw) {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;
    // Убираем известные обёртки URL
    $s = preg_replace('#^https?://t\.me/#i', '', $s);
    $s = preg_replace('#^tg://resolve\?domain=#i', '', $s);
    $s = preg_replace('#^tg://resolve\?phone=#i', '', $s);
    // Если похоже на телефон (есть + или большинство символов — цифры) — нормализуем как телефон
    $looksLikePhone = (strpos($s, '+') !== false) || (preg_match('/^[\d\s()\-.]+$/', $s) === 1);
    if ($looksLikePhone) {
        $phone = rscNormalizePhone($s);
        if ($phone) return $phone; // E.164, начинается с +
        // если телефон битый — упадём дальше как username
    }
    // Username: срезаем @ и оставляем разрешённые символы
    $u = ltrim($s, '@');
    $u = preg_replace('/[^A-Za-z0-9_]/', '', $u);
    if ($u === '') return null;
    return mb_substr($u, 0, 60);
}

function rscNormalizeEmail($raw) {
    if ($raw === null) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;
    if (!filter_var($s, FILTER_VALIDATE_EMAIL)) return null;
    return mb_substr($s, 0, 100);
}

function rscNormalizeTags($raw) {
    if (!is_array($raw)) return null;
    $out = [];
    foreach ($raw as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $t = mb_substr($t, 0, 30);
        if (!in_array($t, $out, true)) $out[] = $t;
        if (count($out) >= 10) break;
    }
    return $out ?: null;
}

/** Сводная нормализация всех полей. Возвращает массив {ok, fields, error}. */
function rscNormalizeContactFields($body) {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') $name = null;
    elseif (mb_strlen($name) > 100) return ['ok' => false, 'error' => 'Имя длиннее 100 символов'];

    $role = trim((string)($body['role'] ?? ''));
    if ($role === '') $role = null;
    elseif (mb_strlen($role) > 60) return ['ok' => false, 'error' => 'Должность длиннее 60 символов'];

    $notes = trim((string)($body['notes'] ?? ''));
    if ($notes === '') $notes = null;
    elseif (mb_strlen($notes) > 500) return ['ok' => false, 'error' => 'Заметка длиннее 500 символов'];

    // Телефоны: поддерживаем два формата —
    //   (новый) body['phones'] = [{phone, label}, ...] — до 5 номеров с подписями
    //   (старый) body['phone']  = "+375..." — один номер без подписи
    // Если передан phones — он главный; phone оставляем для обратной совместимости.
    $phonesRaw = $body['phones'] ?? null;
    $phonesList = [];
    if (is_array($phonesRaw)) {
        foreach ($phonesRaw as $item) {
            if (!is_array($item)) continue;
            $p = rscNormalizePhone($item['phone'] ?? null);
            if (!empty($item['phone']) && $p === null) {
                return ['ok' => false, 'error' => 'Неверный формат телефона (ожидается +375..., 10–15 цифр)'];
            }
            if ($p === null) continue; // пустые игнорируем
            $label = trim((string)($item['label'] ?? ''));
            if (mb_strlen($label) > 30) $label = mb_substr($label, 0, 30);
            $phonesList[] = ['phone' => $p, 'label' => $label !== '' ? $label : null];
        }
        if (count($phonesList) > 5) {
            return ['ok' => false, 'error' => 'Максимум 5 телефонов в одной карточке'];
        }
    } elseif (!empty($body['phone'])) {
        $p = rscNormalizePhone($body['phone']);
        if ($p === null) return ['ok' => false, 'error' => 'Неверный формат телефона (ожидается +375..., 10–15 цифр)'];
        $phonesList[] = ['phone' => $p, 'label' => null];
    }
    $primaryPhone = $phonesList ? $phonesList[0]['phone'] : null;
    $phonesJson = $phonesList ? json_encode($phonesList, JSON_UNESCAPED_UNICODE) : null;

    $email = rscNormalizeEmail($body['email'] ?? null);
    if (!empty($body['email']) && $email === null) {
        return ['ok' => false, 'error' => 'Неверный формат email'];
    }

    $telegram = rscNormalizeTelegram($body['telegram'] ?? null);
    $whatsapp = rscNormalizePhone($body['whatsapp'] ?? null);
    if (!empty($body['whatsapp']) && $whatsapp === null) {
        return ['ok' => false, 'error' => 'Неверный формат WhatsApp (ожидается номер телефона)'];
    }
    $viber = rscNormalizePhone($body['viber'] ?? null);
    if (!empty($body['viber']) && $viber === null) {
        return ['ok' => false, 'error' => 'Неверный формат Viber (ожидается номер телефона)'];
    }

    $tags = rscNormalizeTags($body['tags'] ?? null);

    return [
        'ok' => true,
        'fields' => [
            'name' => $name,
            'role' => $role,
            'phone' => $primaryPhone,
            'phones_json' => $phonesJson,
            'email' => $email,
            'telegram' => $telegram,
            'whatsapp' => $whatsapp,
            'viber' => $viber,
            'notes' => $notes,
            'tags' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
        ],
    ];
}

/**
 * Парсим phones_json для отдачи клиенту. Если колонка пустая — fallback на
 * одиночное поле phone (старые записи).
 */
function rscReadPhones($phonesJson, $legacyPhone) {
    if ($phonesJson) {
        $arr = json_decode($phonesJson, true);
        if (is_array($arr)) {
            $out = [];
            foreach ($arr as $item) {
                if (!is_array($item) || empty($item['phone'])) continue;
                $out[] = ['phone' => $item['phone'], 'label' => $item['label'] ?? null];
            }
            if ($out) return $out;
        }
    }
    if (!empty($legacyPhone)) return [['phone' => $legacyPhone, 'label' => null]];
    return [];
}

/** Получить restaurant_id из RO-сессии (через JOIN). */
function rscRoRestaurantId($pdo, $roUser) {
    $rest = roGetRestaurantRow($pdo, $roUser['restaurant_number'], $roUser['legal_entity_group'] ?? null);
    return $rest ? (int)$rest['id'] : 0;
}

/** Прочитать ресторан по id с группой. Возвращает null если нет. */
function rscGetRestaurantById($pdo, $restaurantId) {
    $s = $pdo->prepare("SELECT id, number, legal_entity_group, region, city, address, active
                        FROM restaurants WHERE id = ? LIMIT 1");
    $s->execute([(int)$restaurantId]);
    return $s->fetch() ?: null;
}

/** Список потенциальных поставщиков для ресторана (та же группа, active). */
function rscGetSuppliersForRestaurant($pdo, $group) {
    $s = $pdo->prepare("
        SELECT id, short_name, full_name, legal_entity_group, is_active, so_enabled
        FROM suppliers
        WHERE legal_entity_group = ? AND is_active = 1
        ORDER BY COALESCE(short_name, full_name)
    ");
    $s->execute([$group]);
    return $s->fetchAll();
}

/** Подгрузить контакты ресторана и сгруппировать по (kind, supplier_id). */
function rscLoadContactsGrouped($pdo, $restaurantId) {
    $s = $pdo->prepare("
        SELECT c.id, c.kind, c.supplier_id, c.entity_group, c.name, c.role,
               c.phone, c.phones_json, c.email, c.telegram, c.whatsapp, c.viber, c.notes,
               c.tags, c.is_primary, c.is_active, c.sort_order,
               c.created_at, c.updated_at, c.updated_by
        FROM restaurant_supplier_contacts c
        WHERE c.restaurant_id = ? AND c.is_active = 1
        ORDER BY c.kind, c.supplier_id, c.is_primary DESC, c.sort_order, c.id
    ");
    $s->execute([(int)$restaurantId]);
    $rows = $s->fetchAll();
    $out = []; // key = "internal:BK_VM" | "external:<uuid>" → list of contacts
    foreach ($rows as $r) {
        $key = $r['kind'] === 'internal'
            ? 'internal:' . ($r['entity_group'] ?? '')
            : 'external:' . ($r['supplier_id'] ?? '');
        if (!empty($r['tags'])) {
            $decoded = json_decode($r['tags'], true);
            $r['tags'] = is_array($decoded) ? $decoded : [];
        } else {
            $r['tags'] = [];
        }
        $r['is_primary'] = (int)$r['is_primary'] === 1;
        $r['is_active']  = (int)$r['is_active']  === 1;
        $r['id'] = (int)$r['id'];
        $r['phones'] = rscReadPhones($r['phones_json'] ?? null, $r['phone'] ?? null);
        unset($r['phones_json']);
        if (!isset($out[$key])) $out[$key] = [];
        $out[$key][] = $r;
    }
    return $out;
}

/** Собрать группы для UI (свой склад + поставщики). */
function rscBuildGroups($pdo, $restaurant, $forStaff) {
    $contactsByKey = rscLoadContactsGrouped($pdo, $restaurant['id']);
    $group = $restaurant['legal_entity_group'] ?? 'BK_VM';

    $groups = [];

    // 1) Свой склад (internal)
    $internalKey = 'internal:' . $group;
    $internalTitle = $group === 'PS' ? 'Свой склад Пицца Стар' : 'Свой склад БК+ВМ';
    $groups[] = [
        'key' => $internalKey,
        'kind' => 'internal',
        'entity_group' => $group,
        'supplier_id' => null,
        'title' => $internalTitle,
        'subtitle' => 'Логистика, кладовщик, ответственный по доставке',
        'contacts' => $contactsByKey[$internalKey] ?? [],
    ];

    // 2) Внешние поставщики (active в этой группе)
    $suppliers = rscGetSuppliersForRestaurant($pdo, $group);
    foreach ($suppliers as $sup) {
        $supKey = 'external:' . $sup['id'];
        $contacts = $contactsByKey[$supKey] ?? [];
        // Для роли ресторана пропускаем поставщиков без контактов —
        // чтобы пустая вкладка не превращалась в каталог.
        if (!$forStaff && !$contacts) continue;
        $groups[] = [
            'key' => $supKey,
            'kind' => 'external',
            'entity_group' => null,
            'supplier_id' => $sup['id'],
            'title' => $sup['short_name'] ?: $sup['full_name'],
            'subtitle' => null,
            'contacts' => $contacts,
        ];
    }

    // 3) «Осиротевшие» внешние контакты — на случай если поставщик стал неактивен
    //    (FK RESTRICT не даст удалить, но is_active=0 возможен). Показываем всё равно.
    foreach ($contactsByKey as $key => $contacts) {
        if (strpos($key, 'external:') !== 0) continue;
        $alreadyIncluded = false;
        foreach ($groups as $g) {
            if ($g['key'] === $key) { $alreadyIncluded = true; break; }
        }
        if ($alreadyIncluded) continue;
        // Поставщик есть, но не в активных — подгружаем имя
        $supId = substr($key, strlen('external:'));
        $st = $pdo->prepare("SELECT short_name, full_name FROM suppliers WHERE id = ? LIMIT 1");
        $st->execute([$supId]);
        $sup = $st->fetch();
        $groups[] = [
            'key' => $key,
            'kind' => 'external',
            'entity_group' => null,
            'supplier_id' => $supId,
            'title' => ($sup['short_name'] ?? '') ?: ($sup['full_name'] ?? 'Поставщик') . ' (неактивен)',
            'subtitle' => 'Поставщик помечен неактивным',
            'contacts' => $contacts,
        ];
    }

    return $groups;
}

// ───────────────────────────────────────────────────────────────────────────
// GET list
// ───────────────────────────────────────────────────────────────────────────
if (($subpoint === 'list' || $subpoint === null) && $method === 'GET') {
    if ($rscIsStaff) {
        $restaurantId = (int)($_GET['restaurant_id'] ?? 0);
        if ($restaurantId <= 0) rscRespond(['error' => 'restaurant_id обязателен'], 400);
        $rest = rscGetRestaurantById($pdo, $restaurantId);
        if (!$rest) rscRespond(['error' => 'Ресторан не найден'], 404);
        $groups = rscBuildGroups($pdo, $rest, true);
    } else {
        $restaurantId = rscRoRestaurantId($pdo, $rscRoUser);
        if (!$restaurantId) rscRespond(['error' => 'Ресторан не определён'], 400);
        $rest = rscGetRestaurantById($pdo, $restaurantId);
        if (!$rest) rscRespond(['error' => 'Ресторан не найден'], 404);
        $groups = rscBuildGroups($pdo, $rest, false);
    }
    rscRespond([
        'restaurant' => [
            'id' => (int)$rest['id'],
            'number' => (int)$rest['number'],
            'legal_entity_group' => $rest['legal_entity_group'],
        ],
        'groups' => $groups,
        'can_edit' => $rscCanEdit,
    ]);
}

// ───────────────────────────────────────────────────────────────────────────
// GET suppliers — список активных поставщиков по группе (для модалки массового
// добавления). Только для сотрудников закупки.
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'suppliers' && $method === 'GET') {
    if (!$rscIsStaff) rscRespond(['error' => 'Только для сотрудников закупки'], 403);
    $group = trim((string)($_GET['group'] ?? ''));
    if ($group !== 'BK_VM' && $group !== 'PS') {
        rscRespond(['error' => 'group должен быть BK_VM или PS'], 400);
    }
    $st = $pdo->prepare("
        SELECT id, short_name, full_name, legal_entity_group
        FROM suppliers
        WHERE legal_entity_group = ? AND is_active = 1
        ORDER BY COALESCE(short_name, full_name)
    ");
    $st->execute([$group]);
    rscRespond(['suppliers' => $st->fetchAll()]);
}

// ───────────────────────────────────────────────────────────────────────────
// GET manager-overview — список ресторанов с числом контактов (для UI закупки)
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'manager-overview' && $method === 'GET') {
    if (!$rscIsStaff) rscRespond(['error' => 'Только для сотрудников закупки'], 403);
    $s = $pdo->query("
        SELECT r.id, r.number, r.legal_entity_group, r.region, r.city, r.address,
               COALESCE((
                 SELECT COUNT(*) FROM restaurant_supplier_contacts c
                 WHERE c.restaurant_id = r.id AND c.is_active = 1
               ), 0) AS contacts_count
        FROM restaurants r
        WHERE r.active = 1
        ORDER BY r.legal_entity_group, r.number
    ");
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['number'] = (int)$r['number'];
        $r['contacts_count'] = (int)$r['contacts_count'];
    }
    rscRespond(['restaurants' => $rows]);
}

// ───────────────────────────────────────────────────────────────────────────
// POST save — создание/обновление контакта (только закупка)
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'save' && $method === 'POST') {
    if (!$rscIsStaff || !$rscCanEdit) rscRespond(['error' => 'Нет прав на изменение контактов'], 403);

    $id = isset($body['id']) ? (int)$body['id'] : 0;
    $restaurantId = (int)($body['restaurant_id'] ?? 0);
    $kind = $body['kind'] ?? 'external';
    if (!in_array($kind, ['external', 'internal'], true)) {
        rscRespond(['error' => 'Неверное значение kind'], 400);
    }

    $rest = rscGetRestaurantById($pdo, $restaurantId);
    if (!$rest) rscRespond(['error' => 'Ресторан не найден'], 400);

    $supplierId = null;
    $entityGroup = null;
    if ($kind === 'external') {
        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        if ($supplierId === '') rscRespond(['error' => 'Не выбран поставщик'], 400);
        // Проверим, что поставщик существует и в той же группе, что и ресторан
        $st = $pdo->prepare("SELECT id, legal_entity_group FROM suppliers WHERE id = ? LIMIT 1");
        $st->execute([$supplierId]);
        $sup = $st->fetch();
        if (!$sup) rscRespond(['error' => 'Поставщик не найден'], 400);
        if ($sup['legal_entity_group'] !== $rest['legal_entity_group']) {
            rscRespond(['error' => 'Поставщик из другой группы юрлиц'], 400);
        }
    } else {
        // internal: entity_group берём из ресторана, чтобы не было разнобоя
        $entityGroup = $rest['legal_entity_group'] ?? 'BK_VM';
    }

    $norm = rscNormalizeContactFields($body);
    if (!$norm['ok']) rscRespond(['error' => $norm['error']], 400);
    $f = $norm['fields'];

    $isPrimary = !empty($body['is_primary']) ? 1 : 0;
    $sortOrder = (int)($body['sort_order'] ?? 0);
    $updatedBy = isset($rscStaffUser['name']) ? $rscStaffUser['name'] : null;

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            // UPDATE
            $st = $pdo->prepare("
                SELECT id, restaurant_id, kind, supplier_id, entity_group
                FROM restaurant_supplier_contacts
                WHERE id = ? LIMIT 1
            ");
            $st->execute([$id]);
            $existing = $st->fetch();
            if (!$existing) {
                $pdo->rollBack();
                rscRespond(['error' => 'Контакт не найден'], 404);
            }
            // Меняем все поля, кроме привязок (restaurant_id/kind/supplier_id/entity_group остаются)
            $upd = $pdo->prepare("
                UPDATE restaurant_supplier_contacts
                SET name = ?, role = ?, phone = ?, phones_json = ?, email = ?,
                    telegram = ?, whatsapp = ?, viber = ?, notes = ?, tags = ?,
                    is_primary = ?, sort_order = ?, updated_by = ?
                WHERE id = ?
            ");
            $upd->execute([
                $f['name'], $f['role'], $f['phone'], $f['phones_json'], $f['email'],
                $f['telegram'], $f['whatsapp'], $f['viber'], $f['notes'], $f['tags'],
                $isPrimary, $sortOrder, $updatedBy, $id,
            ]);
            $savedId = $id;
            $scopeRest = (int)$existing['restaurant_id'];
            $scopeKind = $existing['kind'];
            $scopeSupplier = $existing['supplier_id'];
            $scopeGroup = $existing['entity_group'];
        } else {
            // INSERT
            $ins = $pdo->prepare("
                INSERT INTO restaurant_supplier_contacts
                (restaurant_id, kind, supplier_id, entity_group,
                 name, role, phone, phones_json, email, telegram, whatsapp, viber, notes, tags,
                 is_primary, is_active, sort_order, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ");
            $ins->execute([
                $restaurantId, $kind, $supplierId, $entityGroup,
                $f['name'], $f['role'], $f['phone'], $f['phones_json'], $f['email'],
                $f['telegram'], $f['whatsapp'], $f['viber'], $f['notes'], $f['tags'],
                $isPrimary, $sortOrder, $updatedBy,
            ]);
            $savedId = (int)$pdo->lastInsertId();
            $scopeRest = $restaurantId;
            $scopeKind = $kind;
            $scopeSupplier = $supplierId;
            $scopeGroup = $entityGroup;
        }

        // Если флаг «основной» поставлен — сбрасываем у других в той же паре
        if ($isPrimary === 1) {
            if ($scopeKind === 'external') {
                $reset = $pdo->prepare("
                    UPDATE restaurant_supplier_contacts
                    SET is_primary = 0
                    WHERE restaurant_id = ? AND kind = 'external' AND supplier_id = ? AND id <> ?
                ");
                $reset->execute([$scopeRest, $scopeSupplier, $savedId]);
            } else {
                $reset = $pdo->prepare("
                    UPDATE restaurant_supplier_contacts
                    SET is_primary = 0
                    WHERE restaurant_id = ? AND kind = 'internal' AND entity_group = ? AND id <> ?
                ");
                $reset->execute([$scopeRest, $scopeGroup, $savedId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[rsc save] rest=' . $restaurantId . ' kind=' . $kind . ' err=' . $e->getMessage());
        rscRespond(['error' => 'Не удалось сохранить контакт'], 500);
    }

    rscRespond(['ok' => true, 'id' => $savedId]);
}

// ───────────────────────────────────────────────────────────────────────────
// POST bulk-create — создать одну и ту же карточку контакта во многих ресторанах
// Только закупка. Параметры:
//   restaurant_ids[]  — список id ресторанов-приёмников
//   kind              — 'external' | 'internal'
//   supplier_id       — обязателен при kind='external'
//   + все поля контакта (name, role, phone, email, telegram, whatsapp, viber,
//     notes, tags, is_primary, sort_order)
// При kind='internal' entity_group берётся из ресторана (свой склад).
// При kind='external' проверяется, что поставщик из той же группы, что и ресторан;
// несовпавшие рестораны пропускаются и возвращаются в `skipped` с пометкой.
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'bulk-create' && $method === 'POST') {
    if (!$rscIsStaff || !$rscCanEdit) rscRespond(['error' => 'Нет прав на изменение контактов'], 403);

    $ids = $body['restaurant_ids'] ?? [];
    if (!is_array($ids) || !count($ids)) {
        rscRespond(['error' => 'Не выбран ни один ресторан'], 400);
    }
    // Дедуп и приведение к int
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!count($ids)) rscRespond(['error' => 'Не выбран ни один ресторан'], 400);
    if (count($ids) > 200) rscRespond(['error' => 'Слишком много ресторанов за раз (максимум 200)'], 400);

    $kind = $body['kind'] ?? 'external';
    if (!in_array($kind, ['external', 'internal'], true)) {
        rscRespond(['error' => 'Неверное значение kind'], 400);
    }

    $supplierId = null;
    $supplierGroup = null;
    if ($kind === 'external') {
        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        if ($supplierId === '') rscRespond(['error' => 'Не выбран поставщик'], 400);
        $st = $pdo->prepare("SELECT id, legal_entity_group FROM suppliers WHERE id = ? LIMIT 1");
        $st->execute([$supplierId]);
        $sup = $st->fetch();
        if (!$sup) rscRespond(['error' => 'Поставщик не найден'], 400);
        $supplierGroup = $sup['legal_entity_group'];
    }

    $norm = rscNormalizeContactFields($body);
    if (!$norm['ok']) rscRespond(['error' => $norm['error']], 400);
    $f = $norm['fields'];
    $isPrimary = !empty($body['is_primary']) ? 1 : 0;
    $sortOrder = (int)($body['sort_order'] ?? 0);
    $updatedBy = isset($rscStaffUser['name']) ? $rscStaffUser['name'] : null;

    $created = 0;
    $skipped = [];
    $errors  = [];

    // По одному ресторану в своей транзакции — чтобы ошибка в одном не уронила все
    $restSt = $pdo->prepare("SELECT id, number, legal_entity_group FROM restaurants WHERE id = ? AND active = 1 LIMIT 1");
    $insSt  = $pdo->prepare("
        INSERT INTO restaurant_supplier_contacts
        (restaurant_id, kind, supplier_id, entity_group,
         name, role, phone, phones_json, email, telegram, whatsapp, viber, notes, tags,
         is_primary, is_active, sort_order, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
    ");
    $resetExt = $pdo->prepare("
        UPDATE restaurant_supplier_contacts
        SET is_primary = 0
        WHERE restaurant_id = ? AND kind = 'external' AND supplier_id = ? AND id <> ?
    ");
    $resetInt = $pdo->prepare("
        UPDATE restaurant_supplier_contacts
        SET is_primary = 0
        WHERE restaurant_id = ? AND kind = 'internal' AND entity_group = ? AND id <> ?
    ");

    foreach ($ids as $rid) {
        $restSt->execute([$rid]);
        $rest = $restSt->fetch();
        if (!$rest) {
            $skipped[] = ['restaurant_id' => $rid, 'reason' => 'не найден или неактивен'];
            continue;
        }
        $entityGroup = null;
        if ($kind === 'external') {
            if ($rest['legal_entity_group'] !== $supplierGroup) {
                $skipped[] = [
                    'restaurant_id' => $rid,
                    'number' => (int)$rest['number'],
                    'reason' => 'поставщик не работает с этой группой юрлиц',
                ];
                continue;
            }
        } else {
            $entityGroup = $rest['legal_entity_group'] ?? 'BK_VM';
        }
        try {
            $pdo->beginTransaction();
            $insSt->execute([
                $rid, $kind, $supplierId, $entityGroup,
                $f['name'], $f['role'], $f['phone'], $f['phones_json'], $f['email'],
                $f['telegram'], $f['whatsapp'], $f['viber'], $f['notes'], $f['tags'],
                $isPrimary, $sortOrder, $updatedBy,
            ]);
            $savedId = (int)$pdo->lastInsertId();
            if ($isPrimary === 1) {
                if ($kind === 'external') $resetExt->execute([$rid, $supplierId, $savedId]);
                else                      $resetInt->execute([$rid, $entityGroup, $savedId]);
            }
            $pdo->commit();
            $created++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[rsc bulk-create] rest=' . $rid . ' err=' . $e->getMessage());
            $errors[] = ['restaurant_id' => $rid, 'number' => (int)($rest['number'] ?? 0), 'reason' => 'ошибка БД'];
        }
    }

    rscRespond([
        'ok' => true,
        'created'  => $created,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'total'    => count($ids),
    ]);
}

// ───────────────────────────────────────────────────────────────────────────
// GET find-by-supplier — найти все контакты поставщика (или своего склада)
// по всем ресторанам. Для модалки массового удаления.
//   ?kind=external&supplier_id=UUID
//   ?kind=internal&entity_group=BK_VM|PS
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'find-by-supplier' && $method === 'GET') {
    if (!$rscIsStaff) rscRespond(['error' => 'Только для сотрудников закупки'], 403);
    $kind = $_GET['kind'] ?? 'external';
    if (!in_array($kind, ['external', 'internal'], true)) {
        rscRespond(['error' => 'kind должен быть external или internal'], 400);
    }
    if ($kind === 'external') {
        $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
        if ($supplierId === '') rscRespond(['error' => 'supplier_id обязателен'], 400);
        $st = $pdo->prepare("
            SELECT c.id, c.restaurant_id, r.number AS restaurant_number, r.legal_entity_group,
                   c.name, c.role, c.phone, c.phones_json, c.email, c.telegram, c.whatsapp, c.viber,
                   c.notes, c.tags, c.is_primary, c.created_at
            FROM restaurant_supplier_contacts c
            JOIN restaurants r ON r.id = c.restaurant_id
            WHERE c.kind = 'external' AND c.supplier_id = ? AND c.is_active = 1
            ORDER BY c.name, c.phone, r.number
        ");
        $st->execute([$supplierId]);
    } else {
        $group = $_GET['entity_group'] ?? '';
        if (!in_array($group, ['BK_VM', 'PS'], true)) {
            rscRespond(['error' => 'entity_group должен быть BK_VM или PS'], 400);
        }
        $st = $pdo->prepare("
            SELECT c.id, c.restaurant_id, r.number AS restaurant_number, r.legal_entity_group,
                   c.name, c.role, c.phone, c.phones_json, c.email, c.telegram, c.whatsapp, c.viber,
                   c.notes, c.tags, c.is_primary, c.created_at
            FROM restaurant_supplier_contacts c
            JOIN restaurants r ON r.id = c.restaurant_id
            WHERE c.kind = 'internal' AND c.entity_group = ? AND c.is_active = 1
            ORDER BY c.name, c.phone, r.number
        ");
        $st->execute([$group]);
    }
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['restaurant_id'] = (int)$r['restaurant_id'];
        $r['restaurant_number'] = (int)$r['restaurant_number'];
        $r['is_primary'] = (int)$r['is_primary'] === 1;
        $r['phones'] = rscReadPhones($r['phones_json'] ?? null, $r['phone'] ?? null);
        if (!empty($r['tags'])) {
            $decodedTags = json_decode($r['tags'], true);
            $r['tags'] = is_array($decodedTags) ? $decodedTags : [];
        } else {
            $r['tags'] = [];
        }
        unset($r['phones_json']);
    }
    rscRespond(['contacts' => $rows]);
}

// ───────────────────────────────────────────────────────────────────────────
// POST bulk-update — обновить много контактов сразу (только закупка).
// Принимает: { ids: [int], fields: {name?, role?, phone?, phones?, email?,
//   telegram?, whatsapp?, viber?, notes?, tags?, is_primary?, sort_order?} }
// Меняем ТОЛЬКО те поля, что явно переданы. Для is_primary=1 — после апдейта
// каждого контакта сбрасываем остальные primary в рамках того же ресторана+
// поставщика/группы (как в bulk-create).
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'bulk-update' && $method === 'POST') {
    if (!$rscIsStaff || !$rscCanEdit) rscRespond(['error' => 'Нет прав на изменение контактов'], 403);
    $ids = $body['ids'] ?? [];
    if (!is_array($ids) || !count($ids)) rscRespond(['error' => 'Не выбран ни один контакт'], 400);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!count($ids)) rscRespond(['error' => 'Не выбран ни один контакт'], 400);
    if (count($ids) > 1000) rscRespond(['error' => 'Слишком много контактов за раз (максимум 1000)'], 400);

    $fields = $body['fields'] ?? [];
    if (!is_array($fields) || !count($fields)) {
        rscRespond(['error' => 'Не передано ни одно поле для изменения'], 400);
    }

    // Сначала валидируем все переданные поля одним проходом через нормализатор.
    // Те, которых нет — игнорируем.
    $norm = rscNormalizeContactFields($fields);
    if (!$norm['ok']) rscRespond(['error' => $norm['error']], 400);
    $f = $norm['fields'];

    // Собираем SET ... только по явно переданным ключам.
    $setParts = [];
    $params = [];
    if (array_key_exists('name', $fields))     { $setParts[] = 'name = ?';        $params[] = $f['name']; }
    if (array_key_exists('role', $fields))     { $setParts[] = 'role = ?';        $params[] = $f['role']; }
    // phones и phone редактируем вместе: если пришли «phones» — мы заодно обновляем legacy phone.
    if (array_key_exists('phones', $fields) || array_key_exists('phone', $fields)) {
        $setParts[] = 'phone = ?';         $params[] = $f['phone'];
        $setParts[] = 'phones_json = ?';   $params[] = $f['phones_json'];
    }
    if (array_key_exists('email', $fields))    { $setParts[] = 'email = ?';       $params[] = $f['email']; }
    if (array_key_exists('telegram', $fields)) { $setParts[] = 'telegram = ?';    $params[] = $f['telegram']; }
    if (array_key_exists('whatsapp', $fields)) { $setParts[] = 'whatsapp = ?';    $params[] = $f['whatsapp']; }
    if (array_key_exists('viber', $fields))    { $setParts[] = 'viber = ?';       $params[] = $f['viber']; }
    if (array_key_exists('notes', $fields))    { $setParts[] = 'notes = ?';       $params[] = $f['notes']; }
    if (array_key_exists('tags', $fields))     { $setParts[] = 'tags = ?';        $params[] = $f['tags']; }
    $changingPrimary = array_key_exists('is_primary', $fields);
    $newPrimary = $changingPrimary ? (!empty($fields['is_primary']) ? 1 : 0) : null;
    if ($changingPrimary) { $setParts[] = 'is_primary = ?'; $params[] = $newPrimary; }
    if (array_key_exists('sort_order', $fields)) { $setParts[] = 'sort_order = ?'; $params[] = (int)$fields['sort_order']; }

    if (!$setParts) rscRespond(['error' => 'Не передано ни одно поле для изменения'], 400);

    $updatedBy = isset($rscStaffUser['name']) ? $rscStaffUser['name'] : null;
    $setParts[] = 'updated_by = ?';
    $params[] = $updatedBy;

    $updated = 0;
    $errors = [];

    // Загружаем все контакты разом — нужны restaurant_id/kind/supplier_id/entity_group
    // для сброса флага is_primary в правильном scope.
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $scopeStmt = $pdo->prepare("SELECT id, restaurant_id, kind, supplier_id, entity_group FROM restaurant_supplier_contacts WHERE id IN ($ph)");
    $scopeStmt->execute($ids);
    $scopes = [];
    foreach ($scopeStmt->fetchAll() as $row) {
        $scopes[(int)$row['id']] = $row;
    }

    $updStmt = $pdo->prepare("UPDATE restaurant_supplier_contacts SET " . implode(', ', $setParts) . " WHERE id = ?");
    $resetExt = $pdo->prepare("UPDATE restaurant_supplier_contacts SET is_primary = 0 WHERE restaurant_id = ? AND kind = 'external' AND supplier_id = ? AND id <> ?");
    $resetInt = $pdo->prepare("UPDATE restaurant_supplier_contacts SET is_primary = 0 WHERE restaurant_id = ? AND kind = 'internal' AND entity_group = ? AND id <> ?");

    foreach ($ids as $id) {
        if (!isset($scopes[$id])) { $errors[] = ['id' => $id, 'reason' => 'не найден']; continue; }
        try {
            $pdo->beginTransaction();
            $execParams = $params;
            $execParams[] = $id;
            $updStmt->execute($execParams);
            if ($changingPrimary && $newPrimary === 1) {
                $sc = $scopes[$id];
                if ($sc['kind'] === 'external') $resetExt->execute([(int)$sc['restaurant_id'], $sc['supplier_id'], $id]);
                else                            $resetInt->execute([(int)$sc['restaurant_id'], $sc['entity_group'], $id]);
            }
            $pdo->commit();
            $updated++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[rsc bulk-update] id=' . $id . ' err=' . $e->getMessage());
            $errors[] = ['id' => $id, 'reason' => 'ошибка БД'];
        }
    }

    rscRespond([
        'ok' => true,
        'updated' => $updated,
        'errors' => $errors,
        'total' => count($ids),
    ]);
}

// ───────────────────────────────────────────────────────────────────────────
// POST bulk-delete — удалить много контактов сразу по id (только закупка)
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'bulk-delete' && $method === 'POST') {
    if (!$rscIsStaff || !$rscCanEdit) rscRespond(['error' => 'Нет прав на удаление контактов'], 403);
    $ids = $body['ids'] ?? [];
    if (!is_array($ids) || !count($ids)) rscRespond(['error' => 'Не выбран ни один контакт'], 400);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!count($ids)) rscRespond(['error' => 'Не выбран ни один контакт'], 400);
    if (count($ids) > 1000) rscRespond(['error' => 'Слишком много контактов за раз (максимум 1000)'], 400);
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("DELETE FROM restaurant_supplier_contacts WHERE id IN ($placeholders)");
        $st->execute($ids);
        rscRespond(['ok' => true, 'deleted' => $st->rowCount()]);
    } catch (Throwable $e) {
        error_log('[rsc bulk-delete] err=' . $e->getMessage());
        rscRespond(['error' => 'Не удалось удалить контакты'], 500);
    }
}

// ───────────────────────────────────────────────────────────────────────────
// POST delete — удалить контакт (только закупка)
// ───────────────────────────────────────────────────────────────────────────
if ($subpoint === 'delete' && $method === 'POST') {
    if (!$rscIsStaff || !$rscCanEdit) rscRespond(['error' => 'Нет прав на удаление контактов'], 403);
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) rscRespond(['error' => 'id обязателен'], 400);
    try {
        $st = $pdo->prepare("DELETE FROM restaurant_supplier_contacts WHERE id = ?");
        $st->execute([$id]);
    } catch (Throwable $e) {
        error_log('[rsc delete] id=' . $id . ' err=' . $e->getMessage());
        rscRespond(['error' => 'Не удалось удалить контакт'], 500);
    }
    rscRespond(['ok' => true]);
}

rscRespond(['error' => 'Неизвестный маршрут'], 404);
