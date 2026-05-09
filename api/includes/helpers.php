<?php
/**
 * Вспомогательные функции: ответы, авторизация, фильтры, RBAC.
 * Подключается из index.php. Все переменные доступны через global.
 */
require_once __DIR__ . '/legal_entities.php';

// ═══ Telegram уведомления ═══
function sendTelegramMessage($botToken, $chatId, $text, $parseMode = 'HTML') {
    if (!$botToken || !$chatId) return false;
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_notification' => false,
    ]);
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function sendTelegramBulk($botToken, $chatIds, $text, $parseMode = 'HTML', $replyMarkup = null) {
    if (!$botToken || empty($chatIds)) return 0;
    $sent = 0;
    // Батчи по 25 — Telegram лимит ~30 msg/sec
    $batches = array_chunk($chatIds, 25);
    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $chatId) {
            $msgData = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'disable_notification' => false,
            ];
            if ($replyMarkup) $msgData['reply_markup'] = $replyMarkup;
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($msgData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        $running = null;
        do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);
        foreach ($handles as $ch) {
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $sent++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if (count($batches) > 1) usleep(100000); // 100ms пауза между батчами
    }
    return $sent;
}

/**
 * Возвращает chat_id пользователей, у которых включена указанная настройка уведомлений.
 * Если у пользователя нет записи в telegram_settings — считаем что уведомление включено (по умолчанию).
 */
