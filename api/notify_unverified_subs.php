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

// Шаг 2. Дедлайн = сейчас + 48 часов. Помечаем только те старые подписки,
// у которых дедлайн ещё не был выставлен, чтобы повторный запуск не сдвигал срок.
$deadlineDt = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->modify('+48 hours');
$deadlineSql = $deadlineDt->format('Y-m-d H:i:s');
$deadlineHuman = $deadlineDt->format('d.m H:i');

$pdo->prepare("
    UPDATE ro_telegram_subs
    SET must_reverify_by = ?
    WHERE verified_at IS NULL
      AND must_reverify_by IS NULL
")->execute([$deadlineSql]);

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
$text = "⚠️ <b>Важно: до {$deadlineHuman} привяжите Telegram заново</b>\n\n"
     . "Мы усиливаем безопасность бота: подписки на рестораны больше не выдаются «по выбору в боте». "
     . "Чтобы продолжать пользоваться ботом, привяжите свой Telegram через личный кабинет:\n\n"
     . "1. Зайдите на {$SITE_URL} → войдите как ресторан.\n"
     . "2. Профиль → Telegram → «Получить код».\n"
     . "3. Пришлите 6-значный код в этот чат.\n\n"
     . "После {$deadlineHuman} все непривязанные подписки будут отключены.";

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
