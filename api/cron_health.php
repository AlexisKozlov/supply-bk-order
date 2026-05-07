<?php
// Cron: проверка здоровья сервера
// Запуск каждые 2 минуты в crontab
// Запрещено запускать через HTTP — внутри есть shell_exec.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = '/var/www/bk-calc-secrets/.env';
if (!file_exists($envFile)) exit;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$stateFile = __DIR__ . '/health_state.json';

// Состояние предыдущей проверки
$prevState = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$wasDown = $prevState['down'] ?? false;
$downSince = $prevState['down_since'] ?? null;

$problems = [];

// 1. Проверка БД
try {
    $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    $problems[] = "❌ База данных недоступна: " . $e->getMessage();
    $pdo = null;
}

// 2. Проверка памяти
$memInfo = @file_get_contents('/proc/meminfo');
if ($memInfo && preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) {
    $availMb = intval($m[1]) / 1024;
    if ($availMb < 100) {
        $problems[] = "⚠️ Мало свободной памяти: " . round($availMb) . " МБ";
    }
}

// 3. Проверка диска
$diskFree = @disk_free_space('/');
if ($diskFree !== false) {
    $diskFreeMb = $diskFree / 1024 / 1024;
    if ($diskFreeMb < 500) {
        $problems[] = "⚠️ Мало места на диске: " . round($diskFreeMb) . " МБ";
    }
}

// 4. Проверка php-fpm
$fpmStatus = shell_exec('systemctl is-active php8.3-fpm 2>/dev/null');
if (trim($fpmStatus) !== 'active') {
    $problems[] = "❌ PHP-FPM не запущен";
}

// 5. Проверка nginx
$nginxStatus = shell_exec('systemctl is-active nginx 2>/dev/null');
if (trim($nginxStatus) !== 'active') {
    $problems[] = "❌ Nginx не запущен";
}

$isDown = !empty($problems);
$now = date('Y-m-d H:i:s');

if ($isDown && !$wasDown) {
    // Только что упало — отправляем алерт
    $text = "🚨 <b>ПРОБЛЕМЫ С СЕРВЕРОМ</b>\n";
    $text .= "─────────────────────\n";
    $text .= implode("\n", $problems) . "\n";
    $text .= "\n🕐 Обнаружено: {$now}";
    sendAlert($BOT_TOKEN, $pdo, $text);
    file_put_contents($stateFile, json_encode(['down' => true, 'down_since' => $now, 'problems' => $problems]));
} elseif (!$isDown && $wasDown) {
    // Восстановилось — отправляем уведомление
    $downDuration = $downSince ? round((time() - strtotime($downSince)) / 60) : '?';
    $text = "✅ <b>СЕРВЕР ВОССТАНОВЛЕН</b>\n";
    $text .= "─────────────────────\n";
    $text .= "Был недоступен ~{$downDuration} мин.\n";
    $text .= "🕐 Восстановлен: {$now}";
    sendAlert($BOT_TOKEN, $pdo, $text);
    file_put_contents($stateFile, json_encode(['down' => false]));
} elseif ($isDown && $wasDown) {
    // Всё ещё не работает — обновляем состояние, но не спамим
    file_put_contents($stateFile, json_encode(['down' => true, 'down_since' => $downSince, 'problems' => $problems]));
} else {
    file_put_contents($stateFile, json_encode(['down' => false]));
}

echo "[{$now}] " . ($isDown ? "ПРОБЛЕМЫ: " . implode('; ', $problems) : "OK") . "\n";

function sendAlert($botToken, $pdo, $text) {
    if (!$botToken) return;
    // Отправляем всем админам с Telegram
    $chatIds = [];
    if ($pdo) {
        try {
            $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE role = 'admin' AND telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
            $chatIds = $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {}
    }
    foreach ($chatIds as $chatId) {
        $payload = json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']);
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
