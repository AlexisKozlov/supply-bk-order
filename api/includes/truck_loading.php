<?php
/**
 * API модуля «Загрузка машин» (truck loading).
 * Подключается из index.php. Все переменные ($pdo, $endpoint, $subpoint, $method, $body) доступны.
 *
 * Маршруты:
 *   GET    tl/vehicles                — список типов машин
 *   POST   tl/vehicles                — создать/обновить тип машины
 *   DELETE tl/vehicles/:id            — удалить (деактивировать) тип машины
 *   GET    tl/directions              — направления доставки + справочники для конструктора
 *   POST   tl/directions              — создать/обновить направление
 *   DELETE tl/directions/:id          — удалить (деактивировать) направление
 *   GET    tl/orders?date=YYYY-MM-DD  — заказы ресторанов на дату с агрегацией
 *   GET    tl/plan?date=YYYY-MM-DD    — загрузить план на дату
 *   POST   tl/plan                    — создать/обновить план
 *   DELETE tl/plan/:id                — удалить план
 *   PATCH  tl/plan/:id/status         — изменить статус плана
 *   POST   tl/auto-assign             — автоматическое распределение (bin-packing)
 *
 * Особенности контракта:
 *  - в каждом назначении есть legal_entity_group ('BK_VM' | 'PS') — фронту не надо
 *    угадывать юрлицо по номеру ресторана;
 *  - направление ресторана считает сервер (по городам и ручным спискам), клиент
 *    получает готовые direction_id и direction_name и ничего не вычисляет сам;
 *  - POST tl/plan защищён от затирания чужой работы: клиент присылает updated_at
 *    плана, который загружал, и получает 409, если в базе версия новее.
 *    В ответе на сохранение и на смену статуса возвращается свежий updated_at.
 */

if ($endpoint !== 'tl') return;

// ═══ Хелперы ═══

function tlRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/**
 * Проверка уровня доступа к модулю «Загрузка машин».
 * Раньше этот блок был скопирован в четырёх местах файла — расходились
 * формулировки ошибок, и любая правка требовала четырёх одинаковых изменений.
 *
 * $level — 'view' | 'edit' | 'full'. Админ проходит всегда, запрос по API-ключу
 * (без сессии) уже проверен выше в checkApiKey().
 */
function tlRequireLevel($sessionUser, $level, $errorMessage = 'Недостаточно прав') {
    if (!$sessionUser) return;
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    $userRole = $sessionUser['role'] ?? 'user';
    if ($userRole === 'admin') return;
    $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
    $have = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
    $need = $ACCESS_LEVELS[$level] ?? 0;
    if ($have < $need) tlRespond(['error' => $errorMessage], 403);
}

// ═══ Авторизация и RBAC ═══

$sessionUser = getSessionUser($pdo);
if (!$sessionUser) {
    if (!checkApiKey($pdo)) tlRespond(['error' => 'Unauthorized'], 401);
}
if ($sessionUser) {
    $userRole = $sessionUser['role'] ?? 'user';
    // Доступ к модулю только для admin и manager — защита по роли независимо от прав
    if ($userRole !== 'admin' && $userRole !== 'manager') {
        tlRespond(['error' => 'Модуль доступен только администратору и менеджеру'], 403);
    }
    // Базовый уровень: чтение — «Просмотр», любое изменение — «Редактирование».
    // Отдельные действия ниже требуют больше (см. tlRequireLevel(..., 'full')).
    tlRequireLevel($sessionUser, ($method === 'GET') ? 'view' : 'edit');
}

// Разбор URL: tl/{action}/{param1}/{param2}
$parts = explode('/', trim($uri, '/'));
// parts[0] = 'tl', parts[1] = action, parts[2] = param1, parts[3] = param2
$tlAction = $parts[1] ?? '';
$tlParam1 = $parts[2] ?? null;
$tlParam2 = $parts[3] ?? null;


// ════════════════════════════════════════════
// GET tl/vehicles — список активных типов машин
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'GET') {
    $s = $pdo->query("SELECT id, name, capacity_pallets, capacity_kg, sort_order FROM tl_vehicles WHERE is_active = 1 ORDER BY sort_order, name");
    tlRespond(['vehicles' => $s->fetchAll()]);
}

// ════════════════════════════════════════════
// POST tl/vehicles — создать/обновить тип машины
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'POST') {
    tlRequireLevel($sessionUser, 'edit');

    $name = trim($body['name'] ?? '');
    $capacityPallets = floatval($body['capacity_pallets'] ?? 0);
    $capacityKg = floatval($body['capacity_kg'] ?? 0);
    $sortOrder = intval($body['sort_order'] ?? 0);

    if (!$name) tlRespond(['error' => 'Не указано название машины'], 400);

    $id = $body['id'] ?? null;
    $isNewVehicle = !$id;
    $pdo->beginTransaction();
    try {
        if ($id) {
            $s = $pdo->prepare("UPDATE tl_vehicles SET name = ?, capacity_pallets = ?, capacity_kg = ?, sort_order = ? WHERE id = ?");
            $s->execute([$name, $capacityPallets, $capacityKg, $sortOrder, $id]);
        } else {
            $s = $pdo->prepare("INSERT INTO tl_vehicles (name, capacity_pallets, capacity_kg, sort_order) VALUES (?, ?, ?, ?)");
            $s->execute([$name, $capacityPallets, $capacityKg, $sortOrder]);
            $id = $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tlRespond(['error' => 'Ошибка сохранения машины: ' . $e->getMessage()], 500);
    }

    auditLog($pdo, $isNewVehicle ? 'tl_vehicle_created' : 'tl_vehicle_updated', 'truck_vehicle', (string)$id,
        resolveActorName($pdo, $sessionUser, 'api'), [
            'name'             => $name,
            'capacity_pallets' => $capacityPallets,
            'capacity_kg'      => $capacityKg,
        ]);

    tlRespond(['success' => true, 'id' => (int)$id]);
}

// ════════════════════════════════════════════
// DELETE tl/vehicles/:id — деактивировать тип машины
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'DELETE' && $tlParam1) {
    tlRequireLevel($sessionUser, 'full');

    $vehicleId = intval($tlParam1);
    // Название читаем до деактивации — в журнале «удалили машину №7» бесполезно.
    $vNameStmt = $pdo->prepare("SELECT name FROM tl_vehicles WHERE id = ?");
    $vNameStmt->execute([$vehicleId]);
    $vehicleName = $vNameStmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tl_vehicles SET is_active = 0 WHERE id = ?")->execute([$vehicleId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tlRespond(['error' => 'Ошибка удаления машины: ' . $e->getMessage()], 500);
    }

    auditLog($pdo, 'tl_vehicle_deleted', 'truck_vehicle', (string)$vehicleId,
        resolveActorName($pdo, $sessionUser, 'api'), ['name' => $vehicleName ?: null]);

    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// Направления доставки