function getSubscribedChatIds($pdo, $settingField) {
    $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary', 'data_updates', 'expiring_items', 'restaurant_sales'];
    if (!in_array($settingField, $allowed)) {
        // Без фильтра — всем
        $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }
    $s = $pdo->prepare("SELECT u.telegram_chat_id
        FROM users u
        LEFT JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != ''
          AND COALESCE(ts.`$settingField`, 1) = 1");
    $s->execute();
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function sendTelegramDocument($botToken, $chatId, $filename, $content, $caption = '') {
    if (!$botToken || !$chatId) return false;
    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $boundary = '----BkCalc' . bin2hex(random_bytes(8));
    $crlf = "\r\n";
    $body  = "--{$boundary}{$crlf}Content-Disposition: form-data; name=\"chat_id\"{$crlf}{$crlf}{$chatId}{$crlf}";
    if ($caption !== '') {
        $body .= "--{$boundary}{$crlf}Content-Disposition: form-data; name=\"caption\"{$crlf}{$crlf}{$caption}{$crlf}";
        $body .= "--{$boundary}{$crlf}Content-Disposition: form-data; name=\"parse_mode\"{$crlf}{$crlf}HTML{$crlf}";
    }
    $body .= "--{$boundary}{$crlf}";
    $body .= "Content-Disposition: form-data; name=\"document\"; filename=\"{$filename}\"{$crlf}";
    $body .= "Content-Type: {$mime}{$crlf}{$crlf}";
    $body .= $content . $crlf;
    $body .= "--{$boundary}--{$crlf}";
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary=' . $boundary],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $result = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false || $curlErr) {
        error_log("[sendTelegramDocument] curl error chat={$chatId}: " . ($curlErr ?: 'unknown'));
        return false;
    }
    $data = json_decode($result, true);
    if (!is_array($data) || empty($data['ok'])) {
        $desc = is_array($data) ? ($data['description'] ?? 'no description') : 'bad response';
        error_log("[sendTelegramDocument] Telegram error chat={$chatId} http={$httpCode}: {$desc}");
        return false;
    }
    return true;
}

function dbColumnExists($pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $s->execute([$table, $column]);
        $cache[$key] = (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function notifyTelegramDataUpdate($pdo, $type, $userName, $legalEntity = '', $count = 0) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;

    $tz = new DateTimeZone('Europe/Minsk');
    $time = (new DateTime('now', $tz))->format('H:i');

    $typeNames = [
        'analysis' => '📊 Анализ запасов',
        'shelf_life' => '⏰ Сроки годности',
    ];
    $label = $typeNames[$type] ?? $type;
    $leInfo = $legalEntity ? " ({$legalEntity})" : '';

    $safeUser = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeLE = htmlspecialchars($leInfo, ENT_QUOTES, 'UTF-8');
    $text = "<b>{$label}</b> — данные обновлены{$safeLE}\n";
    $text .= "👤 {$safeUser} в {$time}\n";
    $text .= "📝 Загружено записей: {$count}";

    $chatIds = getSubscribedChatIds($pdo, 'data_updates');
    sendTelegramBulk($botToken, $chatIds, $text);
}

/**
 * После загрузки сроков годности — уведомление о товарах с истекающим сроком (до 30 дней), по юрлицам.
 */
function notifyTelegramExpiringItems($pdo, $userName) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;

    try {
        $tz = new DateTimeZone('Europe/Minsk');
        $today = new DateTime('today', $tz);
        $limitDate = (clone $today)->modify('+30 days')->format('Y-m-d');
        $todayDate = $today->format('Y-m-d');

        $s = $pdo->prepare("SELECT customer, product_name, expiry_date, quantity
            FROM stock_malling
            WHERE expiry_date IS NOT NULL
              AND expiry_date >= ?
              AND expiry_date <= ?
            ORDER BY customer, expiry_date ASC");
        $s->execute([$todayDate, $limitDate]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return;

        // Группируем по юрлицу
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['customer']][] = $r;
        }

        $safeUser = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');

        $text = "⚠️ <b>Истекающие сроки годности</b> (до 30 дней)\n";
        $text .= "👤 Загрузил: {$safeUser}\n\n";

        foreach ($grouped as $entity => $items) {
            $safeEntity = htmlspecialchars($entity, ENT_QUOTES, 'UTF-8');
            $count = count($items);
            $text .= "🏢 <b>{$safeEntity}</b> — {$count} поз.\n";
            // Показываем до 10 позиций на юрлицо
            $shown = 0;
            foreach ($items as $item) {
                if ($shown >= 10) { $text .= "   … и ещё " . ($count - 10) . "\n"; break; }
                $exp = DateTime::createFromFormat('!Y-m-d', $item['expiry_date'], $tz);
                if (!$exp) $exp = new DateTime($item['expiry_date'], $tz);
                $days = (int)$today->diff($exp)->format('%r%a');
                $daysStr = $days === 0 ? 'сегодня!' : ($days === 1 ? 'завтра' : "через {$days} д.");
                $expiryDate = htmlspecialchars($exp->format('d.m.Y'), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars(mb_substr($item['product_name'], 0, 40), ENT_QUOTES, 'UTF-8');
                $qty = floatval($item['quantity']);
                $qtyStr = ($qty == intval($qty)) ? intval($qty) : $qty;
                $text .= "   • {$name} — {$qtyStr} шт, конечный срок {$expiryDate}, {$daysStr}\n";
                $shown++;
            }
            $text .= "\n";
        }

        $chatIds = getSubscribedChatIds($pdo, 'expiring_items');
        sendTelegramBulk($botToken, $chatIds, $text);
    } catch (PDOException $e) {
        error_log("notifyTelegramExpiringItems error: " . $e->getMessage());
    }
}

/**
 * После загрузки реализации ресторанов — уведомление о новых днях.
 */
function notifyTelegramRestaurantSales($pdo, $userName, $items, $count) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;

    try {
        $tz = new DateTimeZone('Europe/Minsk');
        $time = (new DateTime('now', $tz))->format('H:i');
        $safeUser = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');

        // Определяем диапазон загруженных дат
        $dates = array_filter(array_unique(array_column($items, 'sale_date')));
        sort($dates);
        $datesCount = count($dates);
        $groupsCount = count(array_unique(array_column($items, 'analog_group')));

        // Общий диапазон в БД после загрузки
        $s = $pdo->query("SELECT MIN(sale_date) as min_date, MAX(sale_date) as max_date, COUNT(DISTINCT sale_date) as total_days FROM restaurant_sales");
        $totals = $s->fetch(PDO::FETCH_ASSOC);

        $text = "🍽 <b>Реализация ресторанов</b> — данные обновлены\n";
        $text .= "👤 {$safeUser} в {$time}\n";
        $text .= "📝 Загружено записей: {$count}\n";
        if ($datesCount > 0) {
            $from = (new DateTime($dates[0]))->format('d.m.Y');
            $to = (new DateTime($dates[$datesCount - 1]))->format('d.m.Y');
            $text .= "📅 Дни: {$from} — {$to} ({$datesCount} дн.)\n";
            $text .= "📦 Товарных групп: {$groupsCount}\n";
        }
        if ($totals && $totals['min_date']) {
            $totalFrom = (new DateTime($totals['min_date']))->format('d.m.Y');
            $totalTo = (new DateTime($totals['max_date']))->format('d.m.Y');
            $text .= "\n📊 Всего в базе: {$totalFrom} — {$totalTo} ({$totals['total_days']} дн.)";
        }

        $chatIds = getSubscribedChatIds($pdo, 'restaurant_sales');
        sendTelegramBulk($botToken, $chatIds, $text);
    } catch (PDOException $e) {
        error_log("notifyTelegramRestaurantSales error: " . $e->getMessage());
    }
}

// Уведомление участников протокола совещания
function notifyProtocolParticipants($pdo, $protocolId, $topic, $date, $participants, $createdBy) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken || empty($participants)) return;
    try {
        $fmtDate = date('d.m.Y', strtotime($date));
        // Получаем решения
        $d = $pdo->prepare("SELECT text, responsible_person, deadline FROM protocol_decisions WHERE protocol_id = ? ORDER BY id");
        $d->execute([$protocolId]);
        $decisions = $d->fetchAll();

        $text = "📋 <b>Протокол совещания</b>\n";
        $text .= "─────────────────────\n";
        $text .= "📅 {$fmtDate}\n";
        $text .= "📝 {$topic}\n";
        $text .= "✍️ Составил: {$createdBy}\n";
        if ($decisions) {
            $text .= "\n<b>Задачи:</b>\n";
            foreach ($decisions as $i => $dec) {
                $num = $i + 1;
                $text .= "{$num}. {$dec['text']}";
                if ($dec['responsible_person']) $text .= " — {$dec['responsible_person']}";
                if ($dec['deadline']) $text .= " (до " . date('d.m', strtotime($dec['deadline'])) . ")";
                $text .= "\n";
            }
        }

        $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
        $keyboard = json_encode(['inline_keyboard' => [[['text' => '📋 Открыть протокол', 'url' => "{$siteUrl}/protocols/{$protocolId}"]]]]);

        // Находим chat_id участников
        $ph = implode(',', array_fill(0, count($participants), '?'));
        $s = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE name IN ({$ph}) AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
        $s->execute($participants);
        $chatIds = $s->fetchAll(PDO::FETCH_COLUMN);
        if ($chatIds) sendTelegramBulk($botToken, $chatIds, $text, 'HTML', $keyboard);
    } catch (Exception $e) {
        error_log("notifyProtocolParticipants error: " . $e->getMessage());
    }
}

