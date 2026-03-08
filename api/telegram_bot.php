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

$GEMINI_API_KEY = $_ENV['GEMINI_API_KEY'] ?? '';
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? '';

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

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
    return $user['legal_entities'][0] ?? null;
}

// ═══ Команды с данными ═══

function cmdOrders($chatId, $user) {
    global $pdo;
    $entity = getUserEntity($user);

    // Заказы за последние 7 дней
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
        sendMessage($chatId, "📦 За последние 7 дней заказов нет.");
        return;
    }

    $text = "📦 <b>Заказы за 7 дней</b>" . ($entity ? " ($entity)" : "") . "\n\n";
    foreach ($orders as $o) {
        $date = date('d.m', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m', strtotime($o['delivery_date'])) : '—';
        $text .= "• <b>{$o['supplier']}</b> — {$o['items_count']} поз., {$o['total_boxes']} кор.\n";
        $text .= "  создан {$date}, приход {$delivery}, автор: {$o['created_by']}\n";
    }
    $text .= "\nВсего: " . count($orders) . " заказов";
    sendMessage($chatId, $text);
}

function cmdStock($chatId, $user) {
    global $pdo;
    $entity = getUserEntity($user);

    // Товары с низким остатком (из analysis_data)
    $sql = "SELECT a.sku, p.name, a.stock
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.stock <= 5 AND a.stock >= 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY a.stock ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) {
        sendMessage($chatId, "✅ Все остатки в норме.");
        return;
    }

    $text = "📉 <b>Низкие остатки</b> (≤ 5)" . ($entity ? " ($entity)" : "") . "\n\n";
    foreach ($items as $i) {
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $text .= "• {$name} — <b>{$i['stock']}</b>\n";
    }
    sendMessage($chatId, $text);
}

function cmdConsumption($chatId, $user) {
    global $pdo;
    $entity = getUserEntity($user);

    // Топ-15 товаров по расходу
    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) {
        sendMessage($chatId, "📊 Данных о расходе пока нет.");
        return;
    }

    $text = "📊 <b>Топ расхода</b> (шт./день)" . ($entity ? " ($entity)" : "") . "\n\n";
    foreach ($items as $i) {
        $days = max($i['period_days'], 1);
        $daily = round($i['consumption'] / $days, 1);
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $text .= "• {$name} — <b>{$daily}</b> шт./день ({$i['consumption']} за {$days} дн.)\n";
    }
    sendMessage($chatId, $text);
}

function cmdPrices($chatId, $user) {
    global $pdo;
    $entity = getUserEntity($user);

    // Последние изменения цен (только для товаров, у которых есть действующая цена)
    $sql = "SELECT ph.sku, p.name as product_name, ph.old_price, ph.new_price, ph.changed_by, ph.changed_at, ph.supplier
            FROM price_history ph
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci)";
    $params = [];
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $changes = $s->fetchAll();

    if (!$changes) {
        sendMessage($chatId, "💰 Изменений цен пока нет.");
        return;
    }

    $text = "💰 <b>Последние изменения цен</b>\n\n";
    foreach ($changes as $c) {
        $date = date('d.m H:i', strtotime($c['changed_at']));
        $name = $c['product_name'] ? $c['sku'] . ' ' . $c['product_name'] : $c['sku'];
        $arrow = $c['new_price'] > $c['old_price'] ? '📈' : '📉';
        $text .= "• {$name} ({$c['supplier']})\n";
        $text .= "  {$arrow} {$c['old_price']} → <b>{$c['new_price']}</b> ₽ ({$date})\n";
    }
    sendMessage($chatId, $text);
}

