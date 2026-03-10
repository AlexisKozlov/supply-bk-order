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
$DEEPSEEK_API_KEY = $_ENV['DEEPSEEK_API_KEY'] ?? '';
$OPENROUTER_API_KEY = $_ENV['OPENROUTER_API_KEY'] ?? '';

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

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$orders) {
        botSend($chatId, "📦 За последние 7 дней заказов нет.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
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
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
}

function cmdStock($chatId, $user, $editMsgId = null) {
    global $pdo;
    $entity = getUserEntity($user);
    $entityShort = $entity ? ' (' . getEntityShort($entity) . ')' : '';

    $sql = "SELECT a.sku, p.name, p.supplier, a.stock, a.consumption, a.period_days,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   CASE WHEN a.consumption > 0 THEN ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) ELSE 999 END as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 25";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $btns = [
        [['text' => '📊 Анализ запасов', 'callback_data' => 'cmd_analysis']],
        [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$items) {
        botSend($chatId, "✅ Нет товаров с запасом ≤ 5 дней.{$entityShort}", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $text = "📉 <b>Критичные остатки</b> (≤ 5 дней){$entityShort}\n\n";
    foreach ($items as $i) {
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $icon = $i['days_left'] <= 0 ? '🔴' : '🟠';
        $text .= "{$icon} <b>{$name}</b>\n";
        $uLabel = getUomLabel($i['uom'] ?? 'шт');
        $text .= "   ост. {$i['stock']} {$uLabel}, расход {$daily}/день, запас ~{$i['days_left']} дн.\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdConsumption($chatId, $user, $editMsgId = null) {
    global $pdo;
    $entity = getUserEntity($user);

    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days, COALESCE(p.unit_of_measure, 'шт') as uom
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$items) {
        botSend($chatId, "📊 Данных о расходе пока нет.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
        return;
    }

    $text = "📊 <b>Топ расхода</b>" . ($entity ? " ($entity)" : "") . "\n\n";
    foreach ($items as $i) {
        $days = max($i['period_days'], 1);
        $daily = round($i['consumption'] / $days, 1);
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $u = $i['uom'] ?? 'шт';
        $uLabel = getUomLabel($u);
        $text .= "• {$name} — <b>{$daily}</b> {$uLabel}/день ({$i['consumption']} за {$days} дн.)\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
}

function cmdPrices($chatId, $user, $editMsgId = null) {
    global $pdo;
    $entity = getUserEntity($user);

    $sql = "SELECT ph.sku, p.name as product_name, ph.old_price, ph.new_price, ph.changed_by, ph.changed_at, ph.supplier
            FROM price_history ph
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku COLLATE utf8mb4_general_ci = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity COLLATE utf8mb4_general_ci = ph.legal_entity COLLATE utf8mb4_general_ci)";
    $params = [];
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $changes = $s->fetchAll();

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$changes) {
        botSend($chatId, "💰 Изменений цен пока нет.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
        return;
    }

    $text = "💰 <b>Последние изменения цен</b>\n\n";
    foreach ($changes as $c) {
        $date = date('d.m H:i', strtotime($c['changed_at']));
        $name = $c['product_name'] ? $c['sku'] . ' ' . $c['product_name'] : $c['sku'];
        $arrow = $c['new_price'] > $c['old_price'] ? '📈' : '📉';
        $text .= "• {$name} ({$c['supplier']})\n";
        $text .= "  {$arrow} {$c['old_price']} → <b>{$c['new_price']}</b> BYN ({$date})\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
}

function cmdPsc($chatId, $user, $editMsgId = null) {
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

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$agreements) {
        botSend($chatId, "📋 Активных протоколов нет.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
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
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
}

// ═══ Планы по поставщикам ═══

function cmdPlans($chatId, $user, $editMsgId = null) {
    global $pdo;
    $entity = getUserEntity($user);

    $sql = "SELECT p.supplier, p.period_type, p.period_count, p.start_date, p.note, p.created_by, p.updated_at,
                   p.consumption_period_days, p.input_unit
            FROM plans p WHERE 1=1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY p.updated_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $plans = $s->fetchAll();

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$plans) {
        botSend($chatId, "📅 Планов пока нет.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
        return;
    }

    $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
    $text = "📅 <b>Планы поставок</b>" . ($entity ? " (" . getEntityShort($entity) . ")" : "") . "\n\n";
    foreach ($plans as $p) {
        $period = ($p['period_count'] ?? 3) . ' ' . ($periodLabels[$p['period_type']] ?? $p['period_type']);
        $updated = $p['updated_at'] ? date('d.m', strtotime($p['updated_at'])) : '—';
        $text .= "• <b>{$p['supplier']}</b> — {$period}\n";
        if ($p['note']) $text .= "  📝 {$p['note']}\n";
        $text .= "  обновлён {$updated}, автор: {$p['created_by']}\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
}

// ═══ Ожидающие поставки ═══

function cmdDeliveries($chatId, $user, $editMsgId = null) {
    global $pdo;
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

    $menuBtn = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

    if (!$orders) {
        botSend($chatId, "✅ Нет ожидающих поставок.", ['inline_keyboard' => [$menuBtn]], $editMsgId);
        return;
    }

    $text = "🚚 <b>Ожидающие поставки</b>" . ($entity ? " (" . getEntityShort($entity) . ")" : "") . "\n\n";
    foreach ($orders as $o) {
        $delivery = date('d.m', strtotime($o['delivery_date']));
        $overdue = $o['days_overdue'];
        $icon = $overdue > 0 ? '🔴' : ($overdue == 0 ? '🟡' : '🟢');
        $label = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
        $text .= "{$icon} <b>{$o['supplier']}</b> — приход {$delivery} ({$label})\n";
        $text .= "   {$o['items_count']} поз., {$o['total_boxes']} кор.\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => [$menuBtn]], $editMsgId);
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

    $text = "🔍 <b>Режим поиска карточек</b>\n\n";
    $text .= "Карточек в базе: <b>{$total}</b>\n";
    $text .= "Обновлено: {$lastUpdate}\n\n";
    $text .= "Отправьте <b>артикул</b> или <b>название</b> товара.\n";
    $text .= "Бот найдёт актуальную карточку и покажет аналоги.\n\n";
    $text .= "<i>Для выхода нажмите «Выход» или отправьте /menu</i>";

    $btns = [[['text' => '❌ Выход из поиска карточек', 'callback_data' => 'cmd_cards_exit']]];
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
    global $pdo;

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

    $menuBtn = ['text' => '📖 Меню', 'callback_data' => 'cmd_menu'];

    if (!$all) {
        botSend($chatId, "🗓 График доставок пуст.", ['inline_keyboard' => [[$menuBtn]]], $editMsgId);
        return;
    }

    // Группируем по дням
    $byDay = [];
    foreach ($all as $row) {
        $byDay[$row['day_of_week']][] = $row;
    }

    if ($dayNum === null) {
        // Сводка по всем дням + кнопки
        $text = "🗓 <b>График доставок</b>\n\n";

        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $isToday = ($d === $today);
            $mark = $isToday ? '📍 ' : '';
            $todayLabel = $isToday ? ' (сегодня)' : '';
            $text .= "{$mark}<b>{$dayNames[$d]}</b>{$todayLabel} — {$cnt} доставок\n";
        }

        // Кнопки по дням
        $row1 = []; $row2 = [];
        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $mark = ($d === $today) ? '📍' : '';
            $btn = ['text' => "{$mark}{$dayShort[$d]} ({$cnt})", 'callback_data' => "sched_{$d}"];
            if ($d <= 3) $row1[] = $btn; else $row2[] = $btn;
        }
        $buttons = [$row1, $row2, [$menuBtn]];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    } else {
        // Детальный список на конкретный день
        $isToday = ($dayNum === $today);
        $todayLabel = $isToday ? ' (сегодня)' : '';
        $items = $byDay[$dayNum] ?? [];
        $cnt = count($items);
        $text = "🗓 <b>{$dayNames[$dayNum]}{$todayLabel}</b> — {$cnt} доставок\n\n";

        if ($items) {
            foreach ($items as $d) {
                $city = ($d['city'] && $d['city'] !== 'Минск') ? " ({$d['city']})" : '';
                $text .= "#{$d['number']} {$d['delivery_time']} — {$d['address']}{$city}\n";
            }
        } else {
            $text .= "Нет доставок в этот день.\n";
        }

        // Кнопки навигации между днями
        $prevDay = $dayNum > 1 ? $dayNum - 1 : 6;
        $nextDay = $dayNum < 6 ? $dayNum + 1 : 1;
        $navRow = [
            ['text' => "← {$dayShort[$prevDay]}", 'callback_data' => "sched_{$prevDay}"],
            ['text' => '📋 Все дни', 'callback_data' => 'cmd_schedule'],
            ['text' => "{$dayShort[$nextDay]} →", 'callback_data' => "sched_{$nextDay}"],
        ];
        $buttons = [$navRow, [$menuBtn]];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    }
}

// ═══ Полный анализ запасов (как на сайте) ═══

function cmdAnalysis($chatId, $user, $zone = null, $page = 0, $editMsgId = null) {
    global $pdo;
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
        sendMessage($chatId, "📊 Данных анализа нет" . ($entity ? " для {$entityShort}" : "") . ".");
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
        $text = "📊 <b>Анализ запасов</b>" . ($entity ? " ({$entityShort})" : "") . "\n";
        $text .= "Групп товаров: <b>{$total}</b>\n\n";

        $text .= "🔴 Критично (0–5 дн.): <b>{$zoneCounts['red']}</b>\n";
        $text .= "🟠 Внимание (6–10 дн.): <b>{$zoneCounts['orange']}</b>\n";
        $text .= "🟢 Норма (11–30 дн.): <b>{$zoneCounts['green']}</b>\n";
        $text .= "🟣 Излишки (30+ дн.): <b>{$zoneCounts['purple']}</b>\n";

        // Показываем критичные, если есть
        if ($zoneCounts['red'] > 0) {
            $text .= "\n<b>⚠️ Критичные:</b>\n";
            foreach (array_slice($zoneGroups['red'], 0, 10) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🔴 {$g['name']} — <b>{$g['days']}д.</b> ост.{$g['totalStock']}, {$daily}/д\n";
            }
            if ($zoneCounts['red'] > 10) {
                $text .= "… +" . ($zoneCounts['red'] - 10) . " ещё\n";
            }
        }

        // Показываем оранжевые
        if ($zoneCounts['orange'] > 0) {
            $text .= "\n<b>🟠 Внимание:</b>\n";
            foreach (array_slice($zoneGroups['orange'], 0, 8) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🟠 {$g['name']} — <b>{$g['days']}д.</b> ост.{$g['totalStock']}, {$daily}/д\n";
            }
            if ($zoneCounts['orange'] > 8) {
                $text .= "… +" . ($zoneCounts['orange'] - 8) . " ещё\n";
            }
        }

        // Кнопки для просмотра каждой зоны
        $buttons = [];
        if ($zoneCounts['red'] > 0) $buttons[] = ['text' => "🔴 Критичные ({$zoneCounts['red']})", 'callback_data' => 'analysis_red_0'];
        if ($zoneCounts['orange'] > 0) $buttons[] = ['text' => "🟠 Внимание ({$zoneCounts['orange']})", 'callback_data' => 'analysis_orange_0'];
        if ($zoneCounts['green'] > 0) $buttons[] = ['text' => "🟢 Норма ({$zoneCounts['green']})", 'callback_data' => 'analysis_green_0'];
        if ($zoneCounts['purple'] > 0) $buttons[] = ['text' => "🟣 Излишки ({$zoneCounts['purple']})", 'callback_data' => 'analysis_purple_0'];

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => '🏠 Меню', 'callback_data' => 'cmd_menu']];
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

    $text = "📊 <b>{$zoneNames[$zone]}</b>" . ($entity ? " ({$entityShort})" : "") . "\n";
    $text .= "Всего: <b>" . count($items) . "</b> групп";
    if ($totalPages > 1) $text .= " (стр. " . ($page + 1) . "/" . $totalPages . ")";
    $text .= "\n\n";

    foreach ($pageItems as $g) {
        $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
        $daysStr = $g['days'] >= 999 ? '∞' : $g['days'];
        $uL = $g['uomLabel'] ?? 'шт.';
        $text .= "<b>{$g['name']}</b> — <b>{$daysStr}д.</b>\n";
        $text .= "  {$g['totalStock']} {$uL} · {$daily}/д · {$g['supplier']}\n";

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
    if ($page > 0) $navButtons[] = ['text' => '⬅️ Назад', 'callback_data' => "analysis_{$zone}_" . ($page - 1)];
    if ($page + 1 < $totalPages) $navButtons[] = ['text' => 'Далее ➡️', 'callback_data' => "analysis_{$zone}_" . ($page + 1)];
    $navButtons[] = ['text' => '📊 Сводка', 'callback_data' => 'analysis_summary'];

    $keyboard = ['inline_keyboard' => [$navButtons, [['text' => '🏠 Меню', 'callback_data' => 'cmd_menu']]]];
    if ($editMsgId) {
        editMessage($chatId, $editMsgId, $text, $keyboard);
    } else {
        sendMessage($chatId, $text, $keyboard);
    }
}

// ═══ AI ответы через Claude ═══

function askAI($question, $context) {
    global $GEMINI_API_KEY;

    // Groq (основной — самый быстрый, 1-3 сек)
    $groqKey = $GLOBALS['GROQ_API_KEY'] ?? '';
    if ($groqKey) {
        $result = askGroq($question, $context, $groqKey);
        if ($result) return $result;
        error_log("Bot: Groq failed, trying OpenRouter");
    }

    // OpenRouter (запасной — бесплатные модели, но медленнее)
    $openrouterKey = $GLOBALS['OPENROUTER_API_KEY'] ?? '';
    if ($openrouterKey) {
        $result = askOpenRouter($question, $context, $openrouterKey);
        if ($result) return $result;
    }

    // Gemini (fallback — проверяем кэш квоты)
    if ($GEMINI_API_KEY) {
        $geminiBlock = sys_get_temp_dir() . '/gemini_blocked.txt';
        $geminiOk = true;
        if (file_exists($geminiBlock) && time() - filemtime($geminiBlock) < 3600) {
            $geminiOk = false; // Квота исчерпана, не пробуем час
        }
        if ($geminiOk) {
            error_log("Bot: trying Gemini fallback");
            $result = askGemini($question, $context, $GEMINI_API_KEY);
            if ($result) return $result;
        }
    }

    // DeepSeek (если есть баланс)
    $deepseekKey = $GLOBALS['DEEPSEEK_API_KEY'] ?? '';
    if ($deepseekKey) {
        error_log("Bot: trying DeepSeek fallback");
        $result = askDeepSeek($question, $context, $deepseekKey);
        if ($result) return $result;
    }

    return null;
}

function getSystemPrompt() {
    return <<<'PROMPT'
Ты — ассистент отдела закупок сети Burger King в Беларуси.

== ЮРЛИЦА ==
Три юридических лица:
- ООО «Бургер БК» (сокращённо БК) — основное юрлицо Burger King
- ООО «Воглия Матта» (сокращённо ВМ) — второе юрлицо Burger King
- ООО «Пицца Стар» (сокращённо ПС) — юрлицо Pizza Star

ВАЖНО: У каждого юрлица СВОИ данные — свои товары, остатки, расход, заказы, цены и сроки годности.
Бургер БК и Воглия Матта — это два разных юрлица одного бизнеса, но данные у них РАЗНЫЕ.
Пицца Стар — отдельный бизнес со своими данными.
Данные в контексте уже отфильтрованы по текущему юрлицу пользователя.
Если пользователь спрашивает про другое юрлицо — предложи переключиться: /entity

== СРОКИ ГОДНОСТИ ==
В таблице stock_malling хранятся данные по срокам годности со складов.
Поле «customer» — это юрлицо (Бургер БК, Воглия Матта, Пицца Стар).
Если в контексте есть метка [Бургер БК], [Воглия Матта] или [Пицца Стар] — указывай, к какому юрлицу относится товар.
Статусы: Годен — всё ок, Маллинг — снижена цена (скоро истечёт), Блокирован — нельзя использовать.

== ТЕРМИНЫ ==
ПСЦ — протокол согласования цен с поставщиком.
Запас в днях = остаток ÷ дневной расход. ≤3 дн — критично, 3–7 — мало, >14 — норма.
DLT — срок доставки (delivery lead time). DOC — срок документооборота.
НДС — налог на добавленную стоимость (обычно 20%, бывает 10% и 0%). Цены в системе — без НДС.
Кратность — заказ должен быть кратен этому числу коробок.
Кейсовка (вложение, фасовка) — это qty_per_box: сколько штук/кг/литров в одной коробке. Если спрашивают «какая кейсовка» — ответь именно qty_per_box (шт./кор., кг/кор. или л/кор.).

== ДОПОЛНИТЕЛЬНЫЕ ЗНАНИЯ ==
- Поставки: заказы с датой доставки, но без приёмки — это ожидающие поставки. Просроченные — если дата прошла.
- Планы: у поставщиков есть периодичность заказов (каждые N недель/месяцев).
- Рестораны: около 57 ресторанов, каждый имеет свой номер и адрес. У ресторанов есть график доставки по дням недели с временем.
- Группы аналогов: товары с одинаковой analog_group взаимозаменяемы, их запас считается суммарно.
- Единицы: товары считаются в штуках или коробках. qty_per_box — штук в коробке. multiplicity — кратность заказа.
- Сбор остатков: рестораны заполняют формы с остатками определённых товаров.
- Карточки: справочник всех товаров с артикулами и аналогами. У каждого товара может быть список аналогов (замен). Если в контексте есть раздел «КАРТОЧКИ ТОВАРОВ» — покажи найденные карточки с аналогами.

== КОНТЕКСТ РАЗГОВОРА ==
Если вопрос пользователя выглядит как уточнение (например «а по Воглии?», «а для БК?», «ещё по молоку»), система автоматически добавляет контекст предыдущего вопроса. Отвечай на полный вопрос, не упоминая что это уточнение.

== ТИПИЧНЫЕ ВОПРОСЫ И КАК ОТВЕЧАТЬ ==
Вопрос «когда приедет [товар]?» → Покажи ожидающие поставки с этим товаром: дату прихода, количество в коробках и штуках, поставщика.
Вопрос «что привезёт/приедет [поставщик]?» → Покажи ВСЕ товары из заказа этого поставщика: название, количество коробок и штук. Данные будут в разделе «ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику)».
Вопрос «сколько [товара] заказано?» → Покажи позиции заказов с этим товаром и их количество.
Вопрос «какой остаток [товара]?» → Покажи остаток, дневной расход и запас в днях.
Вопрос «что скоро просрочится?» → Покажи товары с близким сроком годности.
Вопрос «когда доставка в ресторан N?» → Покажи график доставок для конкретного ресторана.
Вопрос «какие товары заканчиваются?» → Покажи товары с запасом менее 5 дней.
Вопрос «расскажи про поставщика X» → Покажи контакты, DLT, последний заказ, ПСЦ.
Вопрос «найди карточку [артикул/название]» → Покажи карточку с артикулом, названием и списком аналогов.
Вопрос «какие аналоги у [товара]?» → Покажи карточку и её аналоги.
Вопрос «какая кейсовка [товара]?» → Покажи qty_per_box (вложение в коробку): «В коробке: X шт./кг/л». Также покажи кратность заказа.

== ПРАВИЛА ОТВЕТА ==
- Кратко, на русском, ТОЛЬКО по данным из контекста
- КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО выдумывать данные. Если в контексте нет информации — так и скажи.
- Если данных не найдено или вопрос неоднозначный — задай уточняющий вопрос. Примеры:
  • «Какой именно товар вы имеете в виду? Уточните артикул или полное название.»
  • «По запросу найдено несколько вариантов: [список]. Какой именно?»
  • «Данных по этому товару нет. Возможно, вы имели в виду [похожий товар]?»
  • «Для какого юрлица показать данные? Сейчас выбрано: [юрлицо].»
- Если вопрос слишком общий (например, просто «молоко» без контекста), спроси что именно нужно: остаток, цену, поставку или карточку
- Если в контексте есть раздел «ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику)» — это детальный список ВСЕХ товаров конкретного поставщика. Покажи его полностью.
- Для вопроса «когда приедет [товар]» — используй ТОЛЬКО раздел «ОЖИДАЮЩИЕ ПОСТАВКИ (найденные товары)», где указаны конкретные позиции
- Если несколько товаров — покажи ВСЕ, не обрезай
- Формат товара: «артикул Название»
- Остаток: показывай остаток, расход/день и запас в днях
- Цены: всегда показывай и «без НДС» и «с НДС», указывай ставку НДС
- При вопросе о поставке конкретного товара — показывай КОЛИЧЕСТВО ИМЕННО ЭТОГО ТОВАРА, а не общее количество заказа
- Единицы: у каждого товара своя единица (шт., л, кг) — используй ту, что указана в данных. Не заменяй «л» на «шт.» и наоборот
- HTML для Telegram: <b>жирный</b>, <a href="url">текст</a> для ссылок. НЕ используй Markdown (**, ##, - , ```). Списки — с «•» или «—»
- Если в данных есть ссылки (<a href="...">) — сохраняй их как есть в ответе. НЕ дублируй ссылки, НЕ добавляй новые
- Числа: «5 кор.», «120 шт.», «14 дн.», даты: ДД.ММ.ГГГГ
- Не повторяй вопрос пользователя в ответе
- Ответ должен быть сразу по существу

== ПОДСКАЗКИ КОМАНД ==
Если пользователь спрашивает о чём-то, что лучше посмотреть через команду — предложи её в конце ответа:
- Полный список заказов → /orders
- Все низкие остатки → /stock
- Анализ запасов по зонам → /analysis
- Расход товаров → /consumption
- Изменения цен → /prices
- Протоколы ПСЦ → /psc
- Ожидаемые поставки → /deliveries
- Планы поставок → /plans
- График доставок → /schedule
- Поиск карточек → /cards
- Переключить юрлицо → /entity
Не навязывай команды — предлагай только если это действительно поможет ответить на вопрос полнее.

== ЧАСТЫЕ ОШИБКИ — НЕ ДОПУСКАЙ ==
- НЕ пиши «по данным системы, [товар] от поставщика X» если это не указано явно
- НЕ считай общий запас по аналогам — каждый товар отдельно
- НЕ округляй числа — показывай как есть в данных
- НЕ добавляй «по данным на сегодня» — пользователь знает что данные актуальны
- НЕ используй слова «приблизительно», «ориентировочно» для точных данных из системы
PROMPT;
}

function askOpenRouter($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    // Модели по приоритету: разнообразие провайдеров для обхода лимитов
    $models = [
        'meta-llama/llama-4-maverick:free',
        'meta-llama/llama-4-scout:free',
        'qwen/qwen3-235b-a22b:free',
        'deepseek/deepseek-chat-v3-0324:free',
        'meta-llama/llama-3.3-70b-instruct:free',
        'google/gemma-3-27b-it:free',
    ];

    foreach ($models as $model) {
        $result = callOpenRouter($question, $context, $apiKey, $systemPrompt, $model);
        if ($result) return $result;
    }

    return null;
}

function callOpenRouter($question, $context, $apiKey, $systemPrompt, $model) {
    // Кэш недоступных моделей (файл, чтобы работал между запросами)
    $cacheFile = sys_get_temp_dir() . '/openrouter_blocked.json';
    $blocked = [];
    if (file_exists($cacheFile)) {
        $blocked = json_decode(file_get_contents($cacheFile), true) ?: [];
        // Очистка устаревших (>30 минут)
        $blocked = array_filter($blocked, fn($ts) => time() - $ts < 1800);
    }
    if (isset($blocked[$model])) {
        return null; // Пропускаем — модель недавно дала 429
    }

    // Gemma не поддерживает system role — объединяем с user
    $isGemma = strpos($model, 'gemma') !== false;

    if ($isGemma) {
        $messages = [
            ['role' => 'user', 'content' => "{$systemPrompt}\n\nКонтекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://supply-department.online',
            'X-Title: Supply Bot',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 200) : '(empty)';
        error_log("OpenRouter [{$model}]: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        // Запоминаем 429 чтобы не пробовать эту модель 30 минут
        if ($httpCode === 429) {
            $blocked[$model] = time();
            @file_put_contents($cacheFile, json_encode($blocked));
        }
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? null;

    // Некоторые модели (step, qwen) кладут ответ в reasoning — проверяем
    if (!$content) {
        $reasoning = $data['choices'][0]['message']['reasoning'] ?? null;
        if ($reasoning) $content = $reasoning;
    }

    // Убираем <think> теги если есть
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }

    if ($content) {
        error_log("OpenRouter: OK with model={$model}");
    }

    return $content ?: null;
}

function askDeepSeek($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ],
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
        error_log("DeepSeek API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    // DeepSeek иногда включает <think>...</think> в ответ — убираем
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }
    return $content ?: null;
}

function askGroq($question, $context, $apiKey, $model = 'llama-3.3-70b-versatile') {
    // Кэш rate limit (чтобы не дёргать API зря)
    $cacheFile = sys_get_temp_dir() . '/groq_blocked.json';
    $blocked = [];
    if (file_exists($cacheFile)) {
        $blocked = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    if (isset($blocked[$model]) && time() < $blocked[$model]) {
        error_log("Groq: {$model} rate-limited until " . date('H:i:s', $blocked[$model]));
        // Попробовать меньшую модель
        if ($model === 'llama-3.3-70b-versatile') {
            return askGroq($question, $context, $apiKey, 'llama-3.1-8b-instant');
        }
        return null;
    }

    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ],
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        error_log("Groq API error ({$model}): HTTP {$httpCode}, err={$err}, ctx_len=" . strlen($context));
        if ($httpCode === 429) {
            // Извлекаем время ожидания из ответа
            $waitSec = 600; // По умолчанию 10 мин
            if (preg_match('/try again in (\d+)h/i', $response, $hm)) {
                $waitSec = intval($hm[1]) * 3600;
            } elseif (preg_match('/try again in (\d+)m/i', $response, $mm)) {
                $waitSec = intval($mm[1]) * 60;
            } elseif (preg_match('/try again in ([\d.]+)s/i', $response, $sm)) {
                $waitSec = intval(ceil(floatval($sm[1])));
            }
            $blocked[$model] = time() + $waitSec;
            @file_put_contents($cacheFile, json_encode($blocked));
            // Попробовать меньшую модель
            if ($model === 'llama-3.3-70b-versatile') {
                return askGroq($question, $context, $apiKey, 'llama-3.1-8b-instant');
            }
        }
        return null;
    }

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
        'generationConfig' => ['maxOutputTokens' => 1024, 'temperature' => 0.1],
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
        error_log("Gemini API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        // Если квота исчерпана — запоминаем на час
        if ($httpCode === 429 || strpos($response ?: '', 'quota') !== false) {
            @file_put_contents(sys_get_temp_dir() . '/gemini_blocked.txt', 'blocked');
        }
        return null;
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        error_log("Bot: Gemini returned null");
    }
    return $text;
}

function gatherContext($user) {
    global $pdo;
    $entity = getUserEntity($user);
    $context = "Пользователь: {$user['name']}, роль: {$user['role']}";
    if ($entity) $context .= "\nТекущее юрлицо: {$entity} (все данные ниже — для этого юрлица)";
    $allEntities = implode(', ', $user['legal_entities']);
    $context .= "\nДоступные юрлица: {$allEntities}";
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
            $context .= "- {$name} ({$pc['supplier']}): {$pc['old_price']} → {$pc['new_price']} BYN (" . date('d.m', strtotime($pc['changed_at'])) . ")\n";
        }
    }

    // Топ расхода
    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days, COALESCE(p.unit_of_measure, 'шт') as uom FROM analysis_data a
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
            $cu = $c['uom'] ?? 'шт';
            $cuLabel = getUomLabel($cu);
            $context .= "- {$name}: {$daily} {$cuLabel}/день\n";
        }
    }

    // Сроки годности — скоро истекающие (ближайшие 14 дней)
    $customerName = null;
    if ($entity) {
        if (strpos($entity, 'Бургер') !== false) $customerName = 'Бургер БК';
        elseif (strpos($entity, 'Воглия') !== false) $customerName = 'Воглия Матта';
        elseif (strpos($entity, 'Пицца') !== false) $customerName = 'Пицца Стар';
    }
    $shelfSql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status,
                        DATEDIFF(expiry_date, CURDATE()) as days_left
                 FROM stock_malling
                 WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)";
    $shelfParams = [];
    if ($customerName) { $shelfSql .= " AND customer = ?"; $shelfParams[] = $customerName; }
    $shelfSql .= " ORDER BY expiry_date ASC LIMIT 10";
    $s = $pdo->prepare($shelfSql); $s->execute($shelfParams);
    $expiring = $s->fetchAll();
    if ($expiring) {
        $context .= "\nСроки годности (истекают в ближайшие 14 дней):\n";
        foreach ($expiring as $ex) {
            $date = date('d.m.Y', strtotime($ex['expiry_date']));
            $custLabel = $ex['customer'] ? " [{$ex['customer']}]" : '';
            $context .= "- {$ex['product_name']}{$custLabel}: до {$date} ({$ex['days_left']} дн.), {$ex['quantity']} шт., склад: {$ex['warehouse']}\n";
        }
    }

    // Заблокированные / просроченные
    $blockedSql = "SELECT product_name, customer, expiry_status, block_reason, quantity
                   FROM stock_malling
                   WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)";
    $blockedParams = [];
    if ($customerName) { $blockedSql .= " AND customer = ?"; $blockedParams[] = $customerName; }
    $blockedSql .= " LIMIT 10";
    $s = $pdo->prepare($blockedSql); $s->execute($blockedParams);
    $blocked = $s->fetchAll();
    if ($blocked) {
        $context .= "\nЗаблокированные/просроченные на складе:\n";
        foreach ($blocked as $b) {
            $reason = $b['block_reason'] ?: $b['expiry_status'];
            $custLabel = $b['customer'] ? " [{$b['customer']}]" : '';
            $context .= "- {$b['product_name']}{$custLabel}: {$b['quantity']} шт., статус: {$reason}\n";
        }
    }

    // Ожидаемые поставки (заказы без факт. прихода)
    $delivSql = "SELECT o.supplier, o.delivery_date, o.created_at,
                        (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as boxes,
                        DATEDIFF(CURDATE(), o.delivery_date) as overdue_days
                 FROM orders o
                 WHERE o.received_at IS NULL";
    $delivParams = [];
    if ($entity) { $delivSql .= " AND o.legal_entity = ?"; $delivParams[] = $entity; }
    $delivSql .= " ORDER BY o.delivery_date ASC LIMIT 10";
    $s = $pdo->prepare($delivSql); $s->execute($delivParams);
    $deliveries = $s->fetchAll();
    if ($deliveries) {
        $context .= "\nОжидаемые поставки:\n";
        foreach ($deliveries as $d) {
            $dd = $d['delivery_date'] ? date('d.m', strtotime($d['delivery_date'])) : '—';
            $status = '';
            if ($d['delivery_date'] && $d['overdue_days'] > 0) {
                $status = " ⚠️ просрочена на {$d['overdue_days']} дн.";
            }
            $context .= "- {$d['supplier']}: {$d['boxes']} кор., ожид. {$dd}{$status}\n";
        }
    }

    // Планы поставок
    $planSql = "SELECT pl.supplier, pl.period_type, pl.period_count, pl.note, pl.updated_at
                FROM plans pl";
    $planParams = [];
    if ($entity) { $planSql .= " WHERE pl.legal_entity = ?"; $planParams[] = $entity; }
    $planSql .= " ORDER BY pl.supplier ASC LIMIT 15";
    $s = $pdo->prepare($planSql); $s->execute($planParams);
    $plans = $s->fetchAll();
    if ($plans) {
        $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
        $context .= "\nПланы поставок:\n";
        foreach ($plans as $pl) {
            $period = ($pl['period_count'] ?? 3) . ' ' . ($periodLabels[$pl['period_type']] ?? $pl['period_type']);
            $note = $pl['note'] ? " ({$pl['note']})" : '';
            $context .= "- {$pl['supplier']}: каждые {$period}{$note}\n";
        }
    }

    return $context;
}

