<?php
/**
 * Cron: автосоздание карточек из шаблонов модуля «Задачи».
 * Запуск раз в сутки утром (рекомендуется 8:00 МСК):
 *   php /var/www/bk-calc/api/cron_tasks_recurring.php
 *
 * Логика:
 *   1. Выбираем активные расписания с next_run_date <= CURDATE().
 *   2. Для каждого:
 *      a) Подтягиваем владельца шаблона (user + role).
 *      b) Проверяем tCanWorkWithBoard(owner, target_board). Если нет
 *         доступа — расписание деактивируем (is_active=0,
 *         deactivated_reason='no_access') и идём дальше.
 *      c) Создаём карточку через tCreateCardFromTemplate (атомарно).
 *      d) Обновляем last_run_date = CURDATE(), next_run_date = пересчёт.
 *   3. Финальный лог: schedules=N created=M deactivated=K.
 *
 * Уведомления исполнителям ('assigned') шлёт сама tCreateCardFromTemplate.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$lockFile = __DIR__ . '/cron_tasks_recurring.lock';
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

// Загружаем активные созревшие расписания + владельца
$rows = $pdo->query("
    SELECT
        sch.*,
        tpl.owner_name AS owner_name,
        u.role         AS owner_role
    FROM tasks_template_schedules sch
    JOIN tasks_card_templates tpl ON tpl.id = sch.template_id
    LEFT JOIN users u ON u.name = tpl.owner_name
    WHERE sch.is_active = 1
      AND sch.next_run_date <= CURDATE()
    ORDER BY sch.id
")->fetchAll();

$created = 0;
$deactivated = 0;
$total = count($rows);

foreach ($rows as $sch) {
    try {
        // Шаблон ещё существует? (на случай гонок — каскад FK должен был всё убрать)
        $tplExists = $pdo->prepare("SELECT 1 FROM tasks_card_templates WHERE id = ? AND is_archived = 0");
        $tplExists->execute([(int)$sch['template_id']]);
        if (!$tplExists->fetchColumn()) continue;

        // Проверка доступа владельца к целевой доске
        $board = tGetBoard($pdo, (int)$sch['target_board_id']);
        $owner = ['name' => $sch['owner_name'], 'role' => $sch['owner_role'] ?? 'user'];
        if (!$board || $board['is_archived'] || !tCanWorkWithBoard($pdo, $owner, $board)) {
            $reason = ($board && $board['is_archived']) ? 'board_archived' : 'no_access';
            $pdo->prepare("UPDATE tasks_template_schedules SET is_active = 0, deactivated_reason = ? WHERE id = ?")
                ->execute([$reason, (int)$sch['id']]);
            $deactivated++;
            continue;
        }

        // Атомарное создание карточки
        $cardId = tCreateCardFromTemplate($pdo, (int)$sch['template_id'], $sch, $sch['owner_name']);
        if (!$cardId) continue;

        // Пересчёт дат: last = сегодня, next = следующее срабатывание после сегодня
        $tz = new DateTimeZone('Europe/Minsk');
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $next = tCalcNextRunDate(
            $sch['recurrence_kind'],
            $sch['weekday'],
            $sch['day_of_month'],
            $today
        );
        $pdo->prepare("UPDATE tasks_template_schedules SET last_run_date = ?, next_run_date = ? WHERE id = ?")
            ->execute([$today, $next, (int)$sch['id']]);
        $created++;
    } catch (Exception $e) {
        error_log('[cron_tasks_recurring] schedule#' . (int)$sch['id'] . ' error: ' . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] schedules={$total} created={$created} deactivated={$deactivated}\n";
