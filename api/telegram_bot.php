<?php
// Telegram Bot для Supply Department
// Webhook: https://supply-department.online/api/telegram_bot.php

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) exit;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$BOT_TOKEN) exit;

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) exit;

function sendMessage($chatId, $text, $replyMarkup = null) {
    global $BOT_TOKEN;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup) $data['reply_markup'] = json_encode($replyMarkup);
    file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage?" . http_build_query($data));
}

function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
    global $BOT_TOKEN;
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyMarkup) $data['reply_markup'] = json_encode($replyMarkup);
    file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/editMessageText?" . http_build_query($data));
}

function answerCallback($callbackId, $text = '') {
    global $BOT_TOKEN;
    file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery?" . http_build_query(['callback_query_id' => $callbackId, 'text' => $text]));
}

function showSettings($chatId, $msgId, $userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $settings = $s->fetch();
    if (!$settings) return;

    $labels = [
        'psc_expiry' => 'ПСЦ истекает',
        'overdue_delivery' => 'Просроченная поставка',
        'price_changed' => 'Цены изменились',
        'low_stock' => 'Остатки заканчиваются',
        'daily_summary' => 'Ежедневная сводка',
    ];

    $text = "⚙️ <b>Настройки уведомлений</b>\n\nНажмите кнопку чтобы включить/выключить:";
    $buttons = [];
    foreach ($labels as $key => $label) {
        $on = $settings[$key] ? '✅' : '❌';
        $buttons[] = [['text' => "$on $label", 'callback_data' => "toggle_$key"]];
    }

    $markup = ['inline_keyboard' => $buttons];

    if ($msgId) {
        editMessage($chatId, $msgId, $text, $markup);
    } else {
        sendMessage($chatId, $text, $markup);
    }
}

// Handle callback queries (settings toggle)
if (isset($input['callback_query'])) {
    $cb = $input['callback_query'];
    $chatId = $cb['message']['chat']['id'];
    $msgId = $cb['message']['message_id'];
    $data = $cb['data'] ?? '';

    if (str_starts_with($data, 'toggle_')) {
        $field = substr($data, 7);
        $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary'];
        if (in_array($field, $allowed)) {
            // Найти пользователя по chat_id
            $u = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
            $u->execute([$chatId]);
            $user = $u->fetchColumn();
            if ($user) {
                $pdo->prepare("UPDATE telegram_settings SET `$field` = NOT `$field` WHERE user_name = ?")->execute([$user]);
                // Показать обновлённые настройки
                showSettings($chatId, $msgId, $user);
                answerCallback($cb['id'], 'Настройка изменена');
            }
        }
    }
    exit;
}

// Handle messages
$msg = $input['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$text = trim($msg['text'] ?? '');

if ($text === '/start') {
    sendMessage($chatId, "👋 <b>Supply Department</b>\n\nЭтот бот отправляет уведомления о закупках.\n\nДля привязки аккаунта отправьте свой <b>email</b>, который используете для входа в систему.");
    exit;
}

if ($text === '/settings') {
    $u = $pdo->prepare("SELECT name FROM users WHERE telegram_chat_id = ?");
    $u->execute([$chatId]);
    $user = $u->fetchColumn();
    if (!$user) {
        sendMessage($chatId, "❌ Аккаунт не привязан. Отправьте свой email для привязки.");
        exit;
    }
    showSettings($chatId, null, $user);
    exit;
}

// Попытка привязки по email
if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
    $u = $pdo->prepare("SELECT name FROM users WHERE email = ?");
    $u->execute([$text]);
    $user = $u->fetch();
    if (!$user) {
        sendMessage($chatId, "❌ Пользователь с email <b>{$text}</b> не найден в системе.");
        exit;
    }
    // Сохранить chat_id
    $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE email = ?")->execute([$chatId, $text]);
    // Создать настройки если нет
    $pdo->prepare("INSERT IGNORE INTO telegram_settings (user_name) VALUES (?)")->execute([$user['name']]);
    sendMessage($chatId, "✅ Аккаунт <b>{$user['name']}</b> привязан!\n\nТеперь вы будете получать уведомления. Настроить их можно командой /settings");
    exit;
}

sendMessage($chatId, "Отправьте email для привязки аккаунта или /settings для настроек.");
