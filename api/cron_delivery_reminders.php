<?php
/**
 * Cron: напоминания ресторанам о подаче заявок поставщикам и об основной поставке.
 * Запуск каждые 5 минут: php /var/www/bk-calc/api/cron_delivery_reminders.php
 *
 * Логика для каждой подписки:
 *   1. Регулярные напоминания — в моменты, заданные в reminder_times
 *      ([{"days_before": N, "time": "HH:MM"}, ...]). days_before — за сколько
 *      дней до дня подачи (0 = в сам день; 1 = накануне и т.п.). Каждый слот
 *      фиксируется один раз в окне 5 минут от его времени.
 *   2. Финал — за 5 мин до дедлайна (или до +60 мин после; UNIQUE-индекс
 *      reminder_runs защищает от повторов).
 *   3. Если reminder_times пуст/NULL — фолбэк на старое поведение
 *      (каждый час, начиная с 08:00, до дедлайна).
 *
 * Каналы: portal (фронт сам читает /today endpoint) и telegram (рассылка
 * выбранным сотрудникам ресторана с inline-кнопкой «Сделал заказ»).
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

// Web Push helpers
if (!empty($_ENV['VAPID_PUBLIC']) && !empty($_ENV['VAPID_PRIVATE']) && is_file(__DIR__ . '/includes/push_send.php')) {
    require_once __DIR__ . '/includes/push_send.php';
}

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

// Стартуем запись журнала
$logStart = $pdo->prepare("INSERT INTO reminder_cron_log (started_at) VALUES (NOW())");
$logStart->execute();
$cronLogId = (int)$pdo->lastInsertId();

// Хук на завершение: сохраним статистику и/или ошибку
$cronStats = ['sup_portal'=>0,'sup_tg'=>0,'sup_skip'=>0,'main_portal'=>0,'main_tg'=>0,'main_skip'=>0,'status'=>'ok','error'=>null];
register_shutdown_function(function() use (&$cronStats, $cronLogId, $pdo) {
    try {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $cronStats['status'] = 'error';
            $cronStats['error'] = ($err['message'] ?? '') . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? '');
        }
        $pdo->prepare("
            UPDATE reminder_cron_log
            SET finished_at = NOW(),
                sup_portal = ?, sup_tg = ?, sup_skip = ?,
                main_portal = ?, main_tg = ?, main_skip = ?,
                status = ?, error_text = ?
            WHERE id = ?
        ")->execute([
            $cronStats['sup_portal'], $cronStats['sup_tg'], $cronStats['sup_skip'],
            $cronStats['main_portal'], $cronStats['main_tg'], $cronStats['main_skip'],
            $cronStats['status'], $cronStats['error'], $cronLogId,
        ]);
    } catch (Throwable $e) { /* нет смысла фейлить shutdown */ }
});

// reminder_runs.run_hour хранит МИНУТНЫЙ слот HH*60+MM (0..1439).
// Технические значения для финальных сообщений:
//   1500 — «🔔 Последнее напоминание» (за 5 мин до дедлайна)
//   1501 — «🚨 Дедлайн истёк» (от 0 до 60 мин после дедлайна)
const FINAL_RUN_HOUR_PRE = 1500;
const FINAL_RUN_HOUR_EXPIRED = 1501;
// Для backward-совместимости с уже отправленными TG-сообщениями (старая
// rrack-кнопка несёт этот UUID в callback_data). При получении callback —
// конвертируем в reminder_kind='main_delivery'.
const MAIN_DELIVERY_LEGACY_UUID = '00000000-0000-0000-0000-000000000000';