function cmdPsc($chatId, $user) {
    global $pdo;
    $entity = getUserEntity($user);

    $sql = "SELECT pa.number, pa.supplier, pa.valid_from, pa.valid_to, pa.status,
                   DATEDIFF(pa.valid_to, CURDATE()) as days_left
            FROM price_agreements pa WHERE pa.status = 'active'";
    $params = [];
    if ($entity) { $sql .= " AND pa.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY pa.valid_to ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $agreements = $s->fetchAll();

    if (!$agreements) {
        sendMessage($chatId, "📋 Активных протоколов нет.");
        return;
    }

    $text = "📋 <b>Протоколы (ПСЦ)</b>\n\n";
    foreach ($agreements as $a) {
        $to = date('d.m.Y', strtotime($a['valid_to']));
        $days = $a['days_left'];
        $icon = $days <= 0 ? '🔴' : ($days <= 14 ? '🟡' : '🟢');
        $label = $days <= 0 ? 'истёк' : "ещё {$days} дн.";
        $text .= "{$icon} <b>{$a['number']}</b> — {$a['supplier']}\n";
        $text .= "  до {$to} ({$label})\n";
    }
    sendMessage($chatId, $text);
}

// ═══ AI ответы через Claude ═══

function askAI($question, $context) {
    global $GEMINI_API_KEY;

    // Groq (бесплатный, Llama 3)
    $groqKey = $GLOBALS['GROQ_API_KEY'] ?? '';
    if ($groqKey) {
        return askGroq($question, $context, $groqKey);
    }

    // Gemini (fallback)
    if ($GEMINI_API_KEY) {
        return askGemini($question, $context, $GEMINI_API_KEY);
    }

    return null;
}

function getSystemPrompt() {
    return <<<'PROMPT'
Ты — умный ассистент отдела закупок сети ресторанов быстрого питания (бренд Burger King в Беларуси).

== О КОМПАНИИ ==
- Три юридических лица: «ООО Бургер БК», «ООО Воглия Матта», «ООО Пицца Стар»
- У каждого юрлица свои поставщики, товары, остатки, заказы и цены
- Отдел закупок управляет заказами продуктов у поставщиков для ресторанов

== ТЕРМИНЫ ==
- ПСЦ (протокол согласования цен) — договор с поставщиком о ценах на определённый срок. Если ПСЦ истекает — нужно продлить или заключить новый, иначе поставки остановятся
- Расход (consumption) — сколько товара потребляют рестораны за период (в штуках)
- Остаток (stock) — сколько товара сейчас на складе (в штуках)
- Запас в днях = остаток ÷ (расход ÷ период). Если ≤3 дней — критически мало, 3–7 — мало, 7–14 — внимание, >14 — норма
- Заказ — список товаров для отправки поставщику. Содержит позиции с количеством в коробках
- Коробка (box) — единица упаковки, в каждой коробке определённое количество штук (qty_per_box)
- Кратность (multiplicity) — минимальный шаг заказа (например, кратность 3 = заказ только 3, 6, 9... коробок)
- Дата прихода (delivery_date) — когда поставка приедет в ресторан
- Страховочные дни (safety_days) — дополнительный запас на случай задержки поставки
- Сроки годности — данные со склада (Маллинг): дата производства, годен до, статус, склад (Холод/Мороз/Сухой)

== МОДУЛИ ПРИЛОЖЕНИЯ ==
- Заказ — калькулятор заказа: расчёт потребности с учётом остатков, расхода, транзита, страховочных дней
- Планирование — план закупок на месяц по поставщикам
- История заказов — архив всех заказов с составом
- План-факт — сравнение план vs факт поставок
- База данных — справочник товаров, поставщиков
- График поставок — расписание доставок по дням недели
- Аналитика — ABC/XYZ анализ, тренды, динамика
- Календарь — календарь поставок
- Анализ запасов — остатки, расход, запас в днях, критические позиции
- Сроки годности — данные со склада Маллинг: что годно, что скоро истекает, что заблокировано
- Цены и ПСЦ — прайс-листы, протоколы согласования цен, динамика цен
- Тендеры — проведение тендеров по выбору поставщиков

== КАК ОТВЕЧАТЬ ==
- Кратко, по делу, на русском языке
- Используй ТОЛЬКО данные из контекста. Не выдумывай цифры и факты
- Если данных недостаточно — честно скажи об этом и предложи команду бота
- ВАЖНО: если в контексте найдено НЕСКОЛЬКО товаров — покажи ВСЕ найденные, не выбирай один. Перечисли каждый товар с его данными
- Названия товаров ВСЕГДА показывай в формате: «артикул Название» (например: «100045 Молоко стерилизованное»)
- Если спрашивают остаток — покажи остаток, расход в день и на сколько дней хватит для КАЖДОГО найденного товара
- Если спрашивают совет по закупкам — анализируй остатки и расход: если расход высокий, а остаток низкий — нужно заказывать
- Форматируй для Telegram HTML: <b>жирный</b>, <i>курсив</i>. НЕ используй Markdown (**, ##, ``` и т.д.)
- Числа с единицами: «5 кор.», «120 шт.», «14 дн.»
- Даты в формате ДД.ММ.ГГГГ

== ДОСТУПНЫЕ КОМАНДЫ БОТА (можешь подсказать пользователю) ==
/orders — заказы за 7 дней
/stock — товары с низким остатком
/consumption — топ по расходу
/prices — последние изменения цен
/psc — активные протоколы (ПСЦ)
/settings — настройки уведомлений
PROMPT;
}

function askGroq($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ],
        'max_tokens' => 1024,
        'temperature' => 0.3,
    ]);

    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}",
        'content' => $payload,
        'timeout' => 30,
        'ignore_errors' => true,
    ]];

    $response = @file_get_contents('https://api.groq.com/openai/v1/chat/completions', false, stream_context_create($opts));
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function askGemini($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"]]]
        ],
        'generationConfig' => ['maxOutputTokens' => 1024],
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json",
        'content' => $payload,
        'timeout' => 30,
        'ignore_errors' => true,
    ]];

    $response = @file_get_contents($url, false, stream_context_create($opts));
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

