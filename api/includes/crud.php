<?php
/**
 * REST CRUD для таблиц.
 * Подключается из index.php.
 */
// ═══ REST ═══
// Авторизация проверена в index.php перед подключением этого файла
$allowed = [
    'products', 'suppliers', 'orders', 'order_items', 'plans', 'item_order',
    'settings', 'audit_log', 'stock_1c', 'search_logs', 'cards', 'users',
    'analysis_data', 'notifications', 'restaurants', 'delivery_schedule', 'order_corrections',
    'chat_conversations', 'chat_messages', 'supplier_payments', 'hidden_analogs',
    'error_logs', 'changelog', 'product_adu', 'stock_malling',
    'deficit_sessions', 'deficit_results', 'deficit_tokens', 'deficit_restaurant_stock',
    'stock_collections', 'stock_collection_products', 'stock_collection_data', 'stock_collection_tokens',
    'price_agreements', 'product_prices', 'price_history',
    'tenders', 'tender_items', 'tender_offers', 'tender_offer_prices', 'tender_files',
    'bug_reports', 'bug_report_replies', 'restaurant_sales', 'report_exclusions',
    'veg_sessions', 'veg_session_products', 'veg_tokens', 'veg_delivery_days', 'veg_orders', 'veg_restaurant_notes', 'veg_deadline_rules',
    'dist_sessions', 'dist_session_products', 'dist_entries', 'dist_notes',
    'plt_products', 'plt_deliveries', 'plt_delivery_items', 'plt_daily_stock', 'plt_summary',
    'marketing_activities', 'marketing_activity_items', 'marketing_activity_files',
    'recipes', 'recipe_ingredients', 'recipe_groups', 'recipe_group_items', 'pallet_reference',
];
// Защита: только чтение через REST, запись — через RPC
$readOnly = ['search_logs', 'users', 'error_logs', 'api_keys', 'price_history', 'stock_malling', 'deficit_tokens', 'deficit_restaurant_stock', 'bug_reports', 'bug_report_replies', 'tender_files', 'veg_tokens', 'marketing_activity_files'];
// settings — только чтение и обновление (без delete/insert для защиты системных ключей)
$noInsertDelete = ['settings'];
// audit_log — только чтение и вставка (без update/delete для защиты целостности)
$appendOnly = ['audit_log'];
if (!in_array($endpoint, $allowed)) { respond(['error'=>'Not found'], 404); }
$table = $endpoint;

// RBAC: модульная проверка прав
$sessionUser = getSessionUser($pdo);
// API-ключ без сессии: разрешаем только чтение
if (!$sessionUser) {
    if ($method !== 'GET') {
        respond(['error' => 'Требуется авторизация по сессии для операций записи'], 401);
    }
    // API-ключ даёт только чтение, RBAC не применяется (нет пользователя для проверки ролей)
}
if ($sessionUser) {
    $userRole = $sessionUser['role'] ?? 'user';
    if ($userRole !== 'admin') {
        $module = $TABLE_TO_MODULE[$table] ?? null;
        if ($module) {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $level = $ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0;
            $requiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : (($method === 'DELETE') ? $ACCESS_LEVELS['full'] : $ACCESS_LEVELS['edit']);
            if ($level < $requiredLevel) {
                respond(['error' => 'Недостаточно прав'], 403);
            }
        }
    }
}

// Enforce read-only
if (in_array($table, $readOnly) && $method !== 'GET') {
    respond(['error' => 'Эта таблица доступна только для чтения'], 403);
}
// Enforce no insert/delete for settings
if (in_array($table, $noInsertDelete) && ($method === 'POST' || $method === 'DELETE')) {
    respond(['error' => 'Добавление и удаление для этой таблицы запрещено'], 403);
}
// Settings: PATCH только для админов
if ($table === 'settings' && $method === 'PATCH' && (!$sessionUser || $sessionUser['role'] !== 'admin')) {
    respond(['error' => 'Изменение настроек доступно только администраторам'], 403);
}
// Enforce append-only (allow GET + POST, block PATCH/PUT/DELETE)
if (in_array($table, $appendOnly) && !in_array($method, ['GET', 'POST'])) {
    respond(['error' => 'Для этой таблицы доступно только чтение и добавление'], 403);
}

