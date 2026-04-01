<?php
// Telegram Bot для Supply Department
// Webhook: https://supply-department.online/api/telegram_bot.php

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) exit;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$BOT_TOKEN) exit;

$SITE_URL = $_ENV['SITE_URL'] ?? 'https://supply-department.online';

$GEMINI_API_KEY = $_ENV['GEMINI_API_KEY'] ?? '';
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? '';
$DEEPSEEK_API_KEY = $_ENV['DEEPSEEK_API_KEY'] ?? '';
$OPENROUTER_API_KEY = $_ENV['OPENROUTER_API_KEY'] ?? '';

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Проверка секретного токена вебхука
$webhookSecret = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';
if ($webhookSecret !== '') {
    $headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($webhookSecret, $headerSecret)) {
        http_response_code(403);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) exit;

// Определяем chatId для ошибок
$_CHAT_ID = $input['message']['chat']['id'] ?? $input['callback_query']['message']['chat']['id'] ?? null;

// Глобальный обработчик ошибок — бот никогда не падает молча
set_exception_handler(function(Throwable $e) {
    global $_CHAT_ID;
    if ($_CHAT_ID) {
        sendMessage($_CHAT_ID, "⚠️ Произошла ошибка. Попробуйте ещё раз или используйте /menu");
    }
    error_log('[TelegramBot] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
});

// ═══ Telegram helpers ═══

function sendMessage($chatId, $text, $replyMarkup = null) {
    global $BOT_TOKEN;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup) $data['reply_markup'] = json_encode($replyMarkup);
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $opts = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($data), 'timeout' => 10]];
    @file_get_contents($url, false, stream_context_create($opts));
}

function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
    global $BOT_TOKEN;
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup) $data['reply_markup'] = json_encode($replyMarkup);
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/editMessageText";
    $opts = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($data), 'timeout' => 10]];
    @file_get_contents($url, false, stream_context_create($opts));
}

function answerCallback($callbackId, $text = '', $showAlert = false) {
    global $BOT_TOKEN;
    $params = ['callback_query_id' => $callbackId, 'text' => $text];
    if ($showAlert) $params['show_alert'] = true;
    file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery?" . http_build_query($params));
}

function sendMessageAndGetId($chatId, $text) {
    global $BOT_TOKEN;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $opts = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($data), 'timeout' => 10]];
    $response = @file_get_contents($url, false, stream_context_create($opts));
    if ($response) {
        $result = json_decode($response, true);
        return $result['result']['message_id'] ?? null;
    }
    return null;
}

function deleteMessage($chatId, $messageId) {
    global $BOT_TOKEN;
    @file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/deleteMessage?" . http_build_query(['chat_id' => $chatId, 'message_id' => $messageId]));
}

function sendTyping($chatId) {
    global $BOT_TOKEN;
    @file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/sendChatAction?" . http_build_query(['chat_id' => $chatId, 'action' => 'typing']));
}

function sendDocument($chatId, $filename, $content, $caption = '') {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendDocument";
    $boundary = uniqid('--', true);
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n{$chatId}\r\n";
    if ($caption) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\n{$caption}\r\n";
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"parse_mode\"\r\n\r\nHTML\r\n";
    }
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"document\"; filename=\"{$filename}\"\r\nContent-Type: text/csv\r\n\r\n{$content}\r\n--{$boundary}--\r\n";
    $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary={$boundary}", 'content' => $body, 'timeout' => 30]];
    @file_get_contents($url, false, stream_context_create($opts));
}

require_once __DIR__ . '/includes/bot_ai.php';
require_once __DIR__ . '/includes/bot_lookup.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/bot_tools.php';
require_once __DIR__ . '/includes/bot_veg.php';
require_once __DIR__ . '/includes/bot_chat.php';
require_once __DIR__ . '/includes/bot_import.php';

// ═══ Получить пользователя по chat_id ═══

function getUser($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT name, role, legal_entities FROM users WHERE telegram_chat_id = ?");
    $s->execute([$chatId]);
    $u = $s->fetch();
    if (!$u) return null;
    $u['legal_entities'] = ($u['legal_entities'] && is_string($u['legal_entities'])) ? json_decode($u['legal_entities'], true) : [];
    return $u;
}

function getUserEntity($user) {
    global $pdo;
    // Проверяем, есть ли сохранённый выбор юрлица в telegram_settings
    $s = $pdo->prepare("SELECT selected_entity FROM telegram_settings WHERE user_name = ?");
    $s->execute([$user['name']]);
    $row = $s->fetch();
    $selected = $row ? ($row['selected_entity'] ?? null) : null;
    // Если выбрано и есть в списке доступных — используем
    if ($selected && in_array($selected, $user['legal_entities'])) {
        return $selected;
    }
    return $user['legal_entities'][0] ?? null;
}

function setUserEntity($userName, $entity) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_settings SET selected_entity = ? WHERE user_name = ?")->execute([$entity, $userName]);
}

function getUserMode($userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT mode FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $row = $s->fetch();
    return $row ? ($row['mode'] ?? null) : null;
}

function setUserMode($userName, $mode) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_settings SET mode = ? WHERE user_name = ?")->execute([$mode, $userName]);
}

function getEntityShort($entity) {
    if (strpos($entity, 'Бургер') !== false) return 'БК';
    if (strpos($entity, 'Воглия') !== false) return 'ВМ';
    if (strpos($entity, 'Пицца') !== false) return 'ПС';
    return mb_substr($entity, 0, 4);
}

// ═══ Команды с данными ═══

// Универсальная отправка: editMessage если есть $editMsgId, иначе sendMessage
function botSend($chatId, $text, $replyMarkup = null, $editMsgId = null) {
    if ($editMsgId) {
        editMessage($chatId, $editMsgId, $text, $replyMarkup);
    } else {
        sendMessage($chatId, $text, $replyMarkup);
    }
}

