<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Cron: отправка уведомлений в Telegram
 * Запуск каждые 5 минут: php /var/www/bk-calc/api/cron_telegram.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// Защита от параллельного запуска (flock)
$lockFile = __DIR__ . '/cron_telegram.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Already running, skipping\n";
    exit;
}
// Ограничение времени выполнения — 4 минуты (крон каждые 5 мин)
set_time_limit(240);

$envFile = '/var/www/bk-calc-secrets/.env';
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
    PDO::ATTR_TIMEOUT => 5,
]);
$pdo->exec("SET SESSION max_statement_time = 30");

require_once __DIR__ . '/includes/legal_entities.php';
require_once __DIR__ . '/includes/so_deadline.php';
require_once __DIR__ . '/includes/tg_client.php';
require_once __DIR__ . '/includes/push_send.php'; // web-push для напоминаний по каналу 'push'

// supplier_orders.php обычно подключается только из index.php как HTTP-роутер
// (внутри — `if ($endpoint !== 'so') return;` и парсинг $uri/$method в самом низу).
// Нам нужна только функция soSendSummaryEmail() и её зависимости (объявления
// функций наверху файла) — не HTTP-роутинг. Задаём безопасные заглушки, чтобы
// пройти страж и не попасть ни в один маршрут ($method !== 'GET'/'POST'/... и
// $soAction === '' не совпадёт ни с одним условием ниже).
$endpoint = 'so';
$method = null;
$uri = '';
require_once __DIR__ . '/includes/supplier_orders.php';

// TTL одноразовых токенов входа в кабинет ресторана (синхронизировано с helpers.php).
if (!defined('RO_AUTH_TOKEN_TTL_MINUTES')) define('RO_AUTH_TOKEN_TTL_MINUTES', 10);

// Чистка журнала отправок раз в час: записи старше 30 дней удаляются,
// чтобы tg_send_log не разросся на годы. Не блокируем выходом дальше:
// чистка идёт даже в тихие часы.
try {
    if ((int)date('i') < 5) { // раз в час в первую минуту
        $pdo->exec("DELETE FROM tg_send_log WHERE ts < NOW() - INTERVAL 30 DAY LIMIT 10000");
    }
} catch (Throwable $e) {
    error_log('[cron_telegram] tg_send_log cleanup failed: ' . $e->getMessage());
}