// Поиск товара по артикулу или названию и сбор всех данных по нему
function lookupProduct($question, $entity) {
    global $pdo;
    // Определяем, вопрос ли это о поставке — тогда не нужна история заказов
    $q = mb_strtolower($question);
    $skipHistory = (bool) preg_match('/поставк|приед|привез|когда.*прие|ожидае|приход|привоз/u', $q);

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
            'есть','нет','где','что','как','для','это','еще','ещё','уже','очень','можно','нужно','надо',
            'мне','наш','наши','весь','все','только','сейчас','когда','был','была','будет','день',
            'дней','штук','коробок','последний','сегодня','вчера','завтра','про','информация','инфо',
            'данные','скажи','ответь','дай','группа','аналог','аналоги','поставщик',
            'состав','заказа','заказов','последнего','покаж',
            'приедет','приедут','привезут','поставка','поставки','ожидает','доставка','приход',
            'литров','литр','кило','килограмм','штуки','коробки','упаковок','палет',
            'кейсовка','кейсовки','упаковка','фасовка','вложение','вложенность'];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
                $stem = stemRu($w);
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
            $context .= productFullInfo($prod, $entity, $skipHistory);
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
                $context .= productFullInfo($prod, $entity, $skipHistory);
            }
        }
    }

    // Поиск по названию (и по группе аналогов) — приоритет товарам с остатками
    $foundSkus = [];

    // Если несколько слов — сначала ищем товары, содержащие ВСЕ слова (точный поиск)
    if (count($searchTerms) > 1) {
        // Строим SQL с AND для всех терминов
        $nameConditions = [];
        $nameParams = [];
        foreach ($searchTerms as $term) {
            $nameConditions[] = "(p.name LIKE ? OR p.analog_group LIKE ?)";
            $nameParams[] = "%{$term}%";
            $nameParams[] = "%{$term}%";
        }
        $allCondition = implode(' AND ', $nameConditions);

        // С данными анализа
        $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                FROM products p
                INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                    AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
                WHERE {$allCondition} AND p.is_active = 1" . $eFilter . "
                ORDER BY a.stock DESC LIMIT 10";
        $s = $pdo->prepare($sql);
        $s->execute(array_merge($nameParams, $eParams));
        $products = $s->fetchAll();

        // Без данных анализа
        if (empty($products)) {
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                    FROM products p WHERE {$allCondition} AND p.is_active = 1" . $eFilter . " LIMIT 10";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge($nameParams, $eParams));
            $products = $s->fetchAll();
        }

        foreach ($products as $prod) {
            $key = $prod['sku'] . '|' . $prod['legal_entity'];
            if (isset($foundSkus[$key])) continue;
            $foundSkus[$key] = true;
            $found = true;
            $context .= productFullInfo($prod, $entity, $skipHistory);
        }
    }

    // Если точный поиск не дал результатов — ищем по отдельным словам
    if (!$found) {
        foreach ($searchTerms as $term) {
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
                $context .= productFullInfo($prod, $entity, $skipHistory);
            }
        }
    }

    return $found ? $context : '';
}

