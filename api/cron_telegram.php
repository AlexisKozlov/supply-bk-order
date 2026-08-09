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
require_once __DIR__ . '/includes/so_loading_sheets.php'; // soLsSupplierEnabled: признак цеха

// Включён ли день доставки в маске напоминаний подписки. NULL/пусто → все дни,
// 0 → явно снято всё. Та же логика, что rrDayEnabled в restaurant_reminders.php.
function rrDayEnabledPhp($mask, int $deliveryDow): bool {
    if ($mask === null || $mask === '') return true;
    $mask = (int)$mask;
    if ($mask === 0) return false;
    return ($mask & (1 << ($deliveryDow - 1))) !== 0;
}

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
// Режим проверки: `php cron_telegram.php --dry-run` печатает, что ушло бы,
// и никому ничего не шлёт. Нужен, чтобы проверять новые напоминания на боевых
// данных, не дёргая живых людей.
$DRY_RUN = in_array('--dry-run', $argv ?? [], true);

function tgSend($chatId, $text, $disablePreview = false, $replyMarkup = null) {
    global $pdo, $DRY_RUN;
    if ($DRY_RUN) {
        echo "\n--- [проверка] кому: $chatId ---\n" . strip_tags($text) . "\n";
        return true;
    }
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

/** «1 день», «2 дня», «5 дней» — чтобы в сводке не было «прошло 2 дн.». */
function plural_days($n) {
    $n = abs((int)$n);
    $t = $n % 100;
    if ($t >= 11 && $t <= 14) return 'дней';
    switch ($n % 10) {
        case 1:  return 'день';
        case 2:
        case 3:
        case 4:  return 'дня';
        default: return 'дней';
    }
}

function logNotification($pdo, $type, $legalEntity, $chatId) {
    global $DRY_RUN;
    // В режиме проверки не отмечаем «уже отправлено» — иначе настоящее
    // напоминание потом не уйдёт, его задавит ограничитель повторов.
    if (!empty($DRY_RUN)) return;
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
                        WHERE a.consumption > 0 AND a.stock > 0"
                        // Здесь два юрлица в запросе (остатки и карточки), поэтому
                        // фильтру нужен явный алиас: без него MySQL отвечал
                        // «Column 'legal_entity' is ambiguous», блок молча падал,
                        // и утренняя сводка уходила без критических остатков.
                        . str_replace(' legal_entity IN', ' a.legal_entity IN', $leFilter) . "
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

// ═══ Требуют внимания: один дайджест вместо россыпи сообщений ═══
//
// Раньше каждое напоминание уходило отдельным сообщением, и в понедельник
// человек получал бы семь штук подряд: четыре про приёмку, три про акции.
// Ровно то, от чего уходим — россыпь одинаковых уведомлений перестают
// читать целиком (так вышло с задачами: 40 сообщений в день с мая).
//
// Теперь на человека приходит одно письмо со всеми его хвостами:
//   • просроченные оплаты — портал вёл платёж до «заявка подана» и замолкал,
//     два платежа Скандипакку висели просроченными пять дней;
//   • неотмеченная приёмка — пока её нет, план-факт по заказу не посчитать;
//   • акции, закрытые по дате, — статус «Завершённая» не ставил никто ни разу.
//
// Когда шлём: если появился хотя бы один новый хвост — сразу, со всем списком.
// Если новых нет, а старые висят — повтор раз в шесть дней. Ежедневного
// повторения нет намеренно.
//
// Только по будням: платёж в выходной не проведут, приёмку не отметят.
// Пробный прогон (--dry-run) выходные игнорирует — он ничего не отправляет.
if (!$isWeekend || $DRY_RUN) {
    try {
        // Цифры и пороги должны совпадать с блоком «Требуют внимания»
        // на дашборде (api/includes/rpc/attention.php).
        $DIGEST_REPEAT = 518400; // 6 суток
        $digest = [];            // chat_id => ['payments'=>[], 'receiving'=>[], 'marketing'=>[], 'fresh'=>bool]

        $chatStmt = $pdo->prepare("
            SELECT telegram_chat_id FROM users
            WHERE name = ? AND telegram_chat_id IS NOT NULL
              AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
        ");
        $chatCache = [];
        $chatOf = function ($name) use ($chatStmt, &$chatCache) {
            if (!$name) return null;
            if (array_key_exists($name, $chatCache)) return $chatCache[$name];
            $chatStmt->execute([$name]);
            return $chatCache[$name] = ($chatStmt->fetchColumn() ?: null);
        };
        // Добавить пункт получателю. $key — чтобы понять, новый он или уже был.
        $add = function ($chatId, $section, $line, $key) use (&$digest, $pdo, $DIGEST_REPEAT) {
            if (!isset($digest[$chatId])) {
                $digest[$chatId] = ['tasks' => [], 'payments' => [], 'receiving' => [], 'tenders' => [], 'distribution' => [], 'surveys' => [], 'marketing' => [], 'keys' => [], 'buttons' => [], 'fresh' => false];
            }
            $digest[$chatId][$section][] = $line;
            $digest[$chatId]['keys'][] = $key;
            if (!wasNotified($pdo, 'attention_digest', $key, $chatId, $DIGEST_REPEAT)) {
                $digest[$chatId]['fresh'] = true;
            }
        };

        // ── 0. Просроченные задачи ──────────────────────────────────────
        // Раньше каждая просроченная задача слала своё сообщение каждый будний
        // день: 33 задачи давали около 40 сообщений в сутки, и это шло с мая
        // без единой реакции. Теперь они здесь, а под списком — кнопки
        // «+7 дней», чтобы перенести срок не заходя в портал.
        // Условия те же, что в cron_tasks_deadlines.php: открытая карточка на
        // живой доске, и родитель подзадачи тоже жив (иначе задача на доске не
        // видна, а напоминание о ней выглядит как призрак).
        $overdueTasks = $pdo->query("
            SELECT c.id, c.title, c.board_id, c.due_date,
                   b.title AS board_title, b.owner_name,
                   DATEDIFF(CURDATE(), DATE(c.due_date)) AS overdue_days
            FROM tasks_cards c
            JOIN tasks_boards b ON b.id = c.board_id
            LEFT JOIN tasks_cards p ON p.id = c.parent_card_id
            WHERE c.is_done = 0
              AND c.is_archived = 0
              AND c.due_date IS NOT NULL
              AND DATE(c.due_date) < CURDATE()
              AND b.is_archived = 0
              AND (c.parent_card_id IS NULL OR (COALESCE(p.is_done, 0) = 0 AND COALESCE(p.is_archived, 0) = 0))
            ORDER BY c.due_date
        ")->fetchAll();
        // Соисполнители, у которых задача ещё в работе: закрывший свою часть
        // напоминаний получать не должен, даже если у автора карточка открыта.
        $taskAssignees = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ? AND is_done = 0");
        $BTN_LIMIT = 6; // больше кнопок в одном сообщении — уже стена
        foreach ($overdueTasks as $t) {
            $taskAssignees->execute([$t['id']]);
            $people = array_unique(array_filter(array_merge(
                [$t['owner_name']], array_column($taskAssignees->fetchAll(), 'user_name')
            )));
            $days  = (int)$t['overdue_days'];
            $board = $t['board_title'] ? "\n   📋 {$t['board_title']}" : '';
            $line  = "• <b>{$t['title']}</b> — просрочена на {$days} " . plural_days($days) . $board;
            foreach ($people as $name) {
                $chatId = $chatOf($name);
                if (!$chatId) continue;
                $add($chatId, 'tasks', $line, "task_{$t['id']}");
                if (count($digest[$chatId]['buttons']) < $BTN_LIMIT) {
                    $short = mb_substr($t['title'], 0, 22);
                    if (mb_strlen($t['title']) > 22) $short .= '…';
                    $digest[$chatId]['buttons'][] = [[
                        'text'          => "🗓 +7 дн: {$short}",
                        'callback_data' => "tsnz_{$t['id']}",
                    ]];
                }
            }
        }

        // ── 1. Просроченные оплаты ──────────────────────────────────────
        $statusRu = ['upcoming' => 'предстоит', 'request_due' => 'нужна заявка', 'requested' => 'заявка подана'];
        $overdue = $pdo->query("
            SELECT sp.id, sp.supplier, sp.amount, sp.currency, sp.status,
                   sp.payment_due_date, sp.legal_entity,
                   sp.created_by AS pay_author, sp.paid_by AS requester,
                   o.created_by  AS order_author,
                   DATEDIFF(CURDATE(), sp.payment_due_date) AS overdue_days
            FROM supplier_payments sp
            LEFT JOIN orders o ON o.id = sp.order_id
            WHERE sp.status NOT IN ('paid', 'cancelled')
              AND sp.payment_due_date IS NOT NULL
              AND sp.payment_due_date < CURDATE()
            ORDER BY sp.payment_due_date
        ")->fetchAll();
        foreach ($overdue as $p) {
            $days = (int)$p['overdue_days'];
            $amount = $p['amount']
                ? number_format((float)$p['amount'], 2, ',', ' ') . ' ' . ($p['currency'] ?: 'RUB')
                : 'сумма не указана';
            $line = "• <b>{$p['supplier']}</b> — {$amount}\n"
                  . "   срок " . date('d.m.Y', strtotime($p['payment_due_date']))
                  . ", просрочен на {$days} " . plural_days($days)
                  . " (" . ($statusRu[$p['status']] ?? $p['status']) . ")";
            foreach (array_unique(array_filter([$p['pay_author'], $p['requester'], $p['order_author']])) as $name) {
                $chatId = $chatOf($name);
                if ($chatId) $add($chatId, 'payments', $line, "pay_{$p['id']}");
            }
        }

        // ── 2. Неотмеченная приёмка ─────────────────────────────────────
        // Ждём два дня после поставки: в день привоза отмечать некогда,
        // на следующий тоже бывает не до того.
        $notReceived = $pdo->query("
            SELECT o.id, o.supplier, o.legal_entity, o.delivery_date, o.created_by,
                   DATEDIFF(CURDATE(), o.delivery_date) AS late_days,
                   (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS items
            FROM orders o
            WHERE o.received_at IS NULL
              AND o.delivery_date IS NOT NULL
              AND DATEDIFF(CURDATE(), o.delivery_date) >= 2
            ORDER BY o.delivery_date
        ")->fetchAll();
        foreach ($notReceived as $o) {
            $chatId = $chatOf($o['created_by']);
            if (!$chatId) continue;
            $days = (int)$o['late_days'];
            $line = "• <b>{$o['supplier']}</b> — поставка "
                  . date('d.m.Y', strtotime($o['delivery_date']))
                  . ", прошло {$days} " . plural_days($days)
                  . " ({$o['items']} поз.)";
            $add($chatId, 'receiving', $line, "ord_{$o['id']}");
        }

        // ── 3. Зависшие тендеры ─────────────────────────────────────────
        // Один дедлайн ни о чём не говорит: сразу после него идёт нормальная
        // работа — оценка предложений, согласование. Признак «зависло» —
        // дедлайн прошёл И неделю по тендеру никто ничего не менял.
        // Так «Стаканы девочка» простояли в согласовании 51 день.
        $tenderStatusRu = [
            'draft'      => 'черновик',
            'evaluation' => 'оценка предложений',
            'approval'   => 'согласование',
        ];
        $stuckTenders = $pdo->query("
            SELECT t.id, t.name, t.status, t.deadline, t.created_by,
                   DATEDIFF(CURDATE(), t.deadline)         AS late_days,
                   DATEDIFF(CURDATE(), DATE(t.updated_at)) AS idle_days,
                   (SELECT COUNT(*) FROM tender_offers o WHERE o.tender_id = t.id) AS offers
            FROM tenders t
            WHERE t.status NOT IN ('completed', 'closed', 'archived', 'cancelled')
              AND t.deadline IS NOT NULL
              AND t.deadline < CURDATE()
              AND DATEDIFF(CURDATE(), DATE(t.updated_at)) >= 7
            ORDER BY t.deadline
        ")->fetchAll();
        foreach ($stuckTenders as $t) {
            $chatId = $chatOf($t['created_by']);
            if (!$chatId) continue;
            $idle = (int)$t['idle_days'];
            $line = "• <b>{$t['name']}</b> — " . ($tenderStatusRu[$t['status']] ?? $t['status'])
                  . ", предложений {$t['offers']}\n"
                  . "   дедлайн " . date('d.m.Y', strtotime($t['deadline']))
                  . ", без движения {$idle} " . plural_days($idle);
            $add($chatId, 'tenders', $line, "tnd_{$t['id']}");
        }

        // ── 4. Сессии распределения без движения ────────────────────────
        // Возраст сессии сам по себе ни о чём не говорит. Смотрим на две
        // вещи: сколько строк отгружено и сколько дней по сессии никто
        // ничего не менял. Так «картофельные стрипсы» оказались отгружены
        // полностью — сессию просто забыли закрыть.
        $stuckDist = $pdo->query("
            SELECT s.id, s.name, s.created_by,
                   DATE(s.created_at) AS started,
                   (SELECT COUNT(*) FROM dist_entries e
                      JOIN dist_session_products sp ON sp.id = e.session_product_id
                     WHERE sp.session_id = s.id) AS total,
                   (SELECT COUNT(*) FROM dist_entries e
                      JOIN dist_session_products sp ON sp.id = e.session_product_id
                     WHERE sp.session_id = s.id AND e.shipped = 1) AS shipped,
                   DATEDIFF(CURDATE(), COALESCE(
                       (SELECT MAX(DATE(e.updated_at)) FROM dist_entries e
                          JOIN dist_session_products sp ON sp.id = e.session_product_id
                         WHERE sp.session_id = s.id),
                       DATE(s.created_at))) AS idle_days
            FROM dist_sessions s
            WHERE s.closed_at IS NULL
            HAVING idle_days >= 7
            ORDER BY idle_days DESC
        ")->fetchAll();
        foreach ($stuckDist as $s) {
            $chatId = $chatOf($s['created_by']);
            if (!$chatId) continue;
            $idle  = (int)$s['idle_days'];
            $done  = (int)$s['shipped'];
            $total = (int)$s['total'];
            $tail  = ($total > 0 && $done >= $total)
                ? 'отгружено всё, осталось закрыть'
                : "отгружено {$done} из {$total}";
            $line = "• <b>{$s['name']}</b> — {$tail}\n"
                  . "   открыта с " . date('d.m.Y', strtotime($s['started']))
                  . ", без движения {$idle} " . plural_days($idle);
            $add($chatId, 'distribution', $line, "dist_{$s['id']}");
        }

        // ── 5. Опросы, на которые перестали отвечать ────────────────────
        // «Открыт больше 30 дней» — плохой признак: опрос по кабинету идёт
        // с 4 мая и до сих пор собирает ответы, закрывать его рано.
        // Признак «пора закрывать» — две недели без единого нового ответа.
        $staleSurveys = $pdo->query("
            SELECT s.id, s.title, s.created_by, DATE(s.sent_at) AS sent,
                   (SELECT COUNT(DISTINCT r.restaurant_number) FROM survey_responses r
                     WHERE r.survey_id = s.id) AS answers,
                   (SELECT MAX(DATE(r.submitted_at)) FROM survey_responses r
                     WHERE r.survey_id = s.id) AS last_answer,
                   DATEDIFF(CURDATE(), COALESCE(
                       (SELECT MAX(DATE(r.submitted_at)) FROM survey_responses r WHERE r.survey_id = s.id),
                       DATE(s.sent_at))) AS quiet_days
            FROM surveys s
            WHERE s.status = 'active' AND s.sent_at IS NOT NULL
            HAVING quiet_days >= 14
            ORDER BY quiet_days DESC
        ")->fetchAll();
        foreach ($staleSurveys as $s) {
            $chatId = $chatOf($s['created_by']);
            if (!$chatId) continue;
            $quiet = (int)$s['quiet_days'];
            $line = "• <b>{$s['title']}</b> — ответили {$s['answers']}\n"
                  . "   " . ($s['last_answer']
                        ? 'последний ответ ' . date('d.m.Y', strtotime($s['last_answer']))
                        : 'разослан ' . date('d.m.Y', strtotime($s['sent'])) . ', ответов нет')
                  . ", тишина {$quiet} " . plural_days($quiet);
            $add($chatId, 'surveys', $line, "srv_{$s['id']}");
        }

        // ── 6. Акции, завершённые по дате ───────────────────────────────
        // Дата окончания — вся правда: этапов, которые продолжались бы после
        // неё, в акциях нет. Статус меняем сразу, а сообщение уходит в общем
        // дайджесте. Закрытие само по себе делает дайджест «свежим», иначе
        // акция тихо сменила бы статус и человек об этом не узнал.
        $ended = $pdo->query("
            SELECT id, name, date_from, date_to, legal_entity, created_by
            FROM marketing_activities
            WHERE status = 'active' AND date_to IS NOT NULL AND date_to < CURDATE()
            ORDER BY date_to
        ")->fetchAll();
        foreach ($ended as $a) {
            $toFmt = date('d.m.Y', strtotime($a['date_to']));
            if (!$DRY_RUN) {
                $upd = $pdo->prepare("UPDATE marketing_activities SET status = 'completed', updated_at = NOW() WHERE id = ? AND status = 'active'");
                $upd->execute([$a['id']]);
                if (!$upd->rowCount()) continue; // кто-то успел закрыть руками
                $pdo->prepare("
                    INSERT INTO audit_log (action, entity_type, entity_id, user_name, legal_entity, details)
                    VALUES ('marketing_auto_completed', 'marketing_activities', ?, 'Портал', ?, ?)
                ")->execute([
                    (string)$a['id'],
                    $a['legal_entity'],
                    "Акция «{$a['name']}» завершена автоматически: дата окончания {$toFmt} прошла",
                ]);
            }
            $chatId = $chatOf($a['created_by']);
            if (!$chatId) continue;
            $fromFmt = $a['date_from'] ? date('d.m.Y', strtotime($a['date_from'])) : '—';
            $add($chatId, 'marketing', "• <b>{$a['name']}</b> — шла с {$fromFmt} по {$toFmt}", "mkt_{$a['id']}");
            $digest[$chatId]['fresh'] = true; // разовое событие, молчать нельзя
        }

        // ── Собираем и отправляем ───────────────────────────────────────
        $SITE = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
        foreach ($digest as $chatId => $d) {
            if (!$d['fresh']) continue; // новых хвостов нет — молчим до повтора

            $total = count($d['tasks']) + count($d['payments']) + count($d['receiving']) + count($d['tenders'])
                   + count($d['distribution']) + count($d['surveys']) + count($d['marketing']);
            $text  = "📋 <b>Требуют внимания</b> — {$total}\n";

            // Кнопок меньше, чем задач — говорим об этом, иначе выглядит так,
            // будто часть задач перенести нельзя.
            $taskNote = 'Перенести срок на неделю можно кнопкой ниже.';
            if (count($d['tasks']) > count($d['buttons'])) {
                $taskNote .= ' Кнопки — на первые ' . count($d['buttons']) . ', остальные переносятся в портале.';
            }

            $sections = [
                ['tasks',     '🗓 <b>Просроченные задачи</b>',      $taskNote],
                ['payments',  '🔴 <b>Просроченные оплаты</b>',     'Отметьте оплату в разделе «Оплаты поставщиков».'],
                ['receiving', '📦 <b>Приёмка не отмечена</b>',      'Пока приёмки нет, план-факт по заказу не посчитать.'],
                ['tenders',   '🧾 <b>Тендеры без движения</b>',     'Дедлайн подачи прошёл, а тендер не закрыт.'],
                ['distribution', '🚚 <b>Распределение не закрыто</b>', 'Закройте сессию, когда всё отгружено.'],
                ['surveys',   '📊 <b>Опросы без новых ответов</b>',  'Две недели тишины — можно закрывать и смотреть итоги.'],
                ['marketing', '🏁 <b>Акции завершены по дате</b>',  'Статус переключён на «Завершённая». Если акция продлена, верните его в карточке.'],
            ];
            foreach ($sections as [$key, $title, $note]) {
                if (empty($d[$key])) continue;
                $items = $d[$key];
                $shown = array_slice($items, 0, 10);
                $text .= "\n" . $title . " (" . count($items) . ")\n" . implode("\n", $shown) . "\n";
                if (count($items) > 10) $text .= "   …и ещё " . (count($items) - 10) . "\n";
                $text .= "<i>{$note}</i>\n";
            }
            $text .= "\n<a href=\"{$SITE}/dashboard\">Открыть портал</a>";

            // Кнопки есть только у задач: перенести срок — единственное действие,
            // которое человек делает не глядя. Оплату и приёмку так не отметить,
            // там нужно видеть суммы и позиции.
            $markup = $d['buttons'] ? ['inline_keyboard' => $d['buttons']] : null;
            tgSend($chatId, $text, true, $markup);
            foreach (array_unique($d['keys']) as $k) {
                logNotification($pdo, 'attention_digest', $k, $chatId);
            }
            $sent++;
        }
    } catch (Exception $e) {
        error_log('[cron_telegram] attention digest error: ' . $e->getMessage());
    }
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
            WHERE r.active = 1
              AND (s.tg_blocked_at IS NULL OR s.tg_blocked_at < NOW() - INTERVAL 30 DAY)");
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
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time,
               sst.weekly_deadline_dow, sst.weekly_deadline_time
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
        // Цех собственного производства (ПРЦ): тесто заказывают отдельным
        // разделом кабинета, а не в общем списке поставщиков. Напоминание
        // должно вести именно туда, иначе ресторан попадает на пустой экран.
        $isWorkshop = soLsSupplierEnabled($pdo, (string)$supId);
        $defaultDeadlineTime = $sup['default_deadline_time'];
        // Недельный режим подачи: нормализуем dow к int 1..7 (иначе null), время — строка|null
        $weeklyDow = (isset($sup['weekly_deadline_dow']) && $sup['weekly_deadline_dow'] !== null && $sup['weekly_deadline_dow'] !== '' && (int)$sup['weekly_deadline_dow'] >= 1 && (int)$sup['weekly_deadline_dow'] <= 7) ? (int)$sup['weekly_deadline_dow'] : null;
        $weeklyTime = !empty($sup['weekly_deadline_time']) ? $sup['weekly_deadline_time'] : null;

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

        // Рестораны, которым напоминания по этому поставщику ВЫКЛЮЧЕНЫ вручную
        // (закупщик снял переключатель в «Графиках»). Ключ — restaurant_id.
        $mutedRests = [];
        $muteStmt = $pdo->prepare("SELECT restaurant_id FROM so_reminder_mutes WHERE supplier_id = ?");
        $muteStmt->execute([$supId]);
        foreach ($muteStmt->fetchAll(PDO::FETCH_COLUMN) as $mid) $mutedRests[(int)$mid] = true;

        // Единая модель: подписки ресторана на напоминания этого поставщика.
        // Есть подписка → уважаем вкл/выкл, маску дней и выбранных получателей
        // Telegram. НЕТ подписки → прежнее поведение (все notify_so_reminders,
        // все дни) — обратная совместимость, никого не отключаем молча.
        // Только подписки, настроенные в новой единой модели (cron_managed=1).
        // Легаси-строки (=0) крон НЕ применяет — мягкий переход, поведение
        // как раньше, пока ресторан/закупщик заново не настроит.
        $subByRest = [];
        $subStmt = $pdo->prepare("SELECT id, restaurant_id, is_enabled, telegram_enabled, reminder_days
                                  FROM restaurant_reminder_subscriptions WHERE supplier_id = ? AND cron_managed = 1");
        $subStmt->execute([$supId]);
        $subRows = $subStmt->fetchAll();
        if ($subRows) {
            $subIds = array_map(fn($r) => (int)$r['id'], $subRows);
            // Выбранные рестораном получатели → chat_id. Требуем verified +
            // notify_so_reminders=1 (глобальный мастер-флаг в боте) + не заблокирован.
            $recByRest = [];
            $rph = implode(',', array_fill(0, count($subIds), '?'));
            $recStmt = $pdo->prepare("
                SELECT rrs.restaurant_id, ts.chat_id
                FROM restaurant_reminder_tg_subscribers t
                JOIN restaurant_reminder_subscriptions rrs ON rrs.id = t.subscription_id
                JOIN ro_telegram_subs ts ON ts.id = t.ro_tg_sub_id
                WHERE t.subscription_id IN ($rph) AND t.is_active = 1
                  AND ts.notify_so_reminders = 1
                  AND (ts.verified_at IS NOT NULL OR (ts.must_reverify_by IS NOT NULL AND ts.must_reverify_by > NOW()))
                  AND (ts.tg_blocked_at IS NULL OR ts.tg_blocked_at < NOW() - INTERVAL 30 DAY)
            ");
            $recStmt->execute($subIds);
            foreach ($recStmt->fetchAll() as $r) $recByRest[(int)$r['restaurant_id']][] = (int)$r['chat_id'];
            foreach ($subRows as $r) {
                $rid = (int)$r['restaurant_id'];
                $subByRest[$rid] = [
                    'enabled'    => (int)$r['is_enabled'] === 1,
                    'days'       => $r['reminder_days'] === null ? null : (int)$r['reminder_days'],
                    'tg_enabled' => (int)$r['telegram_enabled'] === 1,
                    'chat_ids'   => $recByRest[$rid] ?? [],
                ];
            }
        }

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
            // Ресторан с выключенными напоминаниями по этому поставщику — пропускаем целиком.
            if (!empty($mutedRests[(int)$s['restaurant_id']])) continue;
            $rn = $s['restaurant_number'];
            if (!isset($byRest[$rn])) {
                $grp = $s['legal_entity_group'] ?: 'BK_VM';
                $chatIdsLookup->execute([$rn, $grp]);
                $cids = $chatIdsLookup->fetchAll(PDO::FETCH_COLUMN);
                // Пропускаем ресторан, только если у него нет TG-подписок И пуш-канал
                // у поставщика выключен. Если push включён — ресторан из графика
                // остаётся с пустым chat_ids: TG-цикл по пустому массиву ничего не
                // отправит, а web-push уйдёт (push работает как самостоятельный канал).
                // Дефолт (только 'tg', push выключен) → поведение идентично прежнему.
                if (empty($cids) && !$pushEnabled) continue;
                $byRest[$rn] = ['chat_ids' => $cids, 'group' => $grp, 'schedule' => [], 'restaurant_id' => (int)$s['restaurant_id']];
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

                    $r = soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz, $weeklyDow, $weeklyTime);
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

            // Подписка ресторана (единая модель, только cron_managed=1).
            // Нет управляемой подписки → прежнее поведение (все notify_so_reminders,
            // все дни) — обратная совместимость.
            $sub = $subByRest[(int)($info['restaurant_id'] ?? 0)] ?? null;
            if ($sub) {
                if (!$sub['enabled']) continue;                                     // ресторан выключил
                if (!rrDayEnabledPhp($sub['days'], $nextDelivery['dow'])) continue; // день не выбран
                // Получатели Telegram. Явно выбранные — только им. Никого не
                // выбрали → шлём всем подписчикам ресторана (дефолт портальных
                // «получают все»), иначе включённый TG-канал молча никому не
                // отправлял бы. Полностью выключить TG можно флагом telegram_enabled.
                if (!$sub['tg_enabled']) {
                    $chatIds = [];
                } elseif (!empty($sub['chat_ids'])) {
                    $chatIds = $sub['chat_ids'];
                }
            }

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
            $redirect = $isWorkshop
                ? '/restaurant/orders/production'
                : "/restaurant/orders/supplier/{$supId}";

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

                    // Заявку подают в кабинете — ссылка открывает нужную
                    // страницу с автоматическим входом. Пошаговый ввод в чате
                    // убран: там не видно ни примечаний, ни минимального заказа.
                    $rows = [[[
                        'text' => $isWorkshop ? '🥖 Заказать тесто' : '📝 Подать заявку',
                        'url'  => $url,
                    ]]];
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
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time,
               sst.weekly_deadline_dow, sst.weekly_deadline_time
        FROM suppliers s
        JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1 AND sst.auto_submit_previous = 1 AND COALESCE(sst.is_accepting_orders, 1) = 1
    ")->fetchAll();

    foreach ($autoSuppliers as $sup) {
        $supId = $sup['id'];
        $supName = $sup['short_name'];
        $defaultDl = $sup['default_deadline_time'];
        // Недельный режим подачи: нормализуем dow к int 1..7 (иначе null), время — строка|null
        $weeklyDow = (isset($sup['weekly_deadline_dow']) && $sup['weekly_deadline_dow'] !== null && $sup['weekly_deadline_dow'] !== '' && (int)$sup['weekly_deadline_dow'] >= 1 && (int)$sup['weekly_deadline_dow'] <= 7) ? (int)$sup['weekly_deadline_dow'] : null;
        $weeklyTime = !empty($sup['weekly_deadline_time']) ? $sup['weekly_deadline_time'] : null;

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

            $r = soCalculateDeadlineCore($ov, $rule, $defaultDl, $deliveryDate, $tz, $weeklyDow, $weeklyTime);
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
        SELECT s.id, COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time,
               sst.weekly_deadline_dow, sst.weekly_deadline_time
        FROM suppliers s
        JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1
          AND sst.auto_email_summary = 1
          AND s.email IS NOT NULL AND s.email <> ''
    ")->fetchAll();

    foreach ($emailSuppliers as $sup) {
        $supId = $sup['id'];
        $defaultDl = $sup['default_deadline_time'];
        // Недельный режим подачи: нормализуем dow к int 1..7 (иначе null), время — строка|null
        $weeklyDow = (isset($sup['weekly_deadline_dow']) && $sup['weekly_deadline_dow'] !== null && $sup['weekly_deadline_dow'] !== '' && (int)$sup['weekly_deadline_dow'] >= 1 && (int)$sup['weekly_deadline_dow'] <= 7) ? (int)$sup['weekly_deadline_dow'] : null;
        $weeklyTime = !empty($sup['weekly_deadline_time']) ? $sup['weekly_deadline_time'] : null;
        // Дни, у которых дедлайн только что наступил. В недельном режиме их
        // сразу несколько — они уйдут одним письмом.
        $dueDates = [];
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

            $r = soCalculateDeadlineCore($ov, $rule, $defaultDl, $deliveryDate, $tz, $weeklyDow, $weeklyTime);
            if (!empty($r['forced_closed']) || !$r['deadline_dt']) continue;
            $minutesSinceDeadline = ($now->getTimestamp() - $r['deadline_dt']->getTimestamp()) / 60;
            if ($minutesSinceDeadline < -1 || $minutesSinceDeadline > 15) continue;

            $dueDates[] = $deliveryDate;
        }

        if (!$dueDates) continue;

        // Недельный приём: один дедлайн закрывает всю неделю доставки, поэтому
        // и письмо одно — книга с вкладкой на каждый день. Обычный режим шлёт
        // по письму на день, как и раньше.
        if ($weeklyDow !== null) {
            $res = soSendWeekSummaryEmail($pdo, $supId, $dueDates, 'auto', null, null);
            $range = $dueDates[0] . '..' . $dueDates[count($dueDates) - 1];
            if (!empty($res['success'])) {
                error_log("[so auto-email] sent week supplier={$supId} {$range} days={$res['days']} rests={$res['restaurants_count']}");
            } elseif (!empty($res['skipped']) && $res['skipped'] !== 'already_sent' && $res['skipped'] !== 'empty') {
                error_log("[so auto-email] skip week supplier={$supId} {$range} reason={$res['skipped']}");
            }
            continue;
        }

        foreach ($dueDates as $deliveryDate) {
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
// Шлём сводку только подписчикам конкретного поставщика и только если дедлайн
// прошёл не слишком давно (см. SO_SUMMARY_MAX_LATE_MINUTES).
define('SO_SUMMARY_MAX_LATE_MINUTES', 12 * 60);
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    $suppliers = $pdo->query("
        SELECT DISTINCT s.id, s.short_name, s.legal_entity, s.legal_entity_group,
               COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time,
               sst.weekly_deadline_dow, sst.weekly_deadline_time
        FROM suppliers s
        JOIN supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
        LEFT JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1
          AND COALESCE(sst.is_accepting_orders, 1) = 1
    ")->fetchAll();

    foreach ($suppliers as $sup) {
        $subsStmt = $pdo->prepare("
            SELECT u.name, u.telegram_chat_id, sss.created_at AS subscribed_at
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
            // Подписчики выбраны, но ни у кого нет привязанного Telegram (или все
            // заблокировали бота) — сводка молча не уходит. Пишем в лог, чтобы
            // такую тишину можно было объяснить, а не искать вслепую. Крон ходит
            // каждые 5 минут, поэтому предупреждаем раз в сутки на поставщика.
            $totalSubs = $pdo->prepare("SELECT COUNT(*) FROM so_supplier_summary_subscribers WHERE supplier_id = ?");
            $totalSubs->execute([$sup['id']]);
            if ((int)$totalSubs->fetchColumn() > 0) {
                $noTgKey = 'so_no_tg_' . $sup['id'] . '_' . $now->format('Y-m-d');
                $seen = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type = 'so_summary_no_tg' AND notification_key = ? LIMIT 1");
                $seen->execute([$noTgKey]);
                if (!$seen->fetch()) {
                    error_log("[so summary] нет получателей с Telegram: supplier={$sup['id']} ({$sup['short_name']})");
                    $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary_no_tg', '', 0, ?)")
                        ->execute([$noTgKey]);
                }
            }
            continue;
        }

            $supId = $sup['id'];
            $supName = $sup['short_name'];
            $defaultDeadlineTime = $sup['default_deadline_time'];
            // Недельный режим подачи: нормализуем dow к int 1..7 (иначе null), время — строка|null
            $weeklyDow = (isset($sup['weekly_deadline_dow']) && $sup['weekly_deadline_dow'] !== null && $sup['weekly_deadline_dow'] !== '' && (int)$sup['weekly_deadline_dow'] >= 1 && (int)$sup['weekly_deadline_dow'] <= 7) ? (int)$sup['weekly_deadline_dow'] : null;
            $weeklyTime = !empty($sup['weekly_deadline_time']) ? $sup['weekly_deadline_time'] : null;
            $supplierGroup = $sup['legal_entity_group'] ?: getEntityGroup($sup['legal_entity'] ?? '');
            $supplierEntities = getEntitiesInGroup($supplierGroup);
            $entityPh = implode(',', array_fill(0, count($supplierEntities), '?'));

            // ── Недельный приём: одна сводка на всю неделю ──
            // Дедлайн здесь один на все дни доставки, поэтому и сообщение одно:
            // книга с вкладкой на день плюс короткий текст по дням. Иначе после
            // дедлайна в чат прилетало шесть сообщений про одну поставку.
            if ($weeklyDow !== null) {
                $weekDue = [];
                $weekDeadline = null;
                for ($iDay = 0; $iDay < 15; $iDay++) {
                    $dObj = (clone $now)->setTime(0, 0, 0)->modify("+{$iDay} days");
                    $dStr = $dObj->format('Y-m-d');
                    $ovS = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
                    $ovS->execute([$supId, $dStr]);
                    $rlS = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
                    $rlS->execute([$supId, (int)$dObj->format('N')]);
                    $rr = soCalculateDeadlineCore($ovS->fetch() ?: null, $rlS->fetch() ?: null, $defaultDeadlineTime, $dStr, $tz, $weeklyDow, $weeklyTime);
                    if (!empty($rr['forced_closed']) || !$rr['deadline_dt']) continue;
                    $mins = ($now->getTimestamp() - $rr['deadline_dt']->getTimestamp()) / 60;
                    if ($mins < 0 || $mins > SO_SUMMARY_MAX_LATE_MINUTES) continue;
                    // Берём только ближайший прошедший дедлайн: если крон долго
                    // молчал, соседние недели не склеиваются в одну сводку.
                    if ($weekDeadline === null) $weekDeadline = $rr['deadline_dt'];
                    if ($rr['deadline_dt']->format('Y-m-d H:i') !== $weekDeadline->format('Y-m-d H:i')) continue;
                    $weekDue[] = $dStr;
                }
                if (!$weekDue) continue;

                $dedupKey = "so_summary_week_{$supId}_{$weekDue[0]}";
                $dup = $pdo->prepare("SELECT id FROM tg_notification_log WHERE notification_type = 'so_summary' AND notification_key = ? AND sent_at > NOW() - INTERVAL 7 DAY LIMIT 1");
                $dup->execute([$dedupKey]);
                if ($dup->fetch()) continue;

                // Подписавшиеся уже после дедлайна сводку задним числом не получают.
                $weekSubs = array_values(array_filter($subs, function ($s) use ($weekDeadline) {
                    if (empty($s['subscribed_at'])) return true;
                    $ts = strtotime($s['subscribed_at']);
                    return $ts === false || $ts <= $weekDeadline->getTimestamp();
                }));
                $markSent = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)");
                if (!$weekSubs) { $markSent->execute([$dedupKey]); continue; }

                $weekSum = soBuildWeekSummaryXlsx($pdo, $supId, $weekDue);
                $range = $weekSum['range_label'] ?: ($weekDue[0] . '..' . $weekDue[count($weekDue) - 1]);
                if ($weekSum['status'] !== 'ok') {
                    // Никто не подал за всю неделю — это тоже новость, но файла нет.
                    $caption = "⚠️ <b>За неделю заявок нет</b>\n"
                             . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
                             . "📅 Доставка: <b>{$range}</b>";
                    foreach ($weekSubs as $sub) tgSend($sub['telegram_chat_id'], $caption, true);
                    $markSent->execute([$dedupKey]);
                    continue;
                }

                $caption = "📋 <b>Заявки на неделю</b>\n"
                         . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
                         . "📅 Доставка: <b>{$range}</b>\n"
                         . "📊 Позиций: <b>{$weekSum['items_count']}</b>\n\n";
                foreach ($weekSum['days'] as $wd) {
                    $caption .= "• {$wd['date_fmt']} — ресторанов <b>{$wd['real_count']}</b>";
                    if ($wd['skip_count'] > 0) $caption .= ", «не нужна» {$wd['skip_count']}";
                    $caption .= ", позиций {$wd['items_count']}\n";
                }
                $perUser = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?, '', ?, ?)");
                foreach ($weekSubs as $sub) {
                    $ok = tgSendDocument($sub['telegram_chat_id'], $weekSum['filename'], $weekSum['xlsx'], $caption);
                    $perUser->execute([$ok ? 'so_summary_sent' : 'so_summary_fail', $sub['telegram_chat_id'], $dedupKey]);
                }
                $markSent->execute([$dedupKey]);
                continue;
            }

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
                // Рестораны с фактической заявкой на эту дату, которых нет в графике
                // (довоз или иное расхождение). Без них их заявки выпадали из сводки
                // и итогов — закупщик видел заниженные цифры (например 150 вместо 342).
                $ordRestStmt = $pdo->prepare("
                    SELECT DISTINCT o.restaurant_number, r.region, r.city, r.address
                    FROM so_orders o
                    LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.legal_entity_group = ?
                    WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.status != 'draft'
                      AND o.legal_entity IN ({$entityPh})");
                $ordRestStmt->execute(array_merge([$supplierGroup, $supId, $deliveryDate], $supplierEntities));
                foreach ($ordRestStmt->fetchAll() as $orr) {
                    $num = (string)$orr['restaurant_number'];
                    if (isset($seenNums[$num])) continue;
                    $seenNums[$num] = true;
                    $expectedRests[] = [
                        'number'  => $orr['restaurant_number'],
                        'region'  => $orr['region'] ?? '',
                        'address' => $orr['address'] ?? '',
                        'city'    => $orr['city'] ?? '',
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

                $r = soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz, $weeklyDow, $weeklyTime);
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

                // Верхняя граница опоздания. Раньше её не было вовсе: крон смотрит
                // на 15 дней вперёд, и при подключении нового поставщика (или после
                // долгого простоя крона) в чат разом улетал залп «никто не подал
                // заявку» за уже прошедшие дни. Ключ дедупа всё равно пишем, чтобы
                // просроченная сводка не выстрелила позже.
                if ($minutesSince > SO_SUMMARY_MAX_LATE_MINUTES) {
                    $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")
                        ->execute([$dedupKey]);
                    continue;
                }

                // Подписчики, актуальные именно для этой даты: тот, кто подписался
                // уже ПОСЛЕ дедлайна, не получает сводку задним числом.
                $dateSubs = array_values(array_filter($subs, function ($s) use ($deadline) {
                    if (empty($s['subscribed_at'])) return true;
                    $ts = strtotime($s['subscribed_at']);
                    return $ts === false || $ts <= $deadline->getTimestamp();
                }));
                if (!$dateSubs) {
                    $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")
                        ->execute([$dedupKey]);
                    continue;
                }

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
                // «Подали» считаем только реальные заявки (с товарами). Отметившие
                // «Поставка не нужна» (заявка без позиций) — отдельной строкой, иначе
                // счётчик путает: «6 из 7», хотя реальных заявок 4.
                $realSubmitterNums = array_values(array_unique(array_map('strval', array_column($orderRows, 'restaurant_number'))));
                $realCount = count(array_intersect($expectedNums, $realSubmitterNums));
                $skipCount = max(0, $submittedCount - $realCount);
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
                    foreach ($dateSubs as $sub) {
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
                    if ($successCount >= count($dateSubs)) {
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

                // Итоги по товарам — только для подписи к файлу.
                $colTotals = array_fill_keys(array_column($productsOut, 'sku'), 0);
                foreach ($pivot as $rn => $pmap) {
                    foreach ($pmap as $sku => $qty) {
                        if (isset($colTotals[$sku])) $colTotals[$sku] += (float)$qty;
                    }
                }

                // Файл собираем той же функцией, что и для письма поставщику.
                // Раньше крон лепил payload сам и не знал ни настроек отчёта
                // (коробки/паллеты, «убрать пустые строки»), ни атрибутов
                // товаров — в Telegram падал файл без учёта настроек.
                $sumFile = soBuildSummaryXlsx($pdo, $supId, $deliveryDate);
                if (($sumFile['status'] ?? '') !== 'ok' || empty($sumFile['xlsx'])) {
                    error_log('[cron_telegram] so summary: xlsx не собран (status='
                        . ($sumFile['status'] ?? '?') . ', ' . ($sumFile['error'] ?? '') . ')');
                    continue;
                }
                $xlsxBinary = $sumFile['xlsx'];
                $filename = $sumFile['filename'] ?: "Заявка {$supName} на {$dateFmt}.xlsx";

                $caption = "🧾 <b>Заказ поставщику</b>\n";
                $caption .= "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n";
                $caption .= "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n";
                $caption .= "\n";
                $caption .= "✅ Подали: <b>{$realCount}</b> из <b>" . count($expectedRests) . "</b>\n";
                if ($skipCount > 0) {
                    $caption .= "🚫 Поставка не нужна: <b>{$skipCount}</b>\n";
                }
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
                foreach ($dateSubs as $sub) {
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

                if ($successCount >= count($dateSubs)) {
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

// Одноразовые ссылки входа из бота живут минуты, но никогда не удалялись:
// накопилось больше 6 тысяч просроченных записей с апреля. Держим неделю —
// этого хватает, чтобы разобрать жалобу «ссылка не открылась».
try {
    $pdo->exec("DELETE FROM ro_tg_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY");
} catch (Exception $e) {}

// Просроченные сессии кабинета ресторана — та же уборка.
try {
    $pdo->exec("DELETE FROM ro_user_sessions WHERE expires_at < NOW() - INTERVAL 7 DAY");
} catch (Exception $e) {}

echo "Отправлено: {$sent}\n";
