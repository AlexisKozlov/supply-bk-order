<?php
/**
 * Cron: напоминания о сроках задач модуля «Задачи».
 * Запуск раз в сутки утром (рекомендуется 9:00 МСК):
 *   php /var/www/bk-calc/api/cron_tasks_deadlines.php
 *
 * Логика:
 *   - Берёт открытые карточки (is_done=0, is_archived=0) с due_date.
 *   - Сравнивает дату срока с сегодняшней (MySQL CURDATE() — серверный TZ).
 *     • завтра  → type = 'due_soon'
 *     • сегодня → type = 'due_today'
 *     • в прошлом → type = 'overdue'
 *   - Получатели — создатель доски + соисполнители карточки.
 *   - Дубликаты: за один календарный день одного типа на одну карточку
 *     одному пользователю отправляется только один раз (проверка по
 *     tasks_notifications с DATE(created_at)=CURDATE()).
 *
 * Каналы отправки — те же, что и у остальных уведомлений модуля задач:
 * запись в tasks_notifications + Telegram (если у пользователя привязан
 * telegram_chat_id). Используется существующая функция taskPushNotif().
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$lockFile = __DIR__ . '/cron_tasks_deadlines.lock';
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

require_once __DIR__ . '/includes/tasks.php';

// Выходные — напоминания о сроках не отправляем. День недели берём из MySQL
// (WEEKDAY: 0=Пн … 5=Сб, 6=Вс), потому что PHP и MySQL у нас в разных
// часовых поясах — у границы суток PHP-дата могла бы дать другой день.
$weekday = (int)$pdo->query("SELECT WEEKDAY(CURDATE())")->fetchColumn();
if ($weekday >= 5) {
    echo "[" . date('Y-m-d H:i:s') . "] выходной (weekday={$weekday}) — пропуск\n";
    exit;
}

// Открытые карточки с дедлайном; считаем категорию срока на стороне БД
// (CURDATE() — серверная локальная дата, MySQL у нас в +03:00).
$rows = $pdo->query("
    SELECT
        c.id          AS card_id,
        c.board_id    AS board_id,
        c.title       AS card_title,
        c.due_date    AS due_date,
        b.title       AS board_title,
        b.owner_name  AS owner_name,
        CASE
            WHEN DATE(c.due_date) < CURDATE() THEN 'overdue'
            WHEN DATE(c.due_date) = CURDATE() THEN 'due_today'
            WHEN DATE(c.due_date) = CURDATE() + INTERVAL 1 DAY THEN 'due_soon'
            ELSE NULL
        END           AS notif_type,
        DATEDIFF(CURDATE(), DATE(c.due_date)) AS overdue_days
    FROM tasks_cards c
    JOIN tasks_boards b ON b.id = c.board_id
    WHERE c.is_done = 0
      AND c.is_archived = 0
      AND c.due_date IS NOT NULL
      AND DATE(c.due_date) <= CURDATE() + INTERVAL 1 DAY
    ORDER BY c.id
")->fetchAll();

$dupCheck = $pdo->prepare("
    SELECT 1 FROM tasks_notifications
    WHERE user_name = ?
      AND card_id = ?
      AND type = ?
      AND DATE(created_at) = CURDATE()
    LIMIT 1
");
// Только соисполнители, у которых задача ещё в работе: кто закрыл свою
// часть (is_done = 1), напоминания о сроке получать больше не должен —
// даже если у автора карточка на его доске всё ещё открыта.
$assigneesStmt = $pdo->prepare("SELECT user_name FROM tasks_assignees WHERE card_id = ? AND is_done = 0");

$sentTotal = 0;
$cardsSeen = 0;

foreach ($rows as $r) {
    $type = $r['notif_type'];
    if (!$type) continue;
    $cardsSeen++;

    // Получатели — создатель доски + соисполнители (без дубликатов)
    $assigneesStmt->execute([$r['card_id']]);
    $assignees = array_column($assigneesStmt->fetchAll(), 'user_name');
    $recipients = array_values(array_unique(array_filter(array_merge(
        [$r['owner_name']], $assignees
    ))));

    $extra = [
        'card_title'   => $r['card_title'],
        'board_title'  => $r['board_title'],
        'due_date'     => $r['due_date'],
        'overdue_days' => (int)$r['overdue_days'],
    ];

    foreach ($recipients as $user) {
        if (!$user) continue;
        // Защита от повторной отправки в течение того же календарного дня
        $dupCheck->execute([$user, $r['card_id'], $type]);
        if ($dupCheck->fetchColumn()) continue;

        // sourceUser = null: уведомление от системы, не от другого пользователя.
        // taskPushNotif пропустит проверку «себе не шлём» (toUser !== null).
        taskPushNotif($pdo, $user, $type, (int)$r['card_id'], (int)$r['board_id'], null, $extra);
        $sentTotal++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] cards={$cardsSeen} sent={$sentTotal}\n";