function productFullInfo($prod, $entity, $skipHistory = false) {
    global $pdo;
    $sku = $prod['sku'];
    $le = $prod['legal_entity'];
    $uom = $prod['unit_of_measure'] ?? 'шт';
    $uomLabel = getUomLabel($uom);
    $uomPerBox = $uom === 'л' ? 'л/кор.' : ($uom === 'кг' ? 'кг/кор.' : 'шт./кор.');
    $info = "\n<b>{$sku} {$prod['name']}</b>\n";
    $info .= "  Поставщик: {$prod['supplier']}, {$uomPerBox}: {$prod['qty_per_box']}, кратность: {$prod['multiplicity']}\n";
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
        $info .= "  Остаток: {$ad['stock']} {$uomLabel}, расход: {$ad['consumption']} за {$ad['period_days']} дн. ({$daily} {$uomLabel}/день)\n";
        $info .= "  Запас на: ~{$daysLeft} дней\n";
    } else {
        $info .= "  Остаток/расход: нет данных\n";
    }

    // Текущая цена
    $s = $pdo->prepare("SELECT price, currency, vat_rate, unit_type FROM product_prices WHERE sku COLLATE utf8mb4_general_ci = ? AND legal_entity COLLATE utf8mb4_general_ci = ? LIMIT 1");
    $s->execute([$sku, $le]);
    $price = $s->fetch();
    if ($price) {
        $vat = $price['vat_rate'] ?? 20;
        $priceWithVat = round($price['price'] * (1 + $vat / 100), 2);
        $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
        $unit = $unitLabels[$price['unit_type']] ?? $price['unit_type'];
        $info .= "  Цена: {$price['price']} {$price['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$price['currency']}\n";
    }

    // Ожидающие поставки с этим товаром
    $s = $pdo->prepare("SELECT o.id as order_id, o.supplier, o.delivery_date, oi.qty_boxes,
                               DATEDIFF(o.delivery_date, CURDATE()) as days_until
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        WHERE oi.sku = ? AND o.legal_entity = ? AND o.received_at IS NULL AND o.delivery_date IS NOT NULL
                        ORDER BY o.delivery_date ASC LIMIT 5");
    $s->execute([$sku, $le]);
    $pending = $s->fetchAll();
    if ($pending) {
        $info .= "  Ожидается поставка:\n";
        foreach ($pending as $p) {
            $dd = date('d.m', strtotime($p['delivery_date']));
            $pcs = $p['qty_boxes'] * max($prod['qty_per_box'], 1);
            $when = $p['days_until'] > 0 ? "через {$p['days_until']} дн." : ($p['days_until'] == 0 ? 'сегодня' : 'просрочена');
            $orderUrl = "https://supply-department.online/order?orderId={$p['order_id']}&mode=view";
            $info .= "    {$dd}: {$p['qty_boxes']} кор. ({$pcs} {$uomLabel}) — {$p['supplier']}, {$when} (<a href=\"{$orderUrl}\">заказ</a>)\n";
        }
    }

    // Последние заказы с этим товаром (пропускаем если не нужна история)
    if (!$skipHistory) {
        $s = $pdo->prepare("SELECT o.id as order_id, o.supplier, o.created_at, o.delivery_date, oi.qty_boxes
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE oi.sku = ? AND o.legal_entity = ?
                ORDER BY o.created_at DESC LIMIT 3");
        $s->execute([$sku, $le]);
        $orders = $s->fetchAll();
        if ($orders) {
            $info .= "  Последние заказы:\n";
            foreach ($orders as $ord) {
                $date = date('d.m', strtotime($ord['created_at']));
                $delivery = $ord['delivery_date'] ? date('d.m', strtotime($ord['delivery_date'])) : '—';
                $orderUrl = "https://supply-department.online/order?orderId={$ord['order_id']}&mode=view";
                $info .= "    {$date}: {$ord['qty_boxes']} кор. ({$ord['supplier']}), приход {$delivery} (<a href=\"{$orderUrl}\">заказ</a>)\n";
            }
        }
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
    if (preg_match('/последни[еймх]/u', $q)) $limit = 5; // "последние заказы" — несколько
    elseif (preg_match('/последн[иеяй]/u', $q)) $limit = 1; // "последний заказ" — один

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

        $s2 = $pdo->prepare("SELECT oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box, oi.consumption_period, oi.stock, oi.transit,
                COALESCE(p.unit_of_measure, 'шт') as uom
                FROM order_items oi LEFT JOIN products p ON p.sku = oi.sku
                WHERE oi.order_id = ? ORDER BY oi.name");
        $s2->execute([$o['id']]);
        $items = $s2->fetchAll();
        foreach ($items as $it) {
            $pcs = $it['qty_boxes'] * max($it['qty_per_box'], 1);
            $u = $it['uom'] ?? 'шт';
            $uLabel = getUomLabel($u);
            $context .= "  - {$it['sku']} {$it['name']}: {$it['qty_boxes']} кор. ({$pcs} {$uLabel})";
            if ($it['stock'] > 0) $context .= ", остаток: {$it['stock']}";
            if ($it['transit'] > 0) $context .= ", транзит: {$it['transit']}";
            $context .= "\n";
        }
        $context .= "Итого: " . count($items) . " позиций\n";
    }

    return $context;
}

// ═══ Вспомогательные функции ═══

// Единица измерения → подпись для вывода
function getUomLabel($uom) {
    return $uom === 'л' ? 'л' : ($uom === 'кг' ? 'кг' : 'шт.');
}

// Стемирование русского слова (обрезка окончаний для поиска)
function stemRu($word) {
    if (mb_strlen($word) < 3) return $word;
    // 3-буквенные окончания (прилагательные, существительные)
    $stem = preg_replace('/(ого|его|ому|ему|ной|ным|ном|ную|ных|ами|ями|ями|ого|ому|ыми|ими|нем|нём|тся|ний|ние|ний|ния|нию|ией|ием|иям|ями|ать|ять|ить|еть|уть|ыть|ять|ось|ась)$/u', '', $word);
    if ($stem !== $word && mb_strlen($stem) >= 3) return $stem;
    // 2-буквенные окончания
    $stem = preg_replace('/(ов|ев|ей|ий|ой|ый|ая|яя|ое|ые|ие|ам|ям|ах|ях|ом|ем|ём|ую|юю|ых|их|ми|ки|ка|ку|ко|ке|ок|ек|ёк|ик|ть|ся|ны|на|но|ну|не|ни)$/u', '', $word);
    if ($stem !== $word && mb_strlen($stem) >= 3) return $stem;
    // 1-буквенные окончания
    $stem = preg_replace('/[аеёиоуыэюя]$/u', '', $word);
    return mb_strlen($stem) >= 3 ? $stem : $word;
}

// Проверка: содержит ли текст хотя бы одно из ключевых слов
function matchesKeywords($text, $keywords) {
    foreach ($keywords as $kw) {
        if (mb_strpos($text, $kw) !== false) return true;
    }
    return false;
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
                   COALESCE(p.unit_of_measure, 'шт') as uom,
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
        $u = $i['uom'] ?? 'шт';
        $uLabel = getUomLabel($u);
        $context .= "- {$name}: остаток {$i['stock']} {$uLabel}, расход {$daily} {$uLabel}/день, запас на ~{$i['days_left']} дн.";
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

    // Маппинг юрлица → customer в stock_malling
    $customerFilter = '';
    $customerParams = [];
    if ($entity) {
        // Определяем короткое имя для фильтра customer
        $customerName = null;
        if (strpos($entity, 'Бургер') !== false) $customerName = 'Бургер БК';
        elseif (strpos($entity, 'Воглия') !== false) $customerName = 'Воглия Матта';
        elseif (strpos($entity, 'Пицца') !== false) $customerName = 'Пицца Стар';
        if ($customerName) {
            $customerFilter = ' AND customer = ?';
            $customerParams = [$customerName];
        }
    }

    // Извлечь ключевые слова для поиска конкретного товара
    $stopShelf = ['срок','годн','годности','годност','истек','просроч','хранен','склад','какой','какая','какие','покажи','сколько','осталось',
                  'бургер','воглия','матта','пицца','стар','юрлиц','юрлица','лицо','лица'];
    $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
    $productTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopShelf) && !is_numeric($w)) {
            $productTerms[] = stemRu($w);
        }
    }

    $context = "\n== СРОКИ ГОДНОСТИ ==\n";
    if ($entity) $context .= "(юрлицо: {$entity})\n";
    $found = false;

    // Поиск по конкретному товару
    if (!empty($productTerms)) {
        foreach ($productTerms as $term) {
            $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                           DATEDIFF(expiry_date, CURDATE()) as days_left
                    FROM stock_malling
                    WHERE product_name LIKE ? AND expiry_date >= CURDATE()" . $customerFilter . "
                    ORDER BY expiry_date ASC LIMIT 15";
            $s = $pdo->prepare($sql); $s->execute(array_merge(["%{$term}%"], $customerParams));
            $items = $s->fetchAll();
            if ($items) {
                $found = true;
                foreach ($items as $i) {
                    $date = date('d.m.Y', strtotime($i['expiry_date']));
                    $status = $i['block_reason'] ?: $i['expiry_status'];
                    $custLabel = $i['customer'] ? " [{$i['customer']}]" : '';
                    $context .= "- {$i['product_name']}{$custLabel}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}, статус: {$status}\n";
                }
            }
        }
    }

    // Если не искали конкретный товар или не нашли — показать скоро истекающие
    if (!$found) {
        $daysAhead = extractNumber($question) ?? 14;

        $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date >= CURDATE()" . $customerFilter . "
                ORDER BY expiry_date ASC LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute(array_merge([$daysAhead], $customerParams));
        $items = $s->fetchAll();
        if ($items) {
            $found = true;
            $context .= "Истекают в ближайшие {$daysAhead} дней:\n";
            foreach ($items as $i) {
                $date = date('d.m.Y', strtotime($i['expiry_date']));
                $custLabel = $i['customer'] ? " [{$i['customer']}]" : '';
                $context .= "- {$i['product_name']}{$custLabel}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}\n";
            }
        }

        // Заблокированные / просроченные
        $sql2 = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)" . $customerFilter . "
                ORDER BY expiry_date ASC LIMIT 15";
        $s2 = $pdo->prepare($sql2);
        $s2->execute($customerParams);
        $blocked = $s2->fetchAll();
        if ($blocked) {
            $found = true;
            $context .= "\nЗаблокированные / просроченные:\n";
            foreach ($blocked as $b) {
                $date = date('d.m.Y', strtotime($b['expiry_date']));
                $reason = $b['block_reason'] ?: $b['expiry_status'];
                $custLabel = $b['customer'] ? " [{$b['customer']}]" : '';
                $context .= "- {$b['product_name']}{$custLabel}: {$date}, {$b['quantity']} шт., статус: {$reason}\n";
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

// Поиск по графику доставок / ресторанам
function lookupSchedule($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $schedKeywords = ['график','доставк','ресторан','рестор','адрес','когда доставк','какой день','расписан'];
    $isSchedQ = false;
    foreach ($schedKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isSchedQ = true; break; }
    }
    if (!$isSchedQ) return '';

    $dayNames = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота'];
    $context = "\n== ГРАФИК ДОСТАВОК ==\n";

    // Ищем номер ресторана
    $restNum = null;
    if (preg_match('/(?:ресторан|рест|#)\s*(\d+)/iu', $question, $m)) {
        $restNum = intval($m[1]);
    } elseif (preg_match('/\b(\d{1,3})\b/', $question, $m)) {
        // Одно-трёхзначное число может быть номером ресторана
        $num = intval($m[1]);
        if ($num >= 1 && $num <= 200) $restNum = $num;
    }

    if ($restNum) {
        $s = $pdo->prepare("SELECT r.id, r.number, r.address, r.region FROM restaurants r WHERE r.number = ? AND r.active = 1 LIMIT 1");
        $s->execute([$restNum]);
        $rest = $s->fetch();
        if ($rest) {
            $context .= "Ресторан #{$rest['number']} — {$rest['address']} ({$rest['region']})\n";
            $s2 = $pdo->prepare("SELECT day_of_week, delivery_time FROM delivery_schedule WHERE restaurant_id = ? AND delivery_time IS NOT NULL ORDER BY day_of_week");
            $s2->execute([$rest['id']]);
            $sched = $s2->fetchAll();
            if ($sched) {
                $context .= "Доставки:\n";
                foreach ($sched as $sc) {
                    $context .= "  {$dayNames[$sc['day_of_week']]}: {$sc['delivery_time']}\n";
                }
            } else {
                $context .= "Доставки не назначены\n";
            }
            return $context;
        }
    }

    // Ищем по адресу
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $addrTerms = [];
    $stopSched = ['график','доставк','доставки','ресторан','какой','день','когда','расписан','покажи','адрес'];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 3 && !in_array($w, $stopSched) && !is_numeric($w)) $addrTerms[] = $w;
    }

    if (!empty($addrTerms)) {
        foreach ($addrTerms as $term) {
            $s = $pdo->prepare("SELECT r.id, r.number, r.address, r.region FROM restaurants r WHERE (r.address LIKE ? OR r.city LIKE ?) AND r.active = 1 LIMIT 5");
            $s->execute(["%{$term}%", "%{$term}%"]);
            $rests = $s->fetchAll();
            foreach ($rests as $rest) {
                $context .= "\nРесторан #{$rest['number']} — {$rest['address']} ({$rest['region']})\n";
                $s2 = $pdo->prepare("SELECT day_of_week, delivery_time FROM delivery_schedule WHERE restaurant_id = ? AND delivery_time IS NOT NULL ORDER BY day_of_week");
                $s2->execute([$rest['id']]);
                $sched = $s2->fetchAll();
                if ($sched) {
                    foreach ($sched as $sc) {
                        $context .= "  {$dayNames[$sc['day_of_week']]}: {$sc['delivery_time']}\n";
                    }
                }
            }
            if (!empty($rests)) return $context;
        }
    }

    // Общая сводка
    $s = $pdo->prepare("SELECT ds.day_of_week, COUNT(*) as cnt FROM delivery_schedule ds JOIN restaurants r ON r.id = ds.restaurant_id AND r.active = 1 WHERE ds.delivery_time IS NOT NULL GROUP BY ds.day_of_week ORDER BY ds.day_of_week");
    $s->execute();
    $summary = $s->fetchAll();
    if ($summary) {
        $context .= "Доставки по дням недели:\n";
        foreach ($summary as $row) {
            $context .= "  {$dayNames[$row['day_of_week']]}: {$row['cnt']} ресторанов\n";
        }
    }
    return $context;
}