// Уведомление ресторанов о новом сборе остатков
function scNotifyRestaurants($pdo, $collectionId, $collectionName, $productsCount) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return 0;

    // Все подписанные рестораны с включёнными уведомлениями о новых сборах (chat_id уникальные)
    $st = $pdo->query("SELECT DISTINCT chat_id, GROUP_CONCAT(DISTINCT restaurant_number ORDER BY CAST(restaurant_number AS UNSIGNED) SEPARATOR ', ') as rests FROM ro_telegram_subs WHERE notify_stock_sessions = 1 AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW())) GROUP BY chat_id");
    $subs = $st->fetchAll();
    if (!$subs) return 0;

    $text = "📋 <b>Новый сбор остатков</b>\n";
    $text .= "─────────────────────\n";
    $text .= "📝 {$collectionName}\n";
    $text .= "📦 Товаров: {$productsCount}\n\n";
    $text .= "Заполните остатки по вашему ресторану:";

    // Только заполнение в боте — публичная ссылка отключена,
    // через ЛК ресторан тоже может зайти, но кнопка ведёт в бот напрямую.
    $btns = [
        [['text' => '📋 Заполнить в боте', 'callback_data' => 'rest_sc_start']],
    ];
    $keyboard = json_encode(['inline_keyboard' => $btns]);

    $chatIds = array_column($subs, 'chat_id');
    return sendTelegramBulk($botToken, $chatIds, $text, 'HTML', $keyboard);
}

