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

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function tgSend($chatId, $text) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
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

echo "Отправлено: {$sent}\n";