// Поиск по ожидающим поставкам
function lookupDeliveries($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $delivKeywords = ['поставк','ожида','приход','приёмк','приемк','привез','в пути','просроч','задерж',
        'когда приед','когда будет','когда приве','заказан','доставк','ожидаем','едет','везут'];
    $isDelivQ = false;
    foreach ($delivKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isDelivQ = true; break; }
    }
    if (!$isDelivQ) return '';

    // Извлекаем поисковые слова для товара (убираем стоп-слова)
    $stopWords = ['когда','приедет','приедут','привезут','привезёт','привезет','будет','будут','поставка','поставки','ожидает',
        'ожидается','приход','сколько','какой','какая','какие','заказ','заказано','нужно','есть',
        'пришло','привезли','уже','ещё','еще','скоро','ожидаем','завтра','послезавтра','сегодня',
        'товар','товары','товаров','позиции','позиций','продукт','продукты','продуктов',
        'понедельник','вторник','среда','четверг','пятница','суббота','воскресенье'];
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $searchTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
            $searchTerms[] = stemRu($w);
        }
    }

    // Парсим дату из вопроса (завтра, послезавтра, день недели)
    $filterDate = null;
    if (mb_strpos($q, 'сегодня') !== false) {
        $filterDate = date('Y-m-d');
    } elseif (mb_strpos($q, 'послезавтра') !== false) {
        $filterDate = date('Y-m-d', strtotime('+2 days'));
    } elseif (mb_strpos($q, 'завтра') !== false) {
        $filterDate = date('Y-m-d', strtotime('+1 day'));
    } else {
        $dayMap = ['понедельник'=>1,'вторник'=>2,'среду'=>3,'среда'=>3,'четверг'=>4,'пятницу'=>5,'пятница'=>5,'субботу'=>6,'суббота'=>6,'воскресенье'=>7];
        foreach ($dayMap as $dayName => $dayNum) {
            if (mb_strpos($q, $dayName) !== false) {
                $today = date('N'); // 1=пн, 7=вс
                $diff = $dayNum - $today;
                if ($diff <= 0) $diff += 7;
                $filterDate = date('Y-m-d', strtotime("+{$diff} days"));
                break;
            }
        }
    }

    // Загружаем ожидающие заказы
    $sql = "SELECT o.id, o.supplier, o.delivery_date, o.created_at, o.created_by,
                   DATEDIFF(CURDATE(), o.delivery_date) as days_overdue
            FROM orders o WHERE o.received_at IS NULL AND o.delivery_date IS NOT NULL";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    if ($filterDate) { $sql .= " AND o.delivery_date = ?"; $params[] = $filterDate; }
    $sql .= " ORDER BY o.delivery_date ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) return "\n== ПОСТАВКИ ==\nОжидающих поставок нет.\n";

    // Если есть поисковые слова — ищем конкретный товар в позициях заказов
    $hasProductSearch = !empty($searchTerms);
    $orderIds = array_column($orders, 'id');

    if ($hasProductSearch && $orderIds) {
        // Сначала проверяем: совпадают ли поисковые слова с именем поставщика
        $supplierMatchedOrders = [];
        foreach ($orders as $o) {
            $supplierLower = mb_strtolower($o['supplier']);
            $supplierStemmed = stemRu($supplierLower);
            foreach ($searchTerms as $term) {
                if (mb_strpos($supplierLower, $term) !== false || mb_strpos($supplierStemmed, $term) !== false) {
                    $supplierMatchedOrders[] = $o['id'];
                    break;
                }
            }
        }

        // Загружаем позиции всех ожидающих заказов
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $s2 = $pdo->prepare("SELECT oi.order_id, oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box,
                                    COALESCE(p.unit_of_measure, 'шт') as uom
                             FROM order_items oi LEFT JOIN products p ON p.sku = oi.sku
                             WHERE oi.order_id IN ({$placeholders})");
        $s2->execute($orderIds);
        $allItems = $s2->fetchAll();

        // Если поставщик найден — показываем все товары его заказов
        if ($supplierMatchedOrders) {
            $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику) ==\n";
            foreach ($orders as $o) {
                if (!in_array($o['id'], $supplierMatchedOrders)) continue;
                $delivery = date('d.m.Y', strtotime($o['delivery_date']));
                $overdue = $o['days_overdue'];
                $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
                $orderUrl = "https://supply-department.online/order?orderId={$o['id']}&mode=view";
                $context .= "\n<b>{$o['supplier']}</b> — приход {$delivery} ({$status}) (<a href=\"{$orderUrl}\">открыть</a>)\n";
                $orderItems = array_filter($allItems, fn($item) => $item['order_id'] == $o['id']);
                $totalBoxes = 0;
                foreach ($orderItems as $item) {
                    $u = $item['uom'] ?? 'шт';
                    $itemUom = getUomLabel($u);
                    $pcs = $item['qty_per_box'] ? $item['qty_boxes'] * $item['qty_per_box'] : '';
                    $pcsInfo = $pcs ? " ({$pcs} {$itemUom})" : '';
                    $context .= "  • {$item['name']}: {$item['qty_boxes']} кор.{$pcsInfo}\n";
                    $totalBoxes += $item['qty_boxes'];
                }
                $context .= "  <b>Итого: {$totalBoxes} кор., " . count($orderItems) . " поз.</b>\n";
            }
            return $context;
        }

        // Ищем совпадения по товару — товар должен содержать ВСЕ поисковые слова
        $matchedItems = [];
        foreach ($allItems as $item) {
            $haystack = mb_strtolower(($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''));
            $matchedAll = true;
            foreach ($searchTerms as $term) {
                if (mb_strpos($haystack, $term) === false) { $matchedAll = false; break; }
            }
            if ($matchedAll) {
                $matchedItems[] = $item;
            }
        }
        // Если по всем словам ничего — ищем хотя бы по одному
        if (empty($matchedItems)) {
            foreach ($allItems as $item) {
                $haystack = mb_strtolower(($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''));
                foreach ($searchTerms as $term) {
                    if (mb_strpos($haystack, $term) !== false) { $matchedItems[] = $item; break; }
                }
            }
        }

        if ($matchedItems) {
            // Группируем по заказу
            $byOrder = [];
            foreach ($matchedItems as $mi) {
                $byOrder[$mi['order_id']][] = $mi;
            }

            $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ (найденные товары) ==\n";
            foreach ($orders as $o) {
                if (!isset($byOrder[$o['id']])) continue;
                $delivery = date('d.m.Y', strtotime($o['delivery_date']));
                $overdue = $o['days_overdue'];
                $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
                $orderUrl = "https://supply-department.online/order?orderId={$o['id']}&mode=view";
                $context .= "\n{$o['supplier']} — приход {$delivery} ({$status}) (<a href=\"{$orderUrl}\">открыть</a>)\n";
                foreach ($byOrder[$o['id']] as $item) {
                    $u = $item['uom'] ?? 'шт';
                    $itemUom = getUomLabel($u);
                    $pcs = $item['qty_per_box'] ? $item['qty_boxes'] * $item['qty_per_box'] : '';
                    $pcsInfo = $pcs ? " ({$pcs} {$itemUom})" : '';
                    $context .= "  • {$item['sku']} {$item['name']}: {$item['qty_boxes']} кор.{$pcsInfo}\n";
                }
            }
            return $context;
        } else {
            // Искали конкретный товар, но не нашли ни в одном заказе
            $searchStr = implode(' ', $searchTerms);
            return "\n== ОЖИДАЮЩИЕ ПОСТАВКИ ==\nТовар «{$searchStr}» НЕ НАЙДЕН ни в одном ожидающем заказе. Всего ожидается " . count($orders) . " поставок.\n";
        }
    }

    // Общий список поставок (без фильтра по товару) — одним запросом
    $orderIds = array_column($orders, 'id');
    $boxesByOrder = [];
    if ($orderIds) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $s3 = $pdo->prepare("SELECT order_id, SUM(qty_boxes) as boxes, COUNT(*) as items FROM order_items WHERE order_id IN ({$ph}) GROUP BY order_id");
        $s3->execute($orderIds);
        foreach ($s3->fetchAll() as $r) $boxesByOrder[$r['order_id']] = $r;
    }

    $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ ==\n";
    foreach ($orders as $o) {
        $delivery = date('d.m.Y', strtotime($o['delivery_date']));
        $overdue = $o['days_overdue'];
        $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
        $info = $boxesByOrder[$o['id']] ?? ['boxes' => 0, 'items' => 0];
        $orderUrl = "https://supply-department.online/order?orderId={$o['id']}&mode=view";
        $context .= "- {$o['supplier']}: приход {$delivery} ({$status}), {$info['boxes']} кор., {$info['items']} поз. (<a href=\"{$orderUrl}\">открыть</a>)\n";
    }
    return $context;
}