// ════════════════════════════════════════════

/**
 * Города сравниваем без учёта регистра и лишних пробелов: в справочнике
 * ресторанов один и тот же город встречается как «Минск» и как «минск ».
 */
function tlNormalizeCity($city) {
    $c = trim((string)$city);
    return function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
}

/** JSON-поле направления → простой массив (NULL и мусор превращаются в []). */
function tlJsonList($raw) {
    if ($raw === null || $raw === '') return [];
    $v = json_decode($raw, true);
    return is_array($v) ? array_values($v) : [];
}

/** Список номеров ресторанов из тела запроса: только положительные, без повторов. */
function tlIntList($raw) {
    $out = [];
    foreach ((array)$raw as $v) {
        $n = intval($v);
        if ($n > 0 && !in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

/**
 * Направления из базы.
 * $onlyActive = false нужен там, где по direction_id сохранённого плана надо
 * показать название направления, которое успели удалить.
 */
function tlFetchDirections($pdo, $onlyActive = true) {
    $sql = "SELECT id, name, cities, include_restaurants, exclude_restaurants, sort_order, is_active
            FROM tl_directions"
         . ($onlyActive ? " WHERE is_active = 1" : "")
         . " ORDER BY sort_order, id";
    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $out[] = [
            'id'                  => (int)$r['id'],
            'name'                => $r['name'],
            'cities'              => array_map('strval', tlJsonList($r['cities'])),
            'include_restaurants' => array_map('intval', tlJsonList($r['include_restaurants'])),
            'exclude_restaurants' => array_map('intval', tlJsonList($r['exclude_restaurants'])),
            'sort_order'          => (int)$r['sort_order'],
            'is_active'           => (int)$r['is_active'],
        ];
    }
    return $out;
}

/** Активные рестораны: номер, город, группа юрлиц. */
function tlFetchRestaurants($pdo) {
    $s = $pdo->query("SELECT number, city, legal_entity_group FROM restaurants WHERE active = 1 ORDER BY number");
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $out[] = [
            'number'             => (int)$r['number'],
            'city'               => $r['city'] ?? '',
            'legal_entity_group' => $r['legal_entity_group'] ?? 'BK_VM',
        ];
    }
    return $out;
}

/**
 * Карта «номер ресторана → направление».
 *
 * Ресторан попадает в направление, если он добавлен в него вручную или его
 * город есть в списке городов направления. Исключение сильнее и того, и другого.
 * Если ресторан подходит сразу нескольким направлениям, побеждает то, у которого
 * меньше sort_order (список уже отсортирован).
 */
function tlBuildDirectionMap($directions, $restaurants) {
    $map = [];
    foreach ($directions as $d) {
        $cities = [];
        foreach ($d['cities'] as $c) $cities[tlNormalizeCity($c)] = true;
        $include = array_flip($d['include_restaurants']);
        $exclude = array_flip($d['exclude_restaurants']);
        foreach ($restaurants as $r) {
            $num = $r['number'];
            if (isset($map[$num])) continue; // уже забрало направление с более высоким приоритетом
            if (isset($exclude[$num])) continue;
            if (!isset($include[$num]) && !isset($cities[tlNormalizeCity($r['city'])])) continue;
            $map[$num] = ['id' => $d['id'], 'name' => $d['name']];
        }
    }
    return $map;
}

// ════════════════════════════════════════════
// GET tl/directions — направления + справочники для конструктора
// ════════════════════════════════════════════
if ($tlAction === 'directions' && $method === 'GET') {
    $directions  = tlFetchDirections($pdo);
    $restaurants = tlFetchRestaurants($pdo);
    $map = tlBuildDirectionMap($directions, $restaurants);

    // Сколько ресторанов реально попало в каждое направление — это подпись на карточке
    $counts = [];
    foreach ($map as $d) {
        $counts[$d['id']] = ($counts[$d['id']] ?? 0) + 1;
    }
    foreach ($directions as &$d) {
        $d['restaurants_count'] = $counts[$d['id']] ?? 0;
        unset($d['is_active']);
    }
    unset($d);

    // Города для выпадающего списка — только те, где есть активные рестораны.
    // Ключ нормализованный, значение — как в справочнике, чтобы не показывать «минск».
    $cities = [];
    foreach ($restaurants as $r) {
        $c = trim((string)$r['city']);
        if ($c === '') continue;
        $key = tlNormalizeCity($c);
        if (!isset($cities[$key])) $cities[$key] = $c;
    }
    $cityList = array_values($cities);
    sort($cityList, SORT_NATURAL | SORT_FLAG_CASE);

    tlRespond([
        'directions'            => $directions,
        'available_cities'      => $cityList,
        'available_restaurants' => $restaurants,
    ]);
}

// ════════════════════════════════════════════
// POST tl/directions — создать/обновить направление
// ════════════════════════════════════════════
if ($tlAction === 'directions' && $method === 'POST') {
    tlRequireLevel($sessionUser, 'edit');

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') tlRespond(['error' => 'Не указано название направления'], 400);
    if (mb_strlen($name, 'UTF-8') > 120) $name = mb_substr($name, 0, 120, 'UTF-8');

    $cities = [];
    foreach ((array)($body['cities'] ?? []) as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $cities, true)) $cities[] = $c;
    }
    $include = tlIntList($body['include_restaurants'] ?? []);
    $exclude = tlIntList($body['exclude_restaurants'] ?? []);
    // Ресторан не может быть одновременно добавлен и исключён — исключение сильнее
    $include = array_values(array_diff($include, $exclude));
    $sortOrder = intval($body['sort_order'] ?? 0);

    $id = intval($body['id'] ?? 0);
    $isNew = $id <= 0;
    $jsonFlags = JSON_UNESCAPED_UNICODE;

    try {
        if ($isNew) {
            $s = $pdo->prepare("INSERT INTO tl_directions (name, cities, include_restaurants, exclude_restaurants, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $s->execute([
                $name,
                json_encode($cities, $jsonFlags),
                json_encode($include, $jsonFlags),
                json_encode($exclude, $jsonFlags),
                $sortOrder,
                resolveActorName($pdo, $sessionUser, 'api'),
            ]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $s = $pdo->prepare("UPDATE tl_directions SET name = ?, cities = ?, include_restaurants = ?, exclude_restaurants = ?, sort_order = ?, is_active = 1 WHERE id = ?");
            $s->execute([
                $name,
                json_encode($cities, $jsonFlags),
                json_encode($include, $jsonFlags),
                json_encode($exclude, $jsonFlags),
                $sortOrder,
                $id,
            ]);
            if ($s->rowCount() === 0) {
                // Строка могла не измениться, но могла и не существовать
                $chk = $pdo->prepare("SELECT id FROM tl_directions WHERE id = ?");
                $chk->execute([$id]);
                if (!$chk->fetchColumn()) tlRespond(['error' => 'Направление не найдено'], 404);
            }
        }
    } catch (Exception $e) {
        error_log('tl/directions POST error: ' . $e->getMessage());
        tlRespond(['error' => 'Ошибка сохранения направления'], 500);
    }

    auditLog($pdo, $isNew ? 'tl_direction_created' : 'tl_direction_updated', 'truck_direction', (string)$id,
        resolveActorName($pdo, $sessionUser, 'api'), [
            'name'     => $name,
            'cities'   => $cities,
            'include'  => $include,
            'exclude'  => $exclude,
        ]);

    tlRespond(['success' => true, 'id' => (int)$id]);
}

