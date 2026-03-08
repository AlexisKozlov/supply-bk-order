<?php
/**
 * Крон-скрипт: уведомления об истекающих протоколах (ПСЦ).
 * Запуск раз в день: crontab -e → 0 9 * * * php /var/www/bk-calc/api/cron_psc_expiry.php
 *
 * Проверяет активные протоколы и отправляет уведомление автору:
 *  - за 30 дней до окончания
 *  - за 14 дней
 *  - за 7 дней
 *  - в день окончания
 */

// Загрузка .env
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) { echo "No .env file\n"; exit(1); }
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Пороги: за сколько дней предупреждать
$thresholds = [30, 14, 7, 0];

$today = date('Y-m-d');
$sent = 0;

foreach ($thresholds as $days) {
    $targetDate = date('Y-m-d', strtotime("+{$days} days"));

    // Найти активные протоколы, у которых valid_to = targetDate
    $stmt = $pdo->prepare("
        SELECT id, number, supplier, created_by, valid_to, legal_entity
        FROM price_agreements
        WHERE status = 'active' AND valid_to = ?
    ");
    $stmt->execute([$targetDate]);
    $agreements = $stmt->fetchAll();

    foreach ($agreements as $ag) {
        $creator = $ag['created_by'];
        if (!$creator) continue;

        // Проверить, что уведомление ещё не отправлено (не дублировать)
        $check = $pdo->prepare("
            SELECT id FROM notifications
            WHERE entity_type = 'agreement' AND entity_id = ? AND target_user = ?
              AND title LIKE ? AND created_at >= CURDATE()
        ");
        $label = $days > 0 ? "через {$days} дн." : "сегодня";
        $check->execute([$ag['id'], $creator, "%{$label}%"]);
        if ($check->fetch()) continue; // Уже отправлено

        // Текст уведомления
        if ($days === 0) {
            $title = "Протокол {$ag['number']} истекает сегодня";
            $message = "Протокол {$ag['number']} ({$ag['supplier']}) истекает сегодня. Нужно продлить или создать новый.";
        } else {
            $title = "Протокол {$ag['number']} истекает через {$days} дн.";
            $message = "Протокол {$ag['number']} ({$ag['supplier']}) действует до " . date('d.m.Y', strtotime($ag['valid_to'])) . ". Осталось {$days} дней.";
        }

        $ins = $pdo->prepare("
            INSERT INTO notifications (type, title, message, entity_type, entity_id, legal_entity, target_user, created_by, read_by, deleted_by, created_at)
            VALUES ('agreement_expiry', ?, ?, 'agreement', ?, ?, ?, 'Система', '[]', '[]', NOW())
        ");
        $ins->execute([$title, $message, $ag['id'], $ag['legal_entity'], $creator]);
        $sent++;
        echo "Уведомление: {$creator} — {$title}\n";
    }
}

echo "Готово. Отправлено уведомлений: {$sent}\n";
