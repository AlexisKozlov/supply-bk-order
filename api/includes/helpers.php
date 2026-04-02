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
        $s = $pdo->prepare("SELECT customer, product_name, expiry_date, quantity
            FROM stock_malling
            WHERE expiry_date IS NOT NULL
              AND expiry_date >= CURDATE()
              AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY customer, expiry_date ASC");
        $s->execute();
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return;

        // Группируем по юрлицу
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['customer']][] = $r;
        }

        $tz = new DateTimeZone('Europe/Minsk');
        $today = new DateTime('now', $tz);
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
                $exp = new DateTime($item['expiry_date']);
                $days = (int)$today->diff($exp)->days;
                $daysStr = $days === 0 ? 'сегодня!' : ($days === 1 ? 'завтра' : "через {$days} д.");
                $name = htmlspecialchars(mb_substr($item['product_name'], 0, 40), ENT_QUOTES, 'UTF-8');
                $qty = floatval($item['quantity']);
                $qtyStr = ($qty == intval($qty)) ? intval($qty) : $qty;
                $text .= "   • {$name} — {$qtyStr} шт, {$daysStr}\n";
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
    $st = $pdo->query("SELECT DISTINCT chat_id, GROUP_CONCAT(DISTINCT restaurant_number ORDER BY CAST(restaurant_number AS UNSIGNED) SEPARATOR ', ') as rests FROM veg_telegram_subs WHERE notify_stock_sessions = 1 GROUP BY chat_id");
    $subs = $st->fetchAll();
    if (!$subs) return 0;

    // Ищем активный токен для WebApp-кнопки
    $tokenStmt = $pdo->prepare("SELECT token FROM stock_collection_tokens WHERE collection_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $tokenStmt->execute([$collectionId]);
    $tok = $tokenStmt->fetchColumn();

    $text = "📋 <b>Новый сбор остатков</b>\n";
    $text .= "─────────────────────\n";
    $text .= "📝 {$collectionName}\n";
    $text .= "📦 Товаров: {$productsCount}\n\n";
    $text .= "Заполните остатки по вашему ресторану:";

    $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
    $btns = [];
    if ($tok) {
        $btns[] = [['text' => '📋 Заполнить остатки', 'web_app' => ['url' => "{$siteUrl}/stock-form/{$tok}"]]];
    }
    $btns[] = [['text' => '📋 Заполнить в боте', 'callback_data' => 'rest_sc_start']];
    $keyboard = json_encode(['inline_keyboard' => $btns]);

    $chatIds = array_column($subs, 'chat_id');
    return sendTelegramBulk($botToken, $chatIds, $text, 'HTML', $keyboard);
}

