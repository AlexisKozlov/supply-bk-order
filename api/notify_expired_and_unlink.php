<?php
/**
 * Разовый скрипт: финальная рассылка тем, у кого истёк срок перепривязки
 * Telegram-бота, и последующая отвязка (удаление) этих подписок.
 *
 * Логика выборки полностью совпадает с RPC tg_admin_unlink_expired:
 *   verified_at IS NULL
 *   AND must_reverify_by IS NOT NULL
 *   AND must_reverify_by < NOW()
 *
 * Порядок:
 *   1) Шлём каждому непривязанному с истёкшим дедлайном финальное сообщение.
 *   2) Одним DELETE удаляем эти подписки из ro_telegram_subs.
 *
 * Сообщение отправляем ДО удаления, иначе потеряем chat_id.
 *
 * Запуск (вручную): php /var/www/bk-calc/api/notify_expired_and_unlink.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = '/var/www/bk-calc-secrets/.env';
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

require_once __DIR__ . '/includes/tg_client.php';

// Шаг 1. Выбираем уникальные chat_id с истёкшим дедлайном без подтверждения.
$rows = $pdo->query("
    SELECT DISTINCT chat_id
    FROM ro_telegram_subs
    WHERE verified_at IS NULL
      AND must_reverify_by IS NOT NULL
      AND must_reverify_by < NOW()
      AND chat_id IS NOT NULL
      AND chat_id <> 0
")->fetchAll();

echo "Уникальных просроченных chat_id для рассылки: " . count($rows) . "\n\n";

$profileUrl = $SITE_URL . '/restaurant/profile';
$text = "⛔️ <b>Доступ к боту отключён</b>\n\n"
     . "Срок перепривязки истёк, ваша подписка удалена в целях безопасности. "
     . "Бот больше не будет присылать уведомления и принимать команды по этому ресторану.\n\n"
     . "Чтобы вернуть доступ:\n"
     . "1. Откройте профиль ресторана: {$profileUrl}\n"
     . "2. Нажмите «Получить код привязки» (код действует 10 минут).\n"
     . "3. Откройте бота @supplyportal_bot и пришлите этот код в чат.\n\n"
     . "После подтверждения в профиле появится «✓ привязан», и бот снова заработает. "
     . "Если ботом пользуются несколько сотрудников ресторана — код получает каждый со своего входа в кабинет.";

$sent = 0; $fail = 0;
foreach ($rows as $row) {
    $chatId = (int)$row['chat_id'];
    if ($chatId === 0) continue;

    $r = tgClientSend($chatId, $text, [
        'disable_preview' => true,
        'token'           => $BOT_TOKEN,
        'pdo'             => $pdo,
    ]);
    if ($r['ok']) {
        $sent++;
        echo "  ok  {$chatId}\n";
    } else {
        $fail++;
        echo "  err {$chatId} (http {$r['http_code']}, code {$r['error_code']}): " . substr((string)$r['description'], 0, 160) . "\n";
    }

    // Не более 20 сообщений в секунду.
    usleep(50000);
}

echo "\nОтправлено: {$sent}, ошибок: {$fail}.\n";

// Шаг 2. Удаляем те же строки. Делаем после рассылки, чтобы не потерять chat_id.
$deleted = $pdo->exec("
    DELETE FROM ro_telegram_subs
    WHERE verified_at IS NULL
      AND must_reverify_by IS NOT NULL
      AND must_reverify_by < NOW()
");

echo "Удалено просроченных подписок: {$deleted}.\n";