function cmdOrders($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT o.id, o.supplier, o.created_by, o.created_at, o.delivery_date,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.created_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) {
        $btns = [
            [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📦 <b>Заказы</b>{$es}\n<i>За 7 дней заказов нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $totalBoxes = array_sum(array_column($orders, 'total_boxes'));
    $text = "📦 <b>Заказы за 7 дней</b>{$es}\n";
    $text .= "<i>" . count($orders) . " заказов · " . number_format($totalBoxes, 0, '.', ' ') . " кор.</i>\n";
    $text .= "─────────────────────\n";
    foreach ($orders as $o) {
        $date = date('d.m', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m', strtotime($o['delivery_date'])) : '—';
        $boxes = $o['total_boxes'] ?: 0;
        $text .= "<b>{$o['supplier']}</b>  {$o['items_count']} поз. · {$boxes} кор.\n";
        $text .= "  {$date} → {$delivery} · {$o['created_by']}\n";
    }
    $btns = [
        [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdStock($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT a.sku, p.name, p.supplier, a.stock, a.consumption, a.period_days,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   CASE WHEN a.consumption > 0 THEN ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) ELSE 999 END as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 25";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $btns = [
        [['text' => '📊 Анализ запасов', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$items) {
        botSend($chatId, "📉 <b>Критичные остатки</b>{$es}\n\n✅ Нет товаров с запасом ≤ 5 дней.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $text = "📉 <b>Критичные остатки</b>{$es}\n";
    $text .= "<i>" . count($items) . " товаров с запасом ≤ 5 дней</i>\n";
    $text .= "─────────────────────\n";
    foreach ($items as $i) {
        $name = $i['name'] ? mb_substr($i['name'], 0, 30) : $i['sku'];
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $icon = $i['days_left'] <= 0 ? '🔴' : '🟠';
        $uLabel = getUomLabel($i['uom'] ?? 'шт');
        $text .= "{$icon} <b>{$name}</b>\n";
        $text .= "  {$i['stock']} {$uLabel} · {$daily}/день · <b>{$i['days_left']} дн.</b>\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdConsumption($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days, COALESCE(p.unit_of_measure, 'шт') as uom
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $btns = [
        [['text' => '📊 Анализ запасов', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$items) {
        botSend($chatId, "📊 <b>Расход</b>{$es}\n\nДанных пока нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $text = "📊 <b>Топ-15 по расходу</b>{$es}\n";
    $text .= "─────────────────────\n";
    foreach ($items as $n => $i) {
        $days = max($i['period_days'], 1);
        $daily = round($i['consumption'] / $days, 1);
        $name = $i['name'] ? mb_substr($i['name'], 0, 28) : $i['sku'];
        $uLabel = getUomLabel($i['uom'] ?? 'шт');
        $num = $n + 1;
        $text .= "<b>{$num}.</b> {$name}\n";
        $text .= "  <b>{$daily}</b> {$uLabel}/день · {$i['consumption']} за {$days} дн.\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdPrices($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT ph.sku, p.name as product_name, ph.old_price, ph.new_price, ph.changed_by, ph.changed_at, ph.supplier
            FROM price_history ph
            LEFT JOIN products p ON p.sku = ph.sku COLLATE utf8mb4_general_ci AND p.legal_entity = ph.legal_entity COLLATE utf8mb4_general_ci
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity = ph.legal_entity COLLATE utf8mb4_general_ci)";
    $params = [];
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $changes = $s->fetchAll();

    $btns = [
        [['text' => '💰 Цены на сайте', 'url' => $SITE_URL . '/pricing']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$changes) {
        botSend($chatId, "💰 <b>Цены</b>{$es}\n\nИзменений пока нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $upCnt = 0; $downCnt = 0;
    foreach ($changes as $c) { $c['new_price'] > $c['old_price'] ? $upCnt++ : $downCnt++; }
    $text = "💰 <b>Изменения цен</b>{$es}\n";
    $text .= "<i>↑{$upCnt} повышений · ↓{$downCnt} снижений</i>\n";
    $text .= "─────────────────────\n";
    foreach ($changes as $c) {
        $date = date('d.m', strtotime($c['changed_at']));
        $name = $c['product_name'] ? mb_substr($c['product_name'], 0, 25) : $c['sku'];
        $pctRaw = $c['old_price'] > 0 ? round(($c['new_price'] - $c['old_price']) / $c['old_price'] * 100) : 0;
        $pct = $pctRaw > 0 ? "+{$pctRaw}%" : "{$pctRaw}%";
        $arrow = $c['new_price'] > $c['old_price'] ? '▲' : '▼';
        $text .= "{$arrow} <b>{$name}</b>\n";
        $text .= "  {$c['old_price']} → <b>{$c['new_price']}</b> ({$pct}) · {$date}\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdPsc($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT pa.number, pa.supplier, pa.valid_from, pa.valid_to, pa.status,
                   DATEDIFF(pa.valid_to, CURDATE()) as days_left
            FROM price_agreements pa WHERE pa.status = 'active'";
    $params = [];
    if ($entity) { $sql .= " AND pa.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY pa.valid_to ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $agreements = $s->fetchAll();

    $btns = [
        [['text' => '📋 Протоколы на сайте', 'url' => $SITE_URL . '/pricing']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$agreements) {
        botSend($chatId, "📋 <b>Протоколы</b>{$es}\n\nАктивных нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $expiring = count(array_filter($agreements, fn($a) => $a['days_left'] <= 14 && $a['days_left'] > 0));
    $expired = count(array_filter($agreements, fn($a) => $a['days_left'] <= 0));
    $text = "📋 <b>Протоколы (ПСЦ)</b>{$es}\n";
    $sub = [];
    if ($expired) $sub[] = "🔴 {$expired} истёк";
    if ($expiring) $sub[] = "🟡 {$expiring} скоро";
    $text .= "<i>" . count($agreements) . " активных" . ($sub ? ' · ' . implode(' · ', $sub) : '') . "</i>\n";
    $text .= "─────────────────────\n";
    foreach ($agreements as $a) {
        $to = date('d.m.Y', strtotime($a['valid_to']));
        $days = $a['days_left'];
        $icon = $days <= 0 ? '🔴' : ($days <= 14 ? '🟡' : '🟢');
        $label = $days <= 0 ? 'истёк' : "{$days} дн.";
        $text .= "{$icon} <b>{$a['supplier']}</b>\n";
        $text .= "  №{$a['number']} · до {$to} · {$label}\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Планы по поставщикам ═══

function cmdPlans($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);

    $sql = "SELECT p.supplier, p.period_type, p.period_count, p.start_date, p.note, p.created_by, p.updated_at,
                   p.consumption_period_days, p.input_unit
            FROM plans p WHERE 1=1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY p.updated_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $plans = $s->fetchAll();

    if (!$plans) {
        $es = $entity ? ' · ' . getEntityShort($entity) : '';
        $btns = [
            [['text' => '📅 Планирование', 'url' => $SITE_URL . '/planning']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📅 <b>Планы поставок</b>{$es}\n<i>Планов пока нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
    $text = "📅 <b>Планы поставок</b>{$es}\n";
    $text .= "─────────────────────\n";
    foreach ($plans as $p) {
        $period = ($p['period_count'] ?? 3) . ' ' . ($periodLabels[$p['period_type']] ?? $p['period_type']);
        $updated = $p['updated_at'] ? date('d.m', strtotime($p['updated_at'])) : '—';
        $text .= "📅 <b>{$p['supplier']}</b> · {$period}\n";
        if ($p['note']) $text .= "  {$p['note']}\n";
    }
    $btns = [
        [['text' => '📅 Планирование', 'url' => $SITE_URL . '/planning']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Ожидающие поставки ═══

function cmdDeliveries($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);

    // Заказы без приёмки (received_at IS NULL) и с датой доставки
    $sql = "SELECT o.id, o.supplier, o.delivery_date, o.created_by, o.created_at,
                   DATEDIFF(CURDATE(), o.delivery_date) as days_overdue,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes
            FROM orders o
            WHERE o.received_at IS NULL AND o.delivery_date IS NOT NULL";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.delivery_date ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) {
        $btns = [
            [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "🚚 <b>Ожидающие поставки</b>\n<i>Все поставки приняты</i> ✅", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $overdueCnt = count(array_filter($orders, fn($o) => $o['days_overdue'] > 0));
    $todayCnt = count(array_filter($orders, fn($o) => $o['days_overdue'] == 0));

    $text = "🚚 <b>Ожидающие поставки</b>{$es}\n";
    $sub = [];
    if ($overdueCnt) $sub[] = "🔴 {$overdueCnt} просроч.";
    if ($todayCnt) $sub[] = "🟡 {$todayCnt} сегодня";
    $text .= "<i>" . count($orders) . " заказов" . ($sub ? ' · ' . implode(' · ', $sub) : '') . "</i>\n";
    $text .= "─────────────────────\n";

    $keyboard = [];
    foreach ($orders as $i => $o) {
        $delivery = date('d.m', strtotime($o['delivery_date']));
        $overdue = $o['days_overdue'];
        $icon = $overdue > 0 ? '🔴' : ($overdue == 0 ? '🟡' : '🟢');
        $label = $overdue > 0 ? "просроч. {$overdue}д" : ($overdue == 0 ? 'сегодня' : abs($overdue) . 'д');
        $num = $i + 1;
        $boxes = $o['total_boxes'] ?: 0;
        $text .= "{$icon} <b>{$o['supplier']}</b> · {$delivery} · {$label}\n";
        $text .= "  {$o['items_count']} поз. · {$boxes} кор.\n";
        $orderUrl = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
        $row = [['text' => "{$num}. {$o['supplier']}", 'url' => $orderUrl]];
        if ($overdue >= 0) {
            $row[] = ['text' => '✅ Принято', 'callback_data' => 'receive_' . $o['id']];
        }
        $keyboard[] = $row;
    }
    $keyboard[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
    botSend($chatId, $text, ['inline_keyboard' => $keyboard], $editMsgId);
}

// ═══ Сегодня (мини-дашборд) ═══

function cmdToday($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $params = $entity ? [$entity] : [];
    $eFilter = $entity ? " AND o.legal_entity = ?" : "";
    $eFilterA = $entity ? " AND a.legal_entity = ?" : "";

    $dayNames = [1=>'понедельник',2=>'вторник',3=>'среда',4=>'четверг',5=>'пятница',6=>'суббота',7=>'воскресенье'];
    $dayName = $dayNames[(int)date('N')] ?? '';
    $text = "📅 <b>Сегодня</b>, " . date('d.m.Y') . " · {$dayName}{$es}\n";
    $text .= "─────────────────────\n";

    // 1. Поставки сегодня
    $s = $pdo->prepare("SELECT o.id, o.supplier, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count, (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes FROM orders o WHERE o.delivery_date = CURDATE() AND o.received_at IS NULL" . $eFilter);
    $s->execute($params);
    $todayOrders = $s->fetchAll();

    // 2. Просроченные
    $s = $pdo->prepare("SELECT o.supplier, DATEDIFF(CURDATE(), o.delivery_date) as days FROM orders o WHERE o.delivery_date < CURDATE() AND o.received_at IS NULL" . $eFilter . " ORDER BY o.delivery_date LIMIT 5");
    $s->execute($params);
    $overdueOrders = $s->fetchAll();

    // 3. Критичные остатки (≤5 дней)
    $s = $pdo->prepare("SELECT p.name, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity = a.legal_entity COLLATE utf8mb4_general_ci WHERE a.consumption > 0 AND a.stock > 0" . $eFilterA . " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 10");
    $s->execute($params);
    $critItems = $s->fetchAll();

    // Сводка
    $text .= "🚚 Поставки сегодня: <b>" . count($todayOrders) . "</b>\n";
    if ($overdueOrders) $text .= "🔴 Просроченных: <b>" . count($overdueOrders) . "</b>\n";
    $text .= "📉 Критичных остатков: <b>" . count($critItems) . "</b>\n";

    // 4. Дедлайны овощей
    try {
        $tz = new DateTimeZone('Europe/Minsk');
        $now = new DateTime('now', $tz);
        $todayDow = (int)$now->format('N');
        $dlRow = $pdo->prepare("SELECT delivery_dow, deadline_time FROM veg_deadline_rules WHERE deadline_dow = ?");
        $dlRow->execute([$todayDow]);
        $todayDeadlines = $dlRow->fetchAll();
        $activeSess = $pdo->query("SELECT id FROM veg_sessions WHERE status='active' LIMIT 1")->fetch();
        if ($activeSess && $todayDeadlines) {
            $dlDays = [1=>'пн',2=>'вт',3=>'ср',4=>'чт',5=>'пт',6=>'сб'];
            foreach ($todayDeadlines as $dl) {
                $delivDay = $dlDays[$dl['delivery_dow']] ?? '';
                $text .= "🥬 Дедлайн заявок на овощи: <b>{$dl['deadline_time']}</b> (доставка {$delivDay})\n";
            }
        }
    } catch (Exception $e) {}

    // Детали
    if ($todayOrders) {
        $text .= "─────────────────────\n";
        $text .= "<b>🚚 Сегодня ожидаются:</b>\n";
        foreach ($todayOrders as $o) {
            $text .= "  {$o['supplier']} · {$o['items_count']} поз. · " . ($o['total_boxes'] ?: 0) . " кор.\n";
        }
    }

    if ($overdueOrders) {
        $text .= "─────────────────────\n";
        $text .= "<b>🔴 Просрочены:</b>\n";
        foreach ($overdueOrders as $o) {
            $text .= "  {$o['supplier']} — {$o['days']} дн.\n";
        }
    }

    if ($critItems) {
        $text .= "─────────────────────\n";
        $text .= "<b>📉 Заканчиваются:</b>\n";
        foreach ($critItems as $c) {
            $icon = $c['days_left'] <= 0 ? '🔴' : '🟠';
            $name = mb_substr($c['name'] ?: '—', 0, 30);
            $text .= "  {$icon} {$name} · {$c['days_left']} дн.\n";
        }
    }

    $btns = [
        [['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries'], ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Экспорт CSV ═══

function cmdExport($chatId, $user, $editMsgId = null) {
    $es = getUserEntity($user) ? ' · ' . getEntityShort(getUserEntity($user)) : '';
    $text = "📤 <b>Экспорт данных</b>{$es}\n\nВыберите, что выгрузить:";
    $btns = [
        [['text' => '📊 Анализ запасов', 'callback_data' => 'export_analysis']],
        [['text' => '📦 Заказы (30 дней)', 'callback_data' => 'export_orders']],
        [['text' => '💰 Прайс-лист', 'callback_data' => 'export_prices']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function generateAnalysisCsv($entity) {
    global $pdo;
    $sql = "SELECT p.sku, p.name, p.supplier, p.category, COALESCE(p.unit_of_measure,'шт') as uom, a.stock, a.consumption, a.period_days, CASE WHEN a.consumption > 0 THEN ROUND(a.stock / (a.consumption / GREATEST(a.period_days,1))) ELSE 999 END as days_left FROM products p INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci WHERE p.is_active = 1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY days_left ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $csv = "\xEF\xBB\xBFАртикул;Название;Поставщик;Категория;Ед.изм.;Остаток;Расход;Дней данных;Дней запаса\n";
    foreach ($rows as $r) {
        $csv .= "{$r['sku']};{$r['name']};{$r['supplier']};{$r['category']};{$r['uom']};{$r['stock']};{$r['consumption']};{$r['period_days']};{$r['days_left']}\n";
    }
    return $csv;
}

function generateOrdersCsv($entity) {
    global $pdo;
    $sql = "SELECT o.supplier, o.created_by, o.created_at, o.delivery_date, o.received_at, oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.created_at DESC, oi.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $csv = "\xEF\xBB\xBFПоставщик;Дата заказа;Дата поставки;Принято;Автор;Артикул;Название;Коробки;Штук\n";
    foreach ($rows as $r) {
        $created = date('d.m.Y', strtotime($r['created_at']));
        $delivery = $r['delivery_date'] ? date('d.m.Y', strtotime($r['delivery_date'])) : '';
        $received = $r['received_at'] ? date('d.m.Y', strtotime($r['received_at'])) : '';
        $pcs = $r['qty_boxes'] * max($r['qty_per_box'], 1);
        $csv .= "{$r['supplier']};{$created};{$delivery};{$received};{$r['created_by']};{$r['sku']};{$r['name']};{$r['qty_boxes']};{$pcs}\n";
    }
    return $csv;
}

function generatePricesCsv($entity) {
    global $pdo;
    $sql = "SELECT pp.sku, p.name, pp.supplier, pp.price, pp.vat_rate, pp.currency, pp.unit_type FROM product_prices pp LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = pp.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = pp.legal_entity COLLATE utf8mb4_general_ci";
    $params = [];
    if ($entity) { $sql .= " WHERE pp.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY pp.supplier, p.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
    $csv = "\xEF\xBB\xBFАртикул;Название;Поставщик;Цена без НДС;НДС%;Цена с НДС;Валюта;Ед.изм.\n";
    foreach ($rows as $r) {
        $vat = $r['vat_rate'] ?? 20;
        $priceVat = round($r['price'] * (1 + $vat / 100), 2);
        $unit = $unitLabels[$r['unit_type']] ?? $r['unit_type'];
        $csv .= "{$r['sku']};{$r['name']};{$r['supplier']};{$r['price']};{$vat};{$priceVat};{$r['currency']};{$unit}\n";
    }
    return $csv;
}

// ═══ Реализация ресторанов ═══

function cmdSales($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;

    // Определяем последнюю дату данных
    $s = $pdo->query("SELECT MAX(sale_date) as last_date, MIN(sale_date) as first_date, COUNT(DISTINCT sale_date) as total_days FROM restaurant_sales");
    $meta = $s->fetch();
    if (!$meta || !$meta['last_date']) {
        $btns = [
            [['text' => '🏪 Реализация на сайте', 'url' => $SITE_URL . '/analysis']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "🏪 <b>Реализация ресторанов</b>\n<i>Данных пока нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }
    $lastDate = $meta['last_date'];

    // Топ-15 по объёму реализации за 30 дней
    $cutoff = date('Y-m-d', strtotime($lastDate . ' -29 days'));
    $s = $pdo->prepare("
        SELECT analog_group,
               ROUND(SUM(quantity)) as total,
               ROUND(SUM(quantity) / COUNT(DISTINCT sale_date)) as avg_day,
               ROUND(AVG(restaurant_count)) as avg_rc,
               COUNT(DISTINCT sale_date) as days_cnt,
               MAX(sale_date) as last_sale
        FROM restaurant_sales
        WHERE sale_date >= ?
        GROUP BY analog_group
        HAVING SUM(quantity) > 0
        ORDER BY total DESC
        LIMIT 15
    ");
    $s->execute([$cutoff]);
    $top = $s->fetchAll();

    // Тренд: сравним последние 14 дней с предыдущими 14
    $cut14 = date('Y-m-d', strtotime($lastDate . ' -13 days'));
    $cut28 = date('Y-m-d', strtotime($lastDate . ' -27 days'));
    $s = $pdo->prepare("
        SELECT analog_group,
               SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) as cur,
               SUM(CASE WHEN sale_date >= ? AND sale_date < ? THEN quantity ELSE 0 END) as prev
        FROM restaurant_sales
        WHERE sale_date >= ?
        GROUP BY analog_group
        HAVING cur > 0 AND prev > 0
    ");
    $s->execute([$cut14, $cut28, $cut14, $cut28]);
    $trends = [];
    while ($r = $s->fetch()) {
        $trends[$r['analog_group']] = $r['prev'] > 0 ? round(($r['cur'] - $r['prev']) / $r['prev'] * 100) : 0;
    }

    // Топ роста и падения
    arsort($trends);
    $topGrow = array_slice($trends, 0, 5, true);
    asort($trends);
    $topDrop = array_slice($trends, 0, 5, true);

    $lastFmt = date('d.m', strtotime($lastDate));
    $cutFmt = date('d.m', strtotime($cutoff));

    $totalAll = array_sum(array_column($top, 'total'));
    $text = "🏪 <b>Реализация ресторанов</b>\n";
    $text .= "<i>{$cutFmt} – {$lastFmt} · " . number_format($totalAll, 0, '.', ' ') . " ед. (топ-15)</i>\n";
    $text .= "─────────────────────\n";

    foreach ($top as $i => $r) {
        $trend = isset($trends[$r['analog_group']]) ? $trends[$r['analog_group']] : null;
        $trendIcon = '';
        if ($trend !== null && abs($trend) > 5) {
            $trendIcon = $trend > 0 ? " ↑{$trend}%" : " ↓" . abs($trend) . '%';
        }
        $num = $i + 1;
        $total = number_format($r['total'], 0, '.', ' ');
        $text .= "<b>{$num}.</b> {$r['analog_group']}\n";
        $text .= "  <b>{$total}</b> · {$r['avg_day']}/день · {$r['avg_rc']} рест.{$trendIcon}\n";
    }

    if ($topGrow || $topDrop) {
        $text .= "─────────────────────\n";
    }
    if ($topGrow) {
        $growParts = [];
        foreach ($topGrow as $name => $t) { $growParts[] = mb_substr($name, 0, 18) . " +{$t}%"; }
        $text .= "📈 " . implode(' · ', $growParts) . "\n";
    }
    if ($topDrop) {
        $dropParts = [];
        foreach ($topDrop as $name => $t) { $dropParts[] = mb_substr($name, 0, 18) . " {$t}%"; }
        $text .= "📉 " . implode(' · ', $dropParts) . "\n";
    }

    $btns = [
        [['text' => '🏪 Подробный отчёт', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ График доставок ═══

function cmdCards($chatId, $user = null, $editMsgId = null) {
    global $pdo;

    // Включаем режим поиска карточек
    if ($user) {
        setUserMode($user['name'], 'cards');
    }
    // Для всех (и ресторанов без аккаунта) — temp-файл (msg_id сохраним после отправки)
    file_put_contents(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt", $editMsgId ?: '0');

    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM cards");
    $s->execute();
    $total = $s->fetch()['cnt'];

    $s = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'last_update' LIMIT 1");
    $s->execute();
    $lastUpdate = $s->fetch()['value'] ?? '—';

    $text = "🔍 <b>Поиск карточек</b>\n";
    $text .= "<i>{$total} карточек · обн. {$lastUpdate}</i>\n";
    $text .= "─────────────────────\n";
    $text .= "Отправьте <b>артикул</b> или <b>название</b> товара.\n";
    $text .= "Бот найдёт карточку и покажет аналоги.";

    $btns = [
        [['text' => '❌ Выход из поиска', 'callback_data' => 'cmd_cards_exit']],
    ];
    if ($user) {
        $btns[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
    } else {
        $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// Стемминг русских слов — обрезка окончаний до основы
function ruStem($word) {
    $word = mb_strtolower($word);
    $word = str_replace('ё', 'е', $word);
    $len = mb_strlen($word);
    if ($len <= 3) return $word;
    // Длинные окончания (3-4 буквы)
    $suffixes4 = ['ками','ений','ения','ться','ного','ному','ными','ного','нном','ться','шить','ения','ский','ская','ское','ские'];
    $suffixes3 = ['ами','ями','ому','ому','ных','ным','ной','ное','ные','ний','ого','ому','ить','ать','ять','ует','ает','ции','тся','ика','ику','ики','ике','ист','ные'];
    $suffixes2 = ['ов','ев','ей','ий','ый','ая','ое','ые','ой','ом','ам','ям','ах','ях','ми','ие','ия','ки','ка','ку','ке','ок','ек','он','ин','ть','ся','ны','на','но'];
    $suffixes1 = ['а','о','у','е','ы','и','ь','й','я'];
    foreach ($suffixes4 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes3 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes2 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes1 as $s) {
        if ($len > 3 && mb_substr($word, -1) === $s) return mb_substr($word, 0, -1);
    }
    return $word;
}

// Словарь синонимов — расширяет запрос близкими словами
function expandSynonyms($words) {
    static $synonyms = [
        'картошка' => ['картофель','картошк','фри'],
        'картофель' => ['картошка','картошк','фри'],
        'помидор' => ['томат','томатн'],
        'томат' => ['помидор','помидорн'],
        'огурец' => ['огурч','корнишон'],
        'корнишон' => ['огурец','огурч'],
        'лук' => ['луков','репчат'],
        'курица' => ['куриц','курин','цыпл','наггетс','чикен'],
        'куриный' => ['курин','курица','цыпл','чикен'],
        'чикен' => ['курица','курин','куриц'],
        'наггетс' => ['наггетсы','курица','куриц'],
        'говядина' => ['говяж','говядин','ангус','бургер'],
        'говяжий' => ['говядин','говяж','ангус'],
        'свинина' => ['свинин','свиной','свин'],
        'булка' => ['булочк','булоч','хлеб'],
        'булочка' => ['булочк','булоч','булка','хлеб'],
        'хлеб' => ['булочк','булоч','булка'],
        'стакан' => ['стаканч','стаканов','стакан'],
        'стаканчик' => ['стакан','стаканч','стаканов'],
        'крышка' => ['крышеч','крышк'],
        'сок' => ['напиток','напит'],
        'напиток' => ['напит','сок','вода','газ'],
        'кола' => ['кока','пепси','газ','напит'],
        'вода' => ['питьев','аура','газ'],
        'молоко' => ['молоч','молок'],
        'сыр' => ['сырн','чиз','чеддер'],
        'чиз' => ['сыр','сырн','чеддер'],
        'салат' => ['салатн','зелен','латук','айсберг'],
        'кетчуп' => ['кетчуп','томатн','соус'],
        'майонез' => ['майонезн','соус'],
        'соус' => ['заправк','дип'],
        'дип' => ['соус','заправк'],
        'мороженое' => ['моро','пломбир','сандей','айс'],
        'кофе' => ['кофейн','капучин','латте','американо'],
        'капучино' => ['кофе','кофейн'],
        'масло' => ['масл','фритюр'],
        'фритюр' => ['масло','масл'],
        'коробка' => ['короб','упаков','тар'],
        'упаковка' => ['упаков','короб','коробк','тар'],
        'пакет' => ['пакетик','мешок','тар'],
        'салфетка' => ['салфет','бумаг'],
    ];
    $expanded = $words;
    foreach ($words as $w) {
        $wLower = mb_strtolower($w);
        if (isset($synonyms[$wLower])) {
            foreach ($synonyms[$wLower] as $syn) {
                $expanded[] = $syn;
            }
        }
    }
    return array_unique($expanded);
}

// Разбить текст на стемы
function stemWords($text) {
    $text = mb_strtolower($text);
    $text = str_replace('ё', 'е', $text);
    $words = preg_split('/[^а-яa-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stems = [];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 2) $stems[] = ruStem($w);
    }
    return $stems;
}

// Отправить сообщение с заменой предыдущего (антиспам)
function cardsSendReplace($chatId, $userMsgId, $botMsgId, $text, $keyboard = null) {
    global $BOT_TOKEN;
    if ($userMsgId) @deleteMessage($chatId, $userMsgId);
    if ($botMsgId) @deleteMessage($chatId, $botMsgId);
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $payload['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
    $resp = json_decode(curl_exec($ch), true); curl_close($ch);
    $newMsgId = $resp['result']['message_id'] ?? null;
    // Сохраняем новый msg_id для следующего поиска
    if ($newMsgId) @file_put_contents(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt", $newMsgId);
    return $newMsgId;
}

// Поиск карточки — прямой ответ без ИИ
function searchCardDirect($chatId, $query, $userMsgId = null, $botMsgId = null) {
    global $pdo;

    $normalize = function($s) {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/[^а-яa-z0-9]/u', '', $s);
    };

    $queryRaw = trim($query);
    if (mb_strlen($queryRaw) < 2) {
        cardsSendReplace($chatId, $userMsgId, $botMsgId, "Введите минимум 2 символа.", ['inline_keyboard' => [[['text' => '❌ Выход', 'callback_data' => 'cmd_cards_exit']]]]);
        return;
    }

    $s = $pdo->prepare("SELECT id, name, analogs FROM cards ORDER BY name");
    $s->execute();
    $allCards = $s->fetchAll();

    $q = $normalize($queryRaw);
    $results = [];

    // 1. Поиск по артикулу (точный и частичный)
    $articleMatch = null;
    if (preg_match('/\d{4,}(?:-\d+)?/', $queryRaw, $am)) {
        $articleMatch = $am[0];
    }

    if ($articleMatch) {
        foreach ($allCards as $c) {
            if ($c['id'] === $articleMatch) {
                $results[] = ['card' => $c, 'reason' => 'найдено по артикулу'];
                break;
            }
        }
        if (empty($results)) {
            foreach ($allCards as $c) {
                $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
                if (!is_array($analogs)) $analogs = [];
                if (in_array($articleMatch, $analogs)) {
                    $results[] = ['card' => $c, 'reason' => "артикул {$articleMatch} — аналог этого товара"];
                }
            }
        }
        if (empty($results)) {
            foreach ($allCards as $c) {
                if ($c['id'] && strpos($c['id'], $articleMatch) !== false) {
                    $results[] = ['card' => $c, 'reason' => 'часть артикула'];
                    if (count($results) >= 10) break;
                }
            }
        }
    }

    // 2. Текстовый поиск — сначала точное вхождение, потом по стемам (морфология)
    if (empty($results)) {
        // 2а. Точное вхождение подстроки (как раньше)
        foreach ($allCards as $c) {
            $normName = $normalize($c['name']);
            $normFull = $normalize($c['id'] ?? '') . $normName;
            if ($normFull && mb_strpos($normFull, $q) !== false) {
                $results[] = ['card' => $c, 'reason' => 'найдено по названию'];
                if (count($results) >= 10) break;
            }
        }
    }

    // 2б. Поиск по основам слов (стемминг) — каждое слово запроса ищем в названии
    if (empty($results)) {
        // Разбиваем запрос на слова, добавляем синонимы, затем стемим
        $rawWords = preg_split('/[^а-яёa-z0-9]+/iu', mb_strtolower($queryRaw), -1, PREG_SPLIT_NO_EMPTY);
        $expandedWords = expandSynonyms($rawWords);
        $queryStems = array_unique(array_map('ruStem', array_filter($expandedWords, fn($w) => mb_strlen($w) >= 2)));
        $queryWordCount = count($rawWords); // оригинальное число слов для scoring

        if (!empty($queryStems)) {
            $scored = [];
            foreach ($allCards as $c) {
                $nameStems = stemWords($c['name']);
                if (empty($nameStems)) continue;
                // Считаем сколько стемов из запроса (с синонимами) нашлись в названии
                $matched = 0;
                foreach ($queryStems as $qs) {
                    foreach ($nameStems as $ns) {
                        if (mb_strpos($ns, $qs) !== false || mb_strpos($qs, $ns) !== false) {
                            $matched++;
                            break;
                        }
                    }
                }
                if ($matched > 0) {
                    $scored[] = ['card' => $c, 'score' => $matched / count($queryStems), 'matched' => $matched];
                }
            }
            // Сортируем по доле совпавших слов (больше = лучше)
            usort($scored, function($a, $b) { return $b['score'] <=> $a['score'] ?: $b['matched'] <=> $a['matched']; });
            $added = [];
            foreach ($scored as $s) {
                if (isset($added[$s['card']['id']])) continue;
                $results[] = ['card' => $s['card'], 'reason' => 'найдено по названию'];
                $added[$s['card']['id']] = true;
                if (count($results) >= 10) break;
            }
        }
    }

    $exitBtn = [['text' => '❌ Выход', 'callback_data' => 'cmd_cards_exit']];

    if (empty($results)) {
        // Попробуем найти похожие по отдельным словам
        $suggestions = [];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($queryRaw));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) < 3) continue;
            $nw = $normalize($w);
            foreach ($allCards as $c) {
                if (mb_strpos($normalize($c['name']), $nw) !== false) {
                    $suggestions[$c['id']] = $c;
                    if (count($suggestions) >= 5) break 2;
                }
            }
        }

        $msg = "❌ По запросу «<b>{$queryRaw}</b>» карточек не найдено.\n\n";
        if (!empty($suggestions)) {
            $msg .= "Возможно, вы имели в виду:\n";
            foreach ($suggestions as $c) {
                $msg .= "• <b>{$c['id']}</b> {$c['name']}\n";
            }
            $msg .= "\nОтправьте артикул для точного поиска.";
        } else {
            $msg .= "Попробуйте:\n• Другой артикул или название\n• Часть названия (например, «стакан» вместо «стаканчик»)\n• Числовой артикул";
        }
        cardsSendReplace($chatId, $userMsgId, $botMsgId, $msg, ['inline_keyboard' => [$exitBtn]]);
        return;
    }

    // Собираем все артикулы (основные + аналоги) для проверки остатков
    $allSkus = [];
    foreach ($results as $r) {
        $c = $r['card'];
        $allSkus[] = $c['id'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (is_array($analogs)) {
            foreach ($analogs as $a) $allSkus[] = $a;
        }
    }
    $allSkus = array_unique(array_filter($allSkus));

    // Проверяем какие артикулы есть на остатках (analysis_data, только БК)
    $inStock = [];
    if ($allSkus) {
        $placeholders = implode(',', array_fill(0, count($allSkus), '?'));
        $params = array_values($allSkus);
        $params[] = 'ООО "Бургер БК"';
        $st = $pdo->prepare("SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box FROM analysis_data a LEFT JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = a.sku COLLATE utf8mb4_unicode_ci AND p.legal_entity COLLATE utf8mb4_unicode_ci = a.legal_entity COLLATE utf8mb4_unicode_ci WHERE a.sku IN ({$placeholders}) AND a.legal_entity = ? AND a.stock > 0");
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $qpb = floatval($row['qty_per_box']) ?: 1;
            $inStock[$row['sku']] = ['name' => $row['name'], 'stock' => round(floatval($row['stock']) / $qpb, 1)];
        }
    }

    $text = "🔍 По запросу «<b>{$queryRaw}</b>»:\n\n";
    foreach ($results as $i => $r) {
        $c = $r['card'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (!is_array($analogs)) $analogs = [];

        // Ищем какой артикул из группы на остатках (основной + аналоги)
        $stockSku = null;
        $groupSkus = array_merge([$c['id']], $analogs);
        foreach ($groupSkus as $sku) {
            if (isset($inStock[$sku])) {
                $stockSku = $sku;
                break;
            }
        }

        $text .= "<code>{$c['id']} {$c['name']}</code>\n";
        if ($stockSku) {
            $stockQty = rtrim(rtrim(number_format($inStock[$stockSku]['stock'], 1, '.', ''), '0'), '.');
            if ($stockSku === $c['id']) {
                $text .= "  📦 <i>на остатках ({$stockQty} кор.)</i>\n";
            } else {
                $stockName = $inStock[$stockSku]['name'];
                $label = $stockName ? "{$stockSku} {$stockName}" : $stockSku;
                $text .= "  📦 <i>на остатках ({$stockQty} кор.): </i><code>{$label}</code>\n";
            }
        }
    }

    $text .= "\n<i>Нажмите на строку, чтобы скопировать</i>";

    // Обрезка по лимиту Telegram
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…";
    }

    cardsSendReplace($chatId, $userMsgId, $botMsgId, $text, ['inline_keyboard' => [$exitBtn]]);
}

function cmdSchedule($chatId, $user, $editMsgId = null, $dayNum = null) {
    global $pdo, $SITE_URL;

    $dayNames = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    $dayShort = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
    $today = (int) date('N'); // 1=Пн, 7=Вс

    $entity = getUserEntity($user);
    // Определяем группу юрлица для фильтра
    $group = 'BK_VM';
    if ($entity && strpos($entity, 'Пицца') !== false) $group = 'PS';

    $sql = "SELECT r.number, r.address, r.city, ds.day_of_week, ds.delivery_time
            FROM delivery_schedule ds
            JOIN restaurants r ON r.id = ds.restaurant_id AND r.active = 1
            WHERE ds.delivery_time IS NOT NULL AND ds.delivery_time != ''
              AND r.legal_entity_group = ?
            ORDER BY ds.day_of_week, ds.delivery_time, r.number";
    $s = $pdo->prepare($sql); $s->execute([$group]);
    $all = $s->fetchAll();

    $backBtn = ['text' => '◂ Меню', 'callback_data' => 'cmd_menu'];

    if (!$all) {
        $btns = [
            [['text' => '🗓 График на сайте', 'url' => $SITE_URL . '/schedule']],
            [$backBtn],
        ];
        botSend($chatId, "🗓 <b>График доставок</b>\n<i>График пуст</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    // Группируем по дням
    $byDay = [];
    foreach ($all as $row) {
        $byDay[$row['day_of_week']][] = $row;
    }
    $totalDeliveries = count($all);

    if ($dayNum === null) {
        $es = $entity ? ' · ' . getEntityShort($entity) : '';
        $text = "🗓 <b>График доставок</b>{$es}\n";
        $text .= "<i>{$totalDeliveries} доставок в неделю</i>\n";
        $text .= "─────────────────────\n";

        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $isToday = ($d === $today);
            $mark = $isToday ? '📍 ' : '';
            $todayLabel = $isToday ? ' <i>(сегодня)</i>' : '';
            $text .= "{$mark}<b>{$dayNames[$d]}</b>{$todayLabel} — {$cnt}\n";
        }

        // Кнопки по дням
        $row1 = []; $row2 = [];
        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $mark = ($d === $today) ? '📍' : '';
            $btn = ['text' => "{$mark}{$dayShort[$d]} ({$cnt})", 'callback_data' => "sched_{$d}"];
            if ($d <= 3) $row1[] = $btn; else $row2[] = $btn;
        }
        $buttons = [$row1, $row2];
        $buttons[] = [['text' => '🗓 График на сайте', 'url' => $SITE_URL . '/schedule']];
        $buttons[] = [$backBtn];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    } else {
        // Детальный список на конкретный день
        $isToday = ($dayNum === $today);
        $todayLabel = $isToday ? ' <i>(сегодня)</i>' : '';
        $items = $byDay[$dayNum] ?? [];
        $cnt = count($items);
        $text = "🗓 <b>{$dayNames[$dayNum]}</b>{$todayLabel} · {$cnt} доставок\n";
        $text .= "─────────────────────\n";

        if ($items) {
            foreach ($items as $d) {
                $city = ($d['city'] && $d['city'] !== 'Минск') ? " ({$d['city']})" : '';
                $text .= "<b>#{$d['number']}</b> {$d['delivery_time']} — {$d['address']}{$city}\n";
            }
        } else {
            $text .= "<i>Нет доставок</i>\n";
        }

        // Кнопки навигации между днями
        $prevDay = $dayNum > 1 ? $dayNum - 1 : 6;
        $nextDay = $dayNum < 6 ? $dayNum + 1 : 1;
        $navRow = [
            ['text' => "← {$dayShort[$prevDay]}", 'callback_data' => "sched_{$prevDay}"],
            ['text' => '📋 Все дни', 'callback_data' => 'cmd_schedule'],
            ['text' => "{$dayShort[$nextDay]} →", 'callback_data' => "sched_{$nextDay}"],
        ];
        $buttons = [$navRow, [$backBtn]];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    }
}

// ═══ Полный анализ запасов (как на сайте) ═══

function cmdAnalysis($chatId, $user, $zone = null, $page = 0, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $entityShort = $entity ? getEntityShort($entity) : '';

    // Загружаем все товары с данными анализа, группируя по аналоговой группе
    $sql = "SELECT p.sku, p.name, p.analog_group, p.supplier, p.qty_per_box, p.category,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   a.stock, a.consumption, a.period_days
            FROM products p
            INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
            WHERE p.is_active = 1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY p.analog_group, p.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) {
        $es = $entity ? ' · ' . $entityShort : '';
        $btns = [
            [['text' => '📊 Анализ на сайте', 'url' => $SITE_URL . '/analysis']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📊 <b>Анализ запасов</b>{$es}\n<i>Данных нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    // Группируем по аналоговой группе
    $groups = [];
    foreach ($items as $item) {
        $groupName = $item['analog_group'] ?: $item['sku'] . ' ' . $item['name'];
        if (!isset($groups[$groupName])) {
            $u = $item['uom'] ?? 'шт';
            $groups[$groupName] = [
                'name' => $groupName,
                'items' => [],
                'totalStock' => 0,
                'totalConsumption' => 0,
                'periodDays' => 30,
                'supplier' => $item['supplier'],
                'category' => $item['category'] ?: '',
                'uomLabel' => getUomLabel($u),
            ];
        }
        $groups[$groupName]['items'][] = $item;
        $groups[$groupName]['totalStock'] += $item['stock'];
        $groups[$groupName]['totalConsumption'] += $item['consumption'];
        if ($item['period_days'] > 0) {
            $groups[$groupName]['periodDays'] = $item['period_days'];
        }
    }

    // Рассчитываем дни для каждой группы и определяем зону
    $zoneGroups = ['red' => [], 'orange' => [], 'green' => [], 'purple' => []];
    foreach ($groups as &$g) {
        $stock = $g['totalStock'];
        $consumption = $g['totalConsumption'];
        $periodDays = max($g['periodDays'], 1);

        if ($consumption <= 0) {
            $g['days'] = $stock > 0 ? 999 : 0;
        } else {
            $dailyRate = $consumption / $periodDays;
            $g['days'] = round($stock / $dailyRate);
        }

        if ($g['days'] <= 5) {
            $g['zone'] = 'red';
        } elseif ($g['days'] <= 10) {
            $g['zone'] = 'orange';
        } elseif ($g['days'] <= 30) {
            $g['zone'] = 'green';
        } else {
            $g['zone'] = 'purple';
        }

        $zoneGroups[$g['zone']][] = $g;
    }
    unset($g);

    // Сортируем каждую зону по дням
    foreach ($zoneGroups as &$zg) {
        usort($zg, fn($a, $b) => $a['days'] - $b['days']);
    }
    unset($zg);

    $zoneCounts = [
        'red' => count($zoneGroups['red']),
        'orange' => count($zoneGroups['orange']),
        'green' => count($zoneGroups['green']),
        'purple' => count($zoneGroups['purple']),
    ];
    $total = array_sum($zoneCounts);

    // Если зона не указана — показываем сводку
    if (!$zone) {
        $es = $entity ? ' · ' . $entityShort : '';
        $text = "📊 <b>Анализ запасов</b>{$es}\n";
        $text .= "<i>{$total} групп товаров</i>\n";
        $text .= "─────────────────────\n";

        $text .= "🔴 Критично (≤5 дн.) — <b>{$zoneCounts['red']}</b>\n";
        $text .= "🟠 Внимание (6–10 дн.) — <b>{$zoneCounts['orange']}</b>\n";
        $text .= "🟢 Норма (11–30 дн.) — <b>{$zoneCounts['green']}</b>\n";
        $text .= "🟣 Излишки (30+ дн.) — <b>{$zoneCounts['purple']}</b>\n";

        // Показываем критичные, если есть
        if ($zoneCounts['red'] > 0) {
            $text .= "─────────────────────\n";
            $text .= "<b>⚠️ Критичные:</b>\n";
            foreach (array_slice($zoneGroups['red'], 0, 10) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🔴 <b>{$g['name']}</b> · {$g['days']}д. · {$g['totalStock']} ост. · {$daily}/д\n";
            }
            if ($zoneCounts['red'] > 10) {
                $text .= "<i>… +" . ($zoneCounts['red'] - 10) . " ещё</i>\n";
            }
        }

        // Показываем оранжевые
        if ($zoneCounts['orange'] > 0 && $zoneCounts['red'] == 0) {
            $text .= "─────────────────────\n";
            $text .= "<b>🟠 Внимание:</b>\n";
            foreach (array_slice($zoneGroups['orange'], 0, 8) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🟠 <b>{$g['name']}</b> · {$g['days']}д. · {$g['totalStock']} ост. · {$daily}/д\n";
            }
            if ($zoneCounts['orange'] > 8) {
                $text .= "<i>… +" . ($zoneCounts['orange'] - 8) . " ещё</i>\n";
            }
        }

        // Кнопки для просмотра каждой зоны
        $buttons = [];
        if ($zoneCounts['red'] > 0) $buttons[] = ['text' => "🔴 Критичные ({$zoneCounts['red']})", 'callback_data' => 'analysis_red_0'];
        if ($zoneCounts['orange'] > 0) $buttons[] = ['text' => "🟠 Внимание ({$zoneCounts['orange']})", 'callback_data' => 'analysis_orange_0'];
        if ($zoneCounts['green'] > 0) $buttons[] = ['text' => "🟢 Норма ({$zoneCounts['green']})", 'callback_data' => 'analysis_green_0'];
        if ($zoneCounts['purple'] > 0) $buttons[] = ['text' => "🟣 Излишки ({$zoneCounts['purple']})", 'callback_data' => 'analysis_purple_0'];

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => '📊 Анализ на сайте', 'url' => $SITE_URL . '/analysis']];
        $rows[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
        $keyboard = ['inline_keyboard' => $rows];
        if ($editMsgId) {
            editMessage($chatId, $editMsgId, $text, $keyboard);
        } else {
            sendMessage($chatId, $text, $keyboard);
        }
        return;
    }

    // Показ конкретной зоны
    $zoneNames = ['red' => '🔴 Критично (0–5 дн.)', 'orange' => '🟠 Внимание (6–10 дн.)', 'green' => '🟢 Норма (11–30 дн.)', 'purple' => '🟣 Излишки (30+ дн.)'];
    $items = $zoneGroups[$zone] ?? [];
    $perPage = 15;
    $offset = $page * $perPage;
    $pageItems = array_slice($items, $offset, $perPage);
    $totalPages = ceil(count($items) / $perPage);

    if (!$pageItems) {
        sendMessage($chatId, "В этой зоне нет товаров.");
        return;
    }

    $es = $entity ? ' · ' . $entityShort : '';
    $text = "📊 <b>{$zoneNames[$zone]}</b>{$es}\n";
    $text .= "<i>" . count($items) . " групп";
    if ($totalPages > 1) $text .= " · стр. " . ($page + 1) . "/" . $totalPages;
    $text .= "</i>\n";
    $text .= "─────────────────────\n";

    foreach ($pageItems as $g) {
        $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
        $daysStr = $g['days'] >= 999 ? '∞' : $g['days'];
        $text .= "<b>{$g['name']}</b> · {$daysStr}д. · {$g['totalStock']} ост. · {$daily}/д\n";

        // Показываем состав группы, если > 1 товара
        if (count($g['items']) > 1) {
            foreach ($g['items'] as $item) {
                $iDaily = $item['period_days'] > 0 ? round($item['consumption'] / $item['period_days'], 1) : 0;
                $text .= "  · {$item['sku']}: {$item['stock']}, {$iDaily}/д\n";
            }
        }
    }

    // Навигация
    $navButtons = [];
    if ($page > 0) $navButtons[] = ['text' => '← Назад', 'callback_data' => "analysis_{$zone}_" . ($page - 1)];
    if ($page + 1 < $totalPages) $navButtons[] = ['text' => 'Далее →', 'callback_data' => "analysis_{$zone}_" . ($page + 1)];
    $navButtons[] = ['text' => '📊 Сводка', 'callback_data' => 'analysis_summary'];

    $keyboard = ['inline_keyboard' => [$navButtons, [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]];
    if ($editMsgId) {
        editMessage($chatId, $editMsgId, $text, $keyboard);
    } else {
        sendMessage($chatId, $text, $keyboard);
    }
}

// ═══ AI, lookup и helper функции вынесены в includes/ ═══

// ═══ Settings UI ═══

function getMenuButtons($user) {
    $entity = $user ? getUserEntity($user) : null;
    $buttons = [
        // Главное
        [['text' => '📅 Сегодня', 'callback_data' => 'cmd_today']],
        // Заказы и поставки
        [
            ['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'],
            ['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries'],
            ['text' => '📅 Планы', 'callback_data' => 'cmd_plans'],
        ],
        // Аналитика
        [
            ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock'],
            ['text' => '📊 Расход', 'callback_data' => 'cmd_consumption'],
            ['text' => '📊 Анализ', 'callback_data' => 'cmd_analysis'],
        ],
        // Цены и реализация
        [
            ['text' => '💰 Цены', 'callback_data' => 'cmd_prices'],
            ['text' => '📋 ПСЦ', 'callback_data' => 'cmd_psc'],
            ['text' => '🏪 Продажи', 'callback_data' => 'cmd_sales'],
        ],
        // Инструменты
        [
            ['text' => '🔍 Карточки', 'web_app' => ['url' => 'https://supply-department.online/search-cards']],
            ['text' => '🗓 График', 'callback_data' => 'cmd_schedule'],
            ['text' => '📤 Экспорт', 'callback_data' => 'cmd_export'],
        ],
        // Рестораны
        [
            ['text' => '✏️ Корректировки', 'callback_data' => 'cmd_corrections'],
            ['text' => '🥬 Овощи', 'callback_data' => 'cmd_veg_stats'],
        ],
        // Файл заказа + ресторанное меню
        [
            ['text' => '📄 Файл заказа', 'callback_data' => 'cmd_upload_order_file'],
            ['text' => '🏪 Меню ресторана', 'callback_data' => 'start_role_restaurant'],
        ],
    ];
    if ($user && count($user['legal_entities']) > 1) {
        $short = $entity ? getEntityShort($entity) : '?';
        $buttons[] = [['text' => "🏢 {$short}", 'callback_data' => 'cmd_entity'], ['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']];
    } else {
        $buttons[] = [['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']];
    }
    return $buttons;
}

function getMenuText($user) {
    $entity = $user ? getUserEntity($user) : null;
    $short = $entity ? getEntityShort($entity) : '';
    $today = date('d.m.Y');
    $dayNames = [1=>'понедельник',2=>'вторник',3=>'среда',4=>'четверг',5=>'пятница',6=>'суббота',7=>'воскресенье'];
    $dayName = $dayNames[(int)date('N')] ?? '';

    $lines = ["🍔 <b>Supply Department</b>"];
    $lines[] = "━━━━━━━━━━━━━━━━━━━━";
    if ($entity) $lines[] = "🏢 {$short} · {$today}, {$dayName}";
    else $lines[] = "📅 {$today}, {$dayName}";
    $lines[] = "";
    $lines[] = "Выберите раздел или задайте вопрос:";

    return implode("\n", $lines);
}

function showSettings($chatId, $msgId, $userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $settings = $s->fetch();
    if (!$settings) return;

    $labels = [
        'daily_summary' => '📊 Ежедневная сводка',
        'data_updates' => '📥 Загрузка данных',
        'expiring_items' => '⚠️ Истекающие сроки',
        'restaurant_sales' => '🍽 Реализация ресторанов',
        'psc_expiry' => '📋 ПСЦ истекает',
        'price_changed' => '💰 Цены изменились',
        'overdue_delivery' => '📦 Просроченная поставка',
        'low_stock' => '📉 Остатки заканчиваются',
        'correction_notifications' => '✏️ Корректировки заказов',
        'chat_notifications' => '💬 Сообщения из ресторанов',
    ];

    $text = "⚙️ <b>Настройки уведомлений</b>\n\nНажмите кнопку чтобы включить/выключить:";
    $buttons = [];
    foreach ($labels as $key => $label) {
        $on = $settings[$key] ? '✅' : '❌';
        $buttons[] = [['text' => "$on $label", 'callback_data' => "toggle_$key"]];
    }
    $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];

    $markup = ['inline_keyboard' => $buttons];

    if ($msgId) {
        editMessage($chatId, $msgId, $text, $markup);
    } else {
        sendMessage($chatId, $text, $markup);
    }
}

// ═══ Inline queries (поиск карточек в любом чате) ═══

if (isset($input['inline_query'])) {
    $iq = $input['inline_query'];
    $query = trim($iq['query'] ?? '');
    $inlineId = $iq['id'];

    $results = [];
    if (mb_strlen($query) >= 2) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
        $st = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE (id LIKE ? OR name LIKE ?) ORDER BY name LIMIT 10");
        $st->execute(["%{$escaped}%", "%{$escaped}%"]);
        $cards = $st->fetchAll();

        foreach ($cards as $i => $c) {
            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            $analogsStr = is_array($analogs) && $analogs ? "\nАналоги: " . implode(', ', $analogs) : '';
            $results[] = [
                'type' => 'article',
                'id' => (string)$i,
                'title' => "{$c['id']} {$c['name']}",
                'description' => $analogsStr ? 'Аналоги: ' . implode(', ', $analogs) : 'Нет аналогов',
                'input_message_content' => ['message_text' => "<b>{$c['id']}</b> {$c['name']}{$analogsStr}", 'parse_mode' => 'HTML'],
            ];
        }
    }

    $payload = json_encode(['inline_query_id' => $inlineId, 'results' => json_encode($results), 'cache_time' => 30]);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/answerInlineQuery");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['inline_query_id' => $inlineId, 'results' => json_encode($results), 'cache_time' => 30], CURLOPT_TIMEOUT => 5]);
    curl_exec($ch); curl_close($ch);
    exit;
}

// ═══ Callback queries (настройки) ═══

if (isset($input['callback_query'])) {
    $cb = $input['callback_query'];
    $chatId = $cb['message']['chat']['id'];
    $msgId = $cb['message']['message_id'];
    $data = $cb['data'] ?? '';

    // Кнопки меню — всё редактируем в том же сообщении
    if (str_starts_with($data, 'cmd_')) {
        $cmd = substr($data, 4);
        $user = getUser($chatId);

        // Карточки доступны всем (и ресторанам без привязки аккаунта)
        if ($cmd === 'cards' || $cmd === 'cards_exit') {
            answerCallback($cb['id']);
            if ($cmd === 'cards') {
                cmdCards($chatId, $user, $msgId);
            } else {
                // cards_exit — выход из режима поиска
                @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
                if ($user) {
                    setUserMode($user['name'], null);
                    editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
                } else {
                    vegShowMySubs($chatId, $msgId);
                }
            }
            exit;
        }

        if (!$user) {
            answerCallback($cb['id'], 'Нажмите /start для привязки аккаунта');
            exit;
        }
        answerCallback($cb['id']);
        switch ($cmd) {
            case 'menu':
                setUserMode($user['name'], null);
                @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
                @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt"); // сброс режима ввода заявки
                editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
                break;
            case 'orders': cmdOrders($chatId, $user, $msgId); break;
            case 'stock': cmdStock($chatId, $user, $msgId); break;
            case 'consumption': cmdConsumption($chatId, $user, $msgId); break;
            case 'prices': cmdPrices($chatId, $user, $msgId); break;
            case 'psc': cmdPsc($chatId, $user, $msgId); break;
            case 'analysis': cmdAnalysis($chatId, $user, null, 0, $msgId); break;
            case 'plans': cmdPlans($chatId, $user, $msgId); break;
            case 'deliveries': cmdDeliveries($chatId, $user, $msgId); break;
            case 'today': cmdToday($chatId, $user, $msgId); break;
            case 'export': cmdExport($chatId, $user, $msgId); break;
            case 'schedule': cmdSchedule($chatId, $user, $msgId); break;
            case 'entity':
                $entities = $user['legal_entities'];
                if (count($entities) <= 1) {
                    $current = getUserEntity($user);
                    $btns = [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]];
                    editMessage($chatId, $msgId, "🏢 Вам доступно одно юрлицо: <b>{$current}</b>", ['inline_keyboard' => $btns]);
                } else {
                    $current = getUserEntity($user);
                    $btns = [];
                    foreach ($entities as $idx => $le) {
                        $mark = ($le === $current) ? '✅ ' : '';
                        $short = getEntityShort($le);
                        $btns[] = [['text' => "{$mark}{$short} — {$le}", 'callback_data' => "entity_{$idx}"]];
                    }
                    $btns[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
                    editMessage($chatId, $msgId, "🏢 <b>Выбор юрлица</b>\n\nТекущее: <b>{$current}</b>\n\nНажмите для переключения:", ['inline_keyboard' => $btns]);
                }
                break;
            case 'sales': cmdSales($chatId, $user, $msgId); break;
            // cards обрабатывается выше в отдельном блоке (доступен без аккаунта)
            case 'veg_stats': cmdVegStats($chatId, $msgId); break;
            case 'corrections': cmdCorrections($chatId, $msgId); break;
            case 'upload_order_file':
                file_put_contents(sys_get_temp_dir() . "/import_{$chatId}.txt", 'order_file');
                editMessage($chatId, $msgId, "📄 <b>Файл заказа</b>\n─────────────────────\nОтправьте файл Excel для ресторанов.\nОн будет доступен всем ресторанам через бот.", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
                break;
            // import удалён из бота — используйте сайт
                break;
            case 'settings': showSettings($chatId, $msgId, $user['name']); break;
        }
        exit;
    }

    // График доставок — навигация по дням
    if (str_starts_with($data, 'sched_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id']);
        $dayNum = intval(substr($data, 6));
        if ($dayNum >= 1 && $dayNum <= 6) {
            cmdSchedule($chatId, $user, $msgId, $dayNum);
        }
        exit;
    }

    // Анализ запасов — навигация по зонам (редактируем то же сообщение)
    if (str_starts_with($data, 'analysis_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id']);
        $parts = explode('_', $data);
        // analysis_summary, analysis_red_0, analysis_orange_1 ...
        if ($parts[1] === 'summary') {
            cmdAnalysis($chatId, $user, null, 0, $msgId);
        } else {
            $zone = $parts[1];
            $page = intval($parts[2] ?? 0);
            cmdAnalysis($chatId, $user, $zone, $page, $msgId);
        }
        exit;
    }

    // Приёмка поставки
    if (str_starts_with($data, 'receive_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        $orderId = substr($data, 8);
        $entity = getUserEntity($user);
        $sql = "SELECT id, supplier FROM orders WHERE id = ? AND received_at IS NULL";
        $p = [$orderId];
        if ($entity) { $sql .= " AND legal_entity = ?"; $p[] = $entity; }
        $s = $pdo->prepare($sql); $s->execute($p);
        $order = $s->fetch();
        if (!$order) { answerCallback($cb['id'], 'Поставка не найдена или уже принята'); exit; }
        $pdo->prepare("UPDATE orders SET received_at = NOW() WHERE id = ?")->execute([$orderId]);
        answerCallback($cb['id'], "✅ {$order['supplier']} принято!");
        cmdDeliveries($chatId, $user, $msgId);
        exit;
    }

    // Экспорт CSV
    if (str_starts_with($data, 'export_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id'], 'Генерирую файл...');
        $type = substr($data, 7);
        $entity = getUserEntity($user);
        $short = $entity ? getEntityShort($entity) : 'all';
        $date = date('Y-m-d');
        switch ($type) {
            case 'analysis':
                $csv = generateAnalysisCsv($entity);
                sendDocument($chatId, "analysis_{$short}_{$date}.csv", $csv, "📊 Анализ запасов · {$short} · {$date}");
                break;
            case 'orders':
                $csv = generateOrdersCsv($entity);
                sendDocument($chatId, "orders_{$short}_{$date}.csv", $csv, "📦 Заказы · {$short} · {$date}");
                break;
            case 'prices':
                $csv = generatePricesCsv($entity);
                sendDocument($chatId, "prices_{$short}_{$date}.csv", $csv, "💰 Цены · {$short} · {$date}");
                break;
        }
        exit;
    }

    // Выбор юрлица
    if (str_starts_with($data, 'entity_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        $idx = intval(substr($data, 7));
        $entities = $user['legal_entities'];
        if (isset($entities[$idx])) {
            setUserEntity($user['name'], $entities[$idx]);
            $short = getEntityShort($entities[$idx]);
            answerCallback($cb['id'], "Выбрано: {$short}");
            $menuBtns = ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]];
            editMessage($chatId, $msgId, "✅ Юрлицо переключено на <b>{$entities[$idx]}</b>\n\nТеперь все данные показываются для этого юрлица.", $menuBtns);
        } else {
            answerCallback($cb['id'], 'Ошибка выбора');
        }
        exit;
    }

    if (str_starts_with($data, 'toggle_')) {
        answerCallback($cb['id']);
        $field = substr($data, 7);
        $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary', 'data_updates', 'expiring_items', 'restaurant_sales', 'correction_notifications', 'chat_notifications'];
        if (in_array($field, $allowed)) {
            $u = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
            $u->execute([$chatId]);
            $user = $u->fetchColumn();
            if ($user) {
                $pdo->prepare("UPDATE telegram_settings SET `$field` = NOT `$field` WHERE user_name = ?")->execute([$user]);
                showSettings($chatId, $msgId, $user);
            }
        }
        exit;
    }

    // ═══ /start: выбор роли ═══
    if ($data === 'start_role_purchasing') {
        answerCallback($cb['id']);
        // Генерируем токен и даём кнопку для авторизации через сайт
        $token = bin2hex(random_bytes(16));
        $tgUsername = $cb['from']['username'] ?? null;
        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE telegram_chat_id = ?")->execute([$chatId]);
        $pdo->prepare("INSERT INTO telegram_link_tokens (token, telegram_chat_id, telegram_username, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))")
            ->execute([$token, $chatId, $tgUsername]);
        $linkUrl = "{$SITE_URL}/telegram-link?token={$token}";
        $keyboard = ['inline_keyboard' => [
            [['text' => '🔐 Войти через сайт', 'url' => $linkUrl]],
            [['text' => '◂ Назад', 'callback_data' => 'start_back']],
        ]];
        editMessage($chatId, $msgId, "🏢 <b>Отдел закупок</b>\n\nДля доступа нужно привязать Telegram к вашему аккаунту на сайте.\n\nНажмите кнопку ниже — откроется сайт, где нужно войти под своим логином.\n\n<i>Ссылка действительна 15 минут.</i>", $keyboard);
        exit;
    }

    if ($data === 'start_role_restaurant') {
        answerCallback($cb['id']);
        vegShowMySubs($chatId, $msgId);
        exit;
    }

    if ($data === 'start_back') {
        answerCallback($cb['id']);
        $keyboard = ['inline_keyboard' => [
            [['text' => '🏢 Отдел закупок', 'callback_data' => 'start_role_purchasing']],
            [['text' => '🏪 Ресторан', 'callback_data' => 'start_role_restaurant']],
        ]];
        editMessage($chatId, $msgId, "👋 <b>Supply Department</b>\n\nДобро пожаловать! Выберите, кто вы:", $keyboard);
        exit;
    }

    // ═══ Чат ресторана с закупками ═══
    if ($data === 'chat_start') {
        answerCallback($cb['id']);
        chatStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'chat_rest_')) {
        answerCallback($cb['id']);
        chatInputMode($chatId, $msgId, substr($data, 10));
        exit;
    }
    if (str_starts_with($data, 'chat_history_')) {
        answerCallback($cb['id']);
        chatShowHistory($chatId, $msgId, substr($data, 13));
        exit;
    }
    if ($data === 'chat_cancel') {
        answerCallback($cb['id']);
        @unlink(sys_get_temp_dir() . "/chat_{$chatId}.txt");
        vegShowMySubs($chatId, $msgId);
        exit;
    }

    // ═══ Файл заказа ═══
    if ($data === 'rest_order_file') {
        answerCallback($cb['id']);
        $file = $pdo->query("SELECT telegram_file_id, file_name, file_path, uploaded_at, uploaded_by FROM order_file ORDER BY id DESC LIMIT 1")->fetch();
        if ($file && $file['telegram_file_id']) {
            $updDate = date('d.m.Y H:i', strtotime($file['uploaded_at']));
            $caption = "📄 Файл заказа\nОбновлён: {$updDate}";
            if ($file['uploaded_by']) $caption .= "\nЗагрузил: {$file['uploaded_by']}";
            $payload = json_encode(['chat_id' => $chatId, 'document' => $file['telegram_file_id'], 'caption' => $caption]);
            $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendDocument");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 15]);
            curl_exec($ch); curl_close($ch);
        } else {
            sendMessage($chatId, "📄 Файл заказа пока не загружен.");
        }
        exit;
    }

    // ═══ Подменю ресторана ═══
    if ($data === 'rest_menu_main') {
        answerCallback($cb['id']);
        restMenuMain($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_menu_veg') {
        answerCallback($cb['id']);
        restMenuVeg($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_schedule') {
        answerCallback($cb['id']);
        restShowSchedule($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_stock') {
        answerCallback($cb['id']);
        restStockStart($chatId, $msgId);
        exit;
    }
    // Сбор остатков
    if ($data === 'rest_sc_start') {
        answerCallback($cb['id']);
        restScStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'sc_col_')) {
        answerCallback($cb['id']);
        restScSelectRest($chatId, $msgId, intval(substr($data, 7)));
        exit;
    }
    if (str_starts_with($data, 'sc_rest_')) {
        answerCallback($cb['id']);
        // sc_rest_{collId}_{restNum}
        $parts = explode('_', substr($data, 8), 2);
        if (count($parts) === 2) restScShowProducts($chatId, $msgId, intval($parts[0]), $parts[1]);
        exit;
    }

    // ═══ Корректировки заказов ═══
    if ($data === 'corr_start') {
        answerCallback($cb['id']);
        corrStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'corr_rest_')) {
        answerCallback($cb['id']);
        corrShowDelivery($chatId, $msgId, substr($data, 10));
        exit;
    }
    if (str_starts_with($data, 'corr_date_')) {
        answerCallback($cb['id']);
        $parts = explode('_', substr($data, 10), 2);
        if (count($parts) === 2) corrStartInput($chatId, $msgId, $parts[0], $parts[1]);
        exit;
    }
    if ($data === 'corr_submit') {
        answerCallback($cb['id']);
        corrSubmitBatch($chatId);
        exit;
    }
    if (str_starts_with($data, 'corr_history_')) {
        answerCallback($cb['id']);
        corrShowHistory($chatId, $msgId, intval(substr($data, 13)));
        exit;
    }
    // Взять в работу
    if (str_starts_with($data, 'corr_take_')) {
        answerCallback($cb['id']);
        $oneId = intval(substr($data, 10));
        $user = getUser($chatId);
        if (!$user) { editMessage($chatId, $msgId, "Нужен привязанный аккаунт."); exit; }
        // Проверяем, не взял ли уже кто-то другой
        $chk = $pdo->prepare("SELECT reviewer_name, reviewer_chat_id, status FROM order_corrections WHERE id = ?");
        $chk->execute([$oneId]);
        $chkRow = $chk->fetch();
        if ($chkRow && $chkRow['status'] === 'in_progress' && $chkRow['reviewer_chat_id'] && (string)$chkRow['reviewer_chat_id'] !== (string)$chatId) {
            answerCallback($cb['id'], "⚠️ Уже в работе у {$chkRow['reviewer_name']}", true);
            exit;
        }
        $ids = corrGetBatchPendingIds($pdo, $oneId);
        if (empty($ids)) {
            // Может уже все in_progress — проверим
            $nmRow2 = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
            $nmRow2->execute([$oneId]);
            $nmData2 = json_decode($nmRow2->fetchColumn() ?: '{}', true);
            corrUpdateAllReviewMessages($pdo, $nmData2['batch_ids'] ?? [$oneId]);
            exit;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE order_corrections SET status = 'in_progress', reviewer_name = ?, reviewer_chat_id = ? WHERE id IN ({$ph}) AND status = 'pending'");
        $upd->execute(array_merge([$user['name'], $chatId], $ids));
        if ($upd->rowCount() === 0) {
            // Кто-то успел раньше
            $who = $pdo->prepare("SELECT reviewer_name FROM order_corrections WHERE id = ?");
            $who->execute([$oneId]);
            $whoName = $who->fetchColumn() ?: 'другой сотрудник';
            answerCallback($cb['id'], "⚠️ Уже в работе у {$whoName}", true);
            exit;
        }
        $nmRow = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
        $nmRow->execute([$oneId]);
        $nmData = json_decode($nmRow->fetchColumn() ?: '{}', true);
        corrUpdateAllReviewMessages($pdo, $nmData['batch_ids'] ?? $ids);
        exit;
    }
    // Проверка: только взявший может рецензировать
    function corrCheckReviewer($pdo, $corrId, $chatId, $cbId) {
        $chk = $pdo->prepare("SELECT reviewer_chat_id, reviewer_name FROM order_corrections WHERE id = ?");
        $chk->execute([$corrId]);
        $r = $chk->fetch();
        if ($r && $r['reviewer_chat_id'] && (string)$r['reviewer_chat_id'] !== (string)$chatId) {
            answerCallback($cbId, "⚠️ В работе у {$r['reviewer_name']}", true);
            return false;
        }
        return true;
    }

    // Закупщик: принять одну позицию (corr_a_{id})
    if (str_starts_with($data, 'corr_a_')) {
        $corrId = intval(substr($data, 7));
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        corrReview($pdo, $chatId, $msgId, [$corrId], 'approve');
        exit;
    }
    // Закупщик: отклонить одну позицию (corr_r_{id})
    if (str_starts_with($data, 'corr_r_')) {
        $corrId = intval(substr($data, 7));
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        corrReview($pdo, $chatId, $msgId, [$corrId], 'reject');
        exit;
    }
    // Принять все pending в батче (по одному ID)
    if (str_starts_with($data, 'corr_aa_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, $corrId);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'approve');
        exit;
    }
    // Отклонить все pending в батче
    if (str_starts_with($data, 'corr_ra_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, $corrId);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'reject');
        exit;
    }
    // Комментарий ко всем pending в батче
    if (str_starts_with($data, 'corr_cm_')) {
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, intval(substr($data, 8)));
        if (empty($ids)) { editMessage($chatId, $msgId, "⚠️ Все позиции уже обработаны."); exit; }
        $state = ['step' => 'review_comment', 'corr_ids' => $ids, 'msg_id' => $msgId];
        @file_put_contents(sys_get_temp_dir() . "/corr_{$chatId}.txt", "corr_review");
        @file_put_contents(sys_get_temp_dir() . "/corr_data_{$chatId}.json", json_encode($state));
        editMessage($chatId, $msgId, "💬 Введите комментарий.\nПосле ввода выберите действие:", ['inline_keyboard' => [
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]]);
        exit;
    }
    // Отправить результат ресторану
    if (str_starts_with($data, 'corr_send_')) {
        answerCallback($cb['id']);
        $corrId = intval(substr($data, 10));
        $batchIds = corrGetBatchAllIds($pdo, $corrId);
        $user = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
        $user->execute([$chatId]);
        $u = $user->fetch();
        if ($batchIds && $u) corrSendResultToRestaurant($pdo, $batchIds, $u['name']);
        exit;
    }
    // Итоговый комментарий после обработки всех позиций
    if (str_starts_with($data, 'corr_fc_')) {
        answerCallback($cb['id']);
        $corrId = intval(substr($data, 8));
        $batchIds = corrGetBatchAllIds($pdo, $corrId);
        if (empty($batchIds)) { editMessage($chatId, $msgId, "⚠️ Заявка не найдена."); exit; }
        $state = ['step' => 'final_comment', 'batch_ids' => $batchIds, 'msg_id' => $msgId];
        @file_put_contents(sys_get_temp_dir() . "/corr_{$chatId}.txt", "corr_review");
        @file_put_contents(sys_get_temp_dir() . "/corr_data_{$chatId}.json", json_encode($state));
        editMessage($chatId, $msgId, "💬 Введите итоговый комментарий для ресторана:", ['inline_keyboard' => [
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]]);
        exit;
    }
    // Отправить с итоговым комментарием
    if ($data === 'corr_fc_send') {
        answerCallback($cb['id']);
        $dataFile = sys_get_temp_dir() . "/corr_data_{$chatId}.json";
        $state = json_decode(@file_get_contents($dataFile), true);
        $comment = $state['final_comment'] ?? '';
        $batchIds = $state['batch_ids'] ?? [];
        @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt"); @unlink($dataFile);
        $user = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
        $user->execute([$chatId]);
        $u = $user->fetch();
        if ($batchIds && $u) corrSendResultToRestaurant($pdo, $batchIds, $u['name'], $comment);
        exit;
    }
    // Принять с комментарием (IDs и комментарий в state)
    if ($data === 'corr_cappr_go') {
        answerCallback($cb['id']);
        $dataFile = sys_get_temp_dir() . "/corr_data_{$chatId}.json";
        $state = json_decode(@file_get_contents($dataFile), true);
        $comment = $state['review_comment'] ?? null;
        $ids = $state['corr_ids'] ?? [];
        @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt"); @unlink($dataFile);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'approve', $comment);
        exit;
    }
    // Отклонить с комментарием
    if ($data === 'corr_crej_go') {
        answerCallback($cb['id']);
        $dataFile = sys_get_temp_dir() . "/corr_data_{$chatId}.json";
        $state = json_decode(@file_get_contents($dataFile), true);
        $comment = $state['review_comment'] ?? null;
        $ids = $state['corr_ids'] ?? [];
        @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt"); @unlink($dataFile);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'reject', $comment);
        exit;
    }
    if ($data === 'corr_rev_cancel') {
        answerCallback($cb['id']);
        @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt");
        @unlink(sys_get_temp_dir() . "/corr_data_{$chatId}.json");
        editMessage($chatId, $msgId, "Отменено.");
        exit;
    }

    // ═══ Овощи: просмотр заявок ресторана ═══
    if ($data === 'veg_my_orders') {
        answerCallback($cb['id']);
        vegShowMyOrders($chatId, $msgId);
        exit;
    }

    if (str_starts_with($data, 'veg_orders_rest_')) {
        answerCallback($cb['id']);
        $restNum = substr($data, 16);
        vegShowRestOrders($chatId, $msgId, $restNum);
        exit;
    }

    // ═══ Овощи: выбор ресторана для подписки ═══
    if (str_starts_with($data, 'veg_sub_')) {
        $restNum = substr($data, 8);
        $exists = $pdo->prepare("SELECT id FROM veg_telegram_subs WHERE chat_id=? AND restaurant_number=?");
        $exists->execute([$chatId, $restNum]);
        if ($exists->fetch()) {
            $pdo->prepare("DELETE FROM veg_telegram_subs WHERE chat_id=? AND restaurant_number=?")->execute([$chatId, $restNum]);
            answerCallback($cb['id'], 'Отписано');
        } else {
            $from = $cb['from'] ?? [];
            $firstName = mb_substr($from['first_name'] ?? '', 0, 255) ?: null;
            $tgUsername = isset($from['username']) ? mb_substr($from['username'], 0, 255) : null;
            $pdo->prepare("INSERT INTO veg_telegram_subs (chat_id, restaurant_number, first_name, username) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), username = VALUES(username)")
                ->execute([$chatId, $restNum, $firstName, $tgUsername]);
            answerCallback($cb['id'], 'Подписано');
        }
        // Обновить список
        vegShowRestaurants($chatId, $msgId);
        exit;
    }

    if ($data === 'veg_my_subs') {
        answerCallback($cb['id']);
        vegShowMySubs($chatId, $msgId);
        exit;
    }

    if ($data === 'veg_my_subs_manage') {
        answerCallback($cb['id']);
        vegShowSubsManage($chatId, $msgId);
        exit;
    }

    if (str_starts_with($data, 'veg_unsub_')) {
        $restNum = substr($data, 10);
        $pdo->prepare("DELETE FROM veg_telegram_subs WHERE chat_id=? AND restaurant_number=?")->execute([$chatId, $restNum]);
        answerCallback($cb['id'], "Отписано от ресторана $restNum");
        vegShowSubsManage($chatId, $msgId);
        exit;
    }

    if ($data === 'veg_pick_rest') {
        answerCallback($cb['id']);
        vegShowRestaurants($chatId, $msgId);
        exit;
    }

    // Подача заявки через бота
    if ($data === 'vegord_start') {
        answerCallback($cb['id']);
        vegStartOrder($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'vegord_rest_')) {
        answerCallback($cb['id']);
        @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt"); // сброс режима ввода
        vegOrderSelectDay($chatId, $msgId, substr($data, 12));
        exit;
    }
    if (str_starts_with($data, 'vegord_day_')) {
        answerCallback($cb['id']);
        $rest = substr($data, 11); // "27_2026-03-19"
        $sep = strpos($rest, '_');
        if ($sep !== false) {
            $restNum = substr($rest, 0, $sep);
            $date = substr($rest, $sep + 1);
            vegOrderShowProducts($chatId, $msgId, $restNum, $date);
        }
        exit;
    }
    if (str_starts_with($data, 'vegord_skip_')) {
        answerCallback($cb['id']);
        $rest = substr($data, 12); // "27_2026-03-19"
        $sep = strpos($rest, '_');
        if ($sep !== false) {
            $restNum = substr($rest, 0, $sep);
            $date = substr($rest, $sep + 1);
            vegOrderSkipDay($chatId, $msgId, $restNum, $date);
        }
        exit;
    }

    if (str_starts_with($data, 'veg_page_')) {
        answerCallback($cb['id']);
        $page = intval(substr($data, 9));
        vegShowRestaurants($chatId, $msgId, $page);
        exit;
    }

    exit;
}

// ═══ Обработка сообщений ═══

$msg = $input['message'] ?? $input['edited_message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];

// Режим чата с закупками — обрабатывает и текст, и фото
$chatModeFile = sys_get_temp_dir() . "/chat_{$chatId}.txt";
if (file_exists($chatModeFile)) {
    if (time() - filemtime($chatModeFile) > 3600) {
        @unlink($chatModeFile);
    } else {
        $chatModeContent = trim(@file_get_contents($chatModeFile));
        $chatRestNum = explode('|', $chatModeContent)[0];
        $msgText = trim($msg['text'] ?? $msg['caption'] ?? '');
        $photoFileId = null;
        if (isset($msg['photo'])) {
            $photos = $msg['photo'];
            $photoFileId = end($photos)['file_id'] ?? null;
        }
        if ($msgText || $photoFileId) {
            if (str_starts_with($msgText, '/')) {
                @unlink($chatModeFile);
                // Пусть обработается как команда ниже
            } else {
                chatProcessMessage($chatId, $msgText, $chatRestNum, $msg['message_id'] ?? null, $msg['from'] ?? [], $photoFileId);
                exit;
            }
        } elseif (isset($msg['document']) || isset($msg['voice']) || isset($msg['video']) || isset($msg['sticker'])) {
            // В чате принимаем только текст и фото
            $userMsgId = $msg['message_id'] ?? null;
            if ($userMsgId) @deleteMessage($chatId, $userMsgId);
            exit;
        }
    }
}

// Режим загрузки файла заказа
$importModeFile = sys_get_temp_dir() . "/import_{$chatId}.txt";
if (file_exists($importModeFile) && isset($msg['document'])) {
    $importType = trim(@file_get_contents($importModeFile));
    @unlink($importModeFile);
    $user = getUser($chatId);
    if ($user && $importType === 'order_file') {
        $fileId = $msg['document']['file_id'] ?? null;
        $fileName = $msg['document']['file_name'] ?? 'file';
        if ($fileId) {
            $pdo->prepare("INSERT INTO order_file (file_name, file_path, telegram_file_id, uploaded_by) VALUES (?, '', ?, ?)")
                ->execute([$fileName, $fileId, $user['name']]);
            sendMessage($chatId, "✅ <b>Файл заказа обновлён</b>\n📄 {$fileName}\n\nРестораны теперь могут скачать его через бот.", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
            exit;
        }
    }
    @unlink($importModeFile);
}

// Фото, документы, голос — вежливый ответ
if (!isset($msg['text']) && (isset($msg['photo']) || isset($msg['document']) || isset($msg['voice']) || isset($msg['video']) || isset($msg['sticker']))) {
    $user = getUser($chatId);
    if ($user) {
        sendMessage($chatId, "Я пока умею работать только с текстовыми сообщениями.\n\nПопробуйте задать вопрос текстом, например:\n• <i>Какой остаток молока?</i>\n• <i>Когда приедет Мираторг?</i>");
    }
    exit;
}

$text = trim($msg['text'] ?? '');

// /veg — подписка на уведомления о заявках (доступна всем, без привязки аккаунта)
if ($text === '/veg') {
    @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
    vegShowMySubs($chatId);
    exit;
}

// /start
if ($text === '/start') {
    @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
    $user = getUser($chatId);
    if ($user) {
        $greeting = "Привет, <b>{$user['name']}</b>! 👋\n\n";
        sendMessage($chatId, $greeting . getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
    } else {
        // Показываем выбор роли: отдел закупок или ресторан
        $keyboard = ['inline_keyboard' => [
            [['text' => '🏢 Отдел закупок', 'callback_data' => 'start_role_purchasing']],
            [['text' => '🏪 Ресторан', 'callback_data' => 'start_role_restaurant']],
        ]];
        sendMessage($chatId, "👋 <b>Supply Department</b>\n\nДобро пожаловать! Выберите, кто вы:", $keyboard);
    }
    exit;
}

// /help или /menu
if ($text === '/help' || $text === '/menu') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    setUserMode($user['name'], null); // сброс режима
    @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt"); // сброс режима ввода заявки
    $tips = "\n\n💡 <i>Примеры вопросов:</i>\n• Какой остаток молока?\n• Товары с запасом на 3 дня\n• Что скоро просрочится?\n• Когда доставка в ресторан 45?";
    sendMessage($chatId, getMenuText($user) . $tips, ['inline_keyboard' => getMenuButtons($user)]);
    exit;
}

// /today — дашборд на сегодня
if ($text === '/today') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdToday($chatId, $user);
    exit;
}

// /export — экспорт CSV
if ($text === '/export') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdExport($chatId, $user);
    exit;
}

// /analysis — полный анализ запасов
if ($text === '/analysis') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdAnalysis($chatId, $user);
    exit;
}

// /entity — переключение юрлица
if ($text === '/entity') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    $entities = $user['legal_entities'];
    if (count($entities) <= 1) {
        $current = getUserEntity($user);
        sendMessage($chatId, "🏢 Вам доступно одно юрлицо: <b>{$current}</b>");
        exit;
    }
    $current = getUserEntity($user);
    $buttons = [];
    foreach ($entities as $idx => $le) {
        $mark = ($le === $current) ? '✅ ' : '';
        $short = getEntityShort($le);
        $buttons[] = [['text' => "{$mark}{$short} — {$le}", 'callback_data' => "entity_{$idx}"]];
    }
    sendMessage($chatId, "🏢 <b>Выбор юрлица</b>\n\nТекущее: <b>{$current}</b>\n\nНажмите для переключения:", ['inline_keyboard' => $buttons]);
    exit;
}

// /settings
if ($text === '/settings') {
    $user = getUser($chatId);
    if (!$user) {
        sendMessage($chatId, "❌ Аккаунт не привязан. Отправьте свой email для привязки.");
        exit;
    }
    showSettings($chatId, null, $user['name']);
    exit;
}

// /cards — доступно всем (и ресторанам)
if ($text === '/cards') {
    $user = getUser($chatId);
    cmdCards($chatId, $user);
    exit;
}

// /orders
if ($text === '/orders') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdOrders($chatId, $user);
    exit;
}

// /stock
if ($text === '/stock') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdStock($chatId, $user);
    exit;
}

// /sales
if ($text === '/sales') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdSales($chatId, $user);
    exit;
}

// /consumption
if ($text === '/consumption') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdConsumption($chatId, $user);
    exit;
}

// /prices
if ($text === '/prices') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdPrices($chatId, $user);
    exit;
}

// /psc
if ($text === '/psc') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdPsc($chatId, $user);
    exit;
}

// Режим остатков склада (через файл — работает и без аккаунта)
// Режим сбора остатков
$scModeFile = sys_get_temp_dir() . "/sc_{$chatId}.txt";
if (file_exists($scModeFile)) {
    if (time() - filemtime($scModeFile) > 1800) {
        @unlink($scModeFile);
        @unlink(sys_get_temp_dir() . "/sc_data_{$chatId}.json");
    } elseif (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        restScProcessInput($chatId, $text, $userMsgId);
        exit;
    } else {
        @unlink($scModeFile);
        @unlink(sys_get_temp_dir() . "/sc_data_{$chatId}.json");
    }
}

$stockModeFile = sys_get_temp_dir() . "/rest_stock_{$chatId}.txt";
if (file_exists($stockModeFile)) {
    if (time() - filemtime($stockModeFile) > 1800) {
        @unlink($stockModeFile);
    } elseif (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        restStockSearch($chatId, $text, $userMsgId);
        exit;
    } else {
        @unlink($stockModeFile);
    }
}

// Режим корректировки заказа (через файл — работает и без аккаунта)
$corrFile = sys_get_temp_dir() . "/corr_{$chatId}.txt";
if (file_exists($corrFile)) {
    if (time() - filemtime($corrFile) > 1800) {
        @unlink($corrFile);
        @unlink(sys_get_temp_dir() . "/corr_data_{$chatId}.json");
    } elseif (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        corrProcessTextInput($chatId, $text, trim(@file_get_contents($corrFile)), $userMsgId);
        exit;
    } else {
        @unlink($corrFile);
        @unlink(sys_get_temp_dir() . "/corr_data_{$chatId}.json");
    }
}

// Режим ввода заявки на овощи (через файл — работает и без аккаунта)
$vegOrderFile = sys_get_temp_dir() . "/vegord_{$chatId}.txt";
if (file_exists($vegOrderFile)) {
    // Автоочистка: если файл старше 30 минут — удаляем (защита от зависания)
    if (time() - filemtime($vegOrderFile) > 1800) {
        @unlink($vegOrderFile);
    } else {
        $vegMode = trim(@file_get_contents($vegOrderFile));
        if ($vegMode && str_starts_with($vegMode, 'vegord_')) {
            if (str_starts_with($text, '/')) {
                @unlink($vegOrderFile);
            } else {
                $userMsgId = $msg['message_id'] ?? null;
                if ($userMsgId) @deleteMessage($chatId, $userMsgId);
                vegOrderProcessInput($chatId, $text, $vegMode);
                exit;
            }
        }
    }
}

// Режим поиска карточек (temp-файл) — работает и без привязки аккаунта
$cardsModeFile = sys_get_temp_dir() . "/cards_mode_{$chatId}.txt";
if (file_exists($cardsModeFile)) {
    // Автоочистка: если файл старше 30 минут — удаляем
    if (time() - filemtime($cardsModeFile) > 1800) {
        @unlink($cardsModeFile);
    } elseif (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        $botMsgId = intval(trim(@file_get_contents($cardsModeFile))) ?: null;
        searchCardDirect($chatId, $text, $userMsgId, $botMsgId);
        exit;
    } else {
        @unlink($cardsModeFile);
    }
}

// Свободный текст — ответ на вопрос
$user = getUser($chatId);
if (!$user) {
    sendMessage($chatId, "🔒 Для доступа к боту нужно привязать Telegram к аккаунту.\n\nНажмите /start чтобы получить ссылку для входа.");
    exit;
}

// Быстрые ответы на приветствия и благодарности — без вызова ИИ
$quickReply = getQuickReply($text, $user);
if ($quickReply) {
    sendMessage($chatId, $quickReply, ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
    exit;
}

// Режим ввода — карточки
$userMode = getUserMode($user['name']);

if ($userMode === 'cards') {
    // Любая /-команда выходит из режима (обработается выше — этот код не должен сюда попасть)
    // На случай необработанных команд:
    if (str_starts_with($text, '/')) {
        setUserMode($user['name'], null);
        // Пусть проваливается в handleFreeText
    } else {
        $userMsgId = $msg['message_id'] ?? null;
        $cardsModeFile2 = sys_get_temp_dir() . "/cards_mode_{$chatId}.txt";
        $botMsgId = file_exists($cardsModeFile2) ? (intval(trim(@file_get_contents($cardsModeFile2))) ?: null) : null;
        searchCardDirect($chatId, $text, $userMsgId, $botMsgId);
        exit;
    }
}

handleFreeText($chatId, $text, $user);