// ═══ ROLE TEMPLATES & PERMISSIONS ═══
// dashboard — отдельный модуль для руководителя (admin/manager).
// pallet-storage — паллетовка склада (отдельная от pallet-calc).
$ROLE_TEMPLATES = [
    'admin' => ['order'=>'full','planning'=>'full','history'=>'full','plan-fact'=>'full','database'=>'full','delivery-schedule'=>'full','analytics'=>'full','calendar'=>'full','analysis'=>'full','restaurant-sales'=>'full','shelf-life'=>'full','pricing'=>'full','tenders'=>'full','stock-collection'=>'full','deficit'=>'full','distribution'=>'full','telegram'=>'full','pallet-calc'=>'full','pallet-storage'=>'full','cards'=>'full','corrections'=>'full','chat'=>'full','marketing'=>'full','protocols'=>'full','restaurant-orders'=>'full','supplier-orders'=>'full','truck-loading'=>'full','surveys'=>'full','tasks'=>'full','dashboard'=>'full'],
    'manager' => ['order'=>'full','planning'=>'full','history'=>'full','plan-fact'=>'full','database'=>'full','delivery-schedule'=>'full','analytics'=>'full','calendar'=>'full','analysis'=>'full','restaurant-sales'=>'full','shelf-life'=>'full','pricing'=>'full','tenders'=>'full','stock-collection'=>'full','deficit'=>'full','distribution'=>'full','telegram'=>'none','pallet-calc'=>'full','pallet-storage'=>'full','cards'=>'full','corrections'=>'full','chat'=>'full','marketing'=>'full','protocols'=>'full','restaurant-orders'=>'full','supplier-orders'=>'full','truck-loading'=>'full','surveys'=>'full','tasks'=>'full','dashboard'=>'full'],
    'user'  => ['order'=>'edit','planning'=>'edit','history'=>'edit','plan-fact'=>'edit','database'=>'edit','delivery-schedule'=>'edit','analytics'=>'view','calendar'=>'view','analysis'=>'edit','restaurant-sales'=>'edit','shelf-life'=>'edit','pricing'=>'edit','tenders'=>'edit','stock-collection'=>'edit','deficit'=>'edit','distribution'=>'edit','telegram'=>'none','pallet-calc'=>'edit','pallet-storage'=>'none','cards'=>'view','corrections'=>'edit','chat'=>'edit','marketing'=>'edit','protocols'=>'edit','restaurant-orders'=>'full','supplier-orders'=>'full','truck-loading'=>'full','surveys'=>'edit','tasks'=>'full','dashboard'=>'none'],
    'viewer' => ['order'=>'view','planning'=>'view','history'=>'view','plan-fact'=>'view','database'=>'view','delivery-schedule'=>'view','analytics'=>'view','calendar'=>'view','analysis'=>'view','restaurant-sales'=>'view','shelf-life'=>'view','pricing'=>'view','tenders'=>'view','stock-collection'=>'view','deficit'=>'view','distribution'=>'view','telegram'=>'none','pallet-calc'=>'view','pallet-storage'=>'none','cards'=>'view','corrections'=>'view','chat'=>'view','marketing'=>'view','protocols'=>'view','restaurant-orders'=>'view','supplier-orders'=>'view','truck-loading'=>'view','surveys'=>'view','tasks'=>'view','dashboard'=>'none'],
];
$ACCESS_LEVELS = ['none'=>0,'view'=>1,'edit'=>2,'full'=>3];
$TABLE_TO_MODULE = [
    'orders'=>'order','order_items'=>'order',
    'plans'=>'planning',
    'products'=>'database','suppliers'=>'database','restaurants'=>'database','cards'=>'cards',
    'delivery_schedule'=>'delivery-schedule',
    'analysis_data'=>'analysis','stock_1c'=>'analysis','restaurant_sales'=>'restaurant-sales',
    'stock_malling'=>'shelf-life','warehouse_cells'=>'shelf-life',
    'notifications'=>'history',
    'settings'=>'database','item_order'=>'order',
    'deficit_sessions'=>'deficit','deficit_results'=>'deficit','deficit_tokens'=>'deficit','deficit_restaurant_stock'=>'deficit',
    'stock_collections'=>'stock-collection','stock_collection_products'=>'stock-collection','stock_collection_data'=>'stock-collection',
    'price_agreements'=>'pricing','product_prices'=>'pricing','price_history'=>'pricing',
    'tenders'=>'tenders','tender_items'=>'tenders','tender_offers'=>'tenders','tender_offer_prices'=>'tenders','tender_files'=>'tenders',
    'dist_sessions'=>'distribution','dist_session_products'=>'distribution','dist_entries'=>'distribution','dist_notes'=>'distribution',
    'plt_products'=>'pallet-calc','plt_deliveries'=>'pallet-calc','plt_delivery_items'=>'pallet-calc','plt_daily_stock'=>'pallet-calc','plt_summary'=>'pallet-calc',
    'pallet_reference'=>'pallet-storage',
    'order_corrections'=>'corrections',
    'chat_conversations'=>'chat','chat_messages'=>'chat',
    'ro_sessions'=>'restaurant-orders','ro_orders'=>'restaurant-orders','ro_order_items'=>'restaurant-orders','ro_templates'=>'restaurant-orders','ro_users'=>'restaurant-orders','ro_deadline_overrides'=>'restaurant-orders','ro_corrections'=>'restaurant-orders',
    'so_supplier_schedules'=>'supplier-orders','so_sessions'=>'supplier-orders','so_orders'=>'supplier-orders','so_order_items'=>'supplier-orders','so_templates'=>'supplier-orders','so_deadline_overrides'=>'supplier-orders',
    'surveys'=>'surveys','survey_questions'=>'surveys','survey_options'=>'surveys','survey_responses'=>'surveys','survey_answers'=>'surveys',
    'product_adu'=>'analysis','report_exclusions'=>'restaurant-sales','changelog'=>'history',
    'supplier_payments'=>'plan-fact',
    'marketing_activities'=>'marketing','marketing_activity_items'=>'marketing','marketing_activity_files'=>'marketing',
    'recipes'=>'marketing','recipe_ingredients'=>'marketing',
    'meeting_protocols'=>'protocols','meeting_protocol_series'=>'protocols','protocol_decisions'=>'protocols',
    'tl_vehicles'=>'truck-loading','tl_plans'=>'truck-loading','tl_trucks'=>'truck-loading','tl_assignments'=>'truck-loading',
    'tasks_boards'=>'tasks','tasks_columns'=>'tasks','tasks_cards'=>'tasks','tasks_labels'=>'tasks','tasks_card_labels'=>'tasks','tasks_checklist'=>'tasks','tasks_assignees'=>'tasks','tasks_attachments'=>'tasks','tasks_comments'=>'tasks','tasks_history'=>'tasks','tasks_relations'=>'tasks','tasks_recurrence'=>'tasks',
];