// Поиск по планам
function lookupPlans($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $planKeywords = ['план','планир','период','частот','как часто','интервал','когда заказ'];
    $isPlanQ = false;
    foreach ($planKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isPlanQ = true; break; }
    }
    if (!$isPlanQ) return '';

    $sql = "SELECT supplier, period_type, period_count, note, created_by, updated_at
            FROM plans WHERE 1=1";
    $params = [];
    if ($entity) { $sql .= " AND legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY updated_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $plans = $s->fetchAll();

    if (!$plans) return "\n== ПЛАНЫ ==\nПланов поставок нет.\n";

    $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
    $context = "\n== ПЛАНЫ ПОСТАВОК ==\n";
    foreach ($plans as $p) {
        $period = ($p['period_count'] ?? 3) . ' ' . ($periodLabels[$p['period_type']] ?? $p['period_type']);
        $context .= "- {$p['supplier']}: каждые {$period}";
        if ($p['note']) $context .= " ({$p['note']})";
        $context .= "\n";
    }
    return $context;
}

// Поиск цен с НДС
function lookupPrices($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $priceKeywords = ['цен','стоимост','прайс','сколько стоит','ндс','налог','vat'];
    $isPriceQ = false;
    foreach ($priceKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isPriceQ = true; break; }
    }
    if (!$isPriceQ) return '';

    // Ищем товар в вопросе
    $skus = [];
    if (preg_match_all('/\b(\d{4,})\b/', $question, $m)) $skus = $m[1];

    $searchTerms = [];
    if (preg_match_all('/[«""]([^»""]+)[»""]/', $question, $m)) $searchTerms = $m[1];

    if (empty($skus) && empty($searchTerms)) {
        $stopPrice = ['цен','цена','цены','стоимост','прайс','сколько','стоит','ндс','налог','покажи','какая','какой','какие'];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3 && !in_array($w, $stopPrice) && !is_numeric($w)) {
                $searchTerms[] = stemRu($w);
            }
        }
    }

    if (empty($skus) && empty($searchTerms)) return '';

    $context = "\n== ЦЕНЫ ==\n";
    $found = false;
    $eFilter = $entity ? " AND pp.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    foreach ($skus as $sku) {
        $sql = "SELECT pp.sku, p.name, pp.price, pp.vat_rate, pp.currency, pp.unit_type, pp.supplier
                FROM product_prices pp
                LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = pp.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = pp.legal_entity COLLATE utf8mb4_general_ci
                WHERE pp.sku = ?" . $eFilter . " LIMIT 5";
        $s = $pdo->prepare($sql); $s->execute(array_merge([$sku], $eParams));
        foreach ($s->fetchAll() as $row) {
            $found = true;
            $vat = $row['vat_rate'] ?? 20;
            $priceWithVat = round($row['price'] * (1 + $vat / 100), 2);
            $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
            $unit = $unitLabels[$row['unit_type']] ?? $row['unit_type'];
            $context .= "- {$row['sku']} {$row['name']}: {$row['price']} {$row['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$row['currency']} — {$row['supplier']}\n";
        }
    }

    foreach ($searchTerms as $term) {
        $sql = "SELECT pp.sku, p.name, pp.price, pp.vat_rate, pp.currency, pp.unit_type, pp.supplier
                FROM product_prices pp
                LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = pp.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = pp.legal_entity COLLATE utf8mb4_general_ci
                WHERE p.name LIKE ?" . $eFilter . " LIMIT 10";
        $s = $pdo->prepare($sql); $s->execute(array_merge(["%{$term}%"], $eParams));
        foreach ($s->fetchAll() as $row) {
            $found = true;
            $vat = $row['vat_rate'] ?? 20;
            $priceWithVat = round($row['price'] * (1 + $vat / 100), 2);
            $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
            $unit = $unitLabels[$row['unit_type']] ?? $row['unit_type'];
            $context .= "- {$row['sku']} {$row['name']}: {$row['price']} {$row['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$row['currency']} — {$row['supplier']}\n";
        }
    }

    return $found ? $context : '';
}

