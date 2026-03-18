<?php
/**
 * Cron: отправка уведомлений в Telegram
 * Запуск каждые 5 минут: php /var/www/bk-calc/api/cron_telegram.php
 */

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) exit;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$BOT_TOKEN) { echo "No TELEGRAM_BOT_TOKEN\n"; exit; }

$SITE_URL = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? '';
$OPENROUTER_API_KEY = $_ENV['OPENROUTER_API_KEY'] ?? '';

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function tgSend($chatId, $text, $disablePreview = false) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($disablePreview) $payload['disable_web_page_preview'] = true;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// ═══ AI для утренней сводки ═══

function askAIDigest($context) {
    global $GROQ_API_KEY, $OPENROUTER_API_KEY;

    $systemPrompt = <<<'PROMPT'
Ты — краткий аналитик отдела закупок Burger King в Беларуси.
На основе данных напиши 1-2 коротких предложения-инсайта (максимум 200 символов).
Фокус: на чём стоит сосредоточить внимание сегодня.
Примеры:
• «Молоко кончится завтра, а ближайшая поставка только в пятницу — стоит заказать срочно.»
• «3 просроченных поставки от Мираторга — нужно уточнить статус.»
• «Всё в порядке, критичных ситуаций нет.»
Отвечай ТОЛЬКО на русском, без эмодзи, без HTML-тегов. Одна мысль, без вступлений.
PROMPT;

    // Groq (быстрый, 1-3 сек)
    if ($GROQ_API_KEY) {
        $result = callAIDigest($systemPrompt, $context, 'groq', $GROQ_API_KEY);
        if ($result) return $result;
    }

    // OpenRouter (fallback)
    if ($OPENROUTER_API_KEY) {
        $result = callAIDigest($systemPrompt, $context, 'openrouter', $OPENROUTER_API_KEY);
        if ($result) return $result;
    }

    return null;
}

function callAIDigest($systemPrompt, $context, $provider, $apiKey) {
    global $SITE_URL;
    if ($provider === 'groq') {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $model = 'llama-3.3-70b-versatile';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    } else {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $model = 'meta-llama/llama-4-scout:free';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'HTTP-Referer: ' . $SITE_URL, 'X-Title: Supply Bot'];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $context],
        ],
        'max_tokens' => 256,
        'temperature' => 0.2,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        error_log("[cron_telegram] AI digest ({$provider}): HTTP {$httpCode}");
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }
    return $content ?: null;
}

// ═══ Дедупликация уведомлений ═══