// ═══ Аудит-лог (хелпер для бэкенда) ═══
function auditLog($pdo, $action, $entityType, $entityId, $userName, $details = null, $changes = null) {
    try {
        $pdo->prepare("INSERT INTO audit_log (action, entity_type, entity_id, user_name, details, changes) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $action,
                $entityType,
                $entityId,
                $userName,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            ]);
    } catch (Exception $e) {
        error_log('auditLog error: ' . $e->getMessage());
    }
}

// Таблицы, в которых есть поле legal_entity и нужна проверка доступа
// Таблицы с колонкой legal_entity — для автоматической фильтрации по юрлицу
// order_items и item_order НЕ включены: у них нет legal_entity, доступ контролируется через родительскую таблицу orders
// marketing_activities, order_corrections, chat_conversations — общие для группы BK+VM by design (см. shared_tables_bk_vm), фильтр по юрлицу не применяем.
$ENTITY_TABLES = ['orders','plans','analysis_data','stock_1c','product_adu','notifications','deficit_sessions','deficit_tokens','stock_collections','price_agreements','product_prices','price_history','tenders','bug_reports','plt_deliveries','plt_daily_stock','plt_summary'];

function resolvePermissions($role, $permissionsJson, $templates) {
    $base = $templates[$role] ?? $templates['user'];
    if ($role === 'admin') return $templates['admin'];
    if (!$permissionsJson) return $base;
    $overrides = is_string($permissionsJson) ? json_decode($permissionsJson, true) : $permissionsJson;
    if (!is_array($overrides)) return $base;
    return array_merge($base, array_intersect_key($overrides, $base));
}

function requireModuleAccess($sessionUser, $module, $minLevel, $roleTemplates, $accessLevels) {
    if (!$sessionUser) {
        respond(['error' => 'Требуется авторизация'], 401);
    }
    $perms = resolvePermissions($sessionUser['role'] ?? 'user', $sessionUser['permissions'] ?? null, $roleTemplates);
    $actual = $accessLevels[$perms[$module] ?? 'none'] ?? 0;
    $required = $accessLevels[$minLevel] ?? 0;
    if ($actual < $required) {
        respond(['error' => 'Недостаточно прав'], 403);
    }
}

// Короткий хелпер для admin-only RPC. Бросает 401/403 и завершает запрос.
function requireAdmin($sessionUser) {
    if (!$sessionUser) {
        respond(['error' => 'Требуется авторизация'], 401);
    }
    if (($sessionUser['role'] ?? '') !== 'admin') {
        respond(['error' => 'Только для администратора'], 403);
    }
}

function checkLegalEntityAccess($sessionUser, $legalEntity) {
    if (!$sessionUser) return true;
    // Админ видит всё, в том числе записи без указанного юрлица.
    if (($sessionUser['role'] ?? '') === 'admin') return true;
    // Запись без юрлица — раньше пропускали всех авторизованных, что давало
    // обход разделения по юрлицам. Теперь — только админу. У обычных
    // пользователей таких записей в проде сейчас нет, поэтому изменение
    // безопасно; если когда-то появятся — админу нужно явно проставить юрлицо.
    if (!$legalEntity) return false;
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) {
        $userEntities = json_decode($userEntities, true);
    }
    if (!is_array($userEntities) || empty($userEntities)) return false;
    // Точное совпадение
    if (in_array($legalEntity, $userEntities)) return true;
    // Нечёткое: короткое имя (Бургер БК) → полное (ООО "Бургер БК")
    $leLower = mb_strtolower(trim($legalEntity));
    foreach ($userEntities as $ue) {
        if (mb_strtolower(trim($ue)) === $leLower) return true;
        // Извлечь имя из кавычек: ООО "Бургер БК" → бургер бк
        if (preg_match('/«([^»]+)»|"([^"]+)"/', $ue, $m)) {
            $short = mb_strtolower(trim($m[1] ?: $m[2]));
            if ($short === $leLower) return true;
        }
    }
    return false;
}