function gatherContext($user) {
    global $pdo;
    $entity = getUserEntity($user);
    $context = "Пользователь: {$user['name']}, роль: {$user['role']}";
    if ($entity) $context .= ", юрлицо: {$entity}";
    $context .= "\nСегодня: " . date('d.m.Y, l') . "\n\n";

    $params = [];
    if ($entity) { $params[] = $entity; }

    // Общая статистика
    $sql = "SELECT COUNT(*) as cnt FROM products WHERE is_active = 1" . ($entity ? " AND legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $prodCount = $s->fetch()['cnt'];
    $context .= "Всего активных товаров: {$prodCount}\n";

    $sql = "SELECT COUNT(DISTINCT supplier) as cnt FROM products WHERE is_active = 1" . ($entity ? " AND legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $suppCount = $s->fetch()['cnt'];
    $context .= "Поставщиков: {$suppCount}\n";

    // Сводка по заказам
    $sql = "SELECT COUNT(*) as cnt FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . ($entity ? " AND o.legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $orderStats = $s->fetch();
    $context .= "Заказов за 7 дней: {$orderStats['cnt']}\n";

    // Последние 5 заказов
    $sql = "SELECT o.supplier, o.created_by, o.created_at, o.delivery_date,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as boxes
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";
    if ($entity) { $sql .= " AND o.legal_entity = ?"; }
    $sql .= " ORDER BY o.created_at DESC LIMIT 5";
    $s = $pdo->prepare($sql); $s->execute($params);
    $recent = $s->fetchAll();
    if ($recent) {
        $context .= "\nПоследние заказы:\n";
        foreach ($recent as $r) {
            $context .= "- {$r['supplier']}: {$r['boxes']} кор., создан " . date('d.m', strtotime($r['created_at'])) . " ({$r['created_by']}), приход " . ($r['delivery_date'] ? date('d.m', strtotime($r['delivery_date'])) : '—') . "\n";
        }
    }

    // Низкие остатки (из analysis_data)
    $sql = "SELECT a.sku, p.name, a.stock FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.stock <= 5 AND a.stock >= 0";
    if ($entity) { $sql .= " AND a.legal_entity = ?"; }
    $sql .= " ORDER BY a.stock ASC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $lowStock = $s->fetchAll();
    if ($lowStock) {
        $context .= "\nНизкие остатки (≤5):\n";
        foreach ($lowStock as $ls) {
            $name = $ls['name'] ? $ls['sku'] . ' ' . $ls['name'] : $ls['sku'];
            $context .= "- {$name}: {$ls['stock']}\n";
        }
    }

    // Протоколы
    $sql = "SELECT number, supplier, valid_to, DATEDIFF(valid_to, CURDATE()) as days_left
            FROM price_agreements WHERE status = 'active'";
    if ($entity) { $sql .= " AND legal_entity = ?"; }
    $sql .= " ORDER BY valid_to ASC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $psc = $s->fetchAll();
    if ($psc) {
        $context .= "\nАктивные протоколы (ПСЦ):\n";
        foreach ($psc as $p) {
            $context .= "- {$p['number']} ({$p['supplier']}): до " . date('d.m.Y', strtotime($p['valid_to'])) . ", осталось {$p['days_left']} дн.\n";
        }
    }

    // Последние изменения цен (только действующие)
    $sql = "SELECT ph.sku, p.name as product_name, ph.supplier, ph.old_price, ph.new_price, ph.changed_at
            FROM price_history ph
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci)";
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $prices = $s->fetchAll();
    if ($prices) {
        $context .= "\nПоследние изменения цен:\n";
        foreach ($prices as $pc) {
            $name = $pc['product_name'] ?: $pc['sku'];
            $context .= "- {$name} ({$pc['supplier']}): {$pc['old_price']} → {$pc['new_price']} ₽ (" . date('d.m', strtotime($pc['changed_at'])) . ")\n";
        }
    }

    // Топ расхода
    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    if ($entity) { $sql .= " AND a.legal_entity = ?"; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $consumption = $s->fetchAll();
    if ($consumption) {
        $context .= "\nТоп расхода:\n";
        foreach ($consumption as $c) {
            $daily = round($c['consumption'] / max($c['period_days'], 1), 1);
            $name = $c['name'] ? $c['sku'] . ' ' . $c['name'] : $c['sku'];
            $context .= "- {$name}: {$daily} шт./день\n";
        }
    }

    return $context;
}

// Поиск товара по артикулу или названию и сбор всех данных по нему
function lookupProduct($question, $entity) {
    global $pdo;

    // Извлечь артикулы (числа 4+ цифр) из вопроса
    $skus = [];
    if (preg_match_all('/\b(\d{4,})\b/', $question, $m)) {
        $skus = $m[1];
    }

    // Извлечь возможные названия товаров:
    // 1) в кавычках
    $searchTerms = [];
    if (preg_match_all('/[«""]([^»""]+)[»""]/', $question, $m)) {
        $searchTerms = array_merge($searchTerms, $m[1]);
    }

    // 2) ключевые слова из вопроса (существительные 3+ букв, кроме стоп-слов)
    if (empty($skus) && empty($searchTerms)) {
        $stopWords = ['какой','какая','какие','каков','сколько','покажи','найди','расскажи','подскажи',
            'остаток','остатки','расход','заказ','заказы','цена','цены','товар','товары','продукт',
            'есть','нет','где','что','как','для','это','еще','уже','очень','можно','нужно','надо',
            'мне','наш','наши','весь','все','только','сейчас','когда','был','была','будет','день',
            'дней','штук','коробок','последний','сегодня','вчера','завтра','про','информация','инфо',
            'данные','скажи','ответь','дай','группа','аналог','аналоги','поставщик',
            'состав','заказа','заказов','последний','последнего','покажи','покаж'];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
                // Обрезаем русские окончания для поиска (молока→молок, булки→булк, кетчупа→кетчуп)
                $stem = $w;
                if (mb_strlen($w) >= 4) {
                    $stem = preg_replace('/(ов|ев|ей|ий|ой|ый|ая|ое|ые|ие|ам|ям|ах|ях|ом|ем|ём|ую|юю|ых|их|ми|ки|ка|ку|ко|ке)$/u', '', $w);
                    if (mb_strlen($stem) < 3) $stem = $w; // Если обрезали слишком коротко
                }
                $searchTerms[] = $stem;
            }
        }
    }

    if (empty($skus) && empty($searchTerms)) return '';

    $context = "\n== НАЙДЕННЫЕ ТОВАРЫ ==\n";
    $found = false;

    $eFilter = $entity ? " AND p.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    // Поиск по артикулам
    foreach ($skus as $sku) {
        $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity
                FROM products p WHERE p.sku = ? AND p.is_active = 1" . $eFilter . " LIMIT 3";
        $s = $pdo->prepare($sql);
        $s->execute(array_merge([$sku], $eParams));
        $products = $s->fetchAll();

        foreach ($products as $prod) {
            $found = true;
            $context .= productFullInfo($prod, $entity);
        }

        if (empty($products)) {
            // Поиск по LIKE
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity
                    FROM products p WHERE p.sku LIKE ? AND p.is_active = 1" . $eFilter . " LIMIT 5";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge(["%{$sku}%"], $eParams));
            $products = $s->fetchAll();
            foreach ($products as $prod) {
                $found = true;
                $context .= productFullInfo($prod, $entity);
            }
        }
    }

    // Поиск по названию (и по группе аналогов) — приоритет товарам с остатками
    $foundSkus = [];
    foreach ($searchTerms as $term) {
        // Сначала товары с данными в analysis_data (есть остаток или расход)
        $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                FROM products p
                INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                    AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
                WHERE (p.name LIKE ? OR p.analog_group LIKE ?) AND p.is_active = 1" . $eFilter . "
                ORDER BY a.stock DESC
                LIMIT 10";
        $s = $pdo->prepare($sql);
        $s->execute(array_merge(["%{$term}%", "%{$term}%"], $eParams));
        $products = $s->fetchAll();

        // Если ничего не нашли с данными — ищем просто по products
        if (empty($products)) {
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                    FROM products p WHERE (p.name LIKE ? OR p.analog_group LIKE ?) AND p.is_active = 1" . $eFilter . " LIMIT 10";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge(["%{$term}%", "%{$term}%"], $eParams));
            $products = $s->fetchAll();
        }

        foreach ($products as $prod) {
            $key = $prod['sku'] . '|' . $prod['legal_entity'];
            if (isset($foundSkus[$key])) continue;
            $foundSkus[$key] = true;
            $found = true;
            $context .= productFullInfo($prod, $entity);
        }
    }

    return $found ? $context : '';
}