// Поиск по карточкам товаров (как страница «Поиск карточек»)
function lookupCards($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем — спрашивают ли про карточку
    $cardKeywords = ['карточ','артикул','найди товар','найди карточ','поиск товар','что за товар',
        'какой товар','что это за','номер товар','код товар','аналог','замен','чем замени'];
    $isCardQ = matchesKeywords($q, $cardKeywords);

    // Также ищем если в вопросе есть артикул (5+ цифр)
    $hasArticle = preg_match('/\b\d{5,}(?:-\d+)?\b/', $question);

    if (!$isCardQ && !$hasArticle) return '';

    // Нормализация (как на фронтенде)
    $normalize = function($s) {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/[^а-яa-z0-9]/u', '', $s);
    };

    // Извлекаем поисковые слова
    $stopWords = ['карточка','карточки','карточку','артикул','артикула','найди','покажи','какой','какая',
        'товар','товара','товары','что','это','номер','код','поиск','где','как',
        'аналог','аналоги','аналога','аналогов','замена','замены','замену','заменить','чем','заменили'];
    $searchTerms = [];
    // Сначала артикулы
    if (preg_match_all('/\b(\d{5,}(?:-\d+)?)\b/', $question, $m)) {
        $searchTerms = $m[1];
    }
    // Потом текстовые слова
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $textTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
            $textTerms[] = stemRu($w);
        }
    }

    if (empty($searchTerms) && empty($textTerms)) return '';

    // Загружаем карточки
    $s = $pdo->prepare("SELECT id, name, analogs FROM cards ORDER BY name");
    $s->execute();
    $allCards = $s->fetchAll();
    if (!$allCards) return '';

    $results = [];

    // Поиск по артикулу
    foreach ($searchTerms as $article) {
        foreach ($allCards as $c) {
            if ($c['id'] === $article) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по артикулу'];
                break;
            }
            // Проверяем аналоги
            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));
            if (in_array($article, $analogs)) {
                $results[$c['id']] = ['card' => $c, 'reason' => "найдено по аналогу (арт. {$article})"];
            }
        }
    }

    // Текстовый поиск по названию
    if (empty($results) && !empty($textTerms)) {
        $searchQuery = implode(' ', $textTerms);
        $normQuery = $normalize($searchQuery);

        foreach ($allCards as $c) {
            $normId = $normalize($c['id']);
            $normName = $normalize($c['name']);
            $normFull = $normId . $normName;

            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));

            // Точное совпадение артикула
            if ($normId === $normQuery) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'точное совпадение'];
                continue;
            }
            // Совпадение по артикулу + названию
            if (mb_strpos($normFull, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по названию'];
                continue;
            }
            // Частичное совпадение артикула
            if (mb_strpos($normId, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'часть артикула'];
                continue;
            }
            // Совпадение по аналогу
            foreach ($analogs as $a) {
                if (mb_strpos($normalize($a), $normQuery) !== false) {
                    $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по аналогу'];
                    break;
                }
            }
            // Частичное совпадение названия
            if (mb_strpos($normName, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по названию'];
            }

            if (count($results) >= 10) break; // Ограничиваем результаты
        }
    }

    if (empty($results)) return '';

    $context = "\n== КАРТОЧКИ ТОВАРОВ ==\n";
    foreach ($results as $r) {
        $c = $r['card'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));
        $analogStr = !empty($analogs) ? ' | аналоги: ' . implode(', ', array_slice($analogs, 0, 5)) : '';
        $context .= "- {$c['id']} {$c['name']}{$analogStr} ({$r['reason']})\n";
    }

    return $context;
}