// ═══ Утилиты ═══
function respond($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit; }
function cleanNumeric($rows) {
    $decimal = ['qty_per_box'];
    foreach ($rows as &$r) { foreach ($decimal as $col) { if (isset($r[$col])) $r[$col] = +$r[$col]; } }
    return $rows;
}
function uuid() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)); }

function verifyAndMigratePassword($pdo, $userName, $inputPassword, $storedHash) {
    if (password_verify($inputPassword, $storedHash)) return true;
    // Fallback: plain-text migration — only if stored value is NOT a bcrypt hash
    if (strncmp($storedHash, '$2', 2) !== 0 && hash_equals($storedHash, $inputPassword)) {
        $hash = password_hash($inputPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=? WHERE name=?")->execute([$hash, $userName]);
        error_log("Password migrated to bcrypt for user: $userName");
        return true;
    }
    return false;
}

function checkApiKey($pdo) {
    $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$k) return false;
    $s = $pdo->prepare("SELECT id FROM api_keys WHERE api_key=? AND is_active='true'");
    $s->execute([$k]);
    return $s->fetch() !== false;
}

// Возвращает key_name активного API-ключа из заголовка X-API-Key или null.
// Кеширует результат на время запроса, чтобы не дёргать БД много раз из resolveActorName().
function getApiKeyName($pdo) {
    static $cache = ['done' => false, 'value' => null];
    if ($cache['done']) return $cache['value'];
    $cache['done'] = true;
    $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$k) return $cache['value'] = null;
    $s = $pdo->prepare("SELECT key_name FROM api_keys WHERE api_key=? AND is_active='true'");
    $s->execute([$k]);
    $row = $s->fetch();
    if (!$row) return $cache['value'] = null;
    $name = isset($row['key_name']) ? trim((string)$row['key_name']) : '';
    return $cache['value'] = ($name !== '' ? $name : 'api');
}

// Единая точка для поля аудита «кто сделал»:
// 1) имя авторизованного пользователя, если есть; 2) 'api:<key_name>' при запросе по API-ключу;
// 3) fallback (по умолчанию 'admin').
function resolveActorName($pdo, $sessionUser, $fallback = 'admin') {
    if ($sessionUser && !empty($sessionUser['name'])) return $sessionUser['name'];
    $apiName = getApiKeyName($pdo);
    if ($apiName) return 'api:' . $apiName;
    return $fallback;
}

function checkAuth($pdo) {
    if (getSessionUser($pdo)) return true;
    return checkApiKey($pdo);
}