function wasNotified($pdo, $type, $legalEntity, $chatId, $intervalSeconds) {
    try {
        $s = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type=? AND legal_entity=? AND chat_id=? AND sent_at > NOW() - INTERVAL ? SECOND LIMIT 1");
        $s->execute([$type, $legalEntity, $chatId, $intervalSeconds]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}

function logNotification($pdo, $type, $legalEntity, $chatId) {
    try {
        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id) VALUES (?,?,?)")
            ->execute([$type, $legalEntity, $chatId]);
    } catch (Exception $e) {}
}

$sent = 0;

// ═══ 1. Уведомления типа agreement_expiry → пользователям с psc_expiry=1 ═══
$notifications = $pdo->query("
    SELECT n.id, n.title, n.message, n.target_user, n.type
    FROM notifications n
    WHERE n.created_at > NOW() - INTERVAL 10 MINUTE
      AND n.type IN ('agreement_expiry')
    ORDER BY n.created_at DESC
")->fetchAll();

foreach ($notifications as $n) {
    $targetUser = $n['target_user'];
    if (!$targetUser) continue;

    // Проверить настройки Telegram
    $u = $pdo->prepare("
        SELECT u.telegram_chat_id, ts.psc_expiry
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.name = ? AND u.telegram_chat_id IS NOT NULL AND ts.psc_expiry = 1
    ");
    $u->execute([$targetUser]);
    $user = $u->fetch();
    if (!$user) continue;

    $text = "📋 <b>{$n['title']}</b>\n\n{$n['message']}";
    tgSend($user['telegram_chat_id'], $text);
    $sent++;
}

// ═══ 2. Ежедневная сводка (только в 9:00-9:05) ═══
$hour = (int)date('H');
$minute = (int)date('i');
if ($hour === 9 && $minute < 5) {
    // Получить всех пользователей с daily_summary=1
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.daily_summary = 1
    ")->fetchAll();

    foreach ($users as $user) {
        $today = date('Y-m-d');
        // Юрлица пользователя
        $le = $user['legal_entities'];
        $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        // Пользователь без привязки к юрлицам — пропускаем (не показываем чужие данные)
        if (empty($entities)) continue;
        $leFilter = '';
        $leParams = [];
        if (!empty($entities)) {
            $ph = implode(',', array_fill(0, count($entities), '?'));
            $leFilter = " AND legal_entity IN ({$ph})";
            $leParams = $entities;
        }

        // Заказы на сегодня (только по юрлицам пользователя)
        $s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date = ? AND received_at IS NULL" . $leFilter);
        $s->execute(array_merge([$today], $leParams));
        $orderCount = $s->fetchColumn();

        // Просроченные
        $s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date < ? AND received_at IS NULL" . $leFilter);
        $s->execute(array_merge([$today], $leParams));
        $overdueCount = $s->fetchColumn();

        // Истекающие ПСЦ
        $s = $pdo->prepare("SELECT COUNT(*) FROM price_agreements WHERE status = 'active' AND valid_to BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY" . $leFilter);
        $s->execute($leParams);
        $expiring = $s->fetchColumn();

        $text = "📊 <b>Сводка на " . date('d.m.Y') . "</b>\n\n";
        $text .= "📦 Поставки сегодня: <b>{$orderCount}</b>\n";
        if ($overdueCount > 0) $text .= "⚠️ Просроченных: <b>{$overdueCount}</b>\n";
        if ($expiring > 0) $text .= "📋 ПСЦ истекает (7 дн.): <b>{$expiring}</b>\n";
        if ($orderCount == 0 && $overdueCount == 0 && $expiring == 0) {
            $text .= "✅ Всё в порядке, активных задач нет";
        }

        // AI-инсайт: собираем контекст и просим AI подсказать на что обратить внимание
        try {
            $aiContext = "Сегодня: " . date('d.m.Y, l') . "\n";
            $aiContext .= "Поставки сегодня: {$orderCount}, просроченных: {$overdueCount}, ПСЦ истекает: {$expiring}.\n";

            // Товары с критическим запасом (≤3 дня)
            $critSql = "SELECT p.name, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) AS days_left, p.supplier
                        FROM analysis_data a
                        LEFT JOIN products p ON p.sku = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity = a.legal_entity COLLATE utf8mb4_general_ci
                        WHERE a.consumption > 0 AND a.stock > 0" . $leFilter . "
                        HAVING days_left <= 3 ORDER BY days_left ASC LIMIT 5";
            $s = $pdo->prepare($critSql);
            $s->execute($leParams);
            $critItems = $s->fetchAll();
            if ($critItems) {
                $aiContext .= "Товары с запасом ≤ 3 дня:\n";
                foreach ($critItems as $ci) {
                    $aiContext .= "- {$ci['name']}: {$ci['days_left']} дн. (поставщик: {$ci['supplier']})\n";
                }
            }

            // Ближайшие ожидаемые поставки
            $upSql = "SELECT supplier, delivery_date FROM orders WHERE delivery_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY AND received_at IS NULL" . $leFilter . " ORDER BY delivery_date LIMIT 5";
            $s = $pdo->prepare($upSql);
            $s->execute($leParams);
            $upcoming = $s->fetchAll();
            if ($upcoming) {
                $dayNames = [1=>'пн',2=>'вт',3=>'ср',4=>'чт',5=>'пт',6=>'сб',7=>'вс'];
                $aiContext .= "Ближайшие поставки:\n";
                foreach ($upcoming as $u) {
                    $dow = $dayNames[(int)date('N', strtotime($u['delivery_date']))] ?? '';
                    $aiContext .= "- {$u['supplier']}: " . date('d.m', strtotime($u['delivery_date'])) . " ({$dow})\n";
                }
            }

            // Просроченные — кто именно
            if ($overdueCount > 0) {
                $ovSql = "SELECT supplier, delivery_date, DATEDIFF(CURDATE(), delivery_date) as days FROM orders WHERE delivery_date < CURDATE() AND received_at IS NULL" . $leFilter . " ORDER BY delivery_date LIMIT 5";
                $s = $pdo->prepare($ovSql);
                $s->execute($leParams);
                $overdue = $s->fetchAll();
                if ($overdue) {
                    $aiContext .= "Просроченные поставки:\n";
                    foreach ($overdue as $ov) {
                        $aiContext .= "- {$ov['supplier']}: ожидалась " . date('d.m', strtotime($ov['delivery_date'])) . " (просрочена на {$ov['days']} дн.)\n";
                    }
                }
            }

            $aiInsight = askAIDigest($aiContext);
            if ($aiInsight) {
                $text .= "\n💡 <i>{$aiInsight}</i>";
            }
        } catch (Exception $e) {
            error_log("[cron_telegram] AI digest error: " . $e->getMessage());
        }

        tgSend($user['telegram_chat_id'], $text);
        $sent++;
    }
}

// ═══ 3. Изменения цен (проверить price_history за последние 10 минут) ═══
$recentPrices = $pdo->query("
    SELECT COUNT(*) as cnt, changed_by, legal_entity
    FROM price_history
    WHERE changed_at > NOW() - INTERVAL 10 MINUTE
    GROUP BY changed_by, legal_entity
")->fetchAll();

if (!empty($recentPrices)) {
    // Пользователи с price_changed=1
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.price_changed = 1
    ")->fetchAll();

    foreach ($recentPrices as $rp) {
        $text = "💰 <b>Обновление цен</b>\n\n{$rp['changed_by']} обновил {$rp['cnt']} цен ({$rp['legal_entity']})";
        foreach ($users as $user) {
            // Отправлять только пользователям с доступом к этому юрлицу
            $le = $user['legal_entities'];
            $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
            if (!empty($entities) && !in_array($rp['legal_entity'], $entities)) continue;
            tgSend($user['telegram_chat_id'], $text);
            $sent++;
        }
    }
}

// ═══ 4. Просроченные поставки (overdue_delivery) ═══
$overdueOrders = $pdo->query("
    SELECT legal_entity, COUNT(*) as cnt, GROUP_CONCAT(supplier SEPARATOR ', ') as suppliers
    FROM orders
    WHERE delivery_date < CURDATE() AND received_at IS NULL
    GROUP BY legal_entity
")->fetchAll();

if (!empty($overdueOrders)) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.overdue_delivery = 1
    ")->fetchAll();

    foreach ($overdueOrders as $od) {
        $text = "⚠️ <b>Просроченные поставки</b>\n\n";
        $text .= "Юрлицо: <b>{$od['legal_entity']}</b>\n";
        $text .= "Количество: <b>{$od['cnt']}</b>\n";
        $text .= "Поставщики: {$od['suppliers']}";
        foreach ($users as $user) {
            $le = $user['legal_entities'];
            $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
            if (!empty($entities) && !in_array($od['legal_entity'], $entities)) continue;
            if (wasNotified($pdo, 'overdue_delivery', $od['legal_entity'], $user['telegram_chat_id'], 86400)) continue;
            tgSend($user['telegram_chat_id'], $text);
            logNotification($pdo, 'overdue_delivery', $od['legal_entity'], $user['telegram_chat_id']);
            $sent++;
        }
    }
}

// ═══ 5. Загрузка данных из 1С (data_updates) ═══
$recentUploads = $pdo->query("
    SELECT legal_entity, COUNT(*) as cnt
    FROM stock_1c
    WHERE updated_at > NOW() - INTERVAL 10 MINUTE
    GROUP BY legal_entity
")->fetchAll();

if (!empty($recentUploads)) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.data_updates = 1
    ")->fetchAll();

    foreach ($recentUploads as $up) {
        $text = "📥 <b>Загрузка данных из 1С</b>\n\n";
        $text .= "Юрлицо: <b>{$up['legal_entity']}</b>\n";
        $text .= "Обновлено позиций: <b>{$up['cnt']}</b>";
        foreach ($users as $user) {
            $le = $user['legal_entities'];
            $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
            if (!empty($entities) && !in_array($up['legal_entity'], $entities)) continue;
            if (wasNotified($pdo, 'data_updates', $up['legal_entity'], $user['telegram_chat_id'], 600)) continue;
            tgSend($user['telegram_chat_id'], $text);
            logNotification($pdo, 'data_updates', $up['legal_entity'], $user['telegram_chat_id']);
            $sent++;
        }
    }
}

// ═══ 6. Истекающие сроки годности (expiring_items) ═══
// stock_malling использует поле «customer» (Бургер БК, Воглия Матта, Пицца Стар)
$expiringItems = $pdo->query("
    SELECT customer, COUNT(*) as cnt,
           GROUP_CONCAT(DISTINCT product_name ORDER BY expiry_date SEPARATOR ', ') as products
    FROM stock_malling
    WHERE expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 DAY
      AND expiry_status = 'Годен'
    GROUP BY customer
")->fetchAll();

if (!empty($expiringItems)) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.expiring_items = 1
    ")->fetchAll();

    foreach ($expiringItems as $ei) {
        $customer = $ei['customer'] ?? '';
        // Ограничиваем список товаров (может быть очень длинным)
        $prodList = mb_strlen($ei['products']) > 300 ? mb_substr($ei['products'], 0, 300) . '…' : $ei['products'];
        $text = "⚠️ <b>Истекающие сроки годности</b>\n\n";
        $text .= "Заказчик: <b>{$customer}</b>\n";
        $text .= "Позиций: <b>{$ei['cnt']}</b>\n";
        $text .= "Товары: {$prodList}";
        foreach ($users as $user) {
            $le = $user['legal_entities'];
            $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
            if (!empty($entities)) {
                // Маппинг customer → legal_entity: customer «Бургер БК» → содержит «Бургер» в legal_entity
                $match = false;
                foreach ($entities as $ent) {
                    if ($customer && (
                        (mb_strpos($customer, 'Бургер') !== false && mb_strpos($ent, 'Бургер') !== false) ||
                        (mb_strpos($customer, 'Воглия') !== false && mb_strpos($ent, 'Воглия') !== false) ||
                        (mb_strpos($customer, 'Пицца') !== false && mb_strpos($ent, 'Пицца') !== false)
                    )) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) continue;
            }
            tgSend($user['telegram_chat_id'], $text);
            $sent++;
        }
    }
}

// ═══ 7. Новые данные реализации ресторанов (restaurant_sales) ═══
// Таблица restaurant_sales не имеет legal_entity — отправляем всем подписчикам
$recentSales = $pdo->query("
    SELECT COUNT(*) as cnt, COUNT(DISTINCT analog_group) as groups_cnt,
           MAX(sale_date) as last_date
    FROM restaurant_sales
    WHERE created_at > NOW() - INTERVAL 10 MINUTE
")->fetch();

if ($recentSales && $recentSales['cnt'] > 0) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.restaurant_sales = 1
    ")->fetchAll();

    $text = "🍽 <b>Новые данные реализации</b>\n\n";
    $text .= "Загружено записей: <b>{$recentSales['cnt']}</b>\n";
    $text .= "Групп товаров: <b>{$recentSales['groups_cnt']}</b>\n";
    $text .= "Последняя дата: <b>{$recentSales['last_date']}</b>";
    foreach ($users as $user) {
        tgSend($user['telegram_chat_id'], $text);
        $sent++;
    }
}

// ═══ 8. Товары с низким запасом (low_stock) ═══
// days_left = stock / (consumption / period_days); показываем товары с запасом <= 3 дня
$lowStockData = $pdo->query("
    SELECT a.legal_entity, COUNT(*) as cnt
    FROM analysis_data a
    WHERE a.consumption > 0
      AND a.stock > 0
      AND ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) <= 3
    GROUP BY a.legal_entity
")->fetchAll();

if (!empty($lowStockData)) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.low_stock = 1
    ")->fetchAll();

    foreach ($lowStockData as $ls) {
        $text = "📉 <b>Низкий запас товаров</b>\n\n";
        $text .= "Юрлицо: <b>{$ls['legal_entity']}</b>\n";
        $text .= "Товаров с запасом ≤ 3 дня: <b>{$ls['cnt']}</b>";
        foreach ($users as $user) {
            $le = $user['legal_entities'];
            $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
            if (!empty($entities) && !in_array($ls['legal_entity'], $entities)) continue;
            if (wasNotified($pdo, 'low_stock', $ls['legal_entity'], $user['telegram_chat_id'], 14400)) continue;
            tgSend($user['telegram_chat_id'], $text);
            logNotification($pdo, 'low_stock', $ls['legal_entity'], $user['telegram_chat_id']);
            $sent++;
        }
    }
}

// ═══ 9. Еженедельный отчёт (пятница 17:00) ═══
$dow = (int)date('N');
if ($dow === 5 && $hour === 17 && $minute < 5) {
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND ts.daily_summary = 1
    ")->fetchAll();

    foreach ($users as $user) {
        $le = $user['legal_entities'];
        $entities = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        if (empty($entities)) continue;
        $ph = implode(',', array_fill(0, count($entities), '?'));
        $leFilter = " AND legal_entity IN ({$ph})";

        if (wasNotified($pdo, 'weekly_report', $entities[0], $user['telegram_chat_id'], 86400)) continue;

        // Заказы за неделю
        $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . $leFilter);
        $s->execute($entities);
        $ordersCnt = $s->fetch()['cnt'];

        $s = $pdo->prepare("SELECT COALESCE(SUM(sub.boxes), 0) as total FROM (SELECT (SELECT SUM(qty_boxes) FROM order_items WHERE order_id = o.id) as boxes FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . $leFilter . ") sub");
        $s->execute($entities);
        $totalBoxes = $s->fetch()['total'];

        // Изменения цен
        $s = $pdo->prepare("SELECT COUNT(*) as cnt, SUM(CASE WHEN new_price > old_price THEN 1 ELSE 0 END) as up_cnt, SUM(CASE WHEN new_price < old_price THEN 1 ELSE 0 END) as down_cnt FROM price_history WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . $leFilter);
        $s->execute($entities);
        $priceStats = $s->fetch();

        // Критичные остатки
        $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM analysis_data a WHERE a.consumption > 0 AND a.stock > 0 AND ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) <= 5" . str_replace('legal_entity', 'a.legal_entity', $leFilter));
        $s->execute($entities);
        $critCnt = $s->fetch()['cnt'];

        $weekStart = date('d.m', strtotime('-6 days'));
        $weekEnd = date('d.m');

        $text = "📊 <b>Итоги недели</b>\n";
        $text .= "<i>{$weekStart} – {$weekEnd}</i>\n";
        $text .= "─────────────────────\n";
        $text .= "📦 Заказов: <b>{$ordersCnt}</b> · <b>" . number_format($totalBoxes, 0, '.', ' ') . "</b> кор.\n";
        if ($priceStats['cnt'] > 0) {
            $text .= "💰 Цены: <b>{$priceStats['cnt']}</b> изм. (▲{$priceStats['up_cnt']} ▼{$priceStats['down_cnt']})\n";
        }
        $text .= "📉 Критичных остатков: <b>{$critCnt}</b>\n";

        // Топ критичных
        if ($critCnt > 0) {
            $s = $pdo->prepare("SELECT p.name, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity = a.legal_entity COLLATE utf8mb4_general_ci WHERE a.consumption > 0 AND a.stock > 0" . str_replace('legal_entity', 'a.legal_entity', $leFilter) . " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 5");
            $s->execute($entities);
            $critItems = $s->fetchAll();
            if ($critItems) {
                $text .= "─────────────────────\n";
                $text .= "⚠️ <b>Заканчиваются:</b>\n";
                foreach ($critItems as $c) {
                    $icon = $c['days_left'] <= 0 ? '🔴' : '🟠';
                    $text .= "{$icon} " . mb_substr($c['name'] ?: '—', 0, 30) . " · {$c['days_left']} дн.\n";
                }
            }
        }

        // AI-инсайт
        try {
            $aiCtx = "Итоги недели: заказов {$ordersCnt}, коробок {$totalBoxes}, изменений цен {$priceStats['cnt']}, критичных остатков {$critCnt}.";
            $aiInsight = askAIDigest($aiCtx);
            if ($aiInsight) $text .= "\n💡 <i>{$aiInsight}</i>";
        } catch (Exception $e) {}

        tgSend($user['telegram_chat_id'], $text);
        logNotification($pdo, 'weekly_report', $entities[0], $user['telegram_chat_id']);
        $sent++;
    }
}

// ═══ Напоминания о заявках на овощи ═══
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    // Получить ссылку на форму (активный токен)
    $vegFormLink = '';
    $vegToken = $pdo->query("SELECT token FROM veg_tokens WHERE expires_at > NOW() ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($vegToken) {
        $vegFormLink = "{$SITE_URL}/veg-order/{$vegToken}";
    }

    // Активные сессии
    $activeSessions = $pdo->query("SELECT id, name FROM veg_sessions WHERE status='active'")->fetchAll();

    if ($activeSessions) {
        // Правила дедлайнов
        $dlRows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules")->fetchAll();
        $deadlineRules = [];
        foreach ($dlRows as $r) $deadlineRules[(int)$r['delivery_dow']] = $r;

        // Все подписки
        $allSubs = $pdo->query("SELECT chat_id, restaurant_number FROM veg_telegram_subs")->fetchAll();
        if ($allSubs) {
            // Группировка подписок по ресторану
            $subsByRest = [];
            foreach ($allSubs as $sub) {
                $subsByRest[$sub['restaurant_number']][] = $sub['chat_id'];
            }

            // Дни доставки по ресторанам
            $deliveryDays = $pdo->query("SELECT restaurant_number, day_of_week FROM veg_delivery_days")->fetchAll();
            $restDays = [];
            foreach ($deliveryDays as $dd) {
                $restDays[$dd['restaurant_number']][] = (int)$dd['day_of_week'];
            }

            foreach ($activeSessions as $session) {
                $sessId = $session['id'];

                foreach ($subsByRest as $restNum => $chatIds) {
                    $days = $restDays[$restNum] ?? [];
                    if (!$days) continue;

                    // Ближайший день доставки (в пределах 7 дней)
                    $nextDelivery = null;
                    for ($i = 0; $i <= 7; $i++) {
                        $check = clone $now;
                        $check->modify("+{$i} days");
                        $dow = (int)$check->format('N'); // 1=пн..7=вс
                        if (in_array($dow, $days) && isset($deadlineRules[$dow])) {
                            $rule = $deadlineRules[$dow];
                            // Вычисляем дедлайн для этого дня доставки
                            $deadlineDow = (int)$rule['deadline_dow'];
                            $diff = $dow - $deadlineDow;
                            if ($diff <= 0) $diff += 7;
                            $deadline = clone $check;
                            $deadline->modify("-{$diff} days");
                            $timeParts = explode(':', $rule['deadline_time']);
                            $deadline->setTime((int)$timeParts[0], (int)($timeParts[1] ?? 0));

                            // Дедлайн должен быть в будущем или только что прошёл
                            $minutesLeft = ($deadline->getTimestamp() - $now->getTimestamp()) / 60;

                            if ($minutesLeft > -10 && $minutesLeft < 200) {
                                $nextDelivery = [
                                    'date' => $check->format('Y-m-d'),
                                    'deadline' => $deadline,
                                    'minutesLeft' => $minutesLeft,
                                    'dow' => $dow,
                                ];
                                break;
                            }
                        }
                    }

                    if (!$nextDelivery) continue;

                    $deliveryDate = $nextDelivery['date'];
                    $minutesLeft = $nextDelivery['minutesLeft'];
                    $deadlineFmt = $nextDelivery['deadline']->format('d.m H:i');

                    // Проверяем есть ли заявка
                    $orderCheck = $pdo->prepare("SELECT COUNT(*) FROM veg_orders WHERE session_id=? AND restaurant_number=? AND delivery_date=? AND quantity > 0");
                    $orderCheck->execute([$sessId, $restNum, $deliveryDate]);
                    $hasOrder = $orderCheck->fetchColumn() > 0;

                    // Проверяем вечернее напоминание (18:00 за день до дедлайна)
                    $deadlineDate = $nextDelivery['deadline']->format('Y-m-d');
                    $eveningCheck = clone $nextDelivery['deadline'];
                    $eveningCheck->modify('-1 day');
                    $eveningCheck->setTime(18, 0);
                    $minutesToEvening = ($eveningCheck->getTimestamp() - $now->getTimestamp()) / 60;

                    // Определяем тип напоминания
                    $reminderType = null;
                    // Вечернее напоминание в 18:00 за день до дедлайна
                    if (!$hasOrder && $minutesToEvening <= 5 && $minutesToEvening > -5) {
                        $reminderType = 'evening';
                    } elseif ($minutesLeft <= -0.1 && $minutesLeft > -10 && !$hasOrder) {
                        $reminderType = 'expired';
                    } elseif (!$hasOrder) {
                        if ($minutesLeft <= 180 && $minutesLeft > 175) $reminderType = '3h';
                        elseif ($minutesLeft <= 120 && $minutesLeft > 115) $reminderType = '2h';
                        elseif ($minutesLeft <= 60 && $minutesLeft > 55) $reminderType = '1h';
                        elseif ($minutesLeft <= 30 && $minutesLeft > 25) $reminderType = '30m';
                    }

                    if (!$reminderType) continue;

                    // Проверяем не отправляли ли уже
                    $logCheck = $pdo->prepare("SELECT id FROM veg_reminder_log WHERE session_id=? AND restaurant_number=? AND delivery_date=? AND reminder_type=?");
                    $logCheck->execute([$sessId, $restNum, $deliveryDate, $reminderType]);
                    if ($logCheck->fetch()) continue;

                    // Формируем текст
                    $dayNames = [1=>'понедельник',2=>'вторник',3=>'среда',4=>'четверг',5=>'пятница',6=>'субботу',7=>'воскресенье'];
                    $dayName = $dayNames[$nextDelivery['dow']] ?? '';

                    $linkLine = $vegFormLink ? "\n\n🔗 <a href=\"{$vegFormLink}\">Подать заявку</a>" : '';

                    if ($reminderType === 'expired') {
                        $msgText = "⚠️ <b>Дедлайн заявки на овощи истёк!</b>\n\n";
                        $msgText .= "🏪 Ресторан <b>{$restNum}</b>\n";
                        $msgText .= "📅 Доставка: {$dayName} ({$deliveryDate})\n\n";
                        $msgText .= "Заявка не была подана. Заказ будет выполнен по предыдущей заявке.";

                        // Подтягиваем количества: сначала из текущей сессии (другие даты), потом из предыдущей
                        $prevItems = [];
                        // 1. Текущая сессия — заявки этого ресторана на другие даты доставки
                        $curOrdStmt = $pdo->prepare("
                            SELECT sp.product_name, sp.unit, o.quantity, o.admin_qty
                            FROM veg_orders o
                            JOIN veg_session_products sp ON sp.id = o.product_id
                            WHERE o.session_id = ? AND o.restaurant_number = ? AND o.delivery_date != ? AND o.quantity > 0
                            ORDER BY o.delivery_date DESC, sp.sort_order
                        ");
                        $curOrdStmt->execute([$sessId, $restNum, $deliveryDate]);
                        $prevItems = $curOrdStmt->fetchAll();

                        // 2. Если в текущей сессии нет — ищем в предыдущей
                        if (!$prevItems) {
                            $prevSessStmt = $pdo->prepare("SELECT id FROM veg_sessions WHERE id < ? ORDER BY id DESC LIMIT 1");
                            $prevSessStmt->execute([$sessId]);
                            $prevSessId = $prevSessStmt->fetchColumn();
                            if ($prevSessId) {
                                $prevOrdStmt = $pdo->prepare("
                                    SELECT sp.product_name, sp.unit, o.quantity, o.admin_qty
                                    FROM veg_orders o
                                    JOIN veg_session_products sp ON sp.id = o.product_id
                                    WHERE o.session_id = ? AND o.restaurant_number = ? AND o.quantity > 0
                                    ORDER BY o.delivery_date DESC, sp.sort_order
                                ");
                                $prevOrdStmt->execute([$prevSessId, $restNum]);
                                $prevItems = $prevOrdStmt->fetchAll();
                            }
                        }

                        if ($prevItems) {
                            // Берём последние количества по каждому товару
                            $byProduct = [];
                            foreach ($prevItems as $pi) {
                                if (!isset($byProduct[$pi['product_name']])) {
                                    $qty = ($pi['admin_qty'] !== null && $pi['admin_qty'] !== '') ? $pi['admin_qty'] : $pi['quantity'];
                                    $unit = $pi['unit'] === 'pcs' ? 'шт' : 'кг';
                                    $byProduct[$pi['product_name']] = floatval($qty) . ' ' . $unit;
                                }
                            }
                            $msgText .= "\n\n📋 <b>Предыдущая заявка:</b>";
                            foreach ($byProduct as $name => $qtyStr) {
                                $msgText .= "\n• {$name} — <b>{$qtyStr}</b>";
                            }
                        }

                        $msgText .= $linkLine;
                    } elseif ($reminderType === 'evening') {
                        $msgText = "🌙 <b>Напоминание: заявка на овощи</b>\n\n";
                        $msgText .= "🏪 Ресторан <b>{$restNum}</b>\n";
                        $msgText .= "📅 Доставка: {$dayName} ({$deliveryDate})\n";
                        $msgText .= "⏳ Дедлайн завтра: <b>{$deadlineFmt}</b>\n\n";
                        $msgText .= "Не забудьте подать заявку!";
                        $msgText .= $linkLine;
                    } else {
                        $timeLabels = ['3h'=>'3 часа','2h'=>'2 часа','1h'=>'1 час','30m'=>'30 минут'];
                        $timeLabel = $timeLabels[$reminderType] ?? $reminderType;
                        $msgText = "⏰ <b>Напоминание: заявка на овощи</b>\n\n";
                        $msgText .= "🏪 Ресторан <b>{$restNum}</b>\n";
                        $msgText .= "📅 Доставка: {$dayName} ({$deliveryDate})\n";
                        $msgText .= "⏳ До дедлайна: <b>{$timeLabel}</b> (до {$deadlineFmt})\n\n";
                        $msgText .= "Заявка ещё не подана! Пожалуйста, заполните заявку.";
                        $msgText .= $linkLine;
                    }

                    // Отправляем (без превью ссылок)
                    foreach ($chatIds as $cid) {
                        tgSend($cid, $msgText, true);
                        $sent++;
                    }

                    // Записываем в лог
                    $pdo->prepare("INSERT IGNORE INTO veg_reminder_log (session_id, restaurant_number, delivery_date, reminder_type) VALUES (?, ?, ?, ?)")
                        ->execute([$sessId, $restNum, $deliveryDate, $reminderType]);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] veg reminders error: ' . $e->getMessage());
}

// Очистка старых записей дедупликации (старше 7 дней)
try {
    $pdo->exec("DELETE FROM tg_notification_log WHERE sent_at < NOW() - INTERVAL 7 DAY");
} catch (Exception $e) {}

echo "Отправлено: {$sent}\n";