function productFullInfo($prod, $entity) {
    global $pdo;
    $sku = $prod['sku'];
    $le = $prod['legal_entity'];
    $info = "\n<b>{$sku} {$prod['name']}</b>\n";
    $info .= "  Поставщик: {$prod['supplier']}, шт./кор.: {$prod['qty_per_box']}, кратность: {$prod['multiplicity']}\n";
    if (!empty($prod['analog_group'])) {
        $info .= "  Группа аналогов: {$prod['analog_group']}\n";
    }

    // Остаток из analysis_data
    $s = $pdo->prepare("SELECT stock, consumption, period_days FROM analysis_data WHERE sku = ? AND legal_entity = ? LIMIT 1");
    $s->execute([$sku, $le]);
    $ad = $s->fetch();
    if ($ad) {
        $daily = $ad['period_days'] > 0 ? round($ad['consumption'] / $ad['period_days'], 1) : 0;
        $daysLeft = $daily > 0 ? round($ad['stock'] / $daily) : '∞';
        $info .= "  Остаток: {$ad['stock']} шт., расход: {$ad['consumption']} за {$ad['period_days']} дн. ({$daily} шт./день)\n";
        $info .= "  Запас на: ~{$daysLeft} дней\n";
    } else {
        $info .= "  Остаток/расход: нет данных\n";
    }

    // Текущая цена
    $s = $pdo->prepare("SELECT price, currency FROM product_prices WHERE sku COLLATE utf8mb4_general_ci = ? AND legal_entity COLLATE utf8mb4_general_ci = ? LIMIT 1");
    $s->execute([$sku, $le]);
    $price = $s->fetch();
    if ($price) {
        $info .= "  Цена: {$price['price']} {$price['currency']}\n";
    }

    // Последние заказы с этим товаром
    $s = $pdo->prepare("SELECT o.supplier, o.created_at, o.delivery_date, oi.qty_boxes
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.sku = ? AND o.legal_entity = ?
            ORDER BY o.created_at DESC LIMIT 5");
    $s->execute([$sku, $le]);
    $orders = $s->fetchAll();
    if ($orders) {
        $info .= "  Последние заказы:\n";
        foreach ($orders as $ord) {
            $date = date('d.m', strtotime($ord['created_at']));
            $delivery = $ord['delivery_date'] ? date('d.m', strtotime($ord['delivery_date'])) : '—';
            $info .= "    {$date}: {$ord['qty_boxes']} кор. ({$ord['supplier']}), приход {$delivery}\n";
        }
    } else {
        $info .= "  Заказов не найдено\n";
    }

    return $info;
}

// Поиск заказов по ключевым словам (поставщик, номер) и подгрузка состава
function lookupOrders($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем, спрашивают ли про заказы
    $orderKeywords = ['заказ', 'состав', 'позиц', 'что заказ', 'заказыв', 'отправ', 'отправл'];
    $isOrderQuestion = false;
    foreach ($orderKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isOrderQuestion = true; break; }
    }
    if (!$isOrderQuestion) return '';

    // Ищем поставщика в вопросе
    $eFilter = $entity ? " AND o.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    // Получаем список поставщиков
    $sql = "SELECT DISTINCT supplier FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . str_replace('o.', '', $eFilter);
    $s = $pdo->prepare($sql); $s->execute($eParams);
    $suppliers = $s->fetchAll(PDO::FETCH_COLUMN);

    $matchedSupplier = null;
    foreach ($suppliers as $sup) {
        if (mb_stripos($q, mb_strtolower($sup)) !== false) {
            $matchedSupplier = $sup;
            break;
        }
        // Поиск по части имени поставщика
        $supWords = preg_split('/[\s\-()]+/u', $sup);
        foreach ($supWords as $sw) {
            if (mb_strlen($sw) >= 4 && mb_stripos($q, mb_strtolower($sw)) !== false) {
                $matchedSupplier = $sup;
                break 2;
            }
        }
    }

    // Определяем кол-во заказов для показа
    $limit = 3;
    if (preg_match('/последн/u', $q)) $limit = 1;

    // Загружаем заказы
    $sql = "SELECT o.id, o.supplier, o.created_by, o.created_at, o.delivery_date
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $eFilter;
    $params = $eParams;
    if ($matchedSupplier) {
        $sql .= " AND o.supplier = ?";
        $params[] = $matchedSupplier;
    }
    $sql .= " ORDER BY o.created_at DESC LIMIT " . intval($limit);
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) return '';

    $context = "\n== НАЙДЕННЫЕ ЗАКАЗЫ ==\n";
    foreach ($orders as $o) {
        $date = date('d.m.Y', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m.Y', strtotime($o['delivery_date'])) : '—';
        $context .= "\nЗаказ #{$o['id']} — {$o['supplier']}, создан {$date}, приход {$delivery}, автор: {$o['created_by']}\n";
        $context .= "Состав:\n";

        $s2 = $pdo->prepare("SELECT oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box, oi.consumption_period, oi.stock, oi.transit
                FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.name");
        $s2->execute([$o['id']]);
        $items = $s2->fetchAll();
        foreach ($items as $it) {
            $pcs = $it['qty_boxes'] * max($it['qty_per_box'], 1);
            $context .= "  - {$it['sku']} {$it['name']}: {$it['qty_boxes']} кор. ({$pcs} шт.)";
            if ($it['stock'] > 0) $context .= ", остаток: {$it['stock']}";
            if ($it['transit'] > 0) $context .= ", транзит: {$it['transit']}";
            $context .= "\n";
        }
        $context .= "Итого: " . count($items) . " позиций\n";
    }

    return $context;
}

// Распознавание числительных (цифрами и словами)
function extractNumber($text) {
    // Сначала ищем цифры
    if (preg_match('/(\d+)/u', $text, $m)) {
        return intval($m[1]);
    }
    // Числительные словами
    $words = [
        'один'=>1,'одного'=>1,'одной'=>1,
        'два'=>2,'двух'=>2,'двум'=>2,
        'три'=>3,'трёх'=>3,'трех'=>3,'трём'=>3,'трем'=>3,
        'четыре'=>4,'четырёх'=>4,'четырех'=>4,
        'пять'=>5,'пяти'=>5,
        'шесть'=>6,'шести'=>6,
        'семь'=>7,'семи'=>7,
        'восемь'=>8,'восьми'=>8,
        'девять'=>9,'девяти'=>9,
        'десять'=>10,'десяти'=>10,
        'одиннадцать'=>11,'двенадцать'=>12,'тринадцать'=>13,'четырнадцать'=>14,
        'пятнадцать'=>15,'двадцать'=>20,'тридцать'=>30,
    ];
    $lower = mb_strtolower($text);
    foreach ($words as $word => $num) {
        if (mb_strpos($lower, $word) !== false) return $num;
    }
    return null;
}

// Анализ запасов по дням — товары с критическим запасом
function lookupStockDays($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем спрашивают ли про запас в днях
    $daysKeywords = ['дней','дня','день','запас','хватит','закончится','кончится','кончается','заканчив','критич','мало','нехватк','дефицит'];
    $isDaysQuestion = false;
    foreach ($daysKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isDaysQuestion = true; break; }
    }
    if (!$isDaysQuestion) return '';

    // Извлечь число дней из вопроса (по умолчанию 7)
    $maxDays = extractNumber($question) ?? 7;

    $sql = "SELECT a.sku, p.name, a.stock, a.consumption, a.period_days, p.supplier,
                   ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci
                AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0 AND a.stock > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= " . intval($maxDays) . " ORDER BY days_left ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) return "\n== АНАЛИЗ ЗАПАСОВ ==\nТоваров с запасом ≤ {$maxDays} дней не найдено.\n";

    $context = "\n== ТОВАРЫ С ЗАПАСОМ ≤ {$maxDays} ДНЕЙ ==\n";
    foreach ($items as $i) {
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $context .= "- {$name}: остаток {$i['stock']} шт., расход {$daily} шт./день, запас на ~{$i['days_left']} дн.";
        if ($i['supplier']) $context .= " ({$i['supplier']})";
        $context .= "\n";
    }
    return $context;
}

