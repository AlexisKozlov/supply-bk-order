<?php
/**
 * Разовый скрипт: уведомить все ещё не подтверждённые Telegram-подписки
 * о том, что нужно перепривязать аккаунт через личный кабинет.
 *
 * Логика:
 *   1) Сначала автоматически верифицируем тех, у кого УЖЕ есть запись в
 *      ro_users.telegram_chat_id — они когда-то ввели 6-значный код и
 *      перепривязка им не нужна.
 *   2) Оставшимся (verified_at IS NULL) выставляем must_reverify_by = NOW()+48h
 *      и шлём сообщение с инструкцией.
 *
 * Запуск (вручную): php /var/www/bk-calc/api/notify_unverified_subs.php
 *
 * Скрипт безопасен к повторному запуску в части БД (UPDATE по «уже не пустым»
 * полям), но повторно отправит сообщения. Запускать один раз.
 */

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) { fwrite(STDERR, "No .env\n"); exit(1); }
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$BOT_TOKEN) { fwrite(STDERR, "No TELEGRAM_BOT_TOKEN\n"); exit(1); }
$SITE_URL = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Шаг 1. Автоверификация тех, кто уже привязал аккаунт через код раньше.
$auto = $pdo->exec("
    UPDATE ro_telegram_subs rs
    JOIN ro_users ru
      ON ru.telegram_chat_id = rs.chat_id
     AND CAST(ru.restaurant_number AS UNSIGNED) = rs.restaurant_number
     AND ru.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
     AND ru.is_active = 1
    SET rs.verified_at = COALESCE(ru.last_login_at, NOW()),
        rs.verified_via = 'migration',
        rs.verified_ro_user_id = ru.id
    WHERE rs.verified_at IS NULL
");
echo "Автоверифицировано подписок (по уже привязанным ro_users): {$auto}\n";

// Шаг 2. Дедлайн ставим только тем, у кого он ещё не выставлен (повторный
// запуск его не сдвигает). Большинству он уже стоит с предыдущего запуска.
$deadlineDt = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->modify('+48 hours');
$deadlineSql = $deadlineDt->format('Y-m-d H:i:s');

$pdo->prepare("
    UPDATE ro_telegram_subs
    SET must_reverify_by = ?
    WHERE verified_at IS NULL
      AND must_reverify_by IS NULL
")->execute([$deadlineSql]);

// Реальный дедлайн берём из БД (он мог быть выставлен ранее и продлён вручную).
$dlRow = $pdo->query("
    SELECT MIN(must_reverify_by) min_dl, MAX(must_reverify_by) max_dl
    FROM ro_telegram_subs
    WHERE verified_at IS NULL
")->fetch();
$dlForText = $dlRow['min_dl'] ?: $deadlineSql;
$deadlineHuman = (new DateTime($dlForText, new DateTimeZone('Europe/Minsk')))->format('d.m H:i');

$rows = $pdo->query("
    SELECT DISTINCT chat_id
    FROM ro_telegram_subs
    WHERE verified_at IS NULL
      AND chat_id IS NOT NULL
      AND chat_id <> 0
")->fetchAll();

echo "Уникальных непроверенных chat_id: " . count($rows) . "\n";
echo "Крайний срок (Минск): {$deadlineHuman}\n\n";

// Текст рассылки.
$profileUrl = $SITE_URL . '/restaurant/profile';
$text = "⚠️ <b>Важно: сегодня в {$deadlineHuman} истекает срок перепривязки бота</b>\n\n"
     . "Мы усилили безопасность: получать данные по ресторану в боте теперь могут только подтверждённые сотрудники. "
     . "Ваша подписка ещё не подтверждена и сегодня будет отключена.\n\n"
     . "Чтобы не потерять доступ:\n"
     . "1. Откройте профиль ресторана: {$profileUrl}\n"
     . "2. Нажмите «Получить код привязки» (код действует 10 минут).\n"
     . "3. Пришлите этот код мне сюда, в чат.\n\n"
     . "После подтверждения в профиле появится «✓ привязан», и бот продолжит работать как обычно. "
     . "Если ботом пользуются несколько сотрудников ресторана — код получает каждый со своего входа в кабинет.";

$sent = 0; $fail = 0;
foreach ($rows as $row) {
    $chatId = (int)$row['chat_id'];
    if ($chatId === 0) continue;

    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = false;
    if ($resp) {
        $j = json_decode($resp, true);
        $ok = !empty($j['ok']);
    }
    if ($ok) {
        $sent++;
        echo "  ok  {$chatId}\n";
    } else {
        $fail++;
        echo "  err {$chatId} (http {$http}): " . substr((string)$resp, 0, 160) . "\n";
    }

    // Грубо ограничиваем темп: не более 20 сообщений в секунду.
    usleep(50000);
}

echo "\nИтого: отправлено {$sent}, ошибок {$fail}.\n";
echo "Доступ непривязанных подписок прекратится после {$deadlineHuman}.\n";
