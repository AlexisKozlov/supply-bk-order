<?php
/**
 * Cron: напоминания ресторанам о подаче заявок поставщикам.
 * Запуск каждые 5 минут: php /var/www/bk-calc/api/cron_delivery_reminders.php
 *
 * Логика: для каждой активной подписки на сегодня (где order_day = текущий
 * день недели по Europe/Minsk) проверяет, нужно ли слать напоминание сейчас.
 * Первое напоминание идёт в 8:00, далее каждый час до дедлайна, либо до тех
 * пор, пока ресторан не нажмёт «Сделал заказ».
 *
 * Каналы: portal (фронт сам читает /today endpoint, мы только пишем
 * reminder_runs для дедупа), telegram (отправляем выбранным подписчикам).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$lockFile = __DIR__ . '/cron_delivery_reminders.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; exit; }
set_time_limit(180);

$envFile = '/var/www/bk-calc-secrets/.env';
if (!file_exists($envFile)) exit("no .env\n");
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';

// sendTelegramMessage — есть в helpers.php, но нужно подключить только нужное.
// Достаточно прямой реализации через curl, без зависимости от веб-конфига.
function rtgSend($botToken, $chatId, $text, $replyMarkup = null) {
    if (!$botToken || !$chatId) return false;
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_notification' => false,
    ];
    if ($replyMarkup) $data['reply_markup'] = json_encode($replyMarkup);
    $payload = json_encode($data);
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

$tz = new DateTimeZone('Europe/Minsk');
$now = new DateTime('now', $tz);
$today = $now->format('Y-m-d');
$todayDow = (int)$now->format('N'); // 1..7
$nowHour = (int)$now->format('G');

// Не шлём раньше 8:00 (бизнес-правило)
if ($nowHour < 8) { echo "Too early ({$nowHour}h), skipping\n"; exit; }

// Дефолтные дедлайны поставщиков для тех, у кого нет override
$defaults = [];
foreach ($pdo->query("SELECT supplier_id, delivery_dow, deadline_time FROM supplier_default_deadlines") as $d) {
    $defaults[$d['supplier_id']][(int)$d['delivery_dow']] = $d['deadline_time'];
}

$stmt = $pdo->prepare("
    SELECT
        ss.order_day, ss.delivery_day,
        sub.id AS subscription_id, sub.portal_enabled, sub.telegram_enabled,
        s.id AS supplier_id, s.short_name AS supplier_name,
        r.id AS restaurant_pk, r.number AS restaurant_number,
        sd.deadline_time AS deadline_override
    FROM supplier_schedules ss
    JOIN restaurant_reminder_subscriptions sub
        ON sub.restaurant_id = ss.restaurant_id AND sub.supplier_id = ss.supplier_id
    JOIN suppliers s ON s.id = ss.supplier_id
    JOIN restaurants r ON r.id = ss.restaurant_id
    LEFT JOIN supplier_schedule_deadlines sd
        ON sd.supplier_id = ss.supplier_id
       AND sd.restaurant_id = ss.restaurant_id
       AND sd.order_day = ss.order_day
    WHERE ss.is_active = 1
      AND ss.order_day = ?
      AND sub.is_enabled = 1
      AND s.is_active = 1
      AND s.so_enabled = 0
      AND r.active = 1
");
$stmt->execute([$todayDow]);

$ackStmt   = $pdo->prepare("SELECT 1 FROM reminder_acknowledgements WHERE restaurant_id = ? AND supplier_id = ? AND target_date = ? AND order_day = ? LIMIT 1");
$portalIns = $pdo->prepare("INSERT IGNORE INTO reminder_runs (subscription_id, target_date, order_day, run_hour, channel, recipient) VALUES (?, ?, ?, ?, 'portal', '_')");
$tgList    = $pdo->prepare("
    SELECT rts.id, rts.chat_id, rts.first_name, rts.username
    FROM restaurant_reminder_tg_subscribers rrts
    JOIN ro_telegram_subs rts ON rts.id = rrts.ro_tg_sub_id
    WHERE rrts.subscription_id = ? AND rrts.is_active = 1
      AND rts.verified_at IS NOT NULL AND rts.chat_id IS NOT NULL
");
$tgRunCheck= $pdo->prepare("SELECT 1 FROM reminder_runs WHERE subscription_id = ? AND target_date = ? AND order_day = ? AND run_hour = ? AND channel = 'telegram' AND recipient = ? LIMIT 1");
$tgRunIns  = $pdo->prepare("INSERT IGNORE INTO reminder_runs (subscription_id, target_date, order_day, run_hour, channel, recipient) VALUES (?, ?, ?, ?, 'telegram', ?)");

$sentPortal = 0; $sentTg = 0; $skipped = 0;

// run_hour = 99 — техническое значение для «финального» напоминания (после
// дедлайна). UNIQUE-индекс reminder_runs гарантирует что финал отправится один раз.
const FINAL_RUN_HOUR = 99;

foreach ($stmt->fetchAll() as $row) {
    $supplierId  = $row['supplier_id'];
    $restPk      = (int)$row['restaurant_pk'];
    $orderDay    = (int)$row['order_day'];
    $deliveryDay = (int)$row['delivery_day'];
    $deadline    = $row['deadline_override'] ?: ($defaults[$supplierId][$deliveryDay] ?? null);

    if (!$deadline) { $skipped++; continue; }
    $deadlineDt = DateTime::createFromFormat('Y-m-d H:i:s', $today . ' ' . $deadline, $tz);
    if (!$deadlineDt) { $skipped++; continue; }
    $minutesLeft = ($deadlineDt->getTimestamp() - $now->getTimestamp()) / 60;

    // Уже отметили «Сделал заказ»?
    $ackStmt->execute([$restPk, $supplierId, $today, $orderDay]);
    if ($ackStmt->fetchColumn()) { $skipped++; continue; }

    $subscriptionId = (int)$row['subscription_id'];

    // Определяем тип отправки:
    //   - regular: до дедлайна, повторы каждый час
    //   - final: за 5 минут до дедлайна и сразу после (одно жёсткое сообщение).
    // Финальное окно: от -5 до +60 минут от дедлайна (cron каждые 5 мин,
    // окно даёт запас на случай пропусков, но дедупликация через UNIQUE спасает).
    $isFinal = ($minutesLeft <= 5 && $minutesLeft >= -60);
    $isRegular = $minutesLeft > 5;
    if (!$isFinal && !$isRegular) { $skipped++; continue; }

    $runHour = $isFinal ? FINAL_RUN_HOUR : $nowHour;
    $deadlineShort = substr($deadline, 0, 5);

    // PORTAL: запись в reminder_runs
    if ((int)$row['portal_enabled'] === 1) {
        try {
            $portalIns->execute([$subscriptionId, $today, $orderDay, $runHour]);
            $sentPortal++;
        } catch (Exception $e) { /* ignore */ }
    }

    // TELEGRAM: рассылка
    if ((int)$row['telegram_enabled'] === 1 && $BOT_TOKEN) {
        if ($isFinal) {
            $text = "🚨 <b>Дедлайн истёк</b>\n"
                  . "Сегодня заявка поставщику <b>" . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . "</b>"
                  . " так и не была отмечена как поданная.\n"
                  . "Крайний срок: <b>{$deadlineShort}</b>.\n"
                  . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".\n\n"
                  . "Если заявка всё же была подана — нажмите «Сделал заказ», чтобы зафиксировать.";
        } else {
            $text = "⏰ <b>Напоминание</b>\n"
                  . "Сегодня до <b>{$deadlineShort}</b> подайте заявку поставщику <b>" . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . "</b>.\n"
                  . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".";
        }
        $callback = "rrack:{$supplierId}:{$orderDay}:{$today}";
        $markup = [
            'inline_keyboard' => [
                [ ['text' => '✓ Сделал заказ', 'callback_data' => $callback] ],
            ],
        ];

        $tgList->execute([$subscriptionId]);
        foreach ($tgList->fetchAll() as $tg) {
            $chatId = (string)$tg['chat_id'];
            $tgRunCheck->execute([$subscriptionId, $today, $orderDay, $runHour, $chatId]);
            if ($tgRunCheck->fetchColumn()) continue;
            $ok = rtgSend($BOT_TOKEN, (int)$chatId, $text, $markup);
            if ($ok) {
                try {
                    $tgRunIns->execute([$subscriptionId, $today, $orderDay, $runHour, $chatId]);
                    $sentTg++;
                } catch (Exception $e) { /* ignore */ }
            }
        }
    }
}

echo "delivery-reminders: portal={$sentPortal}, tg={$sentTg}, skipped={$skipped}, hour={$nowHour}\n";