// ═══ Быстрые ответы на простые фразы ═══

function getQuickReply($text, $user) {
    $t = mb_strtolower(trim($text));
    $name = explode(' ', $user['name'])[1] ?? $user['name']; // Имя (без фамилии)

    // Приветствия
    if (preg_match('/^(привет|здравствуй|добр(ый|ое|ая)|хай|хей|hello|hi|йо|салам|хелло)\b/u', $t)) {
        $hour = (int)(new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('H');
        $greeting = $hour < 12 ? 'Доброе утро' : ($hour < 18 ? 'Добрый день' : 'Добрый вечер');
        return "{$greeting}, <b>{$name}</b>! Чем могу помочь?\n\nЗадайте вопрос или выберите раздел в меню.";
    }

    // Благодарности
    if (preg_match('/^(спасибо|благодар|спс|thx|thanks|мерси)\b/u', $t)) {
        return "Пожалуйста! Если ещё что-то нужно — спрашивайте.";
    }

    // Прощания
    if (preg_match('/^(пока|до свидания|до встречи|бай|bye|удачи|всего доброго)\b/u', $t)) {
        return "До связи, <b>{$name}</b>! Хорошего дня.";
    }

    // Как дела
    if (preg_match('/^как (дела|жизнь|поживаешь|сам)/u', $t)) {
        return "Всё работает! Готов помочь с данными. Задайте вопрос или откройте меню.";
    }

    return null;
}

// ═══ Память разговора (последний контекст) ═══

function saveLastContext($userName, $question, $entity) {
    global $pdo;
    try {
        $pdo->prepare("UPDATE telegram_settings SET last_question = ?, last_entity = ?, last_question_at = NOW() WHERE user_name = ?")
            ->execute([mb_substr($question, 0, 500), $entity, $userName]);
    } catch (Exception $e) { /* колонка может не существовать */ }
}

function getLastContext($userName) {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT last_question, last_entity, last_question_at FROM telegram_settings WHERE user_name = ?");
        $s->execute([$userName]);
        $row = $s->fetch();
        if (!$row || !$row['last_question']) return null;
        // Контекст актуален 10 минут
        $age = time() - strtotime($row['last_question_at']);
        if ($age > 600) return null;
        return $row;
    } catch (Exception $e) { return null; }
}

function isFollowUp($text) {
    $t = mb_strtolower(trim($text));
    // Короткие уточняющие фразы
    return preg_match('/^(а (по|для|у|в|на|что|как)|а если|ещё|еще|и (по|для|ещё|еще)|то же|тоже самое|аналогично|по (бк|вм|пс|бургер|воглия|пицца)|для (бк|вм|пс|бургер|воглия|пицца))/u', $t)
        && mb_strlen($t) < 100;
}

function selectRelevantLookups($q) {
    // Всегда запускаем поиск товара и карточек — они релевантны почти для любого вопроса
    $lookups = ['lookupProduct', 'lookupCards'];

    // Заказы
    if (preg_match('/заказ|состав|позиц|что заказ|заказыв|отправ/u', $q)) {
        $lookups[] = 'lookupOrders';
    }
    // Остатки по дням
    if (preg_match('/остат|запас|дней|заканч|кончает|мало|критич|нулев|ноль/u', $q)) {
        $lookups[] = 'lookupStockDays';
    }
    // Сроки годности
    if (preg_match('/срок|годн|истек|просроч|маллинг|блокир|хранен|expir/u', $q)) {
        $lookups[] = 'lookupShelfLife';
    }
    // Поставщики
    if (preg_match('/поставщик|контакт|телефон|email|whatsapp|telegram|менедж|dlt|срок доставк/u', $q)) {
        $lookups[] = 'lookupSupplier';
    }
    // График доставок
    if (preg_match('/график|расписан|ресторан|доставк.*рестор|рестор.*доставк|какой день/u', $q)) {
        $lookups[] = 'lookupSchedule';
    }
    // Поставки (ожидаемые)
    if (preg_match('/поставк|приед|привез|когда.*прие|ожидае|приход|доставк|привоз/u', $q)) {
        $lookups[] = 'lookupDeliveries';
    }
    // Планы
    if (preg_match('/план|периодич|частот|как часто|график заказ/u', $q)) {
        $lookups[] = 'lookupPlans';
    }
    // Цены
    if (preg_match('/цен|стоим|прайс|ндс|сколько стоит|почём|по чём/u', $q)) {
        $lookups[] = 'lookupPrices';
    }

    // Если не нашли специфических тем — запускаем все (общий вопрос)
    if (count($lookups) <= 2) {
        $lookups = ['lookupProduct', 'lookupCards', 'lookupOrders', 'lookupStockDays',
                     'lookupShelfLife', 'lookupSupplier', 'lookupSchedule',
                     'lookupDeliveries', 'lookupPlans', 'lookupPrices'];
    }

    return array_unique($lookups);
}