// Поиск по срокам годности (stock_malling)
function lookupShelfLife($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $shelfKeywords = ['срок','годн','годност','истек','просроч','expir','маллинг','склад','хранен','блокир'];
    $isShelfQuestion = false;
    foreach ($shelfKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isShelfQuestion = true; break; }
    }
    if (!$isShelfQuestion) return '';

    // Извлечь ключевые слова для поиска конкретного товара
    $stopShelf = ['срок','годн','годности','годност','истек','просроч','хранен','склад','какой','какая','какие','покажи','сколько','осталось'];
    $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
    $productTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopShelf) && !is_numeric($w)) {
            $stem = $w;
            if (mb_strlen($w) >= 4) {
                $stem = preg_replace('/(ов|ев|ей|ий|ой|ый|ая|ое|ые|ие|ам|ям|ах|ях|ом|ем|ём|ую|юю|ых|их|ми|ки|ка|ку|ко|ке)$/u', '', $w);
                if (mb_strlen($stem) < 3) $stem = $w;
            }
            $productTerms[] = $stem;
        }
    }

    $context = "\n== СРОКИ ГОДНОСТИ ==\n";
    $found = false;

    // Поиск по конкретному товару
    if (!empty($productTerms)) {
        foreach ($productTerms as $term) {
            $sql = "SELECT product_name, warehouse, expiry_date, quantity, expiry_status, block_reason,
                           DATEDIFF(expiry_date, CURDATE()) as days_left
                    FROM stock_malling
                    WHERE product_name LIKE ? AND expiry_date >= CURDATE()
                    ORDER BY expiry_date ASC LIMIT 15";
            $s = $pdo->prepare($sql); $s->execute(["%{$term}%"]);
            $items = $s->fetchAll();
            if ($items) {
                $found = true;
                foreach ($items as $i) {
                    $date = date('d.m.Y', strtotime($i['expiry_date']));
                    $status = $i['block_reason'] ?: $i['expiry_status'];
                    $context .= "- {$i['product_name']}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}, статус: {$status}\n";
                }
            }
        }
    }

    // Если не искали конкретный товар или не нашли — показать скоро истекающие
    if (!$found) {
        $daysAhead = extractNumber($question) ?? 14;

        $sql = "SELECT product_name, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date >= CURDATE()
                ORDER BY expiry_date ASC LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute([$daysAhead]);
        $items = $s->fetchAll();
        if ($items) {
            $found = true;
            $context .= "Истекают в ближайшие {$daysAhead} дней:\n";
            foreach ($items as $i) {
                $date = date('d.m.Y', strtotime($i['expiry_date']));
                $context .= "- {$i['product_name']}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}\n";
            }
        }

        // Заблокированные / просроченные
        $s2 = $pdo->prepare("SELECT product_name, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)
                ORDER BY expiry_date ASC LIMIT 15");
        $s2->execute();
        $blocked = $s2->fetchAll();
        if ($blocked) {
            $found = true;
            $context .= "\nЗаблокированные / просроченные:\n";
            foreach ($blocked as $b) {
                $date = date('d.m.Y', strtotime($b['expiry_date']));
                $reason = $b['block_reason'] ?: $b['expiry_status'];
                $context .= "- {$b['product_name']}: {$date}, {$b['quantity']} шт., статус: {$reason}\n";
            }
        }
    }

    if (!$found) return "\n== СРОКИ ГОДНОСТИ ==\nДанных по срокам годности не найдено.\n";
    return $context;
}