// Проверка доступа к юр. лицу в REST-запросах
if ($sessionUser && in_array($table, $ENTITY_TABLES)) {
    $userRole = $sessionUser['role'] ?? 'user';
    $leFilt = $_GET['legal_entity'] ?? null;
    if ($leFilt) {
        // Извлекаем значение из фильтра eq.XXX
        $leVal = (strpos($leFilt, 'eq.') === 0) ? substr($leFilt, 3) : $leFilt;
        $leVal = urldecode($leVal);
        if (!checkLegalEntityAccess($sessionUser, $leVal)) {
            respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
    } elseif ($method === 'GET' && $userRole !== 'admin') {
        // notifications и orders по ID: доступ проверяется позже для каждой записи
        $skipEntityFilter = in_array($table, ['notifications']);
        // Запрос по конкретному ID — проверка доступа будет на строке с checkLegalEntityAccess
        if (isset($_GET['id']) || (isset($parts[1]) && $parts[1])) $skipEntityFilter = true;
        if (!$skipEntityFilter) {
            respond(['error' => 'Требуется фильтр legal_entity'], 400);
        }
    }
    // Для операций записи проверяем legal_entity в теле запроса
    if ($method !== 'GET' && !empty($body)) {
        // Batch insert: проверяем legal_entity в каждой записи массива
        $recsToCheck = (isset($body[0]) && is_array($body[0])) ? $body : [$body];
        foreach ($recsToCheck as $rec) {
            $bodyLE = $rec['legal_entity'] ?? null;
            if ($bodyLE && !checkLegalEntityAccess($sessionUser, $bodyLE)) {
                respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            }
        }
    }
}

// Дочерние таблицы тендеров — проверяем юрлицо через родительский тендер
$TENDER_CHILD_TABLES = ['tender_items', 'tender_offers', 'tender_offer_prices', 'tender_files'];
if ($sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $TENDER_CHILD_TABLES)) {
    // Определяем tender_id из запроса
    $tenderId = null;
    if ($method === 'GET') {
        $tenderId = $_GET['tender_id'] ?? null;
        if ($tenderId) $tenderId = preg_replace('/^eq\./', '', $tenderId);
    } elseif (!empty($body)) {
        $rec = (isset($body[0]) && is_array($body[0])) ? $body[0] : $body;
        $tenderId = $rec['tender_id'] ?? null;
        // Для tender_offer_prices — через offer_id
        if (!$tenderId && ($rec['offer_id'] ?? null)) {
            $s = $pdo->prepare("SELECT tender_id FROM tender_offers WHERE id = ?");
            $s->execute([$rec['offer_id']]);
            $tenderId = $s->fetchColumn() ?: null;
        }
    }
    if ($tenderId) {
        $s = $pdo->prepare("SELECT legal_entity FROM tenders WHERE id = ?");
        $s->execute([$tenderId]);
        $tenderLE = $s->fetchColumn();
        if ($tenderLE && !checkLegalEntityAccess($sessionUser, $tenderLE)) {
            respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
    }
}

// Белый список полей, доступных для фильтрации через GET-параметры
$filterWhitelist = [
    'products'    => ['id','sku','name','supplier','legal_entity','is_active','analog_group','category','is_traceable'],
    'suppliers'   => ['id','short_name','full_name','legal_entity','is_active','dlt','doc','country'],
    'orders'      => ['id','supplier','legal_entity','delivery_date','created_at','created_by','unit','received_at'],
    'order_items' => ['id','order_id','sku','name'],
    'plans'       => ['id','supplier','legal_entity','created_at'],
    'item_order'  => ['supplier','legal_entity','item_id'],
    'settings'    => ['key'],
    'audit_log'   => ['entity_type','entity_id','action','user_name'],
    'stock_1c'    => ['sku','legal_entity'],
    'cards'       => ['id','sku','name','supplier','legal_entity','is_active','analogs','updated_by'],
    'notifications'=> ['id','type','target_user','entity_type','entity_id','legal_entity'],
    'restaurants' => ['id','legal_entity','legal_entity_group'],
    'delivery_schedule' => ['id','restaurant_id','legal_entity'],
    'order_corrections' => ['id','restaurant_number','delivery_date','status','created_at'],
    'chat_conversations' => ['id','restaurant_number','status','restaurant_chat_id'],
    'chat_messages' => ['id','conversation_id','direction','is_read'],
    'dist_sessions' => ['id','status','created_by'],
    'dist_session_products' => ['id','session_id'],
    'dist_entries' => ['id','session_product_id','restaurant_number'],
    'dist_notes' => ['id','session_id','restaurant_number'],
    'supplier_payments' => ['id','order_id','supplier','legal_entity','status','payment_date','created_at'],
    'hidden_analogs' => ['id','analog_group'],
    'analysis_data' => ['id','legal_entity','sku'],
    'error_logs'    => ['id','level','source','user_name','created_at'],
    'changelog'     => ['id','version','created_at'],
    'product_adu'   => ['id','sku','legal_entity'],
    'stock_malling' => ['id','customer','warehouse','product_name','expiry_date','expiry_status'],
    'search_logs'   => ['id','user_name','created_at'],
    'users'         => ['id','name','role'],
    'deficit_sessions'  => ['id','legal_entity','created_by','created_at'],
    'deficit_results'   => ['id','session_id','restaurant_number'],
    'deficit_tokens'    => ['id','legal_entity','created_by'],
    'deficit_restaurant_stock' => ['id','token_id','restaurant_number'],
    'stock_collections'       => ['id','legal_entity','status'],
    'stock_collection_products' => ['id','collection_id'],
    'stock_collection_data'   => ['id','collection_id','product_id','restaurant_number'],
    'stock_collection_tokens' => ['id','collection_id'],
    'price_agreements' => ['id','number','supplier','legal_entity','status','valid_from','valid_to','created_by','approved_by','created_at'],
    'product_prices'   => ['id','sku','supplier','legal_entity','agreement_id','vat_rate','updated_by','updated_at'],
    'restaurant_sales' => ['id','analog_group','sale_date'],
    'report_exclusions' => ['id','analog_group'],
    'veg_sessions'          => ['id','status','created_by'],
    'veg_session_products'  => ['id','session_id'],
    'veg_tokens'            => ['id','session_id'],
    'veg_delivery_days'     => ['id','restaurant_number','day_of_week'],
    'veg_orders'            => ['id','session_id','product_id','restaurant_number','delivery_date'],
    'veg_restaurant_notes'  => ['id','session_id','restaurant_number'],
    'plt_products'          => ['id','entity_group','sku','name','storage_type','boxes_per_pallet'],
    'plt_deliveries'        => ['id','legal_entity','delivery_date','supplier_name','created_by'],
    'plt_delivery_items'    => ['id','delivery_id','product_id'],
    'plt_daily_stock'       => ['id','legal_entity','stock_date'],
    'plt_summary'           => ['id','legal_entity','entry_date','supplier_name','delivery_id','is_manual'],
    'marketing_activities'      => ['id','name','type','status','date_from','date_to','legal_entity','restaurant_count','created_by','created_at'],
    'marketing_activity_items'  => ['id','activity_id','product_id','sku','name','calc_method'],
    'marketing_activity_files'  => ['id','activity_id'],
    'recipes'                   => ['id','code','name','thk','brutto_total','qty_total','created_at'],
    'recipe_ingredients'        => ['id','recipe_id','sku','name','brutto','qty'],
];

// Белый список колонок для записи (POST/PATCH)
$writeWhitelist = [
    'orders'       => ['id','supplier','legal_entity','delivery_date','delivery_date_2','unit','note','items','details','created_by','created_at','updated_at','cda_mode','safety_coef','act_file','received_at','received_by','today_date','safety_days','period_days','has_transit','show_stock_column','ttn_date'],
    'order_items'  => ['id','order_id','sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category','sort_order'],
    'plans'        => ['id','supplier','legal_entity','data','created_by','updated_by','created_at','updated_at','notes','period_type','period_count','items','start_date','planning_date','consumption_period_days','input_unit','note'],
    'products'     => ['id','sku','name','supplier','qty_per_box','boxes_per_pallet','unit_of_measure','legal_entity','multiplicity','analog_group','is_active','category','weight_netto','weight_brutto','external_code','gtin','is_traceable'],
    'suppliers'    => ['id','short_name','full_name','legal_entity','is_active','dlt','doc','palletization','notes','schedule','whatsapp','telegram','viber','email','country','payment_delay_days'],
    'notifications'=> ['id','type','title','message','target_user','entity_type','entity_id','legal_entity','created_by','created_at','read_by','deleted_by'],
    'price_agreements' => ['id','number','supplier','legal_entity','status','valid_from','valid_to','note','doc_type','file_name','file_path','created_by','approved_by','created_at'],
    'product_prices'   => ['id','sku','supplier','legal_entity','price','vat_rate','unit_type','currency','agreement_id','updated_by','updated_at'],
    'audit_log'    => ['action','entity_type','entity_id','user_name','details','changes','legal_entity','created_at'],
    'analysis_data'=> ['id','sku','legal_entity','data','updated_at'],
    'stock_1c'     => ['id','sku','legal_entity','stock','updated_at'],
    'cards'        => ['id','sku','name','supplier','legal_entity','is_active','data','category','analogs','created_by','updated_by'],
    'settings'     => ['key','value'],
    'item_order'   => ['id','supplier','legal_entity','item_id','position'],
    'tenders'      => ['id','name','description','legal_entity','status','deadline','winner_supplier','summary','note','created_by','created_at','updated_at'],
    'tender_items' => ['id','tender_id','name','quantity','unit','sort_order','note'],
    'tender_offers'=> ['id','tender_id','supplier','delivery_days','payment_terms','conditions','note','created_at'],
    'tender_offer_prices' => ['id','offer_id','item_id','price'],
    'restaurant_sales' => ['id','sale_date','analog_group','quantity','restaurant_count'],
    'report_exclusions' => ['id','analog_group','created_by'],
    'changelog'        => ['id','version','title','description','created_by'],
    'deficit_sessions' => ['id','legal_entity','product_name','warehouse_stock','next_delivery_date','growth_factor','total_need','total_allocated','restaurant_count','created_by'],
    'deficit_results'  => ['id','session_id','restaurant_number','current_stock','daily_consumption','days_to_cover','need','allocated','delivery_day'],
    'stock_collections'=> ['id','name'],
    'stock_collection_data' => ['id','stock'],
    'stock_collection_products' => ['id','collection_id','product_name','product_sku','unit','note','sort_order'],
    'stock_collection_tokens' => ['id'],
    'hidden_analogs'        => ['id','analog_group','hidden_by'],
    'veg_sessions'          => ['id','name','date_from','date_to','status'],
    'veg_session_products'  => ['id','session_id','product_name','unit','multiplicity','sort_order'],
    'veg_tokens'            => ['id'],
    'veg_delivery_days'     => ['id','restaurant_number','day_of_week'],
    'veg_orders'            => ['id','quantity','admin_note','admin_qty'],
    'veg_restaurant_notes'  => ['id','session_id','restaurant_number','note'],
    'plt_products'          => ['id','entity_group','name','sku','storage_type','boxes_per_pallet','sort_order'],
    'plt_deliveries'        => ['id','legal_entity','delivery_date','supplier_name','total_cold','total_frozen','note','created_by'],
    'plt_delivery_items'    => ['id','delivery_id','product_id','product_name','boxes_per_pallet','storage_type','boxes','pallets'],
    'plt_daily_stock'       => ['id','legal_entity','stock_date','cold_pallets','frozen_pallets'],
    'plt_summary'           => ['id','legal_entity','entry_date','supplier_name','cold_pallets','frozen_pallets','is_manual','delivery_id'],
];

if ($method === 'GET') {
    $where = []; $params = [];
    $allowedFields = $filterWhitelist[$table] ?? [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['select','order','limit','offset','or'])) continue;
        if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue;
        parseFilter($k, $v, $where, $params, $pdo, $table);
    }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields);

    if ($subpoint) {
        $s = $pdo->prepare("SELECT * FROM `$table` WHERE id=?"); $s->execute([$subpoint]); $row = $s->fetch();
        // Проверка доступа к юрлицу при запросе по ID
        if ($row && $sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES) && isset($row['legal_entity'])) {
            if (!checkLegalEntityAccess($sessionUser, $row['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        }
        if ($row && $table === 'orders') { $s2 = $pdo->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY sort_order ASC"); $s2->execute([$subpoint]); $row['order_items'] = $s2->fetchAll(); }
        respond($row ?: ['error'=>'not found'], $row ? 200 : 404);
    }

    $sel = preg_replace('/\s+/', ' ', trim($_GET['select'] ?? '*'));
    // Убираем пробел между table_name и (
    $sel = preg_replace('/(\w)\s+\(/', '$1(', $sel);
    $hasSubSelect = false; $subTable = ''; $subCols = '';
    if (preg_match('/(\w+)\(([^)]+)\)/', $sel, $m)) {
        $hasSubSelect = true; $subTable = $m[1]; $subCols = $m[2];
        // Валидация имени подтаблицы
        if (!preg_match('/^[a-zA-Z_]\w*$/', $subTable)) { $hasSubSelect = false; $subTable = ''; $subCols = ''; }
        // Валидация колонок подзапроса
        if ($subCols !== '*') {
            $subColsArr = array_map('trim', explode(',', $subCols));
            foreach ($subColsArr as $sc) { if (!preg_match('/^[a-zA-Z_]\w*$/', $sc)) { $hasSubSelect = false; break; } }
        }
        $sel = trim(preg_replace('/,?\s*\w+\([^)]+\)/', '', $sel), ', ');
        if (!$sel) $sel = '*';
    }
    // Валидация основных колонок SELECT + обёртка в обратные кавычки
    if ($sel !== '*') {
        $selCols = array_map('trim', explode(',', $sel));
        $valid = true;
        foreach ($selCols as $sc) { if (!preg_match('/^[a-zA-Z_]\w*$/', $sc)) { $valid = false; break; } }
        $sel = $valid ? implode(',', array_map(fn($c) => "`$c`", $selCols)) : '*';
    }

    // Поиск по товарам внутри заказов
    if ($table === 'orders' && isset($_GET['search']) && trim($_GET['search']) !== '') {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], trim($_GET['search']));
        $searchTerm = '%' . $escaped . '%';
        $where[] = "id IN (SELECT order_id FROM order_items WHERE name LIKE ? ESCAPE '\\\\' OR sku LIKE ? ESCAPE '\\\\')";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Внедряем фильтр по юрлицу в SQL для entity tables, если пользователь не админ и фильтр не указан
    if ($sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES) && !isset($_GET['legal_entity']) && $table !== 'notifications') {
        $userEntities = $sessionUser['legal_entities'] ?? '';
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        if (is_array($userEntities) && !empty($userEntities)) {
            $lePh = implode(',', array_fill(0, count($userEntities), '?'));
            $where[] = "`legal_entity` IN($lePh)";
            $params = array_merge($params, $userEntities);
        } else {
            // У пользователя нет привязки к юрлицам — не показывать ничего
            $where[] = "1=0";
        }
    }

    $sql = "SELECT $sel FROM `$table`";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    if (isset($_GET['order'])) {
        $op = explode('.', $_GET['order']);
        // Валидация имени колонки ORDER BY
        if (preg_match('/^[a-zA-Z_]\w*$/', $op[0])) {
            $sql .= " ORDER BY `{$op[0]}` " . (($op[1]??'asc')==='desc'?'DESC':'ASC');
        }
    }
    $maxLimit = ($table === 'restaurant_sales') ? 500000 : 5000;
    $limit = isset($_GET['limit']) ? max(1, min(intval($_GET['limit']), $maxLimit)) : 1000;
    $sql .= " LIMIT " . $limit;
    if (isset($_GET['offset'])) {
        $sql .= " OFFSET " . max(0, intval($_GET['offset']));
    }

    try {
        $s = $pdo->prepare($sql); $s->execute($params); $data = $s->fetchAll();
    } catch (PDOException $e) {
        error_log("SELECT error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Ошибка запроса к базе данных'], 500);
    }

    // Общее количество записей (без LIMIT) — для пагинации (только по запросу ?count=true)
    if (isset($_GET['count'])) {
        $countSql = "SELECT COUNT(*) FROM `$table`";
        if ($where) $countSql .= ' WHERE ' . implode(' AND ', $where);
        try {
            $cs = $pdo->prepare($countSql); $cs->execute($params);
            $totalCount = $cs->fetchColumn();
            header("X-Total-Count: $totalCount");
            header("Access-Control-Expose-Headers: X-Total-Count");
        } catch (PDOException $e) { /* не блокируем основной ответ */ }
    }

    // Пост-проверка больше не нужна — фильтр по юрлицу внедряется в SQL выше

    if ($hasSubSelect && $subTable && in_array($subTable, $allowed) && !empty($data)) {
        $fk = $table === 'orders' ? 'order_id' : 'id';
        $ids = array_column($data, 'id');
        if ($ids) {
            // Убедимся что FK-колонка включена в SELECT подтаблицы
            $subSelCols = $subCols;
            $fkIncluded = ($subCols === '*');
            if (!$fkIncluded) {
                $subColsArr = array_map('trim', explode(',', $subCols));
                if (!in_array($fk, $subColsArr)) {
                    $subSelCols = "`$fk`," . $subCols;
                }
                $fkIncluded = in_array($fk, $subColsArr);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s2 = $pdo->prepare("SELECT $subSelCols FROM `$subTable` WHERE `$fk` IN ($ph)");
            $s2->execute($ids);
            $subRows = $s2->fetchAll();
            // Группируем по FK
            $grouped = [];
            foreach ($subRows as $sr) {
                $key = $sr[$fk];
                // Убрать FK из результата если он не был в оригинальном запросе
                if ($subCols !== '*' && !$fkIncluded) unset($sr[$fk]);
                // Скрыть пароль при подзапросе таблицы users
                if ($subTable === 'users') unset($sr['password']);
                $grouped[$key][] = $sr;
            }
            foreach ($data as &$row) {
                $row[$subTable] = $grouped[$row['id']] ?? [];
            }
        }
    }
    if ($table === 'products') $data = cleanNumeric($data);
    // Скрыть пароль при чтении users
    if ($table === 'users') { foreach ($data as &$r) { unset($r['password']); } }
    respond($data);
}

if ($method === 'POST') {
    if (!is_array($body) || count($body) === 0) respond(['error' => 'Пустой запрос'], 400);
    // Запрет создания broadcast-уведомлений через REST (только через RPC send_broadcast)
    if ($table === 'notifications') {
        $recs_check = isset($body[0]) ? $body : [$body];
        foreach ($recs_check as $rc) { if (isset($rc['type']) && $rc['type'] === 'broadcast') respond(['error' => 'Используйте RPC send_broadcast'], 403); }
    }
    $recs = isset($body[0]) ? $body : [$body]; $ins = [];
    foreach ($recs as $rec) {
        if (!isset($rec['id']) && !in_array($table, ['audit_log','search_logs','api_keys','settings','notifications','delivery_schedule','restaurants','error_logs','changelog','price_agreements','product_prices','report_exclusions','stock_collection_products','stock_collection_data','stock_collection_tokens','plt_products','plt_deliveries','plt_delivery_items','plt_daily_stock','plt_summary','hidden_analogs','order_corrections','chat_conversations','chat_messages','supplier_payments','order_file'])) $rec['id'] = uuid();
        foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($rec[$jc]) && is_array($rec[$jc])) $rec[$jc] = json_encode($rec[$jc], JSON_UNESCAPED_UNICODE); }
        // Валидация имён колонок
        foreach (array_keys($rec) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Недопустимое имя колонки: '.$col], 400); }
        // Белый список колонок для записи
        if (isset($writeWhitelist[$table])) { $rec = array_intersect_key($rec, array_flip($writeWhitelist[$table])); if (empty($rec)) respond(['error' => 'Нет допустимых колонок для записи'], 400); }
        // audit_log: принудительно ставить user_name из сессии
        if ($table === 'audit_log' && $sessionUser) { $rec['user_name'] = $sessionUser['name']; }
        $cols = array_keys($rec); $ph = implode(',', array_fill(0, count($cols), '?')); $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
        try {
            if ($table === 'analysis_data' || $table === 'cards') {
                // Upsert: при дубле обновляем данные
                $upd = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
                $s = $pdo->prepare("INSERT INTO `$table` ($cn) VALUES ($ph) ON DUPLICATE KEY UPDATE $upd");
            } else {
                $s = $pdo->prepare("INSERT INTO `$table` ($cn) VALUES ($ph)");
            }
            $s->execute(array_values($rec));
        } catch (PDOException $e) {
            error_log("INSERT error [{$table}]: " . $e->getMessage());
            respond(['error' => 'Ошибка добавления записи'], 500);
        }
        $lid = $rec['id'] ?? $pdo->lastInsertId();
        $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE id=?"); $s2->execute([$lid]); $r = $s2->fetch(); if ($r) $ins[] = $r;
    }
    if ($table === 'users') { foreach ($ins as &$r) { unset($r['password']); } }
    respond(count($ins) === 1 ? $ins[0] : $ins, 201);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $where = []; $params = [];
    $allowedFields = $filterWhitelist[$table] ?? [];
    foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue; parseFilter($k, $v, $where, $params, $pdo, $table); }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields);
    if ($subpoint) {
        $where = ["`id`=?"]; $params = [$subpoint];
        // Проверка доступа к юрлицу при обновлении по ID
        if ($sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES)) {
            $chk = $pdo->prepare("SELECT legal_entity FROM `$table` WHERE id=?"); $chk->execute([$subpoint]); $row = $chk->fetch();
            if ($row && isset($row['legal_entity']) && !checkLegalEntityAccess($sessionUser, $row['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        }
    }
    if (!$where) respond(['error'=>'No filters'], 400);
    // Проверка юрлица для PATCH без ID
    if (!$subpoint && $sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES)) {
        if (!isset($_GET['legal_entity'])) respond(['error' => 'Требуется фильтр legal_entity'], 400);
        $leVal = $_GET['legal_entity'];
        $leClean = preg_replace('/^eq\./', '', $leVal);
        if (!checkLegalEntityAccess($sessionUser, $leClean)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
    }
    if (!is_array($body) || count($body) === 0) respond(['error' => 'Пустой запрос'], 400);
    foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($body[$jc]) && is_array($body[$jc])) $body[$jc] = json_encode($body[$jc], JSON_UNESCAPED_UNICODE); }
    // Валидация имён колонок
    foreach (array_keys($body) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Недопустимое имя колонки: '.$col], 400); }
    // Белый список колонок для записи
    if (isset($writeWhitelist[$table])) { $body = array_intersect_key($body, array_flip($writeWhitelist[$table])); if (empty($body)) respond(['error' => 'Нет допустимых колонок для записи'], 400); }
    // PATCH не должен менять created_by / created_at
    unset($body['created_by'], $body['created_at']);
    if (empty($body)) respond(['error' => 'Нет допустимых колонок для записи'], 400);
    // Автоматически обновляем updated_at для таблиц с этой колонкой
    if (in_array($table, ['orders', 'plans']) && !isset($body['updated_at'])) { $body['updated_at'] = date('Y-m-d H:i:s'); }
    $set = []; $sp = [];
    foreach ($body as $c => $v) { $set[] = "`$c`=?"; $sp[] = $v; }
    $all = array_merge($sp, $params);
    try {
        $s = $pdo->prepare("UPDATE `$table` SET " . implode(',', $set) . " WHERE " . implode(' AND ', $where)); $s->execute($all);
    } catch (PDOException $e) {
        error_log("UPDATE error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Ошибка обновления записи'], 500);
    }
    $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE " . implode(' AND ', $where)); $s2->execute($params);
    $result = $s2->fetchAll();
    if ($table === 'users') { foreach ($result as &$r) { unset($r['password']); } }
    respond($result);
}

if ($method === 'DELETE') {
    $where = []; $params = [];
    if ($subpoint) {
        $where[] = "`id`=?"; $params[] = $subpoint;
        // Проверка доступа к юрлицу при удалении по ID
        if ($sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES)) {
            $chk = $pdo->prepare("SELECT legal_entity FROM `$table` WHERE id=?"); $chk->execute([$subpoint]); $row = $chk->fetch();
            if ($row && isset($row['legal_entity']) && !checkLegalEntityAccess($sessionUser, $row['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        }
    } else { $allowedFields = $filterWhitelist[$table] ?? []; foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue; parseFilter($k, $v, $where, $params, $pdo, $table); } if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields); }
    if (!$where) respond(['error'=>'No filters'], 400);
    // Проверка юрлица для DELETE без ID
    if (!$subpoint && $sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES)) {
        if (!isset($_GET['legal_entity'])) respond(['error' => 'Требуется фильтр legal_entity'], 400);
        $leVal = $_GET['legal_entity'];
        $leClean = preg_replace('/^eq\./', '', $leVal);
        if (!checkLegalEntityAccess($sessionUser, $leClean)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
    }
    // Запоминаем ID удаляемых записей для аудита
    $deletedIds = [];
    if (in_array($table, ['orders','plans','products','suppliers','restaurants'])) {
        try {
            $preS = $pdo->prepare("SELECT `id` FROM `$table` WHERE " . implode(' AND ', $where));
            $preS->execute($params);
            $deletedIds = array_column($preS->fetchAll(), 'id');
        } catch (PDOException $e) { /* не блокируем удаление */ }
    }
    try {
        // Каскадное удаление дочерних записей — в транзакции
        $needsTx = ($table === 'orders' && !empty($deletedIds));
        if ($needsTx) $pdo->beginTransaction();
        if ($table === 'orders' && !empty($deletedIds)) {
            $ph = implode(',', array_fill(0, count($deletedIds), '?'));
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id` IN ($ph)")->execute($deletedIds);
        }
        $s = $pdo->prepare("DELETE FROM `$table` WHERE " . implode(' AND ', $where)); $s->execute($params);
        if ($needsTx) $pdo->commit();
    } catch (PDOException $e) {
        if ($needsTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log("DELETE error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Ошибка удаления записи'], 500);
    }
    // Аудит-лог для удалений
    if ($s->rowCount() > 0 && !empty($deletedIds)) {
        $deletedBy = $sessionUser ? $sessionUser['name'] : 'unknown';
        try {
            foreach ($deletedIds as $did) {
                $pdo->prepare("INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `user_name`, `details`, `created_at`) VALUES (?, ?, ?, ?, '{}', NOW())")
                    ->execute([$table . '_deleted', $table, $did, $deletedBy]);
            }
        } catch (PDOException $e) { /* не блокируем ответ */ }
    }
    respond(['deleted' => $s->rowCount()]);
}

respond(['error'=>'Method not allowed'], 405);