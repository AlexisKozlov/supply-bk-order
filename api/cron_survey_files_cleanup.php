<?php
/**
 * Крон-скрипт: чистка протухших черновых файлов опросов.
 * Запуск раз в день: crontab -e → 30 3 * * * php /var/www/bk-calc/api/cron_survey_files_cleanup.php
 *
 * Что делает: удаляет файлы из survey_response_files, у которых
 * response_id IS NULL и created_at старше 30 дней. Это «висящие»
 * черновики — ресторан загрузил файлы, но опрос так и не отправил.
 * После 30 дней такие файлы становятся бесполезным мусором на диске.
 *
 * Алгоритм: сначала собираем пути с диска, потом удаляем строки в БД,
 * потом физически выносим файлы. Если что-то упало посреди — файлы
 * либо есть в БД (вернёмся на следующем запуске), либо уже удалены и
 * на диске — повторное unlink безвреден.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = '/var/www/bk-calc-secrets/.env';
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

$cutoffDays = 30;

$rows = $pdo->prepare("
    SELECT id, file_path
    FROM survey_response_files
    WHERE response_id IS NULL
      AND created_at < (NOW() - INTERVAL ? DAY)
    LIMIT 500
");
$rows->execute([$cutoffDays]);
$batch = $rows->fetchAll();
if (!$batch) {
    echo date('c') . " no expired drafts\n";
    exit(0);
}

$ids = array_column($batch, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$pdo->prepare("DELETE FROM survey_response_files WHERE id IN ($placeholders)")->execute($ids);

$removed = 0;
$errors = 0;
foreach ($batch as $row) {
    $rel = ltrim((string)$row['file_path'], '/');
    $abs = __DIR__ . '/' . $rel;
    if (!is_file($abs)) continue;
    if (@unlink($abs)) $removed++;
    else $errors++;
}

echo date('c') . " cleaned " . count($ids) . " draft rows, removed $removed files, errors=$errors\n";