// ════════════════════════════════════════════
// DELETE tl/directions/:id — деактивировать направление
// ════════════════════════════════════════════
if ($tlAction === 'directions' && $method === 'DELETE' && $tlParam1) {
    tlRequireLevel($sessionUser, 'full');

    $dirId = intval($tlParam1);
    // Название читаем до удаления: «удалили направление №3» в журнале бесполезно
    $nameStmt = $pdo->prepare("SELECT name FROM tl_directions WHERE id = ?");
    $nameStmt->execute([$dirId]);
    $dirName = $nameStmt->fetchColumn();
    if ($dirName === false) tlRespond(['error' => 'Направление не найдено'], 404);

    try {
        // Мягкое удаление: машины уже сохранённых планов продолжают ссылаться на
        // направление, и в плане остаётся видно, куда рейс шёл
        $pdo->prepare("UPDATE tl_directions SET is_active = 0 WHERE id = ?")->execute([$dirId]);
    } catch (Exception $e) {
        error_log('tl/directions DELETE error: ' . $e->getMessage());
        tlRespond(['error' => 'Ошибка удаления направления'], 500);
    }

    auditLog($pdo, 'tl_direction_deleted', 'truck_direction', (string)$dirId,
        resolveActorName($pdo, $sessionUser, 'api'), ['name' => $dirName]);

    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// Хелпер: получить заказы ресторанов на дату
// ════════════════════════════════════════════
function tlGetOrdersForDate($pdo, $date) {
    // Все заказы на дату из всех подходящих активных сессий.
    // Сессии теперь ведутся отдельно по группам юрлиц, поэтому брать только одну
    // "последнюю активную" сессию нельзя — иначе модуль перестаёт видеть часть заказов.

    // Порядок сортировки юрлиц — единственная точка правки при добавлении нового юрлица
    $legalEntityOrder = ['ООО "Бургер БК"', 'ООО "Воглия Матта"', 'ООО "Пицца Стар"'];
    $legalEntityField = 'FIELD(o.legal_entity, ' . implode(', ', array_map(function($e) use ($pdo) {
        return $pdo->quote($e);
    }, $legalEntityOrder)) . ')';

    $s = $pdo->prepare("
        SELECT o.id as order_id, o.restaurant_number, o.status, o.legal_entity,
               o.legal_entity_group,
               r.city, r.address, r.region
        FROM ro_orders o
        INNER JOIN ro_sessions rs
            ON rs.id = o.session_id
            AND rs.status = 'active'
            AND rs.week_start <= ?
            AND rs.week_end >= ?
        LEFT JOIN restaurants r
            ON r.number = o.restaurant_number
            AND r.active = 1
            AND r.legal_entity_group = o.legal_entity_group
        WHERE o.delivery_date = ? AND o.status != 'draft'
        ORDER BY {$legalEntityField}, o.restaurant_number
    ");
    $s->execute([$date, $date, $date]);
    $orders = $s->fetchAll();

    if (empty($orders)) return [];

    $orderIds = array_map(function($order) {
        return (int)$order['order_id'];
    }, $orders);
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    // Карточка товара ищется по ГРУППЕ юрлиц, а не по одному юрлицу:
    // products — общий справочник группы, физически он лежит под главным
    // юрлицом («Бургер БК»). При точном совпадении по legal_entity заказы
    // «Воглия Матта» не находили ни одной карточки — вес и паллеты выходили нулевыми.
    // Подзапрос с ORDER BY is_active DESC берёт активную карточку, а если её нет —
    // не теряет товар совсем (так же сделано в restaurant_orders.php).
    //
    // Кратность: сначала переопределение из шаблона заказов ресторанов
    // (ro_templates.multiplicity), и только потом карточка товара — иначе один
    // и тот же заказ давал разное число паллет в «Заказах ресторанов» и здесь.
    $itemsStmt = $pdo->prepare("
        SELECT oi.id as item_id, oi.order_id, oi.sku, oi.product_name, oi.category, oi.quantity,
               COALESCE(p.weight_brutto, 0) as weight_brutto,
               COALESCE(p.boxes_per_pallet, 0) as boxes_per_pallet,
               COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) as multiplicity
        FROM ro_order_items oi
        JOIN ro_orders o ON o.id = oi.order_id
        LEFT JOIN ro_templates t
            ON t.legal_entity = o.legal_entity
           AND t.category = oi.category
           AND t.sku = oi.sku
           AND t.is_active = 1
        LEFT JOIN products p ON p.id = (
            SELECT p2.id
            FROM products p2
            WHERE p2.sku = oi.sku
              AND p2.legal_entity_group = o.legal_entity_group
            -- Порядок отбора тот же, что в restaurant_orders.php, иначе один
            -- заказ снова даст разные паллеты в двух разделах.
            ORDER BY p2.is_active DESC, (p2.legal_entity = o.legal_entity) DESC, p2.id ASC
            LIMIT 1
        )
        WHERE oi.order_id IN ({$ph})
        ORDER BY oi.order_id, oi.category, oi.product_name
    ");
    $itemsStmt->execute($orderIds);
    $itemsByOrder = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByOrder[(int)$item['order_id']][] = $item;
    }

    // Направление рейса для каждого ресторана считает сервер: клиенту незачем
    // знать правила городов, исключений и приоритетов
    $directionMap = tlBuildDirectionMap(tlFetchDirections($pdo), tlFetchRestaurants($pdo));

    $result = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $orderItems = $itemsByOrder[(int)$orderId] ?? [];

        $categories = [];
        $itemsList = [];
        $totalWeight = 0;
        $totalPallets = 0;

        foreach ($orderItems as $item) {
            $qty = floatval($item['quantity']);
            $weightBrutto = floatval($item['weight_brutto']); // в граммах
            $bpp = floatval($item['boxes_per_pallet']);
            $mult = floatval($item['multiplicity']);

            // Штучный товар (multiplicity > 1): количество в штуках → коробки
            $boxes = $qty;
            if ($mult > 1) {
                $boxes = $qty / $mult;
            }

            $itemWeight = $qty * $weightBrutto / 1000; // в кг (вес считаем по штукам)
            $itemPallets = ($bpp > 0) ? $boxes / $bpp : 0;

            $cat = $item['category'] ?: 'Сухой';

            // Агрегация по категории
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['weight' => 0, 'pallets_raw' => 0, 'items_count' => 0];
            }
            $categories[$cat]['weight'] += $itemWeight;
            $categories[$cat]['pallets_raw'] += $itemPallets;
            $categories[$cat]['items_count']++;

            $totalWeight += $itemWeight;

            $itemsList[] = [
                'item_id' => (int)$item['item_id'],
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'category' => $cat,
                'quantity' => (int)$qty,
                'weight' => round($itemWeight, 2),
                'pallets' => round($itemPallets, 4),
                'boxes_per_pallet' => (int)$bpp,
            ];
        }

        // Округление паллет по категориям: дробная часть ≤ 0.2 → вниз, > 0.2 → вверх.
        // Если в категории есть товар (raw > 0) — минимум 1 паллета.
        $catResult = [];
        foreach ($categories as $catName => $catData) {
            $raw = $catData['pallets_raw'];
            if ($raw > 0) {
                $frac = $raw - floor($raw);
                $palletsRounded = ($frac > 0.2) ? ceil($raw) : floor($raw);
                if ($palletsRounded < 1) $palletsRounded = 1;
            } else {
                $palletsRounded = 0;
            }
            $totalPallets += $palletsRounded;
            $catResult[$catName] = [
                'weight' => round($catData['weight'], 2),
                'pallets' => (int)$palletsRounded,
                'items_count' => $catData['items_count'],
            ];
        }

        $restNumber = (int)$order['restaurant_number'];

        $result[] = [
            'order_id' => (int)$orderId,
            'restaurant_number' => $restNumber,
            'legal_entity' => $order['legal_entity'] ?? '',
            'legal_entity_group' => $order['legal_entity_group'] ?? 'BK_VM',
            'direction_id' => $directionMap[$restNumber]['id'] ?? null,
            'direction_name' => $directionMap[$restNumber]['name'] ?? '',
            'city' => $order['city'] ?? '',
            'address' => $order['address'] ?? '',
            'region' => $order['region'] ?? '',
            'total_weight' => round($totalWeight, 2),
            'total_pallets' => (int)$totalPallets,
            'categories' => $catResult,
            'items' => $itemsList,
        ];
    }

    return $result;
}