// Поиск информации по поставщику
function lookupSupplier($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $supplierKeywords = ['поставщик','поставщ','контакт','телефон','email','dlt','срок документ'];
    $isSupplierQ = false;
    foreach ($supplierKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isSupplierQ = true; break; }
    }

    // Также проверяем, упоминается ли конкретный поставщик
    $params = [];
    $eFilter = '';
    if ($entity) { $eFilter = " AND legal_entity = ?"; $params[] = $entity; }

    $s = $pdo->prepare("SELECT short_name, full_name, telegram, whatsapp, email, dlt, doc FROM suppliers WHERE 1=1" . $eFilter);
    $s->execute($params);
    $allSuppliers = $s->fetchAll();

    $matched = [];
    foreach ($allSuppliers as $sup) {
        $name = mb_strtolower($sup['short_name'] ?? '');
        if ($name && mb_strpos($q, $name) !== false) {
            $matched[] = $sup;
            continue;
        }
        // Поиск по частям имени
        $words = preg_split('/[\s\-()]+/u', $name);
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && mb_strpos($q, $w) !== false) {
                $matched[] = $sup;
                break;
            }
        }
    }

    if (empty($matched) && !$isSupplierQ) return '';

    $context = "\n== ПОСТАВЩИКИ ==\n";

    if (!empty($matched)) {
        foreach ($matched as $sup) {
            $context .= "\n<b>{$sup['short_name']}</b>";
            if ($sup['full_name']) $context .= " ({$sup['full_name']})";
            $context .= "\n";
            if ($sup['email']) $context .= "  Email: {$sup['email']}\n";
            if ($sup['telegram']) $context .= "  Telegram: {$sup['telegram']}\n";
            if ($sup['whatsapp']) $context .= "  WhatsApp: {$sup['whatsapp']}\n";
            if ($sup['dlt']) $context .= "  DLT (срок доставки): {$sup['dlt']} дн.\n";
            if ($sup['doc']) $context .= "  Срок документов: {$sup['doc']} дн.\n";

            // Кол-во товаров этого поставщика
            $s2 = $pdo->prepare("SELECT COUNT(*) as cnt FROM products WHERE supplier = ? AND is_active = 1" . $eFilter);
            $s2->execute(array_merge([$sup['short_name']], $params));
            $cnt = $s2->fetch()['cnt'];
            $context .= "  Товаров: {$cnt}\n";

            // Планы
            $planParams = [$sup['short_name']];
            $planFilter = "";
            if ($entity) { $planFilter = " AND legal_entity = ?"; $planParams[] = $entity; }
            $s3 = $pdo->prepare("SELECT note, start_date, period_type, period_count FROM plans WHERE supplier = ?" . $planFilter . " ORDER BY created_at DESC LIMIT 1");
            $s3->execute($planParams);
            $plan = $s3->fetch();
            if ($plan) {
                $context .= "  Последний план: {$plan['note']}, период: {$plan['period_count']} {$plan['period_type']}\n";
            }

            // Последний заказ
            $s4 = $pdo->prepare("SELECT created_at, delivery_date, (SELECT SUM(qty_boxes) FROM order_items WHERE order_id = o.id) as boxes FROM orders o WHERE o.supplier = ?" . str_replace('legal_entity', 'o.legal_entity', $planFilter) . " ORDER BY o.created_at DESC LIMIT 1");
            $s4->execute($planParams);
            $lastOrder = $s4->fetch();
            if ($lastOrder) {
                $context .= "  Последний заказ: " . date('d.m.Y', strtotime($lastOrder['created_at'])) . ", {$lastOrder['boxes']} кор.\n";
            }

            // ПСЦ
            $s5 = $pdo->prepare("SELECT number, valid_to, DATEDIFF(valid_to, CURDATE()) as days_left FROM price_agreements WHERE supplier = ? AND status = 'active'" . $planFilter . " LIMIT 1");
            $s5->execute($planParams);
            $psc = $s5->fetch();
            if ($psc) {
                $context .= "  ПСЦ: {$psc['number']}, до " . date('d.m.Y', strtotime($psc['valid_to'])) . " ({$psc['days_left']} дн.)\n";
            }
        }
    } elseif ($isSupplierQ) {
        // Список всех поставщиков
        $context .= "Всего поставщиков: " . count($allSuppliers) . "\n";
        foreach (array_slice($allSuppliers, 0, 20) as $sup) {
            $context .= "- {$sup['short_name']}";
            if ($sup['dlt']) $context .= " (DLT: {$sup['dlt']} дн.)";
            $context .= "\n";
        }
    }

    return $context;
}

