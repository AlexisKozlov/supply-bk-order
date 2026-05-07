<?php
/**
 * Обновляет список slash-команд, который Telegram показывает в меню бота.
 *
 * Запуск:
 *   php /var/www/bk-calc/api/update_telegram_commands.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "No .env\n");
    exit(1);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$botToken) {
    fwrite(STDERR, "No TELEGRAM_BOT_TOKEN\n");
    exit(1);
}

$commands = [
    ['command' => 'start', 'description' => 'Начать работу и выбрать роль'],
    ['command' => 'menu', 'description' => 'Главное меню'],
    ['command' => 'restaurant', 'description' => 'Меню ресторана'],
    ['command' => 'cards', 'description' => 'Поиск карточек товаров'],
    ['command' => 'today', 'description' => 'Сводка на сегодня'],
    ['command' => 'orders', 'description' => 'Заказы за 7 дней'],
    ['command' => 'deliveries', 'description' => 'Ближайшие поставки'],
    ['command' => 'plans', 'description' => 'Планирование'],
    ['command' => 'stock', 'description' => 'Критичные остатки'],
    ['command' => 'analysis', 'description' => 'Анализ запасов'],
    ['command' => 'consumption', 'description' => 'Топ расхода'],
    ['command' => 'prices', 'description' => 'Изменения цен'],
    ['command' => 'psc', 'description' => 'Протоколы согласования цен'],
    ['command' => 'schedule', 'description' => 'График доставок'],
    ['command' => 'sales', 'description' => 'Реализация ресторанов'],
    ['command' => 'export', 'description' => 'Выгрузки CSV'],
    ['command' => 'settings', 'description' => 'Настройки уведомлений'],
];

$payload = json_encode([
    'commands' => $commands,
    'language_code' => 'ru',
], JSON_UNESCAPED_UNICODE);

$ch = curl_init("https://api.telegram.org/bot{$botToken}/setMyCommands");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "Curl error: {$curlError}\n");
    exit(1);
}

$data = json_decode($response, true);
if ($httpCode !== 200 || empty($data['ok'])) {
    fwrite(STDERR, "Telegram error HTTP {$httpCode}: {$response}\n");
    exit(1);
}

echo "Telegram commands updated: " . count($commands) . "\n";