// ═══ ROLE TEMPLATES & PERMISSIONS ═══
$ROLE_TEMPLATES = [
    'admin' => ['order'=>'full','planning'=>'full','history'=>'full','plan-fact'=>'full','database'=>'full','delivery-schedule'=>'full','analytics'=>'full','calendar'=>'full','analysis'=>'full','restaurant-sales'=>'full','shelf-life'=>'full','pricing'=>'full','tenders'=>'full','veg'=>'full','stock-collection'=>'full','deficit'=>'full','distribution'=>'full','telegram'=>'full','pallet-calc'=>'full','cards'=>'full','corrections'=>'full','chat'=>'full','marketing'=>'full','protocols'=>'full'],
    'user'  => ['order'=>'edit','planning'=>'edit','history'=>'edit','plan-fact'=>'edit','database'=>'edit','delivery-schedule'=>'edit','analytics'=>'view','calendar'=>'view','analysis'=>'edit','restaurant-sales'=>'edit','shelf-life'=>'edit','pricing'=>'edit','tenders'=>'edit','veg'=>'edit','stock-collection'=>'edit','deficit'=>'edit','distribution'=>'edit','telegram'=>'none','pallet-calc'=>'edit','cards'=>'view','corrections'=>'edit','chat'=>'edit','marketing'=>'edit','protocols'=>'edit'],
    'viewer' => ['order'=>'view','planning'=>'view','history'=>'view','plan-fact'=>'view','database'=>'view','delivery-schedule'=>'view','analytics'=>'view','calendar'=>'view','analysis'=>'view','restaurant-sales'=>'view','shelf-life'=>'view','pricing'=>'view','tenders'=>'view','veg'=>'view','stock-collection'=>'view','deficit'=>'view','distribution'=>'view','telegram'=>'none','pallet-calc'=>'view','cards'=>'view','corrections'=>'view','chat'=>'view','marketing'=>'view','protocols'=>'view'],
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
    'stock_collections'=>'stock-collection','stock_collection_products'=>'stock-collection','stock_collection_data'=>'stock-collection','stock_collection_tokens'=>'stock-collection',
    'price_agreements'=>'pricing','product_prices'=>'pricing','price_history'=>'pricing',
    'tenders'=>'tenders','tender_items'=>'tenders','tender_offers'=>'tenders','tender_offer_prices'=>'tenders','tender_files'=>'tenders',
    'veg_sessions'=>'veg','veg_session_products'=>'veg','veg_tokens'=>'veg','veg_delivery_days'=>'veg','veg_orders'=>'veg','veg_restaurant_notes'=>'veg','veg_deadline_rules'=>'veg',
    'dist_sessions'=>'distribution','dist_session_products'=>'distribution','dist_entries'=>'distribution','dist_notes'=>'distribution',
    'plt_products'=>'pallet-calc','plt_deliveries'=>'pallet-calc','plt_delivery_items'=>'pallet-calc','plt_daily_stock'=>'pallet-calc','plt_summary'=>'pallet-calc',
    'order_corrections'=>'corrections',
    'chat_conversations'=>'chat','chat_messages'=>'chat',
    'product_adu'=>'analysis','report_exclusions'=>'restaurant-sales','changelog'=>'history',
    'supplier_payments'=>'plan-fact',
    'marketing_activities'=>'marketing','marketing_activity_items'=>'marketing','marketing_activity_files'=>'marketing',
    'recipes'=>'marketing','recipe_ingredients'=>'marketing',
    'meeting_protocols'=>'protocols','meeting_protocol_series'=>'protocols','protocol_decisions'=>'protocols',
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
$ENTITY_TABLES = ['orders','plans','analysis_data','stock_1c','product_adu','notifications','deficit_sessions','deficit_tokens','stock_collections','price_agreements','product_prices','price_history','tenders','bug_reports','plt_deliveries','plt_daily_stock','plt_summary','marketing_activities'];

function resolvePermissions($role, $permissionsJson, $templates) {
    $base = $templates[$role] ?? $templates['user'];
    if ($role === 'admin') return $templates['admin'];
    if (!$permissionsJson) return $base;
    $overrides = is_string($permissionsJson) ? json_decode($permissionsJson, true) : $permissionsJson;
    if (!is_array($overrides)) return $base;
    return array_merge($base, array_intersect_key($overrides, $base));
}

function checkLegalEntityAccess($sessionUser, $legalEntity) {
    if (!$sessionUser) return true;
    if (!$legalEntity) return true; // Запись без юрлица — доступна всем авторизованным
    if (($sessionUser['role'] ?? '') === 'admin') return true;
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
    // $_GET['token'] разрешён только для скачивания файлов
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
    $s = $pdo->prepare("SELECT u.name, u.role, u.display_role, u.legal_entities, u.permissions, u.created_at, u.telegram_chat_id, u.hidden_modules FROM user_sessions s JOIN users u ON u.name = s.user_name WHERE s.token = ? AND s.expires_at > NOW()");
    $s->execute([$token]);
    $row = $s->fetch();
    if (!$row) { $_sessionUserCache['result'] = null; return null; }
    static $sessionUpdated = false;
    if (!$sessionUpdated) {
        $s2 = $pdo->prepare("SELECT expires_at FROM user_sessions WHERE token = ?");
        $s2->execute([$token]);
        $exp = $s2->fetchColumn();
        if ($exp && strtotime($exp) - time() < 6 * 86400) {
            $pdo->prepare("UPDATE user_sessions SET expires_at = ? WHERE token = ?")->execute([date('Y-m-d H:i:s', strtotime('+7 days')), $token]);
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

function recordFailedLogin($pdo, $ip, $userName = '') {
    $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name, attempted_at) VALUES (?, ?, NOW())")->execute([$ip, $userName]);
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