// ════════════════════════════════════════════
// GET tl/orders?date=YYYY-MM-DD
// ════════════════════════════════════════════
if ($tlAction === 'orders' && $method === 'GET') {
    $date = $_GET['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата (date=YYYY-MM-DD)'], 400);
    }

    $orders = tlGetOrdersForDate($pdo, $date);
    tlRespond(['orders' => $orders]);
}


// ════════════════════════════════════════════
// GET tl/plan?date=YYYY-MM-DD
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'GET') {
    $date = $_GET['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата (date=YYYY-MM-DD)'], 400);
    }

    $s = $pdo->prepare("SELECT id, delivery_date, allow_mixed_modes, status, note, created_by, created_at, updated_at FROM tl_plans WHERE delivery_date = ?");
    $s->execute([$date]);
    $plan = $s->fetch();

    if (!$plan) {
        tlRespond(['plan' => null]);
    }

    $planId = $plan['id'];

    // Машины плана
    $trucks = $pdo->prepare("
        SELECT id, vehicle_id, direction_id, custom_name, capacity_pallets, capacity_kg, mode, sort_order
        FROM tl_trucks
        WHERE plan_id = ?
        ORDER BY sort_order, id
    ");
    $trucks->execute([$planId]);
    $truckList = $trucks->fetchAll();

    // Назначения всех машин плана — ОДНИМ запросом (раньше был запрос на каждую машину).
    // legal_entity_group берём из строки назначения, а если она пустая (данные,
    // записанные до появления колонки) — из самого заказа.
    $assignmentsByTruck = [];
    if (!empty($truckList)) {
        $truckIds = array_map(function($t) { return (int)$t['id']; }, $truckList);
        $tPh = implode(',', array_fill(0, count($truckIds), '?'));
        $assignments = $pdo->prepare("
            SELECT a.id, a.truck_id, a.assign_type, a.order_id, a.category, a.order_item_id,
                   a.restaurant_number, a.pallets, a.weight_kg, a.sort_order,
                   COALESCE(NULLIF(a.legal_entity_group, ''), o.legal_entity_group) AS legal_entity_group
            FROM tl_assignments a
            LEFT JOIN ro_orders o ON o.id = a.order_id
            WHERE a.truck_id IN ({$tPh})
            ORDER BY a.truck_id, a.sort_order, a.id
        ");
        $assignments->execute($truckIds);
        foreach ($assignments->fetchAll() as $a) {
            $truckKey = (int)$a['truck_id'];
            unset($a['truck_id']); // в ответе поля не было и раньше
            $restNum = $a['restaurant_number'] ? (int)$a['restaurant_number'] : null;
            $a['id'] = (int)$a['id'];
            $a['order_id'] = $a['order_id'] ? (int)$a['order_id'] : null;
            $a['order_item_id'] = $a['order_item_id'] ? (int)$a['order_item_id'] : null;
            $a['restaurant_number'] = $restNum;
            $a['pallets'] = floatval($a['pallets']);
            $a['weight_kg'] = floatval($a['weight_kg']);
            $a['sort_order'] = (int)$a['sort_order'];
            // Последняя страховка: заказ удалён и группу взять неоткуда — определяем
            // по номеру ресторана (у «Пицца Стар» номера от 1000).
            if (empty($a['legal_entity_group'])) {
                $a['legal_entity_group'] = ($restNum !== null && $restNum >= 1000) ? 'PS' : 'BK_VM';
            }
            $assignmentsByTruck[$truckKey][] = $a;
        }
    }

    // Названия направлений берём вместе с удалёнными: если направление
    // деактивировали после сохранения плана, в плане всё равно должно быть видно,
    // куда шёл рейс. Принадлежность ресторана считаем только по активным.
    $allDirections = tlFetchDirections($pdo, false);
    $directionNames = [];
    $activeDirections = [];
    foreach ($allDirections as $d) {
        $directionNames[$d['id']] = $d['name'];
        if ($d['is_active']) $activeDirections[] = $d;
    }
    $restDirection = tlBuildDirectionMap($activeDirections, tlFetchRestaurants($pdo));

    foreach ($truckList as &$truck) {
        $truck['id'] = (int)$truck['id'];
        $truck['vehicle_id'] = $truck['vehicle_id'] ? (int)$truck['vehicle_id'] : null;
        $truck['direction_id'] = $truck['direction_id'] ? (int)$truck['direction_id'] : null;
        $truck['direction_name'] = $truck['direction_id'] ? ($directionNames[$truck['direction_id']] ?? '') : '';
        $truck['capacity_pallets'] = floatval($truck['capacity_pallets']);
        $truck['capacity_kg'] = floatval($truck['capacity_kg']);
        $truck['sort_order'] = (int)$truck['sort_order'];
        $truck['assignments'] = $assignmentsByTruck[$truck['id']] ?? [];

        // Ресторан другого направления в рейсе — это разрешено, но должно быть
        // видно. Ресторан без направления в рейсе с направлением тоже «не по
        // пути»: машина едет не туда
        foreach ($truck['assignments'] as &$a) {
            $rn = $a['restaurant_number'];
            $a['direction_id'] = ($rn !== null) ? ($restDirection[$rn]['id'] ?? null) : null;
            $a['direction_name'] = ($rn !== null) ? ($restDirection[$rn]['name'] ?? '') : '';
            $a['foreign_direction'] = (bool)($truck['direction_id'] && $rn !== null
                && $truck['direction_id'] !== $a['direction_id']);
        }
        unset($a);
    }
    unset($truck);

    $plan['id'] = (int)$plan['id'];
    $plan['allow_mixed_modes'] = (bool)$plan['allow_mixed_modes'];
    $plan['trucks'] = $truckList;

    tlRespond(['plan' => $plan]);
}


// ════════════════════════════════════════════
// POST tl/plan — создать/обновить план
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'POST') {
    $deliveryDate = $body['delivery_date'] ?? '';
    if (!$deliveryDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        tlRespond(['error' => 'Не указана дата доставки'], 400);
    }

    $allowMixed = $body['allow_mixed_modes'] ?? false;
    $note = $body['note'] ?? '';
    $trucks = $body['trucks'] ?? [];
    $createdBy = resolveActorName($pdo, $sessionUser, 'api');
    // Метка версии плана, которую клиент видел, когда начинал править.
    $clientUpdatedAt = trim((string)($body['updated_at'] ?? ''));

    try {
        $pdo->beginTransaction();

        // Найти существующий план (upsert). FOR UPDATE — чтобы два одновременных
        // сохранения не проскочили проверку версии одновременно.
        $s = $pdo->prepare("SELECT id, status, updated_at FROM tl_plans WHERE delivery_date = ? FOR UPDATE");
        $s->execute([$deliveryDate]);
        $existing = $s->fetch();
        $isNewPlan = !$existing;

        if ($existing) {
            $planId = $existing['id'];
            // Подтверждённый план нельзя перезаписывать
            if (($existing['status'] ?? '') === 'confirmed') {
                $pdo->rollBack();
                tlRespond(['error' => 'План подтверждён, верните в черновик перед изменением'], 409);
            }

            // Оптимистичная блокировка: если план в базе успели изменить после
            // того, как клиент его загрузил, — не затираем чужую работу.
            $dbUpdatedAt = $existing['updated_at'] ?? null;
            $dbTs = $dbUpdatedAt ? strtotime($dbUpdatedAt) : 0;
            $clientTs = $clientUpdatedAt !== '' ? strtotime($clientUpdatedAt) : false;
            if ($clientTs === false || ($dbTs && $dbTs > $clientTs)) {
                $pdo->rollBack();
                tlRespond([
                    'error' => 'План изменили, пока вы работали. Обновите страницу',
                    'conflict' => true,
                    'updated_at' => $dbUpdatedAt,
                ], 409);
            }

            // Удалить старые машины (CASCADE удалит assignments)
            $pdo->prepare("DELETE FROM tl_trucks WHERE plan_id = ?")->execute([$planId]);
            // Обновить план
            $pdo->prepare("UPDATE tl_plans SET allow_mixed_modes = ?, note = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$allowMixed ? 1 : 0, $note, $planId]);
        } else {
            $pdo->prepare("INSERT INTO tl_plans (delivery_date, allow_mixed_modes, status, note, created_by, created_at, updated_at) VALUES (?, ?, 'draft', ?, ?, NOW(), NOW())")
                ->execute([$deliveryDate, $allowMixed ? 1 : 0, $note, $createdBy]);
            $planId = $pdo->lastInsertId();
        }

        // Проверка двойной отгрузки: собрать все order_id из входящих назначений
        $incomingOrderIds = [];
        foreach ($trucks as $truck) {
            foreach ($truck['assignments'] ?? [] as $a) {
                if (!empty($a['order_id'])) {
                    $incomingOrderIds[] = intval($a['order_id']);
                }
            }
        }
        $incomingOrderIds = array_unique($incomingOrderIds);

        // Группа юрлица для каждого заказа — берём из самих заказов, а не с клиента:
        // так в назначении всегда лежит достоверная группа и фронту не приходится
        // угадывать её по номеру ресторана.
        $orderGroups = [];
        if (!empty($incomingOrderIds)) {
            $gPh = implode(',', array_fill(0, count($incomingOrderIds), '?'));
            $gStmt = $pdo->prepare("SELECT id, legal_entity_group FROM ro_orders WHERE id IN ({$gPh})");
            $gStmt->execute(array_values($incomingOrderIds));
            foreach ($gStmt->fetchAll() as $row) {
                $orderGroups[(int)$row['id']] = $row['legal_entity_group'];
            }
        }

        if (!empty($incomingOrderIds)) {
            $phCheck = implode(',', array_fill(0, count($incomingOrderIds), '?'));
            $dupStmt = $pdo->prepare("
                SELECT a.order_id, p.delivery_date
                FROM tl_assignments a
                JOIN tl_trucks t ON t.id = a.truck_id
                JOIN tl_plans p ON p.id = t.plan_id
                WHERE p.status = 'confirmed'
                  AND p.id != ?
                  AND a.order_id IN ({$phCheck})
            ");
            // Параметры: сначала planId, потом order_ids
            $dupStmtParams = array_merge([$planId], $incomingOrderIds);
            $dupStmt->execute($dupStmtParams);
            $dups = $dupStmt->fetchAll();
            if (!empty($dups)) {
                $pdo->rollBack();
                $dupInfo = [];
                foreach ($dups as $d) {
                    $dupInfo[] = '#' . $d['order_id'] . ' (план на ' . $d['delivery_date'] . ')';
                }
                tlRespond(['error' => 'Заказы уже в подтверждённом плане: ' . implode(', ', array_unique($dupInfo))], 409);
            }
        }

        // Известные направления: клиент мог держать страницу открытой, пока
        // направление удалили. Неизвестный id молча превращаем в «без направления»,
        // иначе внешний ключ уронит всё сохранение целиком.
        $knownDirections = [];
        foreach ($pdo->query("SELECT id FROM tl_directions")->fetchAll() as $row) {
            $knownDirections[(int)$row['id']] = true;
        }

        // Вставить машины и назначения
        $insertTruck = $pdo->prepare("
            INSERT INTO tl_trucks (plan_id, vehicle_id, direction_id, custom_name, capacity_pallets, capacity_kg, mode, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertAssignment = $pdo->prepare("
            INSERT INTO tl_assignments (truck_id, assign_type, order_id, category, order_item_id, restaurant_number, pallets, weight_kg, sort_order, legal_entity_group)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $assignmentsSaved = 0;
        foreach ($trucks as $truckIdx => $truck) {
            $truckDirection = intval($truck['direction_id'] ?? 0);
            if ($truckDirection <= 0 || !isset($knownDirections[$truckDirection])) $truckDirection = null;

            $insertTruck->execute([
                $planId,
                $truck['vehicle_id'] ?? null,
                $truckDirection,
                $truck['custom_name'] ?? null,
                floatval($truck['capacity_pallets'] ?? 0),
                floatval($truck['capacity_kg'] ?? 0),
                $truck['mode'] ?? 'any',
                $truck['sort_order'] ?? $truckIdx,
            ]);
            $truckId = $pdo->lastInsertId();

            $assignments = $truck['assignments'] ?? [];
            foreach ($assignments as $aIdx => $a) {
                $restNum = isset($a['restaurant_number']) ? (int)$a['restaurant_number'] : null;
                // Группа: из заказа → что прислал клиент → по номеру ресторана (ПС от 1000).
                $aGroup = $orderGroups[(int)($a['order_id'] ?? 0)] ?? null;
                if (!$aGroup) $aGroup = $a['legal_entity_group'] ?? null;
                if (!$aGroup) $aGroup = ($restNum !== null && $restNum >= 1000) ? 'PS' : 'BK_VM';

                $insertAssignment->execute([
                    $truckId,
                    $a['assign_type'] ?? 'order',
                    $a['order_id'] ?? null,
                    $a['category'] ?? null,
                    $a['order_item_id'] ?? null,
                    $a['restaurant_number'] ?? null,
                    floatval($a['pallets'] ?? 0),
                    floatval($a['weight_kg'] ?? 0),
                    $a['sort_order'] ?? $aIdx,
                    $aGroup,
                ]);
                $assignmentsSaved++;
            }
        }

        // Актуальную метку версии читаем из базы (NOW() — время сервера БД),
        // чтобы клиент мог продолжить работу без перезагрузки страницы.
        $uaStmt = $pdo->prepare("SELECT updated_at FROM tl_plans WHERE id = ?");
        $uaStmt->execute([$planId]);
        $newUpdatedAt = $uaStmt->fetchColumn();

        $pdo->commit();

        auditLog($pdo, $isNewPlan ? 'tl_plan_created' : 'tl_plan_updated', 'truck_plan', (string)$planId, $createdBy, [
            'delivery_date'     => $deliveryDate,
            'trucks'            => count($trucks),
            'assignments'       => $assignmentsSaved,
            'allow_mixed_modes' => $allowMixed ? 1 : 0,
        ]);

        tlRespond(['success' => true, 'plan_id' => (int)$planId, 'updated_at' => $newUpdatedAt]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tl/plan POST error: ' . $e->getMessage());
        // Гонка при создании: двое одновременно создали план на одну дату —
        // второй упирается в уникальный индекс. Для человека это тот же случай
        // «пока вы работали, план изменили», а не внутренняя ошибка.
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            tlRespond(['error' => 'План изменили, пока вы работали. Обновите страницу', 'conflict' => true], 409);
        }
        tlRespond(['error' => 'Ошибка сохранения плана'], 500);
    }
}


// ════════════════════════════════════════════
// DELETE tl/plan/:id — удалить план
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'DELETE' && $tlParam1 && !$tlParam2) {
    tlRequireLevel($sessionUser, 'full');

    $planId = intval($tlParam1);

    // Нельзя удалить подтверждённый план
    $statusChk = $pdo->prepare("SELECT status, delivery_date FROM tl_plans WHERE id = ?");
    $statusChk->execute([$planId]);
    $planRow = $statusChk->fetch();
    $planStatus = $planRow['status'] ?? null;
    if ($planStatus === 'confirmed') {
        tlRespond(['error' => 'Подтверждённый план удалить нельзя. Сначала верните его в черновик.'], 409);
    }

    try {
        $pdo->beginTransaction();
        // Удалить назначения через машины
        $pdo->prepare("DELETE a FROM tl_assignments a JOIN tl_trucks t ON a.truck_id = t.id WHERE t.plan_id = ?")->execute([$planId]);
        // Удалить машины
        $pdo->prepare("DELETE FROM tl_trucks WHERE plan_id = ?")->execute([$planId]);
        // Удалить план
        $pdo->prepare("DELETE FROM tl_plans WHERE id = ?")->execute([$planId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tl/plan DELETE error: ' . $e->getMessage());
        tlRespond(['error' => 'Ошибка удаления плана'], 500);
    }

    auditLog($pdo, 'tl_plan_deleted', 'truck_plan', (string)$planId,
        resolveActorName($pdo, $sessionUser, 'api'),
        ['delivery_date' => $planRow['delivery_date'] ?? null]);

    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// PATCH tl/plan/:id/status — изменить статус
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'PATCH' && $tlParam1 && $tlParam2 === 'status') {
    $planId = intval($tlParam1);
    $newStatus = $body['status'] ?? '';

    // Допустимые статусы (по ENUM в tl_plans)
    if (!in_array($newStatus, ['draft', 'confirmed'])) {
        tlRespond(['error' => 'Некорректный статус (допустимо: draft, confirmed)'], 400);
    }

    // Подтверждение плана требует уровня full
    if ($newStatus === 'confirmed') {
        tlRequireLevel($sessionUser, 'full', 'Подтверждение плана доступно только с правом full');
    }

    $planInfo = $pdo->prepare("SELECT status, delivery_date FROM tl_plans WHERE id = ?");
    $planInfo->execute([$planId]);
    $planBefore = $planInfo->fetch();
    if (!$planBefore) tlRespond(['error' => 'План не найден'], 404);

    $pdo->prepare("UPDATE tl_plans SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$newStatus, $planId]);

    $uaStmt = $pdo->prepare("SELECT updated_at FROM tl_plans WHERE id = ?");
    $uaStmt->execute([$planId]);
    $newUpdatedAt = $uaStmt->fetchColumn();

    // Статус пишем в журнал, только если он реально сменился.
    if (($planBefore['status'] ?? '') !== $newStatus) {
        auditLog($pdo, $newStatus === 'confirmed' ? 'tl_plan_confirmed' : 'tl_plan_reopened',
            'truck_plan', (string)$planId, resolveActorName($pdo, $sessionUser, 'api'), [
                'delivery_date' => $planBefore['delivery_date'] ?? null,
                'status_from'   => $planBefore['status'] ?? null,
                'status_to'     => $newStatus,
            ]);
    }

    tlRespond(['success' => true, 'updated_at' => $newUpdatedAt]);
}


// ════════════════════════════════════════════
// POST tl/auto-assign — автоматическое распределение
// ════════════════════════════════════════════
if ($tlAction === 'auto-assign' && $method === 'POST') {
    $date = $body['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата'], 400);
    }

    $vehicleSpecs = $body['vehicles'] ?? []; // [{id, count}]
    $allowMixed = (bool)($body['allow_mixed'] ?? false);

    if (empty($vehicleSpecs)) {
        tlRespond(['error' => 'Не указаны типы машин'], 400);
    }

    // Загрузить характеристики типов машин
    $vehicleIds = array_map(function($v) { return intval($v['id']); }, $vehicleSpecs);
    $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
    $s = $pdo->prepare("SELECT id, name, capacity_pallets, capacity_kg FROM tl_vehicles WHERE id IN ($placeholders) AND is_active = 1");
    $s->execute($vehicleIds);
    $vehicleMap = [];
    foreach ($s->fetchAll() as $v) {
        $vehicleMap[$v['id']] = $v;
    }

    // Все типы машин для создания новых (отсортированы по capacity ASC — для выбора наименьшей подходящей)
    $allVehicleTypes = array_values($vehicleMap);
    usort($allVehicleTypes, function($a, $b) {
        return floatval($a['capacity_pallets']) <=> floatval($b['capacity_pallets']);
    });

    // Загрузить заказы
    $orders = tlGetOrdersForDate($pdo, $date);
    if (empty($orders)) {
        tlRespond(['trucks' => []]);
    }

    // Сформировать единицы загрузки
    $units = [];

    foreach ($orders as $order) {
        if ($allowMixed) {
            // Единица = целый заказ
            $units[] = [
                'assign_type' => 'order',
                'order_id' => $order['order_id'],
                'category' => null,
                'restaurant_number' => $order['restaurant_number'],
                'legal_entity_group' => $order['legal_entity_group'],
                'direction_id' => $order['direction_id'],
                'direction_name' => $order['direction_name'],
                'pallets' => $order['total_pallets'],
                'weight_kg' => $order['total_weight'],
                'mode' => 'any',
            ];
        } else {
            // Единица = заказ по категории
            foreach ($order['categories'] as $catName => $catData) {
                if ($catName === 'Сухой') $mode = 'dry';
                elseif ($catName === 'Холод') $mode = 'cold';
                elseif ($catName === 'Мороз') $mode = 'frozen';
                else {
                    $mode = 'any';
                    error_log('truck_loading: unknown category mode for ' . $catName);
                }

                $units[] = [
                    'assign_type' => 'category',
                    'order_id' => $order['order_id'],
                    'category' => $catName,
                    'restaurant_number' => $order['restaurant_number'],
                    'legal_entity_group' => $order['legal_entity_group'],
                    'direction_id' => $order['direction_id'],
                    'direction_name' => $order['direction_name'],
                    'pallets' => $catData['pallets'],
                    'weight_kg' => $catData['weight'],
                    'mode' => $mode,
                ];
            }
        }
    }

    // ── Пул доступных машин ──
    // Клиент присылает count на каждый тип («сколько угодно» = большое число).
    // Разворачивать его как есть нельзя: пул раздувался до сотен элементов,
    // которые всё равно никогда не понадобятся. Считаем верхнюю границу сами:
    // сколько машин реально может потребоваться на этот объём.
    $minCapacity = 0;
    foreach ($vehicleMap as $v) {
        $cap = floatval($v['capacity_pallets']);
        if ($cap > 0 && ($minCapacity === 0 || $cap < $minCapacity)) $minCapacity = $cap;
    }
    // Считаем по группам «направление + режим»: машина не смешивает «сухой» и
    // «мороз», а рейс не смешивает Минск и Полоцк, поэтому на каждую такую группу
    // нужен свой минимум машин.
    $palletsByGroup = [];
    foreach ($units as $u) {
        $key = ($u['direction_id'] ?? 0) . '|' . ($allowMixed ? 'any' : $u['mode']);
        $palletsByGroup[$key] = ($palletsByGroup[$key] ?? 0) + floatval($u['pallets']);
    }
    $neededTrucks = 0;
    foreach ($palletsByGroup as $groupPallets) {
        $neededTrucks += ($minCapacity > 0) ? max(1, (int)ceil($groupPallets / $minCapacity)) : 1;
    }
    // Запас +2 на неровную укладку. Больше машин, чем единиц загрузки, не нужно
    // в принципе: в каждой машине лежит хотя бы одна единица.
    $maxTrucks = max(1, min(count($units), $neededTrucks + 2));

    $availableVehicles = [];
    foreach ($vehicleSpecs as $spec) {
        $vid = intval($spec['id']);
        $count = intval($spec['count'] ?? 1);
        if (!isset($vehicleMap[$vid])) continue;
        if ($count > $maxTrucks) $count = $maxTrucks; // пользовательское «меньше» не увеличиваем
        for ($i = 0; $i < $count; $i++) {
            $availableVehicles[] = [
                'vehicle_id' => $vid,
                'name' => $vehicleMap[$vid]['name'],
                'capacity_pallets' => floatval($vehicleMap[$vid]['capacity_pallets']),
                'capacity_kg' => floatval($vehicleMap[$vid]['capacity_kg']),
            ];
        }
    }

    // Отсортировать по вместимости (от большей к меньшей)
    usort($availableVehicles, function($a, $b) {
        return $b['capacity_pallets'] <=> $a['capacity_pallets'];
    });

    // First Fit Decreasing: по убыванию паллет, но сначала — по направлению,
    // чтобы рейсы одного направления собирались подряд, а «без направления»
    // ушло в конец списка машин.
    usort($units, function($a, $b) {
        $da = (int)($a['direction_id'] ?? 0);
        $db = (int)($b['direction_id'] ?? 0);
        if ($da !== $db) {
            if ($da === 0) return 1;   // «без направления» — в самый конец
            if ($db === 0) return -1;
            return $da <=> $db;
        }
        return $b['pallets'] <=> $a['pallets'];
    });

    // Машины-контейнеры
    $resultTrucks = [];

    foreach ($units as $unit) {
        $placed = false;

        // Попробовать разместить в существующую машину
        foreach ($resultTrucks as &$truck) {
            // Рейс идёт по одному направлению: Минск и Полоцк в одну машину не
            // кладём даже при свободном месте. Рестораны без направления едут
            // отдельной машиной — вручную их можно переложить куда угодно.
            if ((int)($truck['direction_id'] ?? 0) !== (int)($unit['direction_id'] ?? 0)) {
                continue;
            }
            // Проверка совместимости режимов
            if (!$allowMixed && $truck['mode'] !== 'any' && $unit['mode'] !== 'any' && $truck['mode'] !== $unit['mode']) {
                continue;
            }

            $remainingPallets = $truck['capacity_pallets'] - $truck['used_pallets'];
            $remainingKg = $truck['capacity_kg'] - $truck['used_kg'];

            if ($unit['pallets'] <= $remainingPallets && $unit['weight_kg'] <= $remainingKg) {
                $truck['assignments'][] = [
                    'assign_type' => $unit['assign_type'],
                    'order_id' => $unit['order_id'],
                    'category' => $unit['category'],
                    'order_item_id' => null,
                    'restaurant_number' => $unit['restaurant_number'],
                    'legal_entity_group' => $unit['legal_entity_group'],
                    'direction_id' => $unit['direction_id'],
                    'direction_name' => $unit['direction_name'],
                    'foreign_direction' => false,
                    'pallets' => $unit['pallets'],
                    'weight_kg' => $unit['weight_kg'],
                    'sort_order' => count($truck['assignments']),
                ];
                $truck['used_pallets'] += $unit['pallets'];
                $truck['used_kg'] += $unit['weight_kg'];

                // Установить mode если ещё any
                if ($truck['mode'] === 'any' && $unit['mode'] !== 'any') {
                    $truck['mode'] = $unit['mode'];
                }

                $placed = true;
                break;
            }
        }
        unset($truck);

        if (!$placed) {
            // Попробовать взять из доступного пула
            $newVehicle = null;

            if (!empty($availableVehicles)) {
                // Найти наименьшую подходящую из пула
                foreach ($availableVehicles as $avIdx => $av) {
                    if ($unit['pallets'] <= $av['capacity_pallets'] && $unit['weight_kg'] <= $av['capacity_kg']) {
                        $newVehicle = $av;
                        array_splice($availableVehicles, $avIdx, 1);
                        break;
                    }
                }
                // Если не нашли подходящую — берём самую большую
                if (!$newVehicle && !empty($availableVehicles)) {
                    $newVehicle = array_shift($availableVehicles);
                }
            }

            // Если пул пуст — создать новую машину (наименьший подходящий тип)
            if (!$newVehicle) {
                foreach ($allVehicleTypes as $vt) {
                    if ($unit['pallets'] <= floatval($vt['capacity_pallets']) && $unit['weight_kg'] <= floatval($vt['capacity_kg'])) {
                        $newVehicle = [
                            'vehicle_id' => (int)$vt['id'],
                            'name' => $vt['name'],
                            'capacity_pallets' => floatval($vt['capacity_pallets']),
                            'capacity_kg' => floatval($vt['capacity_kg']),
                        ];
                        break;
                    }
                }
                // Если даже самая большая не подходит — берём самую большую
                if (!$newVehicle && !empty($allVehicleTypes)) {
                    $biggest = end($allVehicleTypes);
                    $newVehicle = [
                        'vehicle_id' => (int)$biggest['id'],
                        'name' => $biggest['name'],
                        'capacity_pallets' => floatval($biggest['capacity_pallets']),
                        'capacity_kg' => floatval($biggest['capacity_kg']),
                    ];
                }
            }

            if (!$newVehicle) {
                // Нет типов машин вообще — ошибка
                tlRespond(['error' => 'Нет доступных типов машин'], 400);
            }

            $resultTrucks[] = [
                'vehicle_id' => (int)$newVehicle['vehicle_id'],
                'custom_name' => null,
                'direction_id' => $unit['direction_id'],
                'direction_name' => $unit['direction_name'],
                'capacity_pallets' => $newVehicle['capacity_pallets'],
                'capacity_kg' => $newVehicle['capacity_kg'],
                'mode' => (!$allowMixed && $unit['mode'] !== 'any') ? $unit['mode'] : 'any',
                'used_pallets' => $unit['pallets'],
                'used_kg' => $unit['weight_kg'],
                'sort_order' => count($resultTrucks),
                'assignments' => [
                    [
                        'assign_type' => $unit['assign_type'],
                        'order_id' => $unit['order_id'],
                        'category' => $unit['category'],
                        'order_item_id' => null,
                        'restaurant_number' => $unit['restaurant_number'],
                        'legal_entity_group' => $unit['legal_entity_group'],
                        'direction_id' => $unit['direction_id'],
                        'direction_name' => $unit['direction_name'],
                        'foreign_direction' => false,
                        'pallets' => $unit['pallets'],
                        'weight_kg' => $unit['weight_kg'],
                        'sort_order' => 0,
                    ],
                ],
            ];
        }
    }

    // Очистить служебные поля перед ответом
    foreach ($resultTrucks as &$truck) {
        unset($truck['used_pallets'], $truck['used_kg']);
    }
    unset($truck);

    tlRespond(['trucks' => $resultTrucks]);
}


// Если ни один маршрут не сработал
tlRespond(['error' => 'Неизвестный маршрут'], 404);
