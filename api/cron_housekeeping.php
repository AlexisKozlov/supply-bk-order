<?php
/**
 * Уборка служебных журналов.
 *
 * Технические логи росли без ограничений: reminder_cron_log за полгода
 * набрал 26 тысяч строк (+288 в сутки), search_logs и error_logs —
 * несколько тысяч. Бизнес-данные (audit_log, ro_audit_log, so_email_log)
 * НЕ трогаем: это история, а не мусор.
 *
 * Отдельная осторожность с reminder_runs. Это таблица защиты от повторной
 * отправки: если удалить свежую строку, напоминание уйдёт человеку второй
 * раз. Поэтому чистим её не по дате записи, а по дате самого напоминания,
 * и только сильно просроченные.
 *
 * Запуск: раз в сутки из crontab.
 * Флаг --dry-run — только посчитать, ничего не удалять.
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

$envFile = '/var/www/bk-calc-secrets/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}

try {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, '[housekeeping] нет связи с БД: ' . $e->getMessage() . "\n");
    exit(1);
}

// [таблица, условие отбора, сколько дней хранить, пояснение]
$RULES = [
    ['reminder_cron_log', 'started_at < NOW() - INTERVAL 30 DAY',   30,  'протокол запусков крона напоминаний'],
    ['reminder_runs',     'target_date < CURDATE() - INTERVAL 30 DAY', 30, 'защита от повторной отправки'],
    ['search_logs',       'created_at < NOW() - INTERVAL 90 DAY',   90,  'что искали в портале'],
    ['error_logs',        'created_at < NOW() - INTERVAL 90 DAY',   90,  'ошибки фронтенда'],
    ['tg_send_log',       'ts < NOW() - INTERVAL 180 DAY',          180, 'отправленные сообщения бота'],
];

$stamp = date('Y-m-d H:i:s');
$total = 0;

foreach ($RULES as [$table, $where, $days, $what]) {
    // Таблицы появляются со временем — отсутствующую просто пропускаем.
    $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $exists->execute([$table]);
    if (!(int)$exists->fetchColumn()) continue;

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where}")->fetchColumn();
    if ($cnt === 0) continue;

    if ($dryRun) {
        echo "[$stamp] {$table}: удалилось бы {$cnt} строк старше {$days} дн. ({$what})\n";
        continue;
    }

    // Партиями: одним DELETE на десятки тысяч строк таблица блокируется
    // надолго, а по ней в это же время работают кроны напоминаний.
    $deleted = 0;
    do {
        $n = $pdo->exec("DELETE FROM `{$table}` WHERE {$where} LIMIT 2000");
        $deleted += $n;
        if ($n === 2000) usleep(200000);
    } while ($n === 2000);

    $total += $deleted;
    echo "[$stamp] {$table}: удалено {$deleted} строк старше {$days} дн. ({$what})\n";
}

if (!$dryRun && $total === 0) {
    echo "[$stamp] чистить нечего\n";
}