function handleFreeText($chatId, $text, $user) {
    global $GEMINI_API_KEY, $GROQ_API_KEY;

    // Без ключа — подсказка по командам
    if (!$GEMINI_API_KEY && !$GROQ_API_KEY) {
        sendMessage($chatId, "Доступные команды:\n/orders — заказы за 7 дней\n/stock — низкие остатки\n/consumption — топ расхода\n/prices — изменения цен\n/psc — протоколы\n/settings — настройки уведомлений");
        return;
    }

    // Отправляем «думает...» сообщение
    sendTyping($chatId);
    $thinkMsg = sendMessageAndGetId($chatId, "🔍 Ищу данные...");

    $entity = getUserEntity($user);
    $context = gatherContext($user);

    // Поиск конкретных товаров в вопросе
    $productContext = lookupProduct($text, $entity);
    if ($productContext) {
        $context .= $productContext;
    }

    // Поиск заказов если спрашивают про состав/заказ
    $orderContext = lookupOrders($text, $entity);
    if ($orderContext) {
        $context .= $orderContext;
    }

    // Анализ запасов по дням если спрашивают
    $stockDaysContext = lookupStockDays($text, $entity);
    if ($stockDaysContext) {
        $context .= $stockDaysContext;
    }

    // Сроки годности если спрашивают
    $shelfContext = lookupShelfLife($text, $entity);
    if ($shelfContext) {
        $context .= $shelfContext;
    }

    // Информация по поставщику
    $supplierContext = lookupSupplier($text, $entity);
    if ($supplierContext) {
        $context .= $supplierContext;
    }

    // Обновляем статус — теперь думает ИИ
    if ($thinkMsg) {
        editMessage($chatId, $thinkMsg, "🤖 Формирую ответ...");
    }
    sendTyping($chatId);

    $answer = askAI($text, $context);

    // Удаляем сообщение-статус
    if ($thinkMsg) {
        deleteMessage($chatId, $thinkMsg);
    }

    if ($answer) {
        sendMessage($chatId, $answer);
    } else {
        sendMessage($chatId, "Не удалось получить ответ. Попробуйте позже или используйте команды:\n/orders /stock /consumption /prices /psc");
    }
}

// ═══ Settings UI ═══