function handleFreeText($chatId, $text, $user) {
    global $GEMINI_API_KEY, $GROQ_API_KEY, $DEEPSEEK_API_KEY, $OPENROUTER_API_KEY;

    // Без ключа — подсказка по командам
    if (!$GEMINI_API_KEY && !$GROQ_API_KEY && !$DEEPSEEK_API_KEY && !$OPENROUTER_API_KEY) {
        sendMessage($chatId, "Доступные команды:\n/orders — заказы за 7 дней\n/stock — низкие остатки\n/consumption — топ расхода\n/prices — изменения цен\n/psc — протоколы\n/settings — настройки уведомлений");
        return;
    }

    // Проверяем, не follow-up ли это (уточняющий вопрос к предыдущему)
    $effectiveText = $text;
    if (isFollowUp($text)) {
        $lastCtx = getLastContext($user['name']);
        if ($lastCtx && $lastCtx['last_question']) {
            // Объединяем: берём предмет из прошлого вопроса + уточнение из текущего
            $effectiveText = $lastCtx['last_question'] . ' ' . $text;
            error_log("Bot: follow-up detected. Combined: {$effectiveText}");
        }
    }

    // Отправляем «думает...» сообщение
    sendTyping($chatId);
    $thinkMsg = sendMessageAndGetId($chatId, "🔍 Ищу данные...");

    $entity = getUserEntity($user);
    $context = gatherContext($user);

    // Поиск данных по вопросу (каждый блок в try-catch чтобы одна ошибка не сломала весь ответ)
    $lookupContext = '';
    // Определяем какие lookup-ы нужны по ключевым словам в вопросе
    $q = mb_strtolower($effectiveText);
    $lookups = selectRelevantLookups($q);
    foreach ($lookups as $fn) {
        try {
            $result = $fn($effectiveText, $entity);
            if ($result) $lookupContext .= $result;
        } catch (Exception $e) {
            error_log("Bot {$fn} error: " . $e->getMessage());
        }
    }

    // Сохраняем контекст для возможных follow-up вопросов
    saveLastContext($user['name'], $effectiveText, $entity);

    // Ограничиваем размер контекста чтобы ИИ не путался
    // Если lookup данных много — урезаем общий контекст (gatherContext), оставляя точечные данные
    $totalLen = mb_strlen($context) + mb_strlen($lookupContext);
    if ($totalLen > 12000) {
        // Lookup-данные приоритетнее — обрезаем общий контекст
        $maxGeneral = max(2000, 12000 - mb_strlen($lookupContext));
        $context = mb_substr($context, 0, $maxGeneral) . "\n…(сокращено)\n";
    }
    if (mb_strlen($lookupContext) > 10000) {
        $lookupContext = mb_substr($lookupContext, 0, 9500) . "\n…(данных больше, показаны основные)\n";
    }
    $context .= $lookupContext;

    // Обновляем статус — теперь думает ИИ
    if ($thinkMsg) {
        editMessage($chatId, $thinkMsg, "🤖 Формирую ответ...");
    }
    sendTyping($chatId);

    $answer = null;
    $aiStart = microtime(true);
    try {
        $answer = askAI($effectiveText, $context);
    } catch (Exception $e) {
        error_log("Bot askAI error: " . $e->getMessage());
    }
    $aiTime = round(microtime(true) - $aiStart, 1);
    error_log("Bot: AI response in {$aiTime}s, context=" . strlen($context) . " bytes, answer=" . ($answer ? strlen($answer) . ' bytes' : 'null'));

    // Удаляем сообщение-статус
    if ($thinkMsg) {
        deleteMessage($chatId, $thinkMsg);
    }

    $menuMarkup = ['inline_keyboard' => [[['text' => '📖 Меню', 'callback_data' => 'cmd_menu']]]];
    if ($answer) {
        // Telegram ограничивает 4096 символов
        if (mb_strlen($answer) > 4000) {
            $answer = mb_substr($answer, 0, 3990) . "\n\n…";
        }
        sendMessage($chatId, $answer, $menuMarkup);
    } else {
        // Если ИИ недоступен — попробуем дать прямой ответ из собранных данных
        $directAnswer = buildDirectAnswer($lookupContext);
        if ($directAnswer) {
            sendMessage($chatId, $directAnswer, $menuMarkup);
        } else {
            error_log("Bot: askAI returned null. GROQ_KEY len=" . strlen($GLOBALS['GROQ_API_KEY'] ?? '') . " context len=" . strlen($context));
            // Формируем подсказку
            $hint = "Не удалось обработать запрос.\n\nПопробуйте уточнить вопрос:\n";
            $hint .= "• Укажите <b>артикул</b> или <b>полное название</b> товара\n";
            $hint .= "• Добавьте контекст: «остаток», «цена», «когда приедет», «аналоги»\n";
            $hint .= "• Или используйте кнопки меню ниже";
            sendMessage($chatId, $hint, ['inline_keyboard' => [
                [['text' => '🔍 Поиск карточек', 'callback_data' => 'cmd_cards']],
                [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']],
            ]]);
        }
    }
}

// Прямой ответ из данных, когда ИИ недоступен
function buildDirectAnswer($context) {
    // Извлекаем секции с полезными данными (== НАЗВАНИЕ ==)
    $sections = [];
    if (preg_match_all('/\n== (.+?) ==\n([\s\S]*?)(?=\n== |\z)/', $context, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $title = trim($m[1]);
            $body = trim($m[2]);
            if ($body) {
                $sections[] = ['title' => $title, 'body' => $body];
            }
        }
    }

    if (empty($sections)) return null;

    // Форматируем для Telegram
    $text = "📋 <i>Данные из системы:</i>\n\n";
    foreach ($sections as $s) {
        $text .= "<b>{$s['title']}</b>\n";
        $body = $s['body'];
        // Убираем лишние отступы
        $body = preg_replace('/^    /m', '  ', $body);
        $body = preg_replace('/^  • /m', '• ', $body);
        $body = preg_replace('/^  - /m', '• ', $body);
        $body = preg_replace('/^- /m', '• ', $body);
        $text .= $body . "\n\n";
    }

    $text = trim($text);

    // Telegram ограничивает 4096 символов
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…<i>обрезано</i>";
    }

    return $text;
}

// ═══ Settings UI ═══

function getMenuButtons($user) {
    $entity = $user ? getUserEntity($user) : null;
    $buttons = [
        [['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'], ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock']],
        [['text' => '📊 Анализ', 'callback_data' => 'cmd_analysis'], ['text' => '📊 Расход', 'callback_data' => 'cmd_consumption']],
        [['text' => '💰 Цены', 'callback_data' => 'cmd_prices'], ['text' => '📋 Протоколы', 'callback_data' => 'cmd_psc']],
        [['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries'], ['text' => '📅 Планы', 'callback_data' => 'cmd_plans']],
        [['text' => '🗓 График доставок', 'callback_data' => 'cmd_schedule'], ['text' => '🔍 Карточки', 'callback_data' => 'cmd_cards']],
    ];
    if ($user && count($user['legal_entities']) > 1) {
        $short = $entity ? getEntityShort($entity) : '?';
        $buttons[] = [['text' => "🏢 Юрлицо ({$short})", 'callback_data' => 'cmd_entity'], ['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']];
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
    $lines[] = "━━━━━━━━━━━━━━━━━";
    if ($entity) $lines[] = "🏢 {$short}  ·  📅 {$today}, {$dayName}";
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
    $buttons[] = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];

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
            case 'schedule': cmdSchedule($chatId, $user, $msgId); break;
            case 'entity':
                $entities = $user['legal_entities'];
                if (count($entities) <= 1) {
                    $current = getUserEntity($user);
                    $btns = [[['text' => '📖 Меню', 'callback_data' => 'cmd_menu']]];
                    editMessage($chatId, $msgId, "🏢 Вам доступно одно юрлицо: <b>{$current}</b>", ['inline_keyboard' => $btns]);
                } else {
                    $current = getUserEntity($user);
                    $btns = [];
                    foreach ($entities as $idx => $le) {
                        $mark = ($le === $current) ? '✅ ' : '';
                        $short = getEntityShort($le);
                        $btns[] = [['text' => "{$mark}{$short} — {$le}", 'callback_data' => "entity_{$idx}"]];
                    }
                    $btns[] = [['text' => '📖 Меню', 'callback_data' => 'cmd_menu']];
                    editMessage($chatId, $msgId, "🏢 <b>Выбор юрлица</b>\n\nТекущее: <b>{$current}</b>\n\nНажмите для переключения:", ['inline_keyboard' => $btns]);
                }
                break;
            case 'cards': cmdCards($chatId, $user, $msgId); break;
            case 'cards_exit':
                setUserMode($user['name'], null);
                editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
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
            $menuBtns = ['inline_keyboard' => [[['text' => '📖 Меню', 'callback_data' => 'cmd_menu']]]];
            editMessage($chatId, $msgId, "✅ Юрлицо переключено на <b>{$entities[$idx]}</b>\n\nТеперь все данные показываются для этого юрлица.", $menuBtns);
        } else {
            answerCallback($cb['id'], 'Ошибка выбора');
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

// /start
if ($text === '/start') {
    $user = getUser($chatId);
    if ($user) {
        $greeting = "Привет, <b>{$user['name']}</b>! 👋\n\n";
        sendMessage($chatId, $greeting . getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
    } else {
        // Генерируем токен и даём кнопку для авторизации через сайт
        $token = bin2hex(random_bytes(16));
        $tgUsername = $msg['from']['username'] ?? null;
        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE telegram_chat_id = ?")->execute([$chatId]);
        $pdo->prepare("INSERT INTO telegram_link_tokens (token, telegram_chat_id, telegram_username, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))")
            ->execute([$token, $chatId, $tgUsername]);
        $linkUrl = "https://supply-department.online/telegram-link?token={$token}";
        $keyboard = ['inline_keyboard' => [
            [['text' => '🔐 Войти через сайт', 'url' => $linkUrl]],
        ]];
        sendMessage($chatId, "👋 <b>Supply Department</b>\n\nЯ бот отдела закупок.\nДля доступа нужно привязать Telegram к вашему аккаунту на сайте.\n\nНажмите кнопку ниже — откроется сайт, где нужно войти под своим логином.\n\n<i>Ссылка действительна 15 минут.</i>", $keyboard);
    }
    exit;
}

// /help или /menu
if ($text === '/help' || $text === '/menu') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    setUserMode($user['name'], null); // сброс режима
    $tips = "\n\n💡 <i>Примеры вопросов:</i>\n• Какой остаток молока?\n• Товары с запасом на 3 дня\n• Что скоро просрочится?\n• Когда доставка в ресторан 45?";
    sendMessage($chatId, getMenuText($user) . $tips, ['inline_keyboard' => getMenuButtons($user)]);
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

// Свободный текст — ответ на вопрос
$user = getUser($chatId);
if (!$user) {
    sendMessage($chatId, "🔒 Для доступа к боту нужно привязать Telegram к аккаунту.\n\nНажмите /start чтобы получить ссылку для входа.");
    exit;
}

// Быстрые ответы на приветствия и благодарности — без вызова ИИ
$quickReply = getQuickReply($text, $user);
if ($quickReply) {
    sendMessage($chatId, $quickReply, ['inline_keyboard' => [[['text' => '📖 Меню', 'callback_data' => 'cmd_menu']]]]);
    exit;
}

// Режим поиска карточек — все сообщения идут в поиск карточек
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
