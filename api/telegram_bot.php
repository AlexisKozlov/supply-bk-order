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

function answerCallback($callbackId, $text = '') {
    global $BOT_TOKEN;
    file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery?" . http_build_query(['callback_query_id' => $callbackId, 'text' => $text]));
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
    $selected = $row['selected_entity'] ?? null;
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
    return $row['mode'] ?? null;
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

function cmdCards($chatId, $user, $editMsgId = null) {
    global $pdo;

    // Включаем режим поиска карточек
    setUserMode($user['name'], 'cards');

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
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// Поиск карточки — прямой ответ без ИИ
function searchCardDirect($chatId, $query) {
    global $pdo;

    $normalize = function($s) {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/[^а-яa-z0-9]/u', '', $s);
    };

    $queryRaw = trim($query);
    if (mb_strlen($queryRaw) < 2) {
        sendMessage($chatId, "Введите минимум 2 символа.", ['inline_keyboard' => [[['text' => '❌ Выход', 'callback_data' => 'cmd_cards_exit']]]]);
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
        // Если не найден как основной — ищем в аналогах
        if (empty($results)) {
            foreach ($allCards as $c) {
                $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
                if (!is_array($analogs)) $analogs = [];
                if (in_array($articleMatch, $analogs)) {
                    $results[] = ['card' => $c, 'reason' => "артикул {$articleMatch} — аналог этого товара"];
                }
            }
        }
        // Частичное совпадение артикула
        if (empty($results)) {
            foreach ($allCards as $c) {
                if ($c['id'] && strpos($c['id'], $articleMatch) !== false) {
                    $results[] = ['card' => $c, 'reason' => 'часть артикула'];
                    if (count($results) >= 10) break;
                }
            }
        }
    }

    // 2. Текстовый поиск (если артикул не нашёлся или запрос текстовый)
    if (empty($results)) {
        foreach ($allCards as $c) {
            $normId = $normalize($c['id'] ?? '');
            $normName = $normalize($c['name']);
            $normFull = $normId . $normName;
            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            if (!is_array($analogs)) $analogs = [];

            if ($normId === $q) {
                $results[] = ['card' => $c, 'reason' => 'точное совпадение артикула'];
                continue;
            }
            if ($normFull && mb_strpos($normFull, $q) !== false) {
                $results[] = ['card' => $c, 'reason' => 'найдено по названию'];
                continue;
            }
            if ($normId && mb_strpos($normId, $q) !== false) {
                $results[] = ['card' => $c, 'reason' => 'часть артикула'];
                continue;
            }
            foreach ($analogs as $a) {
                if (mb_strpos($normalize($a), $q) !== false) {
                    $results[] = ['card' => $c, 'reason' => "найдено по аналогу ({$a})"];
                    break;
                }
            }
            if (mb_strpos($normName, $q) !== false) {
                if (!in_array($c['id'], array_column(array_column($results, 'card'), 'id'))) {
                    $results[] = ['card' => $c, 'reason' => 'найдено по названию'];
                }
            }

            if (count($results) >= 10) break;
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
        sendMessage($chatId, $msg, ['inline_keyboard' => [$exitBtn]]);
        return;
    }

    $text = "🔍 Результаты по «<b>{$queryRaw}</b>»:\n\n";
    foreach ($results as $i => $r) {
        $c = $r['card'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (!is_array($analogs)) $analogs = [];

        $num = $i + 1;
        $text .= "<b>{$num}. {$c['id']}</b> {$c['name']}\n";
        $text .= "   <i>{$r['reason']}</i>\n";
        if (!empty($analogs)) {
            $text .= "   Аналоги: " . implode(', ', $analogs) . "\n";
        }
        $text .= "\n";
    }

    $text .= "<i>Отправьте ещё артикул или название для поиска</i>";

    // Обрезка по лимиту Telegram
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…";
    }

    sendMessage($chatId, $text, ['inline_keyboard' => [$exitBtn]]);
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
        [['text' => '📅 Сегодня', 'callback_data' => 'cmd_today']],
        [['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'], ['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries']],
        [['text' => '📉 Остатки', 'callback_data' => 'cmd_stock'], ['text' => '📊 Расход', 'callback_data' => 'cmd_consumption']],
        [['text' => '🏪 Реализация', 'callback_data' => 'cmd_sales'], ['text' => '📊 Анализ', 'callback_data' => 'cmd_analysis']],
        [['text' => '💰 Цены', 'callback_data' => 'cmd_prices'], ['text' => '📋 Протоколы', 'callback_data' => 'cmd_psc']],
        [['text' => '📅 Планы', 'callback_data' => 'cmd_plans'], ['text' => '🗓 Доставки', 'callback_data' => 'cmd_schedule']],
        [['text' => '🔍 Карточки', 'callback_data' => 'cmd_cards'], ['text' => '📤 Экспорт', 'callback_data' => 'cmd_export']],
        [['text' => '🥬 Подписки', 'callback_data' => 'cmd_veg_stats']],
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

    $lines = ["📖 <b>Supply Department</b>"];
    if ($entity) $lines[] = "🏢 {$short} · {$today}, {$dayName}";
    else $lines[] = "📅 {$today}, {$dayName}";
    $lines[] = "─────────────────────";
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
        if (!$user) {
            answerCallback($cb['id'], 'Нажмите /start для привязки аккаунта');
            exit;
        }
        answerCallback($cb['id']);
        switch ($cmd) {
            case 'menu':
                setUserMode($user['name'], null);
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
            case 'cards': cmdCards($chatId, $user, $msgId); break;
            case 'cards_exit':
                setUserMode($user['name'], null);
                editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
                break;
            case 'veg_stats': cmdVegStats($chatId, $msgId); break;
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
        $field = substr($data, 7);
        $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary', 'data_updates', 'expiring_items', 'restaurant_sales'];
        if (in_array($field, $allowed)) {
            $u = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
            $u->execute([$chatId]);
            $user = $u->fetchColumn();
            if ($user) {
                $pdo->prepare("UPDATE telegram_settings SET `$field` = NOT `$field` WHERE user_name = ?")->execute([$user]);
                showSettings($chatId, $msgId, $user);
                answerCallback($cb['id'], 'Настройка изменена');
            }
        }
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
        answerCallback($cb['id']);
        $restNum = substr($data, 8);
        // Подписать или отписать
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
    vegShowMySubs($chatId);
    exit;
}

// /start
if ($text === '/start') {
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

// /cards
if ($text === '/cards') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
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
                vegOrderProcessInput($chatId, $text, $vegMode);
                exit;
            }
        }
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
        searchCardDirect($chatId, $text);
        exit;
    }
}

handleFreeText($chatId, $text, $user);