$_sessionUserCache = ['done' => false, 'result' => null];
function getSessionUser($pdo) {
    global $_sessionUserCache;
    if ($_sessionUserCache['done']) return $_sessionUserCache['result'];
    $_sessionUserCache['done'] = true;

    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    // $_GET['dl'] — новый одноразовый download-токен (15 мин, привязан к файлу).
    // Принимается только для uploads. Легче, чем session-токен, и не утечёт.
    if (!$token && isset($_GET['dl'])) {
        global $endpoint;
        if (($endpoint ?? '') === 'uploads') {
            $dl = (string)$_GET['dl'];
            unset($_GET['dl']);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            if (preg_match('/^[a-f0-9]{32}$/', $dl)) {
                $st = $pdo->prepare("SELECT user_name, file_path, used_at FROM download_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1");
                $st->execute([$dl]);
                $row = $st->fetch();
                if ($row) {
                    // Одноразовость: после первой выдачи помечаем used_at.
                    // Допускаем повторную выдачу того же файла, чтобы превью
                    // не ломалось при повторных запросах <img>. Если файл другой —
                    // сервер всё равно отдаст ошибку 403/404 при проверке пути.
                    if (!$row['used_at']) {
                        $pdo->prepare("UPDATE download_tokens SET used_at = NOW(), ip_address = ? WHERE token = ?")
                            ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $dl]);
                    }
                    // Эмулируем сессию для скачивания: имя пользователя есть, остальное
                    // не нужно (uploads-обработчики не требуют role/legal_entities).
                    $u = $pdo->prepare("SELECT name, role, display_role, legal_entities, permissions, created_at, telegram_chat_id, hidden_modules FROM users WHERE name = ? LIMIT 1");
                    $u->execute([$row['user_name']]);
                    $userRow = $u->fetch();
                    if ($userRow) {
                        $_sessionUserCache['result'] = $userRow;
                        return $userRow;
                    }
                }
            }
        }
    }
    // [DEPRECATED] $_GET['token'] = session_token. Оставлен для совместимости
    // со старым фронтом (~10 мест), будет удалён после полного перехода на ?dl=.
    if (!$token && isset($_GET['token'])) {
        global $endpoint;
        if (($endpoint ?? '') === 'uploads') {
            $token = $_GET['token'];
            // Убираем токен из GET, чтобы он не попал в логи ошибок PHP
            unset($_GET['token']);
            // Запрещаем кэширование URL с токеном
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
    }
    if (!$token) { $_sessionUserCache['result'] = null; return null; }
    // Очистка сессий перенесена в cron_telegram.php
    // Абсолютный потолок жизни сессии — 30 дней от created_at, для admin — 7.
    // После этого «скользящее» продление expires_at не помогает: токен умирает.
    $s = $pdo->prepare("
        SELECT u.name, u.role, u.display_role, u.legal_entities, u.permissions,
               u.created_at, u.telegram_chat_id, u.hidden_modules,
               s.created_at AS session_created_at
        FROM user_sessions s
        JOIN users u ON u.name = s.user_name
        WHERE s.token = ?
          AND s.expires_at > NOW()
          AND s.created_at > (NOW() - INTERVAL CASE WHEN u.role = 'admin' THEN 7 ELSE 30 END DAY)
    ");
    $s->execute([$token]);
    $row = $s->fetch();
    if (!$row) { $_sessionUserCache['result'] = null; return null; }
    static $sessionUpdated = false;
    if (!$sessionUpdated) {
        $s2 = $pdo->prepare("SELECT expires_at FROM user_sessions WHERE token = ?");
        $s2->execute([$token]);
        $exp = $s2->fetchColumn();
        if ($exp && strtotime($exp) - time() < 6 * 86400) {
            // Продление expires_at не должно перепрыгнуть абсолютный потолок:
            // CASE по роли подбирается на стороне MySQL.
            $pdo->prepare("
                UPDATE user_sessions s
                JOIN users u ON u.name = s.user_name
                SET s.expires_at = LEAST(
                    DATE_ADD(NOW(), INTERVAL 7 DAY),
                    DATE_ADD(s.created_at, INTERVAL CASE WHEN u.role = 'admin' THEN 7 ELSE 30 END DAY)
                )
                WHERE s.token = ?
            ")->execute([$token]);
        }
        $sessionUpdated = true;
    }
    $_sessionUserCache['result'] = $row;
    return $row;
}

function createSessionToken($pdo, $userName) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND id NOT IN (SELECT id FROM (SELECT id FROM user_sessions WHERE user_name = ? ORDER BY created_at DESC LIMIT 4) AS t)")->execute([$userName, $userName]);
    $pdo->prepare("INSERT INTO user_sessions (user_name, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")->execute([$userName, $token, $expires, $ip, $ua]);
    return $token;
}

function checkRateLimit($pdo, $ip, $maxAttempts = 10, $windowMinutes = 10) {
    $pdo->prepare("DELETE FROM failed_login_attempts WHERE attempted_at < NOW() - INTERVAL ? MINUTE")->execute([$windowMinutes]);
    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM failed_login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL ? MINUTE");
    $s->execute([$ip, $windowMinutes]);
    $count = $s->fetch()['cnt'] ?? 0;
    return $count < $maxAttempts;
}

// Rate-limit по конкретной учётной записи (например, "rest_42" для ресторана №42).
// Защищает от распределённого перебора пароля с разных IP против одного аккаунта.
function checkAccountRateLimit($pdo, $userName, $maxAttempts = 5, $windowMinutes = 10) {
    if (!$userName) return true;
    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM failed_login_attempts WHERE user_name = ? AND attempted_at > NOW() - INTERVAL ? MINUTE");
    $s->execute([$userName, $windowMinutes]);
    $count = $s->fetch()['cnt'] ?? 0;
    return $count < $maxAttempts;
}

function recordFailedLogin($pdo, $ip, $userName = '') {
    $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name, attempted_at) VALUES (?, ?, NOW())")->execute([$ip, $userName]);
}

