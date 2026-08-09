<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Cron: отключение учётных записей, которыми давно не пользуются.
 * Запуск раз в сутки, например в 7:00:
 *   php /var/www/bk-calc/api/cron_disable_stale_users.php
 *   php /var/www/bk-calc/api/cron_disable_stale_users.php --dry-run   (только показать)
 *
 * Кого отключаем: сотрудник не заходил STALE_DAYS дней. Если входов не было
 * вовсе, считаем от даты заведения — иначе учётка, заведённая «на будущее» и
 * забытая, висела бы открытой вечно.
 *
 * Кого НЕ трогаем:
 *   - администраторов: остаться без единого админа страшнее, чем лишний доступ;
 *   - уже отключённых;
 *   - тех, кого завели меньше STALE_DAYS дней назад.
 *
 * Отключение не удаляет человека: у него есть история в audit_log, задачи,
 * заявки. Ставим users.disabled_at, вход закрывается, живые сессии перестают
 * действовать (проверка в getSessionUser). Вернуть доступ — кнопка в админке.
 *
 * Итог уходит администраторам в Telegram. Молча закрывать людям доступ нельзя:
 * человек придёт с «портал не пускает», и надо сразу понимать почему.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$DRY_RUN = in_array('--dry-run', $argv ?? [], true);
$STALE_DAYS = 90;

$lockFile = __DIR__ . '/cron_disable_stale_users.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; exit; }
set_time_limit(120);

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

$log = function ($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; };

/**
 * users.created_at — varchar, а не дата (историческое). Приводим через
 * STR_TO_DATE по первым 10 символам, иначе сравнение дат молча врёт.
 */
$sql = "
    SELECT u.name, u.email, u.role,
           MAX(l.created_at) AS last_login,
           STR_TO_DATE(LEFT(u.created_at, 10), '%Y-%m-%d') AS created_dt
    FROM users u
    LEFT JOIN login_log l ON l.user_name = u.name
    WHERE u.disabled_at IS NULL
      AND u.role <> 'admin'
    GROUP BY u.name, u.email, u.role, u.created_at
    HAVING DATEDIFF(NOW(), COALESCE(MAX(l.created_at), created_dt)) >= ?
    ORDER BY COALESCE(MAX(l.created_at), created_dt) ASC
";
$s = $pdo->prepare($sql);
$s->execute([$STALE_DAYS]);
$stale = $s->fetchAll();

if (!$stale) {
    $log("некого отключать (порог {$STALE_DAYS} дн.)");
    exit;
}

$lines = [];
foreach ($stale as $u) {
    $lines[] = $u['last_login']
        ? "• {$u['name']} — последний вход " . date('d.m.Y', strtotime($u['last_login']))
        : "• {$u['name']} — ни разу не заходил, заведён " . date('d.m.Y', strtotime($u['created_dt']));
    if (!$DRY_RUN) {
        $reason = $u['last_login']
            ? "нет входов с " . substr((string)$u['last_login'], 0, 10)
            : "ни разу не заходил с " . $u['created_dt'];
        $pdo->prepare("UPDATE users SET disabled_at = NOW(), disabled_reason = ? WHERE name = ?")
            ->execute([mb_substr($reason, 0, 255), $u['name']]);
        // Живые сессии гасим сразу, не дожидаясь истечения.
        $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ?")->execute([$u['name']]);
    }
}

$log(($DRY_RUN ? 'ПРОБНЫЙ ЗАПУСК: ' : 'отключено: ') . count($stale));
foreach ($lines as $l) echo "  $l\n";

if ($DRY_RUN) exit;

// ─── Сообщаем администраторам ───
if ($BOT_TOKEN) {
    $text = "🔒 <b>Закрыт доступ по неактивности</b>\n"
          . "Не заходили " . $STALE_DAYS . " дней и больше:\n\n"
          . implode("\n", $lines)
          . "\n\nВернуть доступ — админка, карточка сотрудника.";
    $admins = $pdo->query("SELECT telegram_chat_id FROM users WHERE role = 'admin' AND telegram_chat_id IS NOT NULL")->fetchAll();
    foreach ($admins as $a) {
        $payload = ['chat_id' => $a['telegram_chat_id'], 'text' => $text, 'parse_mode' => 'HTML'];
        $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    $log('уведомлено администраторов: ' . count($admins));
}