const DAY_NAMES_ACC = ['', 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу', 'воскресенье'];
const DAY_NAMES_SHORT_RU = ['', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

/**
 * Дата доставки в формате «пн 14.05» по дате подачи заявки и дню недели доставки.
 * delivery_dow может быть как до, так и после order_dow (на той же или следующей неделе).
 */
function rrFormatDeliveryDate($orderDateStr, $orderDow, $deliveryDow, $tz) {
    if ($deliveryDow < 1 || $deliveryDow > 7) return '';
    $diff = ($deliveryDow - $orderDow + 7) % 7;
    if ($diff === 0) $diff = 7; // доставка на следующей неделе, если совпадает с днём подачи
    $dt = DateTime::createFromFormat('Y-m-d', $orderDateStr, $tz);
    if (!$dt) return '';
    $dt->modify("+{$diff} days");
    return (DAY_NAMES_SHORT_RU[$deliveryDow] ?? '') . ' ' . $dt->format('d.m');
}

/**
 * Вычисляет, какие слоты напоминаний должны быть отправлены сейчас для одной
 * подписки. Возвращает массив слотов; каждый слот содержит run_hour для дедупа
 * и человекочитаемое описание для текста сообщения.
 */
function rrComputeFireSlots($now, $todayDow, $orderDay, $deadlineTime, $reminderTimes, $tz) {
    $slots = [];
    if ($orderDay < 1 || $orderDay > 7) return $slots;

    // Ближайшая дата, когда наступит order_day (включая сегодня)
    $diff = ($orderDay - $todayDow + 7) % 7;
    $orderDate = clone $now;
    $orderDate->modify("+{$diff} days");
    $orderDate->setTime(0, 0, 0);
    $orderDateStr = $orderDate->format('Y-m-d');

    // Парсим reminder_times
    $rtimes = is_string($reminderTimes) ? json_decode($reminderTimes, true) : ($reminderTimes ?: []);
    if (!is_array($rtimes)) $rtimes = [];

    // Регулярные слоты: один раз в окне [fire_at, fire_at + 5 min)
    foreach ($rtimes as $rt) {
        $db = (int)($rt['days_before'] ?? 0);
        $time = $rt['time'] ?? null;
        if (!is_string($time) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) continue;

        $fireDate = clone $orderDate;
        $fireDate->modify("-{$db} days");
        $fireAt = DateTime::createFromFormat('Y-m-d H:i', $fireDate->format('Y-m-d') . ' ' . $time, $tz);
        if (!$fireAt) continue;

        $diffSec = $now->getTimestamp() - $fireAt->getTimestamp();
        if ($diffSec < 0 || $diffSec >= 300) continue;

        $hh = (int)substr($time, 0, 2);
        $mm = (int)substr($time, 3, 2);
        $minuteSlot = $hh * 60 + $mm; // 0..1439
        $slots[] = [
            'run_hour'    => $minuteSlot,
            'is_final'    => false,
            'order_date'  => $orderDateStr,
            'order_day'   => $orderDay,
            'days_before' => $db,
            'time'        => substr($time, 0, 5),
        ];
    }

    // Финал: pre — за 0..5 мин до дедлайна; expired — 0..60 мин после.
    // Разные run_hour, чтобы оба сообщения могли уйти.
    if ($todayDow === $orderDay && $deadlineTime) {
        $deadlineDt = DateTime::createFromFormat('Y-m-d H:i:s', $orderDateStr . ' ' . $deadlineTime, $tz);
        if ($deadlineDt) {
            $minutesLeft = ($deadlineDt->getTimestamp() - $now->getTimestamp()) / 60;
            if ($minutesLeft > 0 && $minutesLeft <= 5) {
                $slots[] = [
                    'run_hour'    => FINAL_RUN_HOUR_PRE,
                    'is_final'    => true,
                    'is_expired'  => false,
                    'minutes_left'=> (int)round($minutesLeft),
                    'order_date'  => $orderDateStr,
                    'order_day'   => $orderDay,
                    'days_before' => 0,
                    'time'        => substr($deadlineTime, 0, 5),
                ];
            } elseif ($minutesLeft <= 0 && $minutesLeft >= -60) {
                $slots[] = [
                    'run_hour'    => FINAL_RUN_HOUR_EXPIRED,
                    'is_final'    => true,
                    'is_expired'  => true,
                    'minutes_left'=> (int)round($minutesLeft),
                    'order_date'  => $orderDateStr,
                    'order_day'   => $orderDay,
                    'days_before' => 0,
                    'time'        => substr($deadlineTime, 0, 5),
                ];
            }
        }
    }

    // Фолбэк: пустой reminder_times — старое поведение (каждый час с 8:00 в день подачи)
    if (empty($rtimes) && $todayDow === $orderDay && $deadlineTime && $now->format('G') >= 8) {
        $deadlineDt = DateTime::createFromFormat('Y-m-d H:i:s', $orderDateStr . ' ' . $deadlineTime, $tz);
        if ($deadlineDt) {
            $minutesLeft = ($deadlineDt->getTimestamp() - $now->getTimestamp()) / 60;
            if ($minutesLeft > 5) {
                $slots[] = [
                    'run_hour'    => (int)$now->format('G') * 60, // час → минутный слот HH:00
                    'is_final'    => false,
                    'order_date'  => $orderDateStr,
                    'order_day'   => $orderDay,
                    'days_before' => 0,
                    'time'        => substr($deadlineTime, 0, 5),
                    'legacy'      => true,
                ];
            }
        }
    }

    return $slots;
}

/**
 * Человекочитаемая привязка времени: «сегодня», «завтра», «послезавтра» или
 * «в <день_недели>».
 */
function rrWhenLabel($daysBefore, $orderDow) {
    if ($daysBefore === 0) return 'сегодня';
    if ($daysBefore === 1) return 'завтра';
    if ($daysBefore === 2) return 'послезавтра';
    $name = DAY_NAMES_ACC[$orderDow] ?? '';
    return $name ? "в $name" : "через $daysBefore дн.";
}

// ──────────────────────────────────────────────────────────────────────────
// Общие prepared statements
// ──────────────────────────────────────────────────────────────────────────
$ackStmt   = $pdo->prepare("SELECT 1 FROM reminder_acknowledgements WHERE restaurant_id = ? AND reminder_kind = ? AND supplier_id = ? AND target_date = ? AND order_day = ? LIMIT 1");
$portalIns = $pdo->prepare("INSERT IGNORE INTO reminder_runs (subscription_id, reminder_kind, target_date, order_day, run_hour, channel, recipient) VALUES (?, ?, ?, ?, ?, 'portal', '_')");
$tgRunCheck= $pdo->prepare("SELECT 1 FROM reminder_runs WHERE subscription_id = ? AND reminder_kind = ? AND target_date = ? AND order_day = ? AND run_hour = ? AND channel = 'telegram' AND recipient = ? LIMIT 1");
$tgRunIns  = $pdo->prepare("INSERT IGNORE INTO reminder_runs (subscription_id, reminder_kind, target_date, order_day, run_hour, channel, recipient) VALUES (?, ?, ?, ?, ?, 'telegram', ?)");
$pushRunCheck = $pdo->prepare("SELECT 1 FROM reminder_runs WHERE subscription_id = ? AND reminder_kind = ? AND target_date = ? AND order_day = ? AND run_hour = ? AND channel = 'push' AND recipient = '_' LIMIT 1");
$pushRunIns   = $pdo->prepare("INSERT IGNORE INTO reminder_runs (subscription_id, reminder_kind, target_date, order_day, run_hour, channel, recipient) VALUES (?, ?, ?, ?, ?, 'push', '_')");

// ──────────────────────────────────────────────────────────────────────────
// Проход 1: локальные поставщики
// ──────────────────────────────────────────────────────────────────────────
// Дефолты поставщиков (deadline_time + reminder_times)
$defaults = [];
foreach ($pdo->query("SELECT supplier_id, delivery_dow, deadline_time, reminder_times FROM supplier_default_deadlines") as $d) {
    $defaults[$d['supplier_id']][(int)$d['delivery_dow']] = [
        'deadline_time'  => $d['deadline_time'],
        'reminder_times' => $d['reminder_times'],
    ];
}

// Все активные подписки на локальных поставщиков (без фильтра по order_day —
// напоминания могут быть за N дней до дня подачи)
$stmt = $pdo->prepare("
    SELECT
        ss.order_day, ss.delivery_day,
        sub.id AS subscription_id, sub.portal_enabled, sub.telegram_enabled,
        s.id AS supplier_id, s.short_name AS supplier_name,
        r.id AS restaurant_pk, r.number AS restaurant_number, r.legal_entity_group,
        sd.deadline_time AS deadline_override,
        sd.reminder_times AS reminder_times_override
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
      AND sub.is_enabled = 1
      AND s.is_active = 1
      AND s.so_enabled = 0
      AND r.active = 1
");
$stmt->execute();

$tgList = $pdo->prepare("
    SELECT rts.id, rts.chat_id, rts.first_name, rts.username
    FROM restaurant_reminder_tg_subscribers rrts
    JOIN ro_telegram_subs rts ON rts.id = rrts.ro_tg_sub_id
    WHERE rrts.subscription_id = ? AND rrts.is_active = 1
      AND rts.verified_at IS NOT NULL AND rts.chat_id IS NOT NULL
");

$sentPortal = 0; $sentTg = 0; $skipped = 0;

foreach ($stmt->fetchAll() as $row) {
    $supplierId  = $row['supplier_id'];
    $restPk      = (int)$row['restaurant_pk'];
    $orderDay    = (int)$row['order_day'];
    $deliveryDay = (int)$row['delivery_day'];

    $deadline = $row['deadline_override'] ?: ($defaults[$supplierId][$deliveryDay]['deadline_time'] ?? null);
    if (!$deadline) { $skipped++; continue; }
    $rtimes = $row['reminder_times_override'] ?: ($defaults[$supplierId][$deliveryDay]['reminder_times'] ?? null);

    $slots = rrComputeFireSlots($now, $todayDow, $orderDay, $deadline, $rtimes, $tz);
    if (!$slots) { $skipped++; continue; }

    $subscriptionId = (int)$row['subscription_id'];

    foreach ($slots as $slot) {
        // «Сделал заказ»?
        $ackStmt->execute([$restPk, 'supplier', $supplierId, $slot['order_date'], $orderDay]);
        if ($ackStmt->fetchColumn()) { $skipped++; continue; }

        $deadlineShort = substr($deadline, 0, 5);
        $whenLabel = rrWhenLabel($slot['days_before'], $orderDay);

        // PORTAL
        if ((int)$row['portal_enabled'] === 1) {
            try {
                $portalIns->execute([$subscriptionId, 'supplier', $slot['order_date'], $orderDay, $slot['run_hour']]);
                $sentPortal++;
            } catch (Exception $e) { /* ignore */ }
        }

        // TELEGRAM
        if ((int)$row['telegram_enabled'] === 1 && $BOT_TOKEN) {
            $deliveryLabel = rrFormatDeliveryDate($slot['order_date'], $orderDay, $deliveryDay, $tz);
            if ($slot['is_final']) {
                if (!empty($slot['is_expired'])) {
                    $text = "🚨 <b>Дедлайн истёк</b>\n"
                          . "Сегодня заявка поставщику <b>" . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . "</b>"
                          . " так и не была отмечена как поданная.\n"
                          . "Крайний срок: <b>{$deadlineShort}</b>.\n"
                          . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                          . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".\n\n"
                          . "Если заявка всё же была подана — нажмите «Сделал заказ», чтобы зафиксировать.";
                } else {
                    $minLeft = max(1, (int)($slot['minutes_left'] ?? 5));
                    $text = "🔔 <b>Последнее напоминание</b>\n"
                          . "До дедлайна осталось <b>{$minLeft} мин</b> — подайте заявку поставщику <b>" . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . "</b>.\n"
                          . "Крайний срок: <b>{$deadlineShort}</b>.\n"
                          . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                          . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".";
                }
            } else {
                $text = "⏰ <b>Напоминание</b>\n"
                      . ucfirst($whenLabel) . " до <b>{$deadlineShort}</b> подайте заявку поставщику <b>" . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . "</b>.\n"
                      . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                      . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".";
            }
            $callback = "rrack:{$supplierId}:{$orderDay}:" . $slot['order_date'];
            $markup = [
                'inline_keyboard' => [
                    [ ['text' => '✓ Сделал заказ', 'callback_data' => $callback] ],
                ],
            ];

            $tgList->execute([$subscriptionId]);
            foreach ($tgList->fetchAll() as $tg) {
                $chatId = (string)$tg['chat_id'];
                $tgRunCheck->execute([$subscriptionId, 'supplier', $slot['order_date'], $orderDay, $slot['run_hour'], $chatId]);
                if ($tgRunCheck->fetchColumn()) continue;
                $ok = rtgSend($BOT_TOKEN, (int)$chatId, $text, $markup);
                if ($ok) {
                    try {
                        $tgRunIns->execute([$subscriptionId, 'supplier', $slot['order_date'], $orderDay, $slot['run_hour'], $chatId]);
                        $sentTg++;
                    } catch (Exception $e) { /* ignore */ }
                }
            }
        }

        // WEB PUSH (если есть подписки и не отправляли ещё)
        if (function_exists('pushSendToRestaurant')) {
            $pushRunCheck->execute([$subscriptionId, 'supplier', $slot['order_date'], $orderDay, $slot['run_hour']]);
            if (!$pushRunCheck->fetchColumn()) {
                $deliveryLabel = rrFormatDeliveryDate($slot['order_date'], $orderDay, $deliveryDay, $tz);
                $pushTitle = $slot['is_final']
                    ? (!empty($slot['is_expired']) ? '🚨 Дедлайн истёк' : '🔔 Последнее напоминание')
                    : '⏰ Напоминание';
                $pushBody = ucfirst($whenLabel) . " до {$deadlineShort} — заявка поставщику {$row['supplier_name']}"
                          . ($deliveryLabel ? " (доставка {$deliveryLabel})" : '');
                $sent = pushSendToRestaurant($pdo,
                    (int)$row['restaurant_number'],
                    $row['legal_entity_group'] ?: 'BK_VM',
                    [
                        'title' => $pushTitle,
                        'body'  => $pushBody,
                        'url'   => '/restaurant/reminders',
                        'tag'   => "rem-{$supplierId}-{$slot['order_date']}",
                    ]
                );
                if ($sent > 0) {
                    try {
                        $pushRunIns->execute([$subscriptionId, 'supplier', $slot['order_date'], $orderDay, $slot['run_hour']]);
                        $sentTg += 0; // push не считаем как tg
                    } catch (Exception $e) { /* ignore */ }
                }
                $cronStats['push_sup'] = ($cronStats['push_sup'] ?? 0) + $sent;
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Проход 2: основная поставка
// ──────────────────────────────────────────────────────────────────────────
$mainStmt = $pdo->prepare("
    SELECT
        ds.order_day, ds.day_of_week AS delivery_day, ds.order_deadline, ds.reminder_times,
        sub.id AS subscription_id, sub.portal_enabled, sub.telegram_enabled,
        r.id AS restaurant_pk, r.number AS restaurant_number, r.legal_entity_group
    FROM delivery_schedule ds
    JOIN restaurant_main_delivery_subscriptions sub ON sub.restaurant_id = ds.restaurant_id
    JOIN restaurants r ON r.id = ds.restaurant_id
    WHERE ds.order_day IS NOT NULL
      AND ds.order_deadline IS NOT NULL
      AND sub.is_enabled = 1
      AND r.active = 1
");
$mainStmt->execute();

$mainTgList = $pdo->prepare("
    SELECT rts.id, rts.chat_id, rts.first_name, rts.username
    FROM restaurant_main_delivery_tg_subscribers rmts
    JOIN ro_telegram_subs rts ON rts.id = rmts.ro_tg_sub_id
    WHERE rmts.subscription_id = ? AND rmts.is_active = 1
      AND rts.verified_at IS NOT NULL AND rts.chat_id IS NOT NULL
");

$sentMainPortal = 0; $sentMainTg = 0; $skippedMain = 0;

foreach ($mainStmt->fetchAll() as $row) {
    $restPk      = (int)$row['restaurant_pk'];
    $orderDay    = (int)$row['order_day'];
    $deliveryDay = (int)$row['delivery_day'];
    $deadline    = $row['order_deadline'];
    $rtimes      = $row['reminder_times'];

    $slots = rrComputeFireSlots($now, $todayDow, $orderDay, $deadline, $rtimes, $tz);
    if (!$slots) { $skippedMain++; continue; }

    $subscriptionId = (int)$row['subscription_id'];

    foreach ($slots as $slot) {
        // «Сделал заказ»?
        $ackStmt->execute([$restPk, 'main_delivery', '', $slot['order_date'], $orderDay]);
        if ($ackStmt->fetchColumn()) { $skippedMain++; continue; }

        $deadlineShort = substr($deadline, 0, 5);
        $whenLabel = rrWhenLabel($slot['days_before'], $orderDay);

        // PORTAL
        if ((int)$row['portal_enabled'] === 1) {
            try {
                $portalIns->execute([$subscriptionId, 'main_delivery', $slot['order_date'], $orderDay, $slot['run_hour']]);
                $sentMainPortal++;
            } catch (Exception $e) { /* ignore */ }
        }

        // TELEGRAM
        if ((int)$row['telegram_enabled'] === 1 && $BOT_TOKEN) {
            $deliveryLabel = rrFormatDeliveryDate($slot['order_date'], $orderDay, $deliveryDay, $tz);
            if ($slot['is_final']) {
                if (!empty($slot['is_expired'])) {
                    $text = "🚨 <b>Дедлайн истёк</b>\n"
                          . "Сегодня заявка на <b>основную поставку</b> через 1С"
                          . " так и не была отмечена как поданная.\n"
                          . "Крайний срок: <b>{$deadlineShort}</b>.\n"
                          . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                          . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".\n\n"
                          . "Если заявка всё же была подана — нажмите «Сделал заказ», чтобы зафиксировать.";
                } else {
                    $minLeft = max(1, (int)($slot['minutes_left'] ?? 5));
                    $text = "🔔 <b>Последнее напоминание</b>\n"
                          . "До дедлайна осталось <b>{$minLeft} мин</b> — подайте заявку на <b>основную поставку</b> через 1С.\n"
                          . "Крайний срок: <b>{$deadlineShort}</b>.\n"
                          . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                          . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".";
                }
            } else {
                $text = "⏰ <b>Напоминание</b>\n"
                      . ucfirst($whenLabel) . " до <b>{$deadlineShort}</b> подайте заявку на <b>основную поставку</b> через 1С.\n"
                      . ($deliveryLabel ? "Доставка: <b>{$deliveryLabel}</b>.\n" : '')
                      . "Ресторан №" . htmlspecialchars((string)$row['restaurant_number'], ENT_QUOTES, 'UTF-8') . ".";
            }
            // В callback_data используем legacy-UUID, чтобы старые TG-сообщения
            // (с уже отправленной кнопкой) тоже работали — handler в боте
            // конвертирует этот UUID в reminder_kind='main_delivery'.
            $callback = "rrack:" . MAIN_DELIVERY_LEGACY_UUID . ":{$orderDay}:" . $slot['order_date'];
            $markup = [
                'inline_keyboard' => [
                    [ ['text' => '✓ Сделал заказ', 'callback_data' => $callback] ],
                ],
            ];

            $mainTgList->execute([$subscriptionId]);
            foreach ($mainTgList->fetchAll() as $tg) {
                $chatId = (string)$tg['chat_id'];
                $tgRunCheck->execute([$subscriptionId, 'main_delivery', $slot['order_date'], $orderDay, $slot['run_hour'], $chatId]);
                if ($tgRunCheck->fetchColumn()) continue;
                $ok = rtgSend($BOT_TOKEN, (int)$chatId, $text, $markup);
                if ($ok) {
                    try {
                        $tgRunIns->execute([$subscriptionId, 'main_delivery', $slot['order_date'], $orderDay, $slot['run_hour'], $chatId]);
                        $sentMainTg++;
                    } catch (Exception $e) { /* ignore */ }
                }
            }
        }

        // WEB PUSH для основной поставки
        if (function_exists('pushSendToRestaurant')) {
            $pushRunCheck->execute([$subscriptionId, 'main_delivery', $slot['order_date'], $orderDay, $slot['run_hour']]);
            if (!$pushRunCheck->fetchColumn()) {
                $deliveryLabel = rrFormatDeliveryDate($slot['order_date'], $orderDay, $deliveryDay, $tz);
                $pushTitle = $slot['is_final']
                    ? (!empty($slot['is_expired']) ? '🚨 Дедлайн истёк' : '🔔 Последнее напоминание')
                    : '⏰ Напоминание';
                $pushBody = ucfirst($whenLabel) . " до {$deadlineShort} — заявка на основную поставку через 1С"
                          . ($deliveryLabel ? " (доставка {$deliveryLabel})" : '');
                $sent = pushSendToRestaurant($pdo,
                    (int)$row['restaurant_number'],
                    $row['legal_entity_group'] ?: 'BK_VM',
                    [
                        'title' => $pushTitle,
                        'body'  => $pushBody,
                        'url'   => '/restaurant/reminders',
                        'tag'   => "rem-main-{$slot['order_date']}",
                    ]
                );
                if ($sent > 0) {
                    try {
                        $pushRunIns->execute([$subscriptionId, 'main_delivery', $slot['order_date'], $orderDay, $slot['run_hour']]);
                    } catch (Exception $e) { /* ignore */ }
                }
                $cronStats['push_main'] = ($cronStats['push_main'] ?? 0) + $sent;
            }
        }
    }
}

echo "delivery-reminders: portal={$sentPortal}, tg={$sentTg}, skipped={$skipped}, hour={$nowHour}\n";
echo "main-delivery-reminders: portal={$sentMainPortal}, tg={$sentMainTg}, skipped={$skippedMain}\n";

$cronStats['sup_portal']  = $sentPortal;
$cronStats['sup_tg']      = $sentTg;
$cronStats['sup_skip']    = $skipped;
$cronStats['main_portal'] = $sentMainPortal;
$cronStats['main_tg']     = $sentMainTg;
$cronStats['main_skip']   = $skippedMain;