function recordPortalConsent($pdo, $subjectType, $subjectKey, $displayName = null, $consentCode = 'portal_data_rules', $consentVersion = '2026-04-23') {
    $subjectType = mb_substr((string)$subjectType, 0, 32);
    $subjectKey = mb_substr((string)$subjectKey, 0, 120);
    $displayName = $displayName !== null ? mb_substr((string)$displayName, 0, 255) : null;
    $consentCode = mb_substr((string)$consentCode, 0, 64);
    $consentVersion = mb_substr((string)$consentVersion, 0, 32);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    try {
        $s = $pdo->prepare("
            INSERT INTO portal_user_consents
              (subject_type, subject_key, display_name, consent_code, consent_version, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $s->execute([$subjectType, $subjectKey, $displayName, $consentCode, $consentVersion, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('recordPortalConsent error: ' . $e->getMessage());
    }
}

function parseFilter($key, $val, &$where, &$params, $pdo, $table) {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) return;
    $val = urldecode($val);
    if (strpos($val,'eq.')===0) { $where[]="`$key`=?"; $params[]=substr($val,3); }
    elseif (strpos($val,'neq.')===0) { $where[]="`$key`!=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'gte.')===0) { $where[]="`$key`>=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'gt.')===0) { $where[]="`$key`>?"; $params[]=substr($val,3); }
    elseif (strpos($val,'lte.')===0) { $where[]="`$key`<=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'lt.')===0) { $where[]="`$key`<?"; $params[]=substr($val,3); }
    elseif (preg_match('/^between\.(.+?)\.(.+)$/', $val, $m)) {
        $where[] = "`$key` BETWEEN ? AND ?";
        $params[] = $m[1];
        $params[] = $m[2];
    }
    elseif (strpos($val,'in.')===0) {
        $inv = substr($val, 4, -1); // убираем "in.(" и ")"
        $arr = preg_split('/(?<!\\\\),/', $inv);
        $arr = array_map(fn($x) => str_replace('\\,', ',', $x), $arr);
        $ph = implode(',', array_fill(0, count($arr), '?'));
        $where[] = "`$key` IN($ph)";
        $params = array_merge($params, $arr);
    }
    elseif (strpos($val,'ilike.')===0) {
        $raw = substr($val, 6);
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $raw);
        $where[] = "`$key` LIKE ? ESCAPE '\\\\'"; $params[] = str_replace('*', '%', $escaped);
    }
    elseif ($val === 'is.null') { $where[] = "`$key` IS NULL"; }
    elseif ($val === 'not.is.null') { $where[] = "`$key` IS NOT NULL"; }
    elseif (preg_match('/^not\.(eq|neq|gt|gte|lt|lte)\.(.+)$/', $val, $m)) {
        $ops = ['eq'=>'!=','neq'=>'=','gt'=>'<=','gte'=>'<','lt'=>'>=','lte'=>'>'];
        $where[] = "`$key` {$ops[$m[1]]} ?"; $params[] = $m[2];
    }
    else { $where[]="`$key`=?"; $params[]=$val; }
}

function parseOr($orStr, &$where, &$params, $allowedFields = []) {
    // Разделяем по запятой перед именем колонки, но не по экранированным запятым (\,)
    $parts = preg_split('/(?<!\\\\),(?=[a-zA-Z_])/', $orStr);
    $orClauses = [];
    foreach ($parts as $part) {
        if (preg_match('/^(\w+)\.(eq|neq|gt|gte|lt|lte)\.(.+)$/', $part, $m)) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $m[1])) continue;
            if (!empty($allowedFields) && !in_array($m[1], $allowedFields)) continue;
            $ops = ['eq'=>'=','neq'=>'!=','gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='];
            $orClauses[] = "`{$m[1]}` {$ops[$m[2]]} ?";
            // Убираем экранирование спецсимволов в значении
            $params[] = str_replace(['\\,', '\\(', '\\)', '\\\\'], [',', '(', ')', '\\'], $m[3]);
        } elseif (preg_match('/^(\w+)\.ilike\.(.+)$/', $part, $m)) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $m[1])) continue;
            if (!empty($allowedFields) && !in_array($m[1], $allowedFields)) continue;
            $orClauses[] = "`{$m[1]}` LIKE ? ESCAPE '\\\\'";
            $raw = str_replace(['\\,', '\\(', '\\)', '\\\\'], [',', '(', ')', '\\'], $m[2]);
            $likeVal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw);
            $params[] = str_replace(['%25','*'], '%', $likeVal);
        }
    }
    if ($orClauses) $where[] = '(' . implode(' OR ', $orClauses) . ')';
}