// Тихие часы: 22:00–09:00 по Минску — никакие уведомления не отправляем.
// Выходим до всех проверок, чтобы дедуп не пометил неотправленные сообщения как доставленные.
$__nowHour = (int)(new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('H');
if ($__nowHour < 9 || $__nowHour >= 22) {
    echo "Quiet hours, skipping\n";
    exit;
}

// Тонкая обёртка над tg_client для совместимости с уже написанным кодом.
// Раньше возвращала raw JSON или false. Никто из вызывающих этим не
// пользуется кроме «if (tgSend(...))», поэтому возвращаем bool.
// PDO передаём в опции — клиент сам пометит заблокированных пользователей.
function tgSend($chatId, $text, $disablePreview = false, $replyMarkup = null) {
    global $pdo;
    $opts = ['pdo' => $pdo];
    if ($disablePreview) $opts['disable_preview'] = true;
    if ($replyMarkup)    $opts['reply_markup']    = $replyMarkup;
    return tgClientSend($chatId, $text, $opts)['ok'];
}

/**
 * Отправить документ (файл) в Telegram. Поддерживает бинарные вложения (xlsx, pdf и т.п.).
 * $content — сырое содержимое файла (строка).
 */
function tgSendDocument($chatId, $filename, $content, $caption = '', $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
    global $pdo;
    return tgClientSendDocument($chatId, $filename, $content, [
        'mime'    => $mime,
        'caption' => $caption,
        'pdo'     => $pdo,
    ])['ok'];
}

function dateFromWeekStartByDow(DateTime $weekStart, int $dow, int $weekOffset = 0): DateTime {
    $offsetDays = $dow - 1 + $weekOffset * 7;
    $modifier = ($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days';
    return (clone $weekStart)->modify($modifier);
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
        $chatId = (int)$chatId;
        $s = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type=? AND legal_entity=? AND chat_id=? AND sent_at > NOW() - INTERVAL ? SECOND LIMIT 1");
        $s->execute([$type, $legalEntity, $chatId, $intervalSeconds]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}

function logNotification($pdo, $type, $legalEntity, $chatId) {
    try {
        $chatId = (int)$chatId;
        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id) VALUES (?,?,?)")
            ->execute([$type, $legalEntity, $chatId]);
    } catch (Exception $e) {}
}

function wasNotifiedByKey($pdo, $notificationKey, $intervalSeconds) {
    try {
        $s = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_key = ? AND sent_at > NOW() - INTERVAL ? SECOND LIMIT 1");
        $s->execute([$notificationKey, $intervalSeconds]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}

function logNotificationByKey($pdo, $type, $notificationKey, $chatId = 0, $legalEntity = '') {
    try {
        $chatId = (int)$chatId;
        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?,?,?,?)")
            ->execute([$type, $legalEntity, $chatId, $notificationKey]);
    } catch (Exception $e) {}
}

$sent = 0;

// Проверка выходных (секции 1-9 отправляются только в рабочие дни)
$tz = new DateTimeZone('Europe/Minsk');
$nowMinsk = new DateTime('now', $tz);
$isWeekend = ((int)$nowMinsk->format('N') >= 6);

// ═══ 1. Уведомления типа agreement_expiry → пользователям с psc_expiry=1 ═══
if (!$isWeekend):
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
        WHERE u.name = ? AND u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.psc_expiry = 1
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.daily_summary = 1
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
                        LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.price_changed = 1
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.overdue_delivery = 1
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.data_updates = 1
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

// Истекающие сроки годности не рассылаем по cron.
// Уведомление отправляется только после новой загрузки сроков в replace_stock_malling.

// ═══ 7. Новые данные реализации ресторанов (restaurant_sales) ═══
// Реализация хранится по группе юрлиц (BK_VM/PS) — уведомляем тех, у кого
// хотя бы одно юрлицо входит в обновлённую группу.
$recentSalesByGroup = $pdo->query("
    SELECT legal_entity_group, COUNT(*) as cnt, COUNT(DISTINCT analog_group) as groups_cnt,
           MAX(sale_date) as last_date
    FROM restaurant_sales
    WHERE created_at > NOW() - INTERVAL 10 MINUTE
    GROUP BY legal_entity_group
")->fetchAll();

foreach ($recentSalesByGroup as $recentSales) {
    if (!$recentSales['cnt']) continue;
    $group = $recentSales['legal_entity_group'];
    $groupLabel = $group === 'PS' ? 'Пицца Стар' : 'Бургер БК + Воглия Матта';
    $users = $pdo->query("
        SELECT u.name, u.telegram_chat_id, u.legal_entities
        FROM users u
        JOIN telegram_settings ts ON ts.user_name = u.name
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.restaurant_sales = 1
    ")->fetchAll();

    $text = "🍽 <b>Новые данные реализации</b>\n\n";
    $text .= "Юрлица: <b>" . htmlspecialchars($groupLabel, ENT_QUOTES) . "</b>\n";
    $text .= "Загружено записей: <b>{$recentSales['cnt']}</b>\n";
    $text .= "Групп товаров: <b>{$recentSales['groups_cnt']}</b>\n";
    $text .= "Последняя дата: <b>{$recentSales['last_date']}</b>";
    foreach ($users as $user) {
        // У пользователя должно быть хотя бы одно юрлицо из этой группы
        $le = ($user['legal_entities'] && is_string($user['legal_entities'])) ? json_decode($user['legal_entities'], true) : [];
        if (!$le) continue;
        $hasAny = false;
        foreach ($le as $userLe) {
            if (getEntityGroup($userLe) === $group) { $hasAny = true; break; }
        }
        if (!$hasAny) continue;
        $key = 'group_' . $group;
        if (wasNotified($pdo, 'restaurant_sales', $key, $user['telegram_chat_id'], 600)) continue;
        tgSend($user['telegram_chat_id'], $text);
        logNotification($pdo, 'restaurant_sales', $key, $user['telegram_chat_id']);
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.low_stock = 1
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
        WHERE u.telegram_chat_id IS NOT NULL AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY) AND ts.daily_summary = 1
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
            $s = $pdo->prepare("SELECT p.name, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1 WHERE a.consumption > 0 AND a.stock > 0" . str_replace('legal_entity', 'a.legal_entity', $leFilter) . " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 5");
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

endif; // !$isWeekend — конец блока уведомлений для рабочих дней

// ═══ Оплаты российских поставщиков ═══
try {
    // За 7 дней до оплаты + за день до дедлайна заявки
    $payments = $pdo->query("SELECT sp.*, o.created_by FROM supplier_payments sp LEFT JOIN orders o ON o.id = sp.order_id WHERE sp.status IN ('upcoming', 'request_due')")->fetchAll();
    $tz = new DateTimeZone('Europe/Moscow');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    foreach ($payments as $p) {
        $payDate = new DateTime($p['payment_date']);
        $daysUntilPay = (int)$now->diff($payDate)->format('%r%a');
        $deadlineDt = new DateTime($p['request_deadline']);
        $hoursUntilDeadline = ($deadlineDt->getTimestamp() - $now->getTimestamp()) / 3600;

        // Определяем создателя заказа для уведомления
        $createdBy = $p['created_by'] ?: null;
        if (!$createdBy) continue;
        $userSt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE name = ? AND telegram_chat_id IS NOT NULL AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)");
        $userSt->execute([$createdBy]);
        $chatId = $userSt->fetchColumn();
        if (!$chatId) continue;

        $amountStr = $p['amount'] ? number_format(floatval($p['amount']), 0, '.', ' ') . ' ' . ($p['currency'] ?: 'RUB') : 'сумма не указана';
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        $payDow = $dayNames[(int)$payDate->format('N')] ?? '';
        $payFmt = $payDow . ' ' . $payDate->format('d.m.Y');

        // За 7 дней до оплаты
        if ($daysUntilPay <= 7 && $daysUntilPay > 1 && $p['status'] === 'upcoming') {
            if (!wasNotified($pdo, 'payment_7days', "pay_{$p['id']}", $chatId, 86400)) {
                $text = "💰 <b>Оплата через {$daysUntilPay} дн.</b>\n";
                $text .= "─────────────────────\n";
                $text .= "📦 Поставщик: <b>{$p['supplier']}</b>\n";
                $text .= "💵 Сумма: <b>{$amountStr}</b>\n";
                $text .= "📅 Оплата: {$payFmt}\n";
                $text .= "⏰ Заявку подать до: " . date('d.m H:i', strtotime($p['request_deadline'])) . "\n";
                $text .= "\n<i>Не забудьте подать заявку в Битрикс!</i>";
                tgSend($chatId, $text);
                logNotification($pdo, 'payment_7days', "pay_{$p['id']}", $chatId);
                $sent++;
            }
        }

        // За день до дедлайна заявки (< 24 часов)
        if ($hoursUntilDeadline <= 24 && $hoursUntilDeadline > 0) {
            if (!wasNotified($pdo, 'payment_deadline', "pay_{$p['id']}", $chatId, 43200)) {
                $hoursFmt = $hoursUntilDeadline < 2 ? 'менее 2 часов' : round($hoursUntilDeadline) . ' ч';
                $text = "⚠️ <b>Дедлайн заявки на оплату!</b>\n";
                $text .= "─────────────────────\n";
                $text .= "📦 Поставщик: <b>{$p['supplier']}</b>\n";
                $text .= "💵 Сумма: <b>{$amountStr}</b>\n";
                $text .= "📅 Оплата: {$payFmt}\n";
                $text .= "⏰ Осталось: <b>{$hoursFmt}</b>\n";
                $text .= "\n<b>Подайте заявку в Битрикс сейчас!</b>";
                tgSend($chatId, $text);
                logNotification($pdo, 'payment_deadline', "pay_{$p['id']}", $chatId);
                // Обновляем статус
                $pdo->prepare("UPDATE supplier_payments SET status = 'request_due' WHERE id = ? AND status = 'upcoming'")->execute([$p['id']]);
                $sent++;
            }
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] payment reminder error: ' . $e->getMessage());
}

// ═══ Напоминания о сборе остатков ═══
try {
    // Активные сборы старше 4 часов — напоминаем незаполнившим ресторанам
    $activeSc = $pdo->query("SELECT id, name, legal_entity_group FROM stock_collections WHERE status = 'active' AND created_at < NOW() - INTERVAL 4 HOUR")->fetchAll();
    foreach ($activeSc as $sc) {
        // Рестораны которые уже заполнили
        $filled = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
        $filled->execute([$sc['id']]);
        $filledSet = array_flip($filled->fetchAll(PDO::FETCH_COLUMN));

        // Подписанные рестораны только из группы юрлиц этого сбора (BK_VM или PS).
        $subsStmt = $pdo->prepare("SELECT DISTINCT s.chat_id, s.restaurant_number, s.notify_stock_reminders
            FROM ro_telegram_subs s
            JOIN restaurants r ON r.number = s.restaurant_number AND r.legal_entity_group = ?
            WHERE s.tg_blocked_at IS NULL OR s.tg_blocked_at < NOW() - INTERVAL 30 DAY");
        $subsStmt->execute([$sc['legal_entity_group']]);
        $subs = $subsStmt->fetchAll();
        foreach ($subs as $sub) {
            if (isset($filledSet[$sub['restaurant_number']])) continue;
            if (!$sub['notify_stock_reminders']) continue;
            // Проверяем не отправляли ли уже напоминание (раз в 12 часов)
            if (wasNotified($pdo, 'stock_collection_reminder', "sc_{$sc['id']}_{$sub['restaurant_number']}", $sub['chat_id'], 43200)) continue;

            $text = "📋 <b>Напоминание: сбор остатков</b>\n";
            $text .= "─────────────────────\n";
            $text .= "📝 {$sc['name']}\n";
            $text .= "🏪 Ресторан <b>" . formatRestaurantNumber($sub['restaurant_number']) . "</b>\n\n";
            $text .= "Вы ещё не заполнили остатки.\nПожалуйста, заполните через бот.";

            $keyboard = json_encode(['inline_keyboard' => [
                [['text' => '📋 Заполнить', 'callback_data' => 'rest_sc_start']],
            ]]);

            tgSend($sub['chat_id'], $text, false, json_decode($keyboard, true));
            logNotification($pdo, 'stock_collection_reminder', "sc_{$sc['id']}_{$sub['restaurant_number']}", $sub['chat_id']);
            $sent++;
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] stock collection reminder error: ' . $e->getMessage());
}

// ═══ ПРОТОКОЛЫ: автостатус «просрочено» для решений ═══
// Напоминания о дедлайнах сюда не входят — они идут через модуль «Задачи»
// (cron_tasks_deadlines.php), т.к. каждое решение протокола уже создаёт
// карточку (protocol_decisions.tasks_card_id). Здесь — только статус.
try {
    $pdo->exec("UPDATE protocol_decisions SET status = 'overdue' WHERE status = 'pending' AND deadline IS NOT NULL AND deadline < CURDATE()");
} catch (Exception $e) {
    error_log('[cron_telegram] protocol overdue status update error: ' . $e->getMessage());
}

// ═══ ЗАКАЗЫ РЕСТОРАНОВ: напоминания о дедлайнах ═══
// Перебираем все активные сессии (BK_VM и PS могут иметь отдельные)
try {
    $roSessions = $pdo->query("SELECT id, week_start, week_end, legal_entity_group FROM ro_sessions WHERE status = 'active' AND week_end >= CURDATE() ORDER BY id DESC")->fetchAll();
    foreach ($roSessions as $roSess) {
        $tz = new DateTimeZone('Europe/Minsk');
        $now = new DateTime('now', $tz);
        $currentTime = $now->format('H:i');

        $tomorrow = (new DateTime('now', $tz))->modify('+1 day')->format('Y-m-d');
        $tomorrowInSession = $tomorrow >= $roSess['week_start'] && $tomorrow <= $roSess['week_end'];

        $dateOpen = false;
        if ($tomorrowInSession) {
            $openChk = $pdo->prepare("SELECT is_open FROM ro_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
            $openChk->execute([$roSess['id'], $tomorrow]);
            $dateOpen = (int)$openChk->fetchColumn() === 1;
        }

        if (!$tomorrowInSession || !$dateOpen) continue;
        if (!($currentTime >= '08:00' && $currentTime < '08:15' || $currentTime >= '12:00' && $currentTime < '12:15')) continue;

        $reminderType = $currentTime < '09:00' ? 'ro_morning' : 'ro_midday';
        $tomorrowDow = (int)(new DateTime($tomorrow))->format('N');
        $sessGroup = $roSess['legal_entity_group'] ?: 'BK_VM';

        // Источник чатов — ro_telegram_subs (несколько сотрудников на ресторан).
        $s = $pdo->prepare("
            SELECT rs.restaurant_number, rs.legal_entity_group, rs.chat_id AS telegram_chat_id
            FROM ro_telegram_subs rs
            WHERE rs.chat_id IS NOT NULL
              AND rs.legal_entity_group = ?
              AND (rs.verified_at IS NOT NULL
                   OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))
              AND (rs.tg_blocked_at IS NULL OR rs.tg_blocked_at < NOW() - INTERVAL 30 DAY)
              AND EXISTS (
                  SELECT 1 FROM restaurants r
                  JOIN delivery_schedule ds ON ds.restaurant_id = r.id
                  WHERE r.number = rs.restaurant_number
                    AND r.legal_entity_group = rs.legal_entity_group COLLATE utf8mb4_general_ci
                    AND r.active = 1
                    AND ds.day_of_week = ?
              )
              AND rs.restaurant_number NOT IN (
                  SELECT o.restaurant_number FROM ro_orders o
                  WHERE o.session_id = ? AND o.delivery_date = ? AND o.status != 'draft'
              )
        ");
        $s->execute([$sessGroup, $tomorrowDow, $roSess['id'], $tomorrow]);
        $missing = $s->fetchAll();

        foreach ($missing as $m) {
            $dedupKey = "{$reminderType}_{$m['restaurant_number']}_{$tomorrow}";
            $dup = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_key = ? AND sent_at > NOW() - INTERVAL 6 HOUR");
            $dup->execute([$dedupKey]);
            if ($dup->fetch()) continue;

            $timeLeft = $currentTime < '09:00' ? 'до 10:00' : 'до 13:00';
            $dateFormatted = (new DateTime($tomorrow))->format('d.m');
            $text = "⏰ <b>Напоминание</b>\n\n";
            $text .= "Ресторан <b>" . formatRestaurantNumber($m['restaurant_number']) . "</b>: не подана заявка на <b>{$dateFormatted}</b>.\n";
            $text .= "Дедлайн: {$timeLeft}.\n\n";

            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO ro_tg_tokens (token, kind, telegram_chat_id, restaurant_number, legal_entity_group, expires_at, used) VALUES (?, 'auth', ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . RO_AUTH_TOKEN_TTL_MINUTES . " MINUTE), 0)")
                ->execute([$token, $m['telegram_chat_id'], $m['restaurant_number'], $m['legal_entity_group'] ?: $sessGroup]);
            $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://supply-department.online', '/');

            $btns = ['inline_keyboard' => [
                [['text' => '🏠 Открыть кабинет', 'url' => "{$siteUrl}/restaurant?tg_token={$token}"]],
            ]];
            tgSend($m['telegram_chat_id'], $text, false, $btns);
            $sent++;

            $pdo->prepare("INSERT INTO tg_notification_log (notification_key, sent_at) VALUES (?, NOW())")->execute([$dedupKey]);
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] ro deadline reminders error: ' . $e->getMessage());
}

// ═══ ЗАЯВКИ ПОСТАВЩИКАМ (so_*): напоминания ресторанам о дедлайнах ═══
// Аналогично овощам: вечернее за день до дедлайна + 3ч/2ч/1ч/30мин + expired.
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    // Активные временные периоды графиков (на сегодня и вперёд). Для дат внутри
    // периода напоминания должны идти по ВРЕМЕННОМУ графику, а не основному
    // (та же логика, что в cron_delivery_reminders.php для локальных поставщиков).
    $soTempPeriods = []; // supplier_id => [['date_from','date_to'], ...]
    foreach ($pdo->query("SELECT supplier_id, date_from, date_to FROM so_supplier_temp_schedule_periods WHERE date_to >= CURDATE()") as $p) {
        $soTempPeriods[$p['supplier_id']][] = ['date_from' => $p['date_from'], 'date_to' => $p['date_to']];
    }
    $soDateInTempPeriod = function ($supId, $dateStr) use ($soTempPeriods) {
        foreach ($soTempPeriods[$supId] ?? [] as $p) {
            if ($dateStr >= $p['date_from'] && $dateStr <= $p['date_to']) return true;
        }
        return false;
    };

    // Все активные so_*-поставщики (подключённые через портал) с графиком,
    // принимающие заявки. Локальных не трогаем — их обрабатывает отдельный
    // модуль cron_delivery_reminders.php с гибкими временами и ack-кнопкой.
    $suppliers = $pdo->query("
        SELECT DISTINCT s.id, s.short_name, s.legal_entity, s.legal_entity_group,
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time
        FROM suppliers s
        JOIN supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
        LEFT JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1 AND COALESCE(sst.is_accepting_orders, 1) = 1
    ")->fetchAll();

    // Кэш настроек напоминаний поставщика (тайминги + каналы). Читаем один раз
    // на поставщика, а не на каждый ресторан. Дефолты (все тайминги / канал 'tg')
    // уже подставляет soGetSupplierSettings — при отсутствии настроек поведение как раньше.
    $supReminderCfg = [];

    foreach ($suppliers as $sup) {
        $supId = $sup['id'];
        $supName = $sup['short_name'];
        $defaultDeadlineTime = $sup['default_deadline_time'];

        if (!isset($supReminderCfg[$supId])) {
            $rcfg = soGetSupplierSettings($pdo, $supId);
            $supReminderCfg[$supId] = [
                'offsets'  => $rcfg['reminder_offsets']  ?? [],
                'channels' => $rcfg['reminder_channels'] ?? [],
            ];
        }
        $remOffsets  = $supReminderCfg[$supId]['offsets'];
        $remChannels = $supReminderCfg[$supId]['channels'];
        $tgEnabled   = in_array('tg', $remChannels, true);
        $pushEnabled = in_array('push', $remChannels, true);

        // Все расписания поставщика: ресторан + дни заказа/доставки
        $schStmt = $pdo->prepare("
            SELECT ss.restaurant_id, ss.order_day, ss.delivery_day,
                   r.number AS restaurant_number, r.legal_entity_group
            FROM supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id AND r.active = 1
            WHERE ss.supplier_id = ? AND ss.is_active = 1
        ");
        $schStmt->execute([$supId]);
        $schRows = $schStmt->fetchAll();
        foreach ($schRows as &$sr) { $sr['source'] = 'main'; }
        unset($sr);

        // Если у поставщика есть активный временный период — добавляем его строки.
        // В цикле подбора дат: основные дни, попавшие в период, подавляются, а
        // временные — учитываются только внутри периода.
        if (isset($soTempPeriods[$supId])) {
            $tmpStmt = $pdo->prepare("
                SELECT ssi.restaurant_id, ssi.order_day, ssi.delivery_day,
                       r.number AS restaurant_number, r.legal_entity_group
                FROM so_supplier_temp_schedule_items ssi
                JOIN so_supplier_temp_schedule_periods sp ON sp.id = ssi.period_id
                JOIN restaurants r ON r.id = ssi.restaurant_id AND r.active = 1
                WHERE sp.supplier_id = ? AND ssi.is_active = 1 AND sp.date_to >= CURDATE()
            ");
            $tmpStmt->execute([$supId]);
            foreach ($tmpStmt->fetchAll() as $tr) {
                $tr['source'] = 'temp';
                $schRows[] = $tr;
            }
        }

        // Группируем по ресторану. Источник получателей — видимые подписки бота:
        // чтобы человек получал напоминания только по тем ресторанам, которые видит в меню бота.
        $byRest = [];
        $chatIdsLookup = $pdo->prepare("
            SELECT DISTINCT chat_id
            FROM ro_telegram_subs
            WHERE restaurant_number = ?
              AND legal_entity_group = ?
              AND notify_so_reminders = 1 AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
              AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
        ");
        foreach ($schRows as $s) {
            $rn = $s['restaurant_number'];
            if (!isset($byRest[$rn])) {
                $grp = $s['legal_entity_group'] ?: 'BK_VM';
                $chatIdsLookup->execute([$rn, $grp]);
                $cids = $chatIdsLookup->fetchAll(PDO::FETCH_COLUMN);
                if (empty($cids)) continue; // ресторан без подписок — пропускаем
                $byRest[$rn] = ['chat_ids' => $cids, 'group' => $grp, 'schedule' => []];
            }
            $byRest[$rn]['schedule'][] = [
                'order_day' => (int)$s['order_day'],
                'delivery_day' => (int)$s['delivery_day'],
                'source' => $s['source'] ?? 'main',
            ];
        }

        foreach ($byRest as $restNum => $info) {
            $chatIds = $info['chat_ids'];

            // Ищем ближайший будущий день поставки (в пределах 2 недель)
            $nextDelivery = null;
            foreach ($info['schedule'] as $sc) {
                $deliveryDow = $sc['delivery_day'];

                // Понедельник текущей недели
                $weekStart = clone $now;
                $weekStart->setTime(0, 0, 0);
                $weekStart->modify('-' . ((int)$weekStart->format('N') - 1) . ' days');

                for ($w = 0; $w < 2; $w++) {
                    $deliveryDateObj = dateFromWeekStartByDow($weekStart, $deliveryDow, $w);
                    if ($deliveryDateObj < (clone $now)->setTime(0,0,0)) continue;

                    $deliveryDate = $deliveryDateObj->format('Y-m-d');

                    // Временный график: основной день, попавший в активный период,
                    // подавляется; временный день — только внутри периода.
                    $src = $sc['source'] ?? 'main';
                    $inTemp = $soDateInTempPeriod($supId, $deliveryDate);
                    if ($src === 'main' && $inTemp) continue;
                    if ($src === 'temp' && !$inTemp) continue;

                    // Дедлайн через ядро: override → rule → default. is_closed здесь не учитываем
                    // (для совместимости с прежней логикой напоминаний — она тоже не различала закрытые дни).
                    $ovStmt = $pdo->prepare("SELECT deadline_date, deadline_time FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
                    $ovStmt->execute([$supId, $deliveryDate]);
                    $override = $ovStmt->fetch() ?: null;

                    $rlStmt = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
                    $rlStmt->execute([$supId, $deliveryDow]);
                    $rule = $rlStmt->fetch() ?: null;

                    $r = soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz);
                    if (!$r['deadline_dt']) continue;
                    $deadline = $r['deadline_dt'];
                    $minutesLeft = ($deadline->getTimestamp() - $now->getTimestamp()) / 60;

                    // Берём ближайший активный дедлайн (-10..+2000 мин)
                    if ($minutesLeft > -10 && $minutesLeft < 2000) {
                        if (!$nextDelivery || $minutesLeft < $nextDelivery['minutesLeft']) {
                            $nextDelivery = [
                                'date' => $deliveryDate,
                                'deadline' => $deadline,
                                'minutesLeft' => $minutesLeft,
                                'dow' => $deliveryDow,
                            ];
                        }
                    }
                }
            }

            if (!$nextDelivery) continue;

            $deliveryDate = $nextDelivery['date'];
            $minutesLeft = $nextDelivery['minutesLeft'];
            $deadlineFmt = $nextDelivery['deadline']->format('d.m H:i');

            // Есть ли непустая заявка?
            $oc = $pdo->prepare("SELECT COUNT(*) FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND status != 'draft'");
            $oc->execute([$supId, $restNum, $deliveryDate]);
            $hasOrder = (int)$oc->fetchColumn() > 0;

            // Вечернее напоминание в 18:00 за день до дедлайна
            $eveningCheck = clone $nextDelivery['deadline'];
            $eveningCheck->modify('-1 day')->setTime(18, 0);
            $minutesToEvening = ($eveningCheck->getTimestamp() - $now->getTimestamp()) / 60;

            $reminderType = null;
            if (!$hasOrder && $minutesToEvening <= 5 && $minutesToEvening > -5) {
                $reminderType = 'evening';
            } elseif (!$hasOrder && $minutesLeft <= -0.1 && $minutesLeft > -10) {
                $reminderType = 'expired';
            } elseif (!$hasOrder) {
                if ($minutesLeft <= 180 && $minutesLeft > 175) $reminderType = '3h';
                elseif ($minutesLeft <= 120 && $minutesLeft > 115) $reminderType = '2h';
                elseif ($minutesLeft <= 60 && $minutesLeft > 55) $reminderType = '1h';
                elseif ($minutesLeft <= 30 && $minutesLeft > 25) $reminderType = '30m';
            }

            if (!$reminderType) continue;

            // Фильтр таймингов по настройкам поставщика (дефолт: все включены — как раньше)
            if (!in_array($reminderType, $remOffsets, true)) continue;

            // Если поставщик осознанно выключил все каналы — слать нечего
            if (!$tgEnabled && !$pushEnabled) continue;

            // Дедупликация
            $dedupKey = "so_rem_{$reminderType}_{$supId}_{$restNum}_{$deliveryDate}";
            $dup = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_key = ? AND sent_at > NOW() - INTERVAL 24 HOUR LIMIT 1");
            $dup->execute([$dedupKey]);
            if ($dup->fetch()) continue;

            // Текст
            $dayNames = [1=>'понедельник',2=>'вторник',3=>'среду',4=>'четверг',5=>'пятницу',6=>'субботу',7=>'воскресенье'];
            $dayName = $dayNames[$nextDelivery['dow']] ?? '';

            $prettyRestNum = formatRestaurantNumber($restNum);
            if ($reminderType === 'expired') {
                $msgText = "⚠️ <b>Дедлайн заявки истёк!</b>\n\n";
                $msgText .= "🏪 Ресторан <b>{$prettyRestNum}</b>\n";
                $msgText .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                $msgText .= "📅 Доставка в {$dayName} ({$deliveryDate})\n\n";
                $msgText .= "Заявка не была подана.";
            } elseif ($reminderType === 'evening') {
                $msgText = "🌙 <b>Напоминание: заявка поставщику</b>\n\n";
                $msgText .= "🏪 Ресторан <b>{$prettyRestNum}</b>\n";
                $msgText .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                $msgText .= "📅 Доставка в {$dayName} ({$deliveryDate})\n";
                $msgText .= "⏳ Дедлайн завтра: <b>{$deadlineFmt}</b>\n\n";
                $msgText .= "Не забудьте подать заявку!";
            } else {
                $timeLabels = ['3h' => '3 часа', '2h' => '2 часа', '1h' => '1 час', '30m' => '30 минут'];
                $timeLabel = $timeLabels[$reminderType] ?? $reminderType;
                $msgText = "⏰ <b>Напоминание: заявка поставщику</b>\n\n";
                $msgText .= "🏪 Ресторан <b>{$prettyRestNum}</b>\n";
                $msgText .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                $msgText .= "📅 Доставка в {$dayName} ({$deliveryDate})\n";
                $msgText .= "⏳ До дедлайна: <b>{$timeLabel}</b> (до {$deadlineFmt})\n\n";
                $msgText .= "Заявка ещё не подана!";
            }

            $restGroup = $byRest[$restNum]['group'] ?? 'BK_VM';
            $redirect = "/restaurant/orders/supplier/{$supId}";

            // Дедуп-ключ на весь тик один (see $dedupKey выше) — не плодим по каналам.
            // Флаг: записан ли дедуп-лог. При включённом TG его пишет цикл рассылки
            // (место и поведение как раньше). При push-only лог фиксируем отдельно ниже.
            $dedupLogged = false;

            // ── Канал Telegram ──
            // Рассылаем каждому подписчику ресторана (свой токен на каждый chat_id)
            if ($tgEnabled) {
                $tokStmt = $pdo->prepare("INSERT INTO ro_tg_tokens (token, kind, telegram_chat_id, restaurant_number, legal_entity_group, expires_at, used) VALUES (?, 'auth', ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . RO_AUTH_TOKEN_TTL_MINUTES . " MINUTE), 0)");
                $logStmt = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_reminder', '', ?, ?)");
                foreach ($chatIds as $chatId) {
                    $token = bin2hex(random_bytes(32));
                    $tokStmt->execute([$token, $chatId, $restNum, $restGroup]);
                    $url = "{$SITE_URL}/restaurant?tg_token={$token}&redirect=" . urlencode($redirect);

                    $rows = [];
                    if ($reminderType !== 'expired') {
                        $rows[] = [['text' => '📝 Подать в боте', 'callback_data' => "soord_day_{$supId}_{$restNum}_{$deliveryDate}"]];
                    }
                    $rows[] = [['text' => '🌐 Открыть на сайте', 'url' => $url]];
                    $keyboard = ['inline_keyboard' => $rows];

                    tgSend($chatId, $msgText, true, $keyboard);
                    $sent++;
                    $logStmt->execute([$chatId, $dedupKey]);
                    $dedupLogged = true;
                }
            }

            // ── Канал Web Push ──
            // Один вызов на ресторан (не на каждый chat_id). Сбой пуша не должен
            // мешать TG и не ронять крон — оборачиваем в try/catch.
            if ($pushEnabled) {
                try {
                    // Короткий заголовок/текст без HTML-разметки Telegram
                    $pushTitles = [
                        'expired' => '⚠️ Дедлайн заявки истёк',
                        'evening' => '🌙 Напоминание: заявка поставщику',
                    ];
                    $pushTitle = $pushTitles[$reminderType] ?? '⏰ Напоминание: заявка поставщику';
                    if ($reminderType === 'expired') {
                        $pushBody = "{$supName}: заявка на {$deliveryDate} не подана, дедлайн истёк.";
                    } else {
                        $pushBody = "{$supName}: подайте заявку на {$deliveryDate}, дедлайн {$deadlineFmt}.";
                    }
                    pushSendToRestaurant($pdo, (int)$restNum, $restGroup, [
                        'title' => $pushTitle,
                        'body'  => $pushBody,
                        'url'   => $redirect,
                        'tag'   => $dedupKey,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[cron_telegram] so reminder push error: ' . $e->getMessage());
                }
            }

            // Если TG выключен (цикл рассылки не выполнялся и дедуп не записан),
            // но мы пытались отправить пуш — фиксируем дедуп один раз, чтобы
            // напоминание не уходило каждый прогон крона.
            if (!$dedupLogged && $pushEnabled) {
                $pdo->prepare("INSERT INTO tg_notification_log (notification_type, notification_key) VALUES ('so_reminder', ?)")->execute([$dedupKey]);
            }
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] so reminders error: ' . $e->getMessage());
}

// ═══ ЗАЯВКИ ПОСТАВЩИКАМ (so_*): авто-подача предыдущей заявки по дедлайну ═══
// Если у поставщика so_supplier_settings.auto_submit_previous = 1 — после прохождения
// дедлайна (окно -5..+15 мин) для каждого ресторана без submitted/locked заявки
// копируем последнюю поданную заявку того же ресторана как новую submitted.
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    // Только so_*-поставщики (подключённые через портал). Локальных не
    // автосабмитим — у них своя логика подачи через приложение.
    $autoSuppliers = $pdo->query("
        SELECT s.id, s.short_name,
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time
        FROM suppliers s
        JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1 AND sst.auto_submit_previous = 1 AND COALESCE(sst.is_accepting_orders, 1) = 1
    ")->fetchAll();

    foreach ($autoSuppliers as $sup) {
        $supId = $sup['id'];
        $supName = $sup['short_name'];
        $defaultDl = $sup['default_deadline_time'];

        // Собираем кандидатов по датам (2 недели вперёд) с учётом ЭФФЕКТИВНОГО графика:
        // внутри активного временного периода — временные дни/рестораны, иначе основные.
        $candidates = [];
        for ($iDay = 0; $iDay < 15; $iDay++) {
            $dObj = (clone $now)->setTime(0, 0, 0)->modify("+{$iDay} days");
            $deliveryDate = $dObj->format('Y-m-d');
            $deliveryDow = (int)$dObj->format('N');

            // Дедлайн через ядро: override → rule → default, forced_closed — пропускаем
            $ovStmt = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
            $ovStmt->execute([$supId, $deliveryDate]);
            $ov = $ovStmt->fetch() ?: null;

            $rlStmt = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
            $rlStmt->execute([$supId, $deliveryDow]);
            $rule = $rlStmt->fetch() ?: null;

            $r = soCalculateDeadlineCore($ov, $rule, $defaultDl, $deliveryDate, $tz);
            if (!empty($r['forced_closed']) || !$r['deadline_dt']) continue;
            $deadline = $r['deadline_dt'];
            $minutesSinceDeadline = ($now->getTimestamp() - $deadline->getTimestamp()) / 60;

            // Окно срабатывания: дедлайн прошёл от 0 до 15 минут назад
            if ($minutesSinceDeadline < -1 || $minutesSinceDeadline > 15) continue;

            foreach (soGetEffectiveScheduleRows($pdo, $supId, $deliveryDate, null, true) as $er) {
                if ((int)$er['delivery_day'] !== $deliveryDow) continue;
                $candidates[] = [
                    'restaurant_number' => (int)$er['restaurant_number'],
                    'delivery_date' => $deliveryDate,
                    'group' => $er['legal_entity_group'] ?: 'BK_VM',
                ];
            }
        }

        if (empty($candidates)) continue;

        // Убираем дубликаты (ресторан + дата)
        $seen = [];
        foreach ($candidates as $c) {
            $k = $c['restaurant_number'] . '|' . $c['delivery_date'];
            if (!isset($seen[$k])) $seen[$k] = $c;
        }

        foreach ($seen as $c) {
            $rn = $c['restaurant_number'];
            $dd = $c['delivery_date'];
            $le = roGetLegalEntity($pdo, $rn, $c['group']);

            // Есть ли уже submitted/locked заявка?
            $oc = $pdo->prepare("SELECT COUNT(*) FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND status IN ('submitted','locked')");
            $oc->execute([$supId, $rn, $dd]);
            if ((int)$oc->fetchColumn() > 0) continue;

            // Есть ли черновик с правками отдела закупок (admin_qty)?
            // Если отдел закупок вмешался — не подавать автоматически: это его решение.
            $draftCheck = $pdo->prepare("
                SELECT COUNT(*) FROM so_orders o
                JOIN so_order_items oi ON oi.order_id = o.id
                WHERE o.supplier_id = ? AND o.restaurant_number = ? AND o.delivery_date = ? AND o.legal_entity = ?
                  AND o.status = 'draft' AND oi.admin_qty IS NOT NULL
            ");
            $draftCheck->execute([$supId, $rn, $dd, $le]);
            if ((int)$draftCheck->fetchColumn() > 0) continue;

            // Ищем последнюю поданную заявку
            $prev = $pdo->prepare("
                SELECT id FROM so_orders
                WHERE supplier_id = ? AND restaurant_number = ? AND legal_entity = ?
                  AND status IN ('submitted','locked') AND delivery_date < ?
                ORDER BY delivery_date DESC LIMIT 1
            ");
            $prev->execute([$supId, $rn, $le, $dd]);
            $prevOrderId = $prev->fetchColumn();
            if (!$prevOrderId) continue;

            // Атомарный захват права на авто-подачу через UNIQUE(supplier_id,restaurant_number,delivery_date)
            // в so_auto_submit_log. Если параллельный cron уже обработал — INSERT IGNORE вернёт 0 затронутых строк.
            $lockStmt = $pdo->prepare("
                INSERT IGNORE INTO so_auto_submit_log (supplier_id, restaurant_number, delivery_date, source_order_id)
                VALUES (?, ?, ?, ?)
            ");
            $lockStmt->execute([$supId, $rn, $dd, $prevOrderId]);
            if ($lockStmt->rowCount() === 0) continue; // уже обработано

            // Копируем позиции
            $pdo->beginTransaction();
            try {
                // Создаём или обновляем заявку-черновик → submitted.
                // Черновик без admin_qty — безопасно перезаписать (проверили выше).
                $existing = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
                $existing->execute([$supId, $rn, $dd, $le]);
                $existingId = $existing->fetchColumn();

                if ($existingId) {
                    $pdo->prepare("UPDATE so_orders SET status='submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$existingId]);
                    $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$existingId]);
                    $newOrderId = $existingId;
                } else {
                    $pdo->prepare("
                        INSERT INTO so_orders (restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity)
                        VALUES (?, ?, ?, ?, 'submitted', NOW(), ?)
                    ")->execute([$rn, $supId, $dd, $now->format('Y-m-d'), $le]);
                    $newOrderId = (int)$pdo->lastInsertId();
                }

                // Копируем позиции (берём финальные значения: admin_qty если было, иначе quantity).
                // Только те, чей товар есть в АКТУАЛЬНОМ активном шаблоне поставщика —
                // иначе снятый из ассортимента товар продолжал бы авто-переноситься
                // (вручную ресторан его заказать уже не может, см. проверку в submit-order).
                $pdo->prepare("
                    INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity)
                    SELECT ?, soi.product_id, soi.sku, soi.product_name, COALESCE(soi.admin_qty, soi.quantity)
                    FROM so_order_items soi
                    JOIN so_templates t
                      ON t.sku = soi.sku AND t.supplier_id = ? AND t.legal_entity = ? AND t.is_active = 1
                    WHERE soi.order_id = ? AND COALESCE(soi.admin_qty, soi.quantity) > 0
                ")->execute([$newOrderId, $supId, $le, $prevOrderId]);

                // Дополняем запись лога новым order_id (сама запись уже вставлена lock-шагом выше).
                $pdo->prepare("UPDATE so_auto_submit_log SET new_order_id = ? WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ?")
                    ->execute([$newOrderId, $supId, $rn, $dd]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                // Откатываем lock-запись, чтобы следующий запуск cron смог повторить попытку.
                $pdo->prepare("DELETE FROM so_auto_submit_log WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND new_order_id IS NULL")
                    ->execute([$supId, $rn, $dd]);
                error_log('[cron_telegram] auto_submit error for ' . $supId . '/' . $rn . '/' . $dd . ': ' . $e->getMessage());
                continue;
            }

            // Уведомление подписчикам ресторана из видимых подписок бота.
            $subStmt = $pdo->prepare("
                SELECT DISTINCT chat_id
                FROM ro_telegram_subs
                WHERE restaurant_number = ?
                  AND legal_entity_group = ?
                  AND notify_so_reminders = 1 AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
                  AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
            ");
            $subStmt->execute([$rn, $c['group']]);
            $subChats = $subStmt->fetchAll(PDO::FETCH_COLUMN);
            $dateObj = new DateTime($dd);
            $itemsStmt = $pdo->prepare("
                SELECT sku, product_name, quantity
                FROM so_order_items
                WHERE order_id = ?
                ORDER BY product_name, id
            ");
            $itemsStmt->execute([$newOrderId]);
            $items = $itemsStmt->fetchAll();
            $msg = "🤖 <b>Заявка выставлена автоматически</b>\n\n";
            $msg .= "🏪 Ресторан <b>" . formatRestaurantNumber($rn) . "</b>\n";
            $msg .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
            $msg .= "📅 Доставка: <b>" . $dateObj->format('d.m.Y') . "</b>\n\n";
            $msg .= "Дедлайн прошёл — подали копию вашей предыдущей заявки.";
            if ($items) {
                $msg .= "\n\n📋 <b>Что подали:</b>\n";
                foreach ($items as $item) {
                    $name = trim(($item['sku'] ?: '') . ' ' . ($item['product_name'] ?: ''));
                    if ($name === '') $name = 'Товар без названия';
                    $qty = rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.');
                    $msg .= "• " . htmlspecialchars($name, ENT_QUOTES) . " — <b>{$qty}</b>\n";
                }
            }
            foreach ($subChats as $cid) {
                tgSend($cid, $msg, true);
                $sent++;
            }
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] so auto-submit error: ' . $e->getMessage());
}

// Авто-отправка сводки поставщику на email в дедлайн.
// Отдельно от auto_submit_previous: критерий — so_supplier_settings.auto_email_summary=1
// и непустой suppliers.email. Идём тем же окном дедлайна (0..15 мин после), одно
// письмо на (поставщик, день) — защита в soSendSummaryEmail через so_email_auto_log.
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $emailSuppliers = $pdo->query("
        SELECT s.id, COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time
        FROM suppliers s
        JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1
          AND sst.auto_email_summary = 1
          AND s.email IS NOT NULL AND s.email <> ''
    ")->fetchAll();

    foreach ($emailSuppliers as $sup) {
        $supId = $sup['id'];
        $defaultDl = $sup['default_deadline_time'];
        for ($iDay = 0; $iDay < 15; $iDay++) {
            $dObj = (clone $now)->setTime(0, 0, 0)->modify("+{$iDay} days");
            $deliveryDate = $dObj->format('Y-m-d');
            $deliveryDow = (int)$dObj->format('N');

            $ovStmt = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
            $ovStmt->execute([$supId, $deliveryDate]);
            $ov = $ovStmt->fetch() ?: null;
            $rlStmt = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
            $rlStmt->execute([$supId, $deliveryDow]);
            $rule = $rlStmt->fetch() ?: null;

            $r = soCalculateDeadlineCore($ov, $rule, $defaultDl, $deliveryDate, $tz);
            if (!empty($r['forced_closed']) || !$r['deadline_dt']) continue;
            $minutesSinceDeadline = ($now->getTimestamp() - $r['deadline_dt']->getTimestamp()) / 60;
            if ($minutesSinceDeadline < -1 || $minutesSinceDeadline > 15) continue;

            // Одно письмо на поставщика+день; skipped='already_sent' при повторе.
            $res = soSendSummaryEmail($pdo, $supId, $deliveryDate, 'auto', null, null);
            if (!empty($res['success'])) {
                error_log("[so auto-email] sent supplier={$supId} date={$deliveryDate} rests={$res['restaurants_count']}");
            } elseif (!empty($res['skipped']) && $res['skipped'] !== 'already_sent' && $res['skipped'] !== 'empty') {
                error_log("[so auto-email] skip supplier={$supId} date={$deliveryDate} reason={$res['skipped']}");
            }
        }
    }
} catch (Throwable $e) {
    error_log('[so auto-email] fatal: ' . $e->getMessage());
}

// ═══ ЗАЯВКИ ПОСТАВЩИКАМ (so_*): итоговая сводка отделу закупок после дедлайна ═══
// После прохождения дедлайна (не более 20 мин назад) шлём сводку только тем,
// кто подписан на конкретного поставщика.
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    $suppliers = $pdo->query("
        SELECT DISTINCT s.id, s.short_name, s.legal_entity, s.legal_entity_group,
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time
        FROM suppliers s
        JOIN supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
        LEFT JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1
          AND COALESCE(sst.is_accepting_orders, 1) = 1
    ")->fetchAll();

    foreach ($suppliers as $sup) {
        $subsStmt = $pdo->prepare("
            SELECT u.name, u.telegram_chat_id
            FROM so_supplier_summary_subscribers sss
            JOIN users u ON u.name = sss.user_name
            WHERE sss.supplier_id = ?
              AND u.telegram_chat_id IS NOT NULL
              AND u.telegram_chat_id != ''
              AND (u.tg_blocked_at IS NULL OR u.tg_blocked_at < NOW() - INTERVAL 30 DAY)
            ORDER BY u.name
        ");
        $subsStmt->execute([$sup['id']]);
        $subs = $subsStmt->fetchAll();
        if (!$subs) {
            continue;
        }

            $supId = $sup['id'];
            $supName = $sup['short_name'];
            $defaultDeadlineTime = $sup['default_deadline_time'];
            $supplierGroup = $sup['legal_entity_group'] ?: getEntityGroup($sup['legal_entity'] ?? '');
            $supplierEntities = getEntitiesInGroup($supplierGroup);
            $entityPh = implode(',', array_fill(0, count($supplierEntities), '?'));

            // Ближайшие даты поставки (2 недели вперёд) с учётом ЭФФЕКТИВНОГО графика:
            // внутри активного временного периода — временные дни/рестораны, иначе основные.
            for ($iDay = 0; $iDay < 15; $iDay++) {
                $deliveryDateObj = (clone $now)->setTime(0, 0, 0)->modify("+{$iDay} days");
                $deliveryDate = $deliveryDateObj->format('Y-m-d');
                $deliveryDow = (int)$deliveryDateObj->format('N');

                // Ожидаемые рестораны на эту дату по эффективному графику (группа поставщика)
                $expectedRests = [];
                $seenNums = [];
                foreach (soGetEffectiveScheduleRows($pdo, $supId, $deliveryDate, null, true) as $er) {
                    if ((int)$er['delivery_day'] !== $deliveryDow) continue;
                    if (($er['legal_entity_group'] ?? '') !== $supplierGroup) continue;
                    $num = (string)$er['restaurant_number'];
                    if (isset($seenNums[$num])) continue;
                    $seenNums[$num] = true;
                    $expectedRests[] = [
                        'number'  => $er['restaurant_number'],
                        'region'  => $er['region'] ?? '',
                        'address' => $er['address'] ?? '',
                        'city'    => $er['city'] ?? '',
                    ];
                }
                if (!$expectedRests) continue;
                usort($expectedRests, function ($a, $b) {
                    $rc = strcmp((string)($a['region'] ?? ''), (string)($b['region'] ?? ''));
                    return $rc !== 0 ? $rc : ((int)$a['number'] <=> (int)$b['number']);
                });

                // Дедлайн через ядро: override → rule → default. Закрытые дни пропускаем.
                $ovStmt = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
                $ovStmt->execute([$supId, $deliveryDate]);
                $override = $ovStmt->fetch() ?: null;

                $rlStmt = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
                $rlStmt->execute([$supId, $deliveryDow]);
                $rule = $rlStmt->fetch() ?: null;

                $r = soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz);
                if (!empty($r['forced_closed']) || !$r['deadline_dt']) continue;
                $deadline = $r['deadline_dt'];
                $minutesSince = ($now->getTimestamp() - $deadline->getTimestamp()) / 60;

                // Отправляем после дедлайна, даже если cron пропустил стандартное окно.
                // От дублей защищает notification_key ниже.
                if ($minutesSince < 0) continue;

                // Дедупликация
                $dedupKey = "so_summary_{$supId}_{$deliveryDate}";
                $dup = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type = 'so_summary' AND notification_key = ? AND sent_at > NOW() - INTERVAL 7 DAY LIMIT 1");
                $dup->execute([$dedupKey]);
                if ($dup->fetch()) continue;

                $expectedNums = array_values(array_unique(array_map('strval', array_column($expectedRests, 'number'))));
                $expectedPh = implode(',', array_fill(0, count($expectedNums), '?'));

                // Кто подал заявку (по статусу, независимо от количеств)
                $subStmt = $pdo->prepare("
                    SELECT restaurant_number FROM so_orders
                    WHERE supplier_id = ? AND delivery_date = ? AND status != 'draft'
                      AND legal_entity IN ({$entityPh})
                      AND restaurant_number IN ({$expectedPh})
                ");
                $subStmt->execute(array_merge([$supId, $deliveryDate], $supplierEntities, $expectedNums));
                $submittedByStatus = array_flip($subStmt->fetchAll(PDO::FETCH_COLUMN));

                // Позиции с ненулевыми количествами — для таблицы/пивота
                $ordStmt = $pdo->prepare("
                    SELECT o.restaurant_number, oi.sku, oi.product_name,
                           COALESCE(oi.admin_qty, oi.quantity) AS qty
                    FROM so_orders o
                    JOIN so_order_items oi ON oi.order_id = o.id
                    WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.status != 'draft'
                      AND o.legal_entity IN ({$entityPh})
                      AND o.restaurant_number IN ({$expectedPh})
                      AND COALESCE(oi.admin_qty, oi.quantity) > 0
                ");
                $ordStmt->execute(array_merge([$supId, $deliveryDate], $supplierEntities, $expectedNums));
                $orderRows = $ordStmt->fetchAll();

                // Пивот: список товаров и матрица значений
                $productsOrdered = [];  // sku => ['sku','name']
                $pivot = [];            // rest_num => sku => qty
                foreach ($orderRows as $row) {
                    $sku = $row['sku'];
                    if (!isset($productsOrdered[$sku])) {
                        $productsOrdered[$sku] = ['sku' => $sku, 'name' => $row['product_name']];
                    }
                    $rn = $row['restaurant_number'];
                    if (!isset($pivot[$rn])) $pivot[$rn] = [];
                    $pivot[$rn][$sku] = ($pivot[$rn][$sku] ?? 0) + (float)$row['qty'];
                }
                uasort($productsOrdered, function($a, $b) { return strcmp($a['name'], $b['name']); });

                // Считаем подавших по статусу заявки, а не по наличию ненулевых позиций
                $submittedCount = count(array_intersect($expectedNums, array_keys($submittedByStatus)));
                $missingCount = count($expectedNums) - $submittedCount;
                $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
                $dateFmt = $deliveryDateObj->format('d.m.Y');
                $dayShort = $dayNames[(int)$deliveryDateObj->format('N')] ?? '';

                // Если вообще никто не подал — шлём только текст без файла
                if (!$productsOrdered) {
                    $caption = "⚠️ <b>Никто не подал заявку</b>\n";
                    $caption .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                    $caption .= "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n";
                    $caption .= "🏪 Ресторанов по графику: <b>" . count($expectedRests) . "</b>";
                    $perUser = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?, '', ?, ?)");
                    $sentCheck = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type = 'so_summary_sent' AND notification_key = ? AND chat_id = ? AND sent_at > NOW() - INTERVAL 7 DAY LIMIT 1");
                    $successCount = 0;
                    foreach ($subs as $sub) {
                        $sentCheck->execute([$dedupKey, $sub['telegram_chat_id']]);
                        if ($sentCheck->fetch()) {
                            $successCount++;
                            continue;
                        }
                        $ok = tgSend($sub['telegram_chat_id'], $caption, true);
                        $type = $ok !== false ? 'so_summary_sent' : 'so_summary_fail';
                        $perUser->execute([$type, $sub['telegram_chat_id'], $dedupKey]);
                        if ($ok !== false) {
                            $sent++;
                            $successCount++;
                        }
                    }
                    if ($successCount >= count($subs)) {
                        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")
                            ->execute([$dedupKey]);
                    }
                    continue;
                }

                // Список товаров: те, что есть в заказах (из шаблона при необходимости)
                $productsOut = array_values($productsOrdered);

                // Если ни один ресторан не подал — товаров нет, но нам всё равно нужен
                // хоть один столбец-товар. В этом случае тянем шаблон поставщика.
                if (!$productsOut) {
                    $tplStmt = $pdo->prepare("SELECT DISTINCT sku, product_name FROM so_templates WHERE supplier_id = ? AND legal_entity IN ({$entityPh}) AND is_active = 1 ORDER BY sort_order, product_name");
                    $tplStmt->execute(array_merge([$supId], $supplierEntities));
                    foreach ($tplStmt->fetchAll() as $t) {
                        $productsOut[] = ['sku' => $t['sku'], 'name' => $t['product_name']];
                    }
                }

                // Формируем данные для Node-генератора
                $restaurantsOut = [];
                foreach ($expectedRests as $rest) {
                    $rn = $rest['number'];
                    $restaurantsOut[] = [
                        'number'   => (int)$rn,
                        'city'     => $rest['city'] ?: '',
                        'region'   => $rest['region'] ?: '',
                        'address'  => $rest['address'] ?: '',
                        'submitted'=> isset($submittedByStatus[$rn]),
                    ];
                }

                $itemsOut = new stdClass();
                $colTotals = array_fill_keys(array_column($productsOut, 'sku'), 0);
                foreach ($pivot as $rn => $pmap) {
                    foreach ($pmap as $sku => $qty) {
                        $itemsOut->{"{$rn}_{$sku}"} = ['qty' => (float)$qty, 'is_admin' => false];
                        if (isset($colTotals[$sku])) $colTotals[$sku] += (float)$qty;
                    }
                }

                $payload = [
                    'supplier_name'      => $supName,
                    'delivery_date_fmt'  => $dateFmt,
                    'sheet_name'         => $supName,
                    'products'           => $productsOut,
                    'restaurants'        => $restaurantsOut,
                    'items'              => $itemsOut,
                ];

                // Временные файлы для обмена с Node
                $tmpJson = tempnam(sys_get_temp_dir(), 'so_json_');
                $tmpXlsx = tempnam(sys_get_temp_dir(), 'so_xlsx_') . '.xlsx';
                file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));

                $scriptPath = escapeshellarg(__DIR__ . '/../scripts/build_so_order_xlsx.mjs');
                $cmd = 'node ' . $scriptPath . ' ' . escapeshellarg($tmpJson) . ' ' . escapeshellarg($tmpXlsx) . ' 2>&1';
                exec($cmd, $outLines, $rc);
                @unlink($tmpJson);

                if ($rc !== 0 || !file_exists($tmpXlsx)) {
                    error_log('[cron_telegram] so summary: node generator failed (rc=' . $rc . '): ' . implode("\n", $outLines));
                    @unlink($tmpXlsx);
                    continue;
                }

                $xlsxBinary = file_get_contents($tmpXlsx);
                @unlink($tmpXlsx);

                $filename = "Заявка {$supName} на {$dateFmt}.xlsx";

                $caption = "🧾 <b>Заказ поставщику</b>\n";
                $caption .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                $caption .= "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n";
                $caption .= "\n";
                $caption .= "✅ Подали: <b>{$submittedCount}</b> из <b>" . count($expectedRests) . "</b>\n";
                if ($missingCount > 0) {
                    $caption .= "❌ Не подали: <b>{$missingCount}</b>\n";
                }
                arsort($colTotals);
                $topProducts = array_slice($colTotals, 0, 5, true);
                if ($topProducts) {
                    $caption .= "\n📊 <b>Итого по товарам:</b>\n";
                    foreach ($topProducts as $sku => $tot) {
                        if ($tot <= 0) continue;
                        $name = $productsOrdered[$sku]['name'] ?? $sku;
                        $caption .= "• " . htmlspecialchars($name, ENT_QUOTES) . " — <b>" . rtrim(rtrim(number_format($tot, 2, '.', ''), '0'), '.') . "</b>\n";
                    }
                    if (count($colTotals) > 5) {
                        $caption .= "… и ещё " . (count($colTotals) - 5) . " позиций в файле";
                    }
                }

                $perUser = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?, '', ?, ?)");
                $sentCheck = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type = 'so_summary_sent' AND notification_key = ? AND chat_id = ? AND sent_at > NOW() - INTERVAL 7 DAY LIMIT 1");
                $successCount = 0;
                foreach ($subs as $sub) {
                    $sentCheck->execute([$dedupKey, $sub['telegram_chat_id']]);
                    if ($sentCheck->fetch()) {
                        $successCount++;
                        continue;
                    }
                    $ok = tgSendDocument($sub['telegram_chat_id'], $filename, $xlsxBinary, $caption);
                    $type = $ok !== false ? 'so_summary_sent' : 'so_summary_fail';
                    $perUser->execute([$type, $sub['telegram_chat_id'], $dedupKey]);
                    if ($ok !== false) {
                        $sent++;
                        $successCount++;
                    }
                }

                if ($successCount >= count($subs)) {
                    $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")
                        ->execute([$dedupKey]);
                }
            }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] so summary error: ' . $e->getMessage());
}

// ═══ ОПРОСЫ: напоминания ресторанам, которые не ответили ═══
try {
    $surveys = $pdo->query("
        SELECT id, title, legal_entity_group, remind_after_hours
        FROM surveys
        WHERE status = 'active'
          AND sent_at IS NOT NULL
          AND sent_at <= NOW() - INTERVAL remind_after_hours HOUR
    ")->fetchAll();

    foreach ($surveys as $survey) {
        $surveyId = $survey['id'];
        $surveyTitle = $survey['title'];
        $surveyGroup = $survey['legal_entity_group'];
        $intervalSeconds = max(1, (int)$survey['remind_after_hours']) * 3600;

        $chatIds = [];

        $roPendingChats = $pdo->prepare("
            SELECT DISTINCT CAST(rs.chat_id AS CHAR) AS chat_id
            FROM ro_telegram_subs rs
            JOIN restaurants r
              ON r.number = rs.restaurant_number
             AND r.active = 1
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
            LEFT JOIN survey_responses sr
              ON sr.survey_id = ?
             AND sr.restaurant_number = rs.restaurant_number
            WHERE rs.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
              AND rs.chat_id IS NOT NULL
              AND (rs.verified_at IS NOT NULL
                   OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))
              AND (rs.tg_blocked_at IS NULL OR rs.tg_blocked_at < NOW() - INTERVAL 30 DAY)
              AND sr.id IS NULL
        ");
        $roPendingChats->execute([$surveyId, $surveyGroup]);
        foreach ($roPendingChats->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
            $chatId = trim((string)$chatId);
            if ($chatId !== '') $chatIds[$chatId] = true;
        }

        foreach (array_keys($chatIds) as $chatId) {
            $notificationKey = "survey_reminder_{$surveyId}_{$chatId}";
            if (wasNotifiedByKey($pdo, $notificationKey, $intervalSeconds)) {
                continue;
            }
            // Отдельная проверка «отложено на час» — если пользователь нажал
            // «Напомнить через час», не шлём повтор до истечения часа.
            $snoozeKey = "survey_snooze_{$surveyId}_{$chatId}";
            if (wasNotifiedByKey($pdo, $snoozeKey, 3600)) {
                continue;
            }

            $text = "🔔 <b>Напоминание</b>\n\n";
            $text .= "У вас ещё есть рестораны без ответа в опросе:\n«" . htmlspecialchars($surveyTitle, ENT_QUOTES, 'UTF-8') . "»\n\n";
            $text .= "Пожалуйста, откройте опрос и заполните оставшиеся ответы.";

            $btns = ['inline_keyboard' => [
                [['text' => '📋 Пройти опрос', 'callback_data' => "srv_start_{$surveyId}"]],
                [['text' => '⏰ Напомнить через час', 'callback_data' => "srv_snooze_{$surveyId}"]],
            ]];

            if (tgSend($chatId, $text, false, $btns)) {
                logNotificationByKey($pdo, 'survey_reminder', $notificationKey, (int)$chatId, $surveyGroup);
                $sent++;
            }
        }
    }
} catch (Exception $e) {
    error_log('[cron_telegram] survey reminder error: ' . $e->getMessage());
}

// Очистка старых записей дедупликации (старше 7 дней)
try {
    $pdo->exec("DELETE FROM tg_notification_log WHERE sent_at < NOW() - INTERVAL 7 DAY");
} catch (Exception $e) {}

// Очистка истёкших сессий
try {
    $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");
} catch (Exception $e) {}

echo "Отправлено: {$sent}\n";