function showSettings($chatId, $msgId, $userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $settings = $s->fetch();
    if (!$settings) return;

    $labels = [
        'psc_expiry' => 'ПСЦ истекает',
        'overdue_delivery' => 'Просроченная поставка',
        'price_changed' => 'Цены изменились',
        'low_stock' => 'Остатки заканчиваются',
        'daily_summary' => 'Ежедневная сводка',
    ];

    $text = "⚙️ <b>Настройки уведомлений</b>\n\nНажмите кнопку чтобы включить/выключить:";
    $buttons = [];
    foreach ($labels as $key => $label) {
        $on = $settings[$key] ? '✅' : '❌';
        $buttons[] = [['text' => "$on $label", 'callback_data' => "toggle_$key"]];
    }

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

    // Кнопки меню
    if (str_starts_with($data, 'cmd_')) {
        $cmd = substr($data, 4);
        $user = getUser($chatId);
        if (!$user) {
            answerCallback($cb['id'], 'Сначала привяжите аккаунт');
            exit;
        }
        answerCallback($cb['id']);
        switch ($cmd) {
            case 'orders': cmdOrders($chatId, $user); break;
            case 'stock': cmdStock($chatId, $user); break;
            case 'consumption': cmdConsumption($chatId, $user); break;
            case 'prices': cmdPrices($chatId, $user); break;
            case 'psc': cmdPsc($chatId, $user); break;
            case 'settings': showSettings($chatId, null, $user['name']); break;
        }
        exit;
    }

    if (str_starts_with($data, 'toggle_')) {
        $field = substr($data, 7);
        $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary'];
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
    exit;
}

// ═══ Обработка сообщений ═══

$msg = $input['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$text = trim($msg['text'] ?? '');

// /start
if ($text === '/start') {
    $user = getUser($chatId);
    $greeting = $user ? "Привет, <b>{$user['name']}</b>! 👋" : "👋 <b>Supply Department</b>";
    $intro = $user
        ? "Я помогу с закупками: остатки, заказы, цены, сроки годности. Задай вопрос или выбери из меню:"
        : "Я бот отдела закупок. Для начала отправьте свой <b>email</b>, чтобы привязать аккаунт.";

    $keyboard = ['inline_keyboard' => [
        [['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'], ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock']],
        [['text' => '📊 Расход', 'callback_data' => 'cmd_consumption'], ['text' => '💰 Цены', 'callback_data' => 'cmd_prices']],
        [['text' => '📋 Протоколы', 'callback_data' => 'cmd_psc'], ['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']],
    ]];
    sendMessage($chatId, "{$greeting}\n\n{$intro}", $keyboard);
    exit;
}

// /help или /menu
if ($text === '/help' || $text === '/menu') {
    $keyboard = ['inline_keyboard' => [
        [['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'], ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock']],
        [['text' => '📊 Расход', 'callback_data' => 'cmd_consumption'], ['text' => '💰 Цены', 'callback_data' => 'cmd_prices']],
        [['text' => '📋 Протоколы', 'callback_data' => 'cmd_psc'], ['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']],
    ]];
    sendMessage($chatId, "📖 <b>Меню</b>\n\nВыберите раздел или задайте вопрос текстом:\n\n<i>Примеры вопросов:</i>\n• Какой остаток молока?\n• Товары с запасом на 3 дня\n• Состав последнего заказа\n• Что скоро просрочится на складе?", $keyboard);
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

// /orders
if ($text === '/orders') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "❌ Сначала привяжите аккаунт — отправьте email."); exit; }
    cmdOrders($chatId, $user);
    exit;
}

// /stock
if ($text === '/stock') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "❌ Сначала привяжите аккаунт — отправьте email."); exit; }
    cmdStock($chatId, $user);
    exit;
}

// /consumption
if ($text === '/consumption') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "❌ Сначала привяжите аккаунт — отправьте email."); exit; }
    cmdConsumption($chatId, $user);
    exit;
}

// /prices
if ($text === '/prices') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "❌ Сначала привяжите аккаунт — отправьте email."); exit; }
    cmdPrices($chatId, $user);
    exit;
}

// /psc
if ($text === '/psc') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "❌ Сначала привяжите аккаунт — отправьте email."); exit; }
    cmdPsc($chatId, $user);
    exit;
}

// Привязка по email
if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
    $u = $pdo->prepare("SELECT name FROM users WHERE email = ?");
    $u->execute([$text]);
    $user = $u->fetch();
    if (!$user) {
        sendMessage($chatId, "❌ Пользователь с email <b>{$text}</b> не найден в системе.");
        exit;
    }
    $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE email = ?")->execute([$chatId, $text]);
    $pdo->prepare("INSERT IGNORE INTO telegram_settings (user_name) VALUES (?)")->execute([$user['name']]);
    sendMessage($chatId, "✅ Аккаунт <b>{$user['name']}</b> привязан!\n\nТеперь вы будете получать уведомления.\n\nКоманды: /orders /stock /prices /psc /help\nНастройки: /settings\n\nИли просто задайте вопрос!");
    exit;
}

// Свободный текст — ответ на вопрос
$user = getUser($chatId);
if (!$user) {
    sendMessage($chatId, "Отправьте email для привязки аккаунта или /start для начала.");
    exit;
}

handleFreeText($chatId, $text, $user);
