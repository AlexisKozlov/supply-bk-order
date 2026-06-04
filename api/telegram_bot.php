<?php
// Telegram Bot для Supply Department
// Webhook: https://supply-department.online/api/telegram_bot.php

// Часовой пояс по умолчанию — Минск (+03:00). Совпадает с TZ MariaDB.
date_default_timezone_set('Europe/Minsk');

$envFile = '/var/www/bk-calc-secrets/.env';
if (!file_exists($envFile)) exit;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
if (!$BOT_TOKEN) exit;

$SITE_URL = $_ENV['SITE_URL'] ?? 'https://supply-department.online';

$GEMINI_API_KEY = $_ENV['GEMINI_API_KEY'] ?? '';
$GROQ_API_KEY = $_ENV['GROQ_API_KEY'] ?? '';
$DEEPSEEK_API_KEY = $_ENV['DEEPSEEK_API_KEY'] ?? '';
$OPENROUTER_API_KEY = $_ENV['OPENROUTER_API_KEY'] ?? '';

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 3,
]);
// Лимит на отдельный SQL-запрос — 5 секунд. Без него тяжёлый запрос
// (большой JOIN, lock) подвесил бы webhook на весь PHP-FPM timeout, и
// пользователь видел бы «бот не отвечает». При срабатывании MariaDB
// кидает PDOException, его ловит глобальный set_exception_handler выше.
// AI-инструменты в bot_tools.php переопределяют этот лимит наверх (30 сек),
// потому что им нужны более тяжёлые отчёты.
try { $pdo->exec("SET SESSION max_statement_time = 5"); } catch (Throwable $e) {}

// Проверка секретного токена вебхука — обязательная.
// Без этого любой из интернета мог бы слать боту поддельные сообщения
// от лица любого пользователя и выполнять команды.
$webhookSecret = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';
if (!$webhookSecret) {
    error_log('[TelegramBot] TELEGRAM_WEBHOOK_SECRET не задан в .env — webhook отключён');
    http_response_code(500);
    exit;
}
$headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($webhookSecret, $headerSecret)) {
    http_response_code(403);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) exit;

// Определяем chatId для ошибок
$_CHAT_ID = $input['message']['chat']['id'] ?? $input['callback_query']['message']['chat']['id'] ?? null;

// Любая активность от пользователя = бот снова доступен. Сбрасываем
// флажок «заблокировал бота» в обеих таблицах подписок. Если он не
// блокировал — UPDATE'ы не задевают строки (фильтр на tg_blocked_at IS NOT NULL).
$_INCOMING_CHAT = $input['message']['chat']['id']
    ?? $input['edited_message']['chat']['id']
    ?? $input['callback_query']['from']['id']
    ?? null;
if ($_INCOMING_CHAT) {
    try {
        $pdo->prepare("UPDATE users SET tg_blocked_at = NULL WHERE telegram_chat_id = ? AND tg_blocked_at IS NOT NULL")
            ->execute([(string)$_INCOMING_CHAT]);
        $pdo->prepare("UPDATE ro_telegram_subs SET tg_blocked_at = NULL WHERE chat_id = ? AND tg_blocked_at IS NOT NULL")
            ->execute([(int)$_INCOMING_CHAT]);
    } catch (Throwable $e) {
        error_log('[telegram_bot] unblock-on-incoming failed: ' . $e->getMessage());
    }
}

/**
 * Уведомление админов в Telegram о критической ошибке бота. Срабатывает
 * максимум раз в 10 минут на одну сигнатуру (file:line:message-hash),
 * чтобы fatal в горячем коде не завалил админа сотней сообщений в час.
 *
 * Best-effort: любое исключение внутри (PDO мёртв, нет токена, нет admin'ов)
 * проглатывается — иначе уведомление само станет источником fatal'ов.
 *
 * Дедуп хранится в tg_state с chat_id=0 и mode='err:{sig}', TTL 600 сек.
 */
function tgNotifyAdminError(string $where, string $message, string $file, int $line): void {
    try {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) return;
        // Сигнатура: file + line + первые 80 символов сообщения.
        $sig = substr(md5($file . ':' . $line . ':' . substr($message, 0, 80)), 0, 12);
        $mode = 'err:' . $sig;
        if (function_exists('tgStateGet') && tgStateGet(0, $mode) !== null) {
            return; // уже уведомили за последние 10 минут
        }
        // Список адресатов — все админы с привязанным TG, не заблокировавшие бота.
        $admins = $pdo->query("
            SELECT name, telegram_chat_id
            FROM users
            WHERE role = 'admin' AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''
              AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$admins) return;

        $safeMsg = tgEsc(mb_substr($message, 0, 400));
        $safeFile = tgEsc(basename($file));
        $text = "⚠️ <b>Ошибка Telegram-бота</b>\n"
              . "<i>{$where}</i>\n\n"
              . "<code>{$safeMsg}</code>\n\n"
              . "📄 {$safeFile}:{$line}\n"
              . "🕒 " . date('d.m H:i');
        foreach ($admins as $a) {
            if (function_exists('tgClientSend')) {
                @tgClientSend((int)$a['telegram_chat_id'], $text, ['pdo' => $pdo]);
            }
        }
        // Запоминаем дедуп
        if (function_exists('tgStateSet')) {
            tgStateSet(0, $mode, ['file' => $file, 'line' => $line, 'msg' => mb_substr($message, 0, 200)], 600);
        }
    } catch (Throwable $e) {
        // Best-effort: сама нотификация не должна породить новый fatal.
        error_log('[TelegramBot] notify admin failed: ' . $e->getMessage());
    }
}

// Глобальный обработчик ошибок — бот никогда не падает молча. Telegram'у
// всегда отвечаем 200 OK, иначе webhook уйдёт в retry-петлю (минута, 5
// мин, час, день) и забьёт очередь на много дней при постоянной ошибке.
// Пользователю шлём короткое сообщение, в лог пишем подробности, админу —
// уведомление с дедупом (см. tgNotifyAdminError).
set_exception_handler(function(Throwable $e) {
    global $_CHAT_ID;
    if ($_CHAT_ID) {
        sendMessage($_CHAT_ID, "⚠️ Произошла ошибка. Попробуйте ещё раз или используйте /menu");
    }
    error_log('[TelegramBot] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    tgNotifyAdminError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
    if (!headers_sent()) http_response_code(200);
});

// Fatal errors (out of memory, parse error, segfault) set_exception_handler
// не ловит — нужен shutdown-handler. Иначе при OOM PHP отдаёт 500 и
// Telegram опять начинает retry-петлю.
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log('[TelegramBot] FATAL ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        tgNotifyAdminError('FATAL', $err['message'], $err['file'], $err['line']);
        if (!headers_sent()) http_response_code(200);
    }
});

// HTML-escape для подстановки строк из БД и user-input в parse_mode=HTML.
// Без него имя товара/поставщика/юрлица с & или < ломает разметку, и
// Telegram молча отвергает всё сообщение (HTTP 400 «can't parse entities»).
function tgEsc(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ═══ Telegram helpers ═══
// Все ниже — тонкие обёртки над tg_client.php. Сам клиент через cURL,
// проверяет ответ Telegram, логирует ошибки в error_log с префиксом
// [tg-client]. Бизнес-сигнатуры функций сохранены, чтобы не править
// сотни вызовов sendMessage/editMessage/… в коде бота.

require_once __DIR__ . '/includes/tg_client.php';

// Тонкие обёртки — берут глобальный $pdo и передают в опции, чтобы
// tg_client сам помечал заблокированных пользователей (HTTP 403 / chat
// not found). Сбрасывается флаг в начале обработки входящего сообщения.
function sendMessage($chatId, $text, $replyMarkup = null) {
    global $pdo;
    return tgClientSend($chatId, $text, ['reply_markup' => $replyMarkup, 'pdo' => $pdo])['ok'];
}

function editMessage($chatId, $messageId, $text, $replyMarkup = null) {
    global $pdo;
    return tgClientEdit($chatId, $messageId, $text, ['reply_markup' => $replyMarkup, 'pdo' => $pdo])['ok'];
}

function editMessageReplyMarkup($chatId, $messageId, $replyMarkup = null) {
    global $pdo;
    return tgClientEditReplyMarkup($chatId, $messageId, $replyMarkup, ['pdo' => $pdo])['ok'];
}

/**
 * Найти ro_telegram_subs запись по chat_id (только верифицированных).
 * Возвращает массив с id, restaurant_number, legal_entity_group, либо null.
 */
function rrFindRoSub($pdo, $chatId) {
    $s = $pdo->prepare("
        SELECT id, restaurant_number, legal_entity_group, first_name, username
        FROM ro_telegram_subs
        WHERE chat_id = ? AND verified_at IS NOT NULL
        LIMIT 1
    ");
    $s->execute([$chatId]);
    return $s->fetch() ?: null;
}

/**
 * Управление напоминаниями: список ВСЕХ локальных поставщиков ресторана
 * с возможностью подключить/отключить подписку прямо в боте.
 *
 * Каждый поставщик имеет статус для текущего chat_id:
 *   ✓ — вы получаете → кнопка отключает
 *   ○ — не получаете  → кнопка подключает (и автоматически создаёт подписку)
 */
function rrShowMyReminders($pdo, $chatId, $editMsgId = null) {
    $tg = rrFindRoSub($pdo, $chatId);
    if (!$tg) {
        $msg = "🔒 Эта функция доступна только привязанным сотрудникам ресторана.\n\nНажмите /start чтобы привязать чат к ресторану.";
        if ($editMsgId) editMessage($chatId, $editMsgId, $msg);
        else sendMessage($chatId, $msg);
        return;
    }
    // Restaurant id
    $r = $pdo->prepare("SELECT id, number FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
    $r->execute([$tg['restaurant_number'], $tg['legal_entity_group']]);
    $rest = $r->fetch();
    if (!$rest) {
        $msg = "Не удалось найти ваш ресторан в базе. Обратитесь в отдел закупок.";
        if ($editMsgId) editMessage($chatId, $editMsgId, $msg);
        else sendMessage($chatId, $msg);
        return;
    }

    // Список ВСЕХ локальных поставщиков ресторана (по supplier_schedules)
    $sup = $pdo->prepare("
        SELECT DISTINCT s.id, s.short_name
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id
        WHERE ss.restaurant_id = ? AND ss.is_active = 1 AND s.is_active = 1 AND s.so_enabled = 0
        ORDER BY s.short_name
    ");
    $sup->execute([(int)$rest['id']]);
    $suppliers = $sup->fetchAll();

    // Есть ли у ресторана настроенная основная поставка (хотя бы один день с дедлайном)
    $mainCheck = $pdo->prepare("
        SELECT 1 FROM delivery_schedule
        WHERE restaurant_id = ? AND order_day IS NOT NULL AND order_deadline IS NOT NULL
        LIMIT 1
    ");
    $mainCheck->execute([(int)$rest['id']]);
    $hasMain = (bool)$mainCheck->fetchColumn();

    if (!$suppliers && !$hasMain) {
        $msg = "📭 Для вашего ресторана пока не настроены графики локальных поставщиков и основной поставки.\n\nЕсли нужно — обратитесь в отдел закупок.";
        if ($editMsgId) editMessage($chatId, $editMsgId, $msg);
        else sendMessage($chatId, $msg);
        return;
    }

    // На какие поставщики этот tg-пользователь сейчас подписан
    $subscribedIds = [];
    $stmt = $pdo->prepare("
        SELECT s.supplier_id
        FROM restaurant_reminder_tg_subscribers rrts
        JOIN restaurant_reminder_subscriptions s ON s.id = rrts.subscription_id
        WHERE rrts.ro_tg_sub_id = ? AND rrts.is_active = 1
          AND s.restaurant_id = ? AND s.is_enabled = 1
    ");
    $stmt->execute([(int)$tg['id'], (int)$rest['id']]);
    foreach ($stmt->fetchAll() as $r) $subscribedIds[$r['supplier_id']] = true;

    // Подписан ли этот tg-пользователь на основную поставку
    $mainOn = false;
    if ($hasMain) {
        $mainStmt = $pdo->prepare("
            SELECT 1 FROM restaurant_main_delivery_tg_subscribers rmts
            JOIN restaurant_main_delivery_subscriptions s ON s.id = rmts.subscription_id
            WHERE rmts.ro_tg_sub_id = ? AND rmts.is_active = 1
              AND s.restaurant_id = ? AND s.is_enabled = 1
            LIMIT 1
        ");
        $mainStmt->execute([(int)$tg['id'], (int)$rest['id']]);
        $mainOn = (bool)$mainStmt->fetchColumn();
    }

    $text = "🔔 <b>Напоминания о подаче заявок</b>\n"
          . "Ресторан №" . htmlspecialchars((string)$rest['number'], ENT_QUOTES, 'UTF-8') . "\n\n"
          . "Нажмите на пункт, чтобы подключить или отключить напоминания.\n"
          . "<i>Поставщики, которые принимают заявки через портал, уведомляют автоматически — здесь не показаны.</i>";

    $keyboard = [];
    if ($hasMain) {
        $icon = $mainOn ? '✅' : '⬜';
        $keyboard[] = [
            ['text' => $icon . ' Основная поставка (склад)', 'callback_data' => 'rrtoggle_main'],
        ];
    }
    foreach ($suppliers as $s) {
        $isOn = !empty($subscribedIds[$s['id']]);
        $icon = $isOn ? '✅' : '⬜';
        $name = $s['short_name'];
        if (mb_strlen($name) > 26) $name = mb_substr($name, 0, 24) . '…';
        $keyboard[] = [
            ['text' => $icon . ' ' . $name, 'callback_data' => 'rrtoggle:' . $s['id']],
        ];
    }
    $keyboard[] = [
        ['text' => '⟳ Обновить', 'callback_data' => 'rrmine'],
        ['text' => '◂ Меню', 'callback_data' => 'rest_my_subs'],
    ];
    $markup = ['inline_keyboard' => $keyboard];

    if ($editMsgId) editMessage($chatId, $editMsgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
}

function answerCallback($callbackId, $text = '', $showAlert = false) {
    return tgClientAnswerCallback($callbackId, $text, $showAlert)['ok'];
}

function sendMessageAndGetId($chatId, $text) {
    global $pdo;
    $r = tgClientSend($chatId, $text, ['pdo' => $pdo]);
    return $r['result']['message_id'] ?? null;
}

function deleteMessage($chatId, $messageId) {
    global $pdo;
    return tgClientDelete($chatId, $messageId, ['pdo' => $pdo])['ok'];
}

function sendTyping($chatId) {
    global $pdo;
    return tgClientTyping($chatId, ['pdo' => $pdo])['ok'];
}

function sendDocument($chatId, $filename, $content, $caption = '') {
    // Раньше слался без mime → Telegram сам угадывал. По факту в бот
    // отдавались csv-выгрузки, mime ставим явно. На вызывающей стороне
    // это не отражается — caption передаётся в HTML.
    global $pdo;
    return tgClientSendDocument($chatId, $filename, $content, [
        'mime'    => 'text/csv',
        'caption' => $caption,
        'pdo'     => $pdo,
    ])['ok'];
}

require_once __DIR__ . '/includes/legal_entities.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/bot_state.php';
require_once __DIR__ . '/includes/bot_ai.php';
require_once __DIR__ . '/includes/bot_lookup.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/bot_tools.php';
require_once __DIR__ . '/includes/bot_rest.php';
require_once __DIR__ . '/includes/bot_surveys.php';
require_once __DIR__ . '/includes/bot_chat.php';
require_once __DIR__ . '/includes/bot_import.php';
require_once __DIR__ . '/includes/bot_faq.php';

// ═══ Получить пользователя по chat_id ═══

function getUser($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT name, role, legal_entities FROM users WHERE telegram_chat_id = ?");
    $s->execute([$chatId]);
    $u = $s->fetch();
    if (!$u) return null;
    $u['legal_entities'] = ($u['legal_entities'] && is_string($u['legal_entities'])) ? json_decode($u['legal_entities'], true) : [];
    return $u;
}

function getUserEntity($user) {
    global $pdo;
    // Проверяем, есть ли сохранённый выбор юрлица в telegram_settings
    $s = $pdo->prepare("SELECT selected_entity FROM telegram_settings WHERE user_name = ?");
    $s->execute([$user['name']]);
    $row = $s->fetch();
    $selected = $row ? ($row['selected_entity'] ?? null) : null;
    // Если выбрано и есть в списке доступных — используем
    if ($selected && in_array($selected, $user['legal_entities'])) {
        return $selected;
    }
    return $user['legal_entities'][0] ?? null;
}

function setUserEntity($userName, $entity) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_settings SET selected_entity = ? WHERE user_name = ?")->execute([$entity, $userName]);
}

function getUserMode($userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT mode FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $row = $s->fetch();
    return $row ? ($row['mode'] ?? null) : null;
}

function setUserMode($userName, $mode) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_settings SET mode = ? WHERE user_name = ?")->execute([$mode, $userName]);
}

function getEntityShort($entity) {
    if (strpos($entity, 'Бургер') !== false) return 'БК';
    if (strpos($entity, 'Воглия') !== false) return 'ВМ';
    if (strpos($entity, 'Пицца') !== false) return 'ПС';
    return mb_substr($entity, 0, 4);
}

function botOpenRestaurantCabinet($chatId, $msgId, $restNum) {
    global $pdo;

    $restGroup = botGetRestaurantGroupByNumber($pdo, $restNum);
    $ru = $pdo->prepare("SELECT id FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
    $ru->execute([$restNum, $restGroup]);
    if (!$ru->fetch()) {
        editMessage($chatId, $msgId, "Учётная запись ресторана " . botFormatSubscribedRestaurant($restNum, $restGroup) . " не найдена. Обратитесь в отдел закупок.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO ro_tg_tokens (token, kind, telegram_chat_id, restaurant_number, legal_entity_group, expires_at, used) VALUES (?, 'auth', ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . RO_AUTH_TOKEN_TTL_MINUTES . " MINUTE), 0)")
        ->execute([$token, $chatId, $restNum, $restGroup]);

    $siteUrl = rtrim(getenv('SITE_URL') ?: 'https://supply-department.online', '/');
    $url = "{$siteUrl}/restaurant?tg_token={$token}";
    $btns = [
        [['text' => '🏠 Открыть кабинет', 'url' => $url]],
        [['text' => '« Назад', 'callback_data' => 'start_role_restaurant']],
    ];

    editMessage(
        $chatId,
        $msgId,
        "🏠 <b>Личный кабинет</b>\n\nНажмите кнопку, чтобы открыть кабинет ресторана " . botFormatSubscribedRestaurant($restNum, $restGroup) . ".\n\n<i>Ссылка действует 5 минут.</i>",
        ['inline_keyboard' => $btns]
    );
}

function botEnsureRestaurantSubscription($chatId, $restNum, $from = [], $verifiedRoUserId = null, $verifiedVia = null) {
    global $pdo;
    $firstName = mb_substr($from['first_name'] ?? '', 0, 255) ?: null;
    $tgUsername = isset($from['username']) ? mb_substr($from['username'], 0, 255) : null;
    $restGroup = botGetRestaurantGroupByNumber($pdo, $restNum);
    if ($verifiedVia !== null) {
        // Подписка с подтверждением: дополнительно фиксируем verified_at и сбрасываем дедлайн перепривязки.
        $pdo->prepare("
            INSERT INTO ro_telegram_subs (restaurant_number, legal_entity_group, chat_id, first_name, username,
                                          verified_at, verified_via, verified_ro_user_id, must_reverify_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, NULL)
            ON DUPLICATE KEY UPDATE
                first_name = COALESCE(VALUES(first_name), first_name),
                username = COALESCE(VALUES(username), username),
                verified_at = NOW(),
                verified_via = VALUES(verified_via),
                verified_ro_user_id = VALUES(verified_ro_user_id),
                must_reverify_by = NULL
        ")->execute([(int)$restNum, $restGroup, (int)$chatId, $firstName, $tgUsername, $verifiedVia, $verifiedRoUserId]);
    } else {
        $pdo->prepare("
            INSERT INTO ro_telegram_subs (restaurant_number, legal_entity_group, chat_id, first_name, username)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                first_name = COALESCE(VALUES(first_name), first_name),
                username = COALESCE(VALUES(username), username)
        ")->execute([(int)$restNum, $restGroup, (int)$chatId, $firstName, $tgUsername]);
    }
}

// Сколько подтверждённых ресторанов привязано к chat_id (с учётом переходного периода).
// Если у подписки есть must_reverify_by и срок ещё не истёк — она тоже считается активной.
function botCountActiveSubs($chatId) {
    global $pdo;
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM ro_telegram_subs
        WHERE chat_id = ?
          AND (verified_at IS NOT NULL
               OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
    ");
    $s->execute([(int)$chatId]);
    return (int)$s->fetchColumn();
}

// Сколько у подписок этого chat_id осталось до дедлайна (если они не подтверждены).
// Возвращает NULL, если все подписки подтверждены или истекли.
function botGetReverifyDeadline($chatId) {
    global $pdo;
    $s = $pdo->prepare("
        SELECT MAX(must_reverify_by) AS dl
        FROM ro_telegram_subs
        WHERE chat_id = ?
          AND verified_at IS NULL
          AND must_reverify_by IS NOT NULL
          AND must_reverify_by > NOW()
    ");
    $s->execute([(int)$chatId]);
    return $s->fetchColumn() ?: null;
}

// Текст-инструкция о привязке аккаунта ресторана через 6-значный код.
function botRestaurantLinkInstructions() {
    global $SITE_URL;
    return "🏪 <b>Кабинет ресторана</b>\n\n"
         . "Чтобы пользоваться ботом, привяжите аккаунт ресторана:\n\n"
         . "1. Откройте <a href=\"{$SITE_URL}\">{$SITE_URL}</a>, войдите как ресторан.\n"
         . "2. Профиль → Telegram → «Получить код».\n"
         . "3. Пришлите 6-значный код в этот чат.\n\n"
         . "<i>Код действует 10 минут. Каждый сотрудник получает свой код.</i>";
}

function botCommandsHelpText() {
    return "📋 <b>Команды бота</b>\n\n"
         . "/start — начать и выбрать роль\n"
         . "/menu — главное меню\n"
         . "/restaurant — меню ресторана\n"
         . "/cards — поиск карточек товаров\n"
         . "/today — сводка на сегодня\n"
         . "/orders — заказы за 7 дней\n"
         . "/deliveries — ближайшие поставки\n"
         . "/plans — планирование\n"
         . "/stock — критичные остатки\n"
         . "/analysis — анализ запасов\n"
         . "/consumption — топ расхода\n"
         . "/prices — изменения цен\n"
         . "/psc — протоколы согласования цен\n"
         . "/schedule — график доставок\n"
         . "/sales — реализация ресторанов\n"
         . "/export — выгрузки CSV\n"
         . "/settings — настройки уведомлений";
}

// ═══ Команды с данными ═══

// Универсальная отправка: editMessage если есть $editMsgId, иначе sendMessage
function botSend($chatId, $text, $replyMarkup = null, $editMsgId = null) {
    if ($editMsgId) {
        editMessage($chatId, $editMsgId, $text, $replyMarkup);
    } else {
        sendMessage($chatId, $text, $replyMarkup);
    }
}

function cmdOrders($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT o.id, o.supplier, o.created_by, o.created_at, o.delivery_date,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.created_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) {
        $btns = [
            [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📦 <b>Заказы</b>{$es}\n<i>За 7 дней заказов нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $totalBoxes = array_sum(array_column($orders, 'total_boxes'));
    $text = "📦 <b>Заказы за 7 дней</b>{$es}\n";
    $text .= "<i>" . count($orders) . " заказов · " . number_format($totalBoxes, 0, '.', ' ') . " кор.</i>\n";
    $text .= "─────────────────────\n";
    foreach ($orders as $o) {
        $date = date('d.m', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m', strtotime($o['delivery_date'])) : '—';
        $boxes = $o['total_boxes'] ?: 0;
        $text .= "<b>" . tgEsc($o['supplier']) . "</b>  {$o['items_count']} поз. · {$boxes} кор.\n";
        $text .= "  {$date} → {$delivery} · " . tgEsc($o['created_by']) . "\n";
    }
    $btns = [
        [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdStock($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT a.sku, p.name, p.supplier, a.stock, a.consumption, a.period_days,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   CASE WHEN a.consumption > 0 THEN ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) ELSE 999 END as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 25";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $btns = [
        [['text' => '📊 Анализ запасов', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$items) {
        botSend($chatId, "📉 <b>Критичные остатки</b>{$es}\n\n✅ Нет товаров с запасом ≤ 5 дней.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $text = "📉 <b>Критичные остатки</b>{$es}\n";
    $text .= "<i>" . count($items) . " товаров с запасом ≤ 5 дней</i>\n";
    $text .= "─────────────────────\n";
    foreach ($items as $i) {
        $name = $i['name'] ? mb_substr($i['name'], 0, 30) : $i['sku'];
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $icon = $i['days_left'] <= 0 ? '🔴' : '🟠';
        $uLabel = getUomLabel($i['uom'] ?? 'шт');
        $text .= "{$icon} <b>" . tgEsc($name) . "</b>\n";
        $text .= "  {$i['stock']} {$uLabel} · {$daily}/день · <b>{$i['days_left']} дн.</b>\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdConsumption($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days, COALESCE(p.unit_of_measure, 'шт') as uom
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    $btns = [
        [['text' => '📊 Анализ запасов', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$items) {
        botSend($chatId, "📊 <b>Расход</b>{$es}\n\nДанных пока нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $text = "📊 <b>Топ-15 по расходу</b>{$es}\n";
    $text .= "─────────────────────\n";
    foreach ($items as $n => $i) {
        $days = max($i['period_days'], 1);
        $daily = round($i['consumption'] / $days, 1);
        $name = $i['name'] ? mb_substr($i['name'], 0, 28) : $i['sku'];
        $uLabel = getUomLabel($i['uom'] ?? 'шт');
        $num = $n + 1;
        $text .= "<b>{$num}.</b> " . tgEsc($name) . "\n";
        $text .= "  <b>{$daily}</b> {$uLabel}/день · {$i['consumption']} за {$days} дн.\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdPrices($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT ph.sku, p.name as product_name, ph.old_price, ph.new_price, ph.changed_by, ph.changed_at, ph.supplier
            FROM price_history ph
            LEFT JOIN products p ON p.sku = ph.sku AND p.legal_entity = ph.legal_entity AND p.is_active = 1
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity = ph.legal_entity COLLATE utf8mb4_general_ci)";
    $params = [];
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $changes = $s->fetchAll();

    $btns = [
        [['text' => '💰 Цены на сайте', 'url' => $SITE_URL . '/pricing']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$changes) {
        botSend($chatId, "💰 <b>Цены</b>{$es}\n\nИзменений пока нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $upCnt = 0; $downCnt = 0;
    foreach ($changes as $c) {
        if ($c['new_price'] > $c['old_price']) $upCnt++;
        elseif ($c['new_price'] < $c['old_price']) $downCnt++;
    }
    $text = "💰 <b>Изменения цен</b>{$es}\n";
    $text .= "<i>↑{$upCnt} повышений · ↓{$downCnt} снижений</i>\n";
    $text .= "─────────────────────\n";
    foreach ($changes as $c) {
        $date = date('d.m', strtotime($c['changed_at']));
        $name = $c['product_name'] ? mb_substr($c['product_name'], 0, 25) : $c['sku'];
        $pctRaw = $c['old_price'] > 0 ? round(($c['new_price'] - $c['old_price']) / $c['old_price'] * 100) : 0;
        $pct = $pctRaw > 0 ? "+{$pctRaw}%" : "{$pctRaw}%";
        $arrow = $c['new_price'] > $c['old_price'] ? '▲' : ($c['new_price'] < $c['old_price'] ? '▼' : '•');
        $text .= "{$arrow} <b>" . tgEsc($name) . "</b>\n";
        $text .= "  {$c['old_price']} → <b>{$c['new_price']}</b> ({$pct}) · {$date}\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function cmdPsc($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';

    $sql = "SELECT pa.number, pa.supplier, pa.valid_from, pa.valid_to, pa.status,
                   DATEDIFF(pa.valid_to, CURDATE()) as days_left
            FROM price_agreements pa WHERE pa.status = 'active'";
    $params = [];
    if ($entity) { $sql .= " AND pa.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY pa.valid_to ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $agreements = $s->fetchAll();

    $btns = [
        [['text' => '📋 Протоколы на сайте', 'url' => $SITE_URL . '/pricing']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];

    if (!$agreements) {
        botSend($chatId, "📋 <b>Протоколы</b>{$es}\n\nАктивных нет.", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $expiring = count(array_filter($agreements, fn($a) => $a['days_left'] <= 14 && $a['days_left'] > 0));
    $expired = count(array_filter($agreements, fn($a) => $a['days_left'] <= 0));
    $text = "📋 <b>Протоколы (ПСЦ)</b>{$es}\n";
    $sub = [];
    if ($expired) $sub[] = "🔴 {$expired} истёк";
    if ($expiring) $sub[] = "🟡 {$expiring} скоро";
    $text .= "<i>" . count($agreements) . " активных" . ($sub ? ' · ' . implode(' · ', $sub) : '') . "</i>\n";
    $text .= "─────────────────────\n";
    foreach ($agreements as $a) {
        $to = date('d.m.Y', strtotime($a['valid_to']));
        $days = $a['days_left'];
        $icon = $days <= 0 ? '🔴' : ($days <= 14 ? '🟡' : '🟢');
        $label = $days <= 0 ? 'истёк' : "{$days} дн.";
        $text .= "{$icon} <b>" . tgEsc($a['supplier']) . "</b>\n";
        $text .= "  №" . tgEsc($a['number']) . " · до {$to} · {$label}\n";
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Планы по поставщикам ═══

function cmdPlans($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);

    $sql = "SELECT p.supplier, p.period_type, p.period_count, p.start_date, p.note, p.created_by, p.updated_at,
                   p.consumption_period_days, p.input_unit
            FROM plans p WHERE 1=1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY p.updated_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $plans = $s->fetchAll();

    if (!$plans) {
        $es = $entity ? ' · ' . getEntityShort($entity) : '';
        $btns = [
            [['text' => '📅 Планирование', 'url' => $SITE_URL . '/planning']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📅 <b>Планы поставок</b>{$es}\n<i>Планов пока нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
    $text = "📅 <b>Планы поставок</b>{$es}\n";
    $text .= "─────────────────────\n";
    foreach ($plans as $p) {
        $period = ($p['period_count'] ?? 3) . ' ' . ($periodLabels[$p['period_type']] ?? $p['period_type']);
        $updated = $p['updated_at'] ? date('d.m', strtotime($p['updated_at'])) : '—';
        $text .= "📅 <b>" . tgEsc($p['supplier']) . "</b> · {$period}\n";
        if ($p['note']) $text .= "  " . tgEsc($p['note']) . "\n";
    }
    $btns = [
        [['text' => '📅 Планирование', 'url' => $SITE_URL . '/planning']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Ожидающие поставки ═══

function cmdDeliveries($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);

    // Заказы без приёмки (received_at IS NULL) и с датой доставки
    $sql = "SELECT o.id, o.supplier, o.delivery_date, o.created_by, o.created_at,
                   DATEDIFF(CURDATE(), o.delivery_date) as days_overdue,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes
            FROM orders o
            WHERE o.received_at IS NULL AND o.delivery_date IS NOT NULL";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.delivery_date ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) {
        $btns = [
            [['text' => '📦 Заказы на сайте', 'url' => $SITE_URL . '/orders']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "🚚 <b>Ожидающие поставки</b>\n<i>Все поставки приняты</i> ✅", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $overdueCnt = count(array_filter($orders, fn($o) => $o['days_overdue'] > 0));
    $todayCnt = count(array_filter($orders, fn($o) => $o['days_overdue'] == 0));

    $text = "🚚 <b>Ожидающие поставки</b>{$es}\n";
    $sub = [];
    if ($overdueCnt) $sub[] = "🔴 {$overdueCnt} просроч.";
    if ($todayCnt) $sub[] = "🟡 {$todayCnt} сегодня";
    $text .= "<i>" . count($orders) . " заказов" . ($sub ? ' · ' . implode(' · ', $sub) : '') . "</i>\n";
    $text .= "─────────────────────\n";

    $keyboard = [];
    foreach ($orders as $i => $o) {
        $delivery = date('d.m', strtotime($o['delivery_date']));
        $overdue = $o['days_overdue'];
        $icon = $overdue > 0 ? '🔴' : ($overdue == 0 ? '🟡' : '🟢');
        $label = $overdue > 0 ? "просроч. {$overdue}д" : ($overdue == 0 ? 'сегодня' : abs($overdue) . 'д');
        $num = $i + 1;
        $boxes = $o['total_boxes'] ?: 0;
        $text .= "{$icon} <b>" . tgEsc($o['supplier']) . "</b> · {$delivery} · {$label}\n";
        $text .= "  {$o['items_count']} поз. · {$boxes} кор.\n";
        $orderUrl = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
        $row = [['text' => "{$num}. " . tgEsc($o['supplier']), 'url' => $orderUrl]];
        if ($overdue >= 0) {
            $row[] = ['text' => '✅ Принято', 'callback_data' => 'receive_' . $o['id']];
        }
        $keyboard[] = $row;
    }
    $keyboard[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
    botSend($chatId, $text, ['inline_keyboard' => $keyboard], $editMsgId);
}

// ═══ Сегодня (мини-дашборд) ═══

function cmdToday($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $es = $entity ? ' · ' . getEntityShort($entity) : '';
    $params = $entity ? [$entity] : [];
    $eFilter = $entity ? " AND o.legal_entity = ?" : "";
    $eFilterA = $entity ? " AND a.legal_entity = ?" : "";

    $dayNames = [1=>'понедельник',2=>'вторник',3=>'среда',4=>'четверг',5=>'пятница',6=>'суббота',7=>'воскресенье'];
    $dayName = $dayNames[(int)date('N')] ?? '';
    $text = "📅 <b>Сегодня</b>, " . date('d.m.Y') . " · {$dayName}{$es}\n";
    $text .= "─────────────────────\n";

    // 1. Поставки сегодня
    $s = $pdo->prepare("SELECT o.id, o.supplier, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as items_count, (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as total_boxes FROM orders o WHERE o.delivery_date = CURDATE() AND o.received_at IS NULL" . $eFilter);
    $s->execute($params);
    $todayOrders = $s->fetchAll();

    // 2. Просроченные
    $s = $pdo->prepare("SELECT o.supplier, DATEDIFF(CURDATE(), o.delivery_date) as days FROM orders o WHERE o.delivery_date < CURDATE() AND o.received_at IS NULL" . $eFilter . " ORDER BY o.delivery_date LIMIT 5");
    $s->execute($params);
    $overdueOrders = $s->fetchAll();

    // 3. Критичные остатки (≤5 дней)
    $s = $pdo->prepare("SELECT p.name, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1 WHERE a.consumption > 0 AND a.stock > 0" . $eFilterA . " HAVING days_left <= 5 ORDER BY days_left ASC LIMIT 10");
    $s->execute($params);
    $critItems = $s->fetchAll();

    // Сводка
    $text .= "🚚 Поставки сегодня: <b>" . count($todayOrders) . "</b>\n";
    if ($overdueOrders) $text .= "🔴 Просроченных: <b>" . count($overdueOrders) . "</b>\n";
    $text .= "📉 Критичных остатков: <b>" . count($critItems) . "</b>\n";

    // Детали
    if ($todayOrders) {
        $text .= "─────────────────────\n";
        $text .= "<b>🚚 Сегодня ожидаются:</b>\n";
        foreach ($todayOrders as $o) {
            $text .= "  {$o['supplier']} · {$o['items_count']} поз. · " . ($o['total_boxes'] ?: 0) . " кор.\n";
        }
    }

    if ($overdueOrders) {
        $text .= "─────────────────────\n";
        $text .= "<b>🔴 Просрочены:</b>\n";
        foreach ($overdueOrders as $o) {
            $text .= "  {$o['supplier']} — {$o['days']} дн.\n";
        }
    }

    if ($critItems) {
        $text .= "─────────────────────\n";
        $text .= "<b>📉 Заканчиваются:</b>\n";
        foreach ($critItems as $c) {
            $icon = $c['days_left'] <= 0 ? '🔴' : '🟠';
            $name = mb_substr($c['name'] ?: '—', 0, 30);
            $text .= "  {$icon} {$name} · {$c['days_left']} дн.\n";
        }
    }

    $btns = [
        [['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries'], ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ Экспорт CSV ═══

function cmdExport($chatId, $user, $editMsgId = null) {
    $es = getUserEntity($user) ? ' · ' . getEntityShort(getUserEntity($user)) : '';
    $text = "📤 <b>Экспорт данных</b>{$es}\n\nВыберите, что выгрузить:";
    $btns = [
        [['text' => '📊 Анализ запасов', 'callback_data' => 'export_analysis']],
        [['text' => '📦 Заказы (30 дней)', 'callback_data' => 'export_orders']],
        [['text' => '💰 Прайс-лист', 'callback_data' => 'export_prices']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

function generateAnalysisCsv($entity) {
    global $pdo;
    $sql = "SELECT p.sku, p.name, p.supplier, p.category, COALESCE(p.unit_of_measure,'шт') as uom, a.stock, a.consumption, a.period_days, CASE WHEN a.consumption > 0 THEN ROUND(a.stock / (a.consumption / GREATEST(a.period_days,1))) ELSE 999 END as days_left FROM products p INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci WHERE p.is_active = 1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY days_left ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $csv = "\xEF\xBB\xBFАртикул;Название;Поставщик;Категория;Ед.изм.;Остаток;Расход;Дней данных;Дней запаса\n";
    foreach ($rows as $r) {
        $csv .= "{$r['sku']};{$r['name']};{$r['supplier']};{$r['category']};{$r['uom']};{$r['stock']};{$r['consumption']};{$r['period_days']};{$r['days_left']}\n";
    }
    return $csv;
}

function generateOrdersCsv($entity) {
    global $pdo;
    $sql = "SELECT o.supplier, o.created_by, o.created_at, o.delivery_date, o.received_at, oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY o.created_at DESC, oi.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $csv = "\xEF\xBB\xBFПоставщик;Дата заказа;Дата поставки;Принято;Автор;Артикул;Название;Коробки;Штук\n";
    foreach ($rows as $r) {
        $created = date('d.m.Y', strtotime($r['created_at']));
        $delivery = $r['delivery_date'] ? date('d.m.Y', strtotime($r['delivery_date'])) : '';
        $received = $r['received_at'] ? date('d.m.Y', strtotime($r['received_at'])) : '';
        $pcs = $r['qty_boxes'] * max($r['qty_per_box'], 1);
        $csv .= "{$r['supplier']};{$created};{$delivery};{$received};{$r['created_by']};{$r['sku']};{$r['name']};{$r['qty_boxes']};{$pcs}\n";
    }
    return $csv;
}

function generatePricesCsv($entity) {
    global $pdo;
    $sql = "SELECT pp.sku, p.name, pp.supplier, pp.price, pp.vat_rate, pp.currency, pp.unit_type FROM product_prices pp LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity = pp.legal_entity AND p.is_active = 1 WHERE pp.price_type = 'purchase'";
    $params = [];
    if ($entity) { $sql .= " AND pp.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY pp.supplier, p.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $rows = $s->fetchAll();
    $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
    $csv = "\xEF\xBB\xBFАртикул;Название;Поставщик;Цена без НДС;НДС%;Цена с НДС;Валюта;Ед.изм.\n";
    foreach ($rows as $r) {
        $vat = $r['vat_rate'] ?? 20;
        $priceVat = round($r['price'] * (1 + $vat / 100), 2);
        $unit = $unitLabels[$r['unit_type']] ?? $r['unit_type'];
        $csv .= "{$r['sku']};{$r['name']};{$r['supplier']};{$r['price']};{$vat};{$priceVat};{$r['currency']};{$unit}\n";
    }
    return $csv;
}

// ═══ Реализация ресторанов ═══

function cmdSales($chatId, $user, $editMsgId = null) {
    global $pdo, $SITE_URL;

    // Определяем последнюю дату данных
    $s = $pdo->query("SELECT MAX(sale_date) as last_date, MIN(sale_date) as first_date, COUNT(DISTINCT sale_date) as total_days FROM restaurant_sales");
    $meta = $s->fetch();
    if (!$meta || !$meta['last_date']) {
        $btns = [
            [['text' => '🏪 Реализация на сайте', 'url' => $SITE_URL . '/analysis']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "🏪 <b>Реализация ресторанов</b>\n<i>Данных пока нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }
    $lastDate = $meta['last_date'];

    // Топ-15 по объёму реализации за 30 дней
    $cutoff = date('Y-m-d', strtotime($lastDate . ' -29 days'));
    $s = $pdo->prepare("
        SELECT analog_group,
               ROUND(SUM(quantity)) as total,
               ROUND(SUM(quantity) / COUNT(DISTINCT sale_date)) as avg_day,
               ROUND(AVG(restaurant_count)) as avg_rc,
               COUNT(DISTINCT sale_date) as days_cnt,
               MAX(sale_date) as last_sale
        FROM restaurant_sales
        WHERE sale_date >= ?
        GROUP BY analog_group
        HAVING SUM(quantity) > 0
        ORDER BY total DESC
        LIMIT 15
    ");
    $s->execute([$cutoff]);
    $top = $s->fetchAll();

    // Тренд: сравним последние 14 дней с предыдущими 14
    $cut14 = date('Y-m-d', strtotime($lastDate . ' -13 days'));
    $cut28 = date('Y-m-d', strtotime($lastDate . ' -27 days'));
    $s = $pdo->prepare("
        SELECT analog_group,
               SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) as cur,
               SUM(CASE WHEN sale_date >= ? AND sale_date < ? THEN quantity ELSE 0 END) as prev
        FROM restaurant_sales
        WHERE sale_date >= ?
        GROUP BY analog_group
        HAVING cur > 0 AND prev > 0
    ");
    $s->execute([$cut14, $cut28, $cut14, $cut28]);
    $trends = [];
    while ($r = $s->fetch()) {
        $trends[$r['analog_group']] = $r['prev'] > 0 ? round(($r['cur'] - $r['prev']) / $r['prev'] * 100) : 0;
    }

    // Топ роста и падения
    arsort($trends);
    $topGrow = array_slice($trends, 0, 5, true);
    asort($trends);
    $topDrop = array_slice($trends, 0, 5, true);

    $lastFmt = date('d.m', strtotime($lastDate));
    $cutFmt = date('d.m', strtotime($cutoff));

    $totalAll = array_sum(array_column($top, 'total'));
    $text = "🏪 <b>Реализация ресторанов</b>\n";
    $text .= "<i>{$cutFmt} – {$lastFmt} · " . number_format($totalAll, 0, '.', ' ') . " ед. (топ-15)</i>\n";
    $text .= "─────────────────────\n";

    foreach ($top as $i => $r) {
        $trend = isset($trends[$r['analog_group']]) ? $trends[$r['analog_group']] : null;
        $trendIcon = '';
        if ($trend !== null && abs($trend) > 5) {
            $trendIcon = $trend > 0 ? " ↑{$trend}%" : " ↓" . abs($trend) . '%';
        }
        $num = $i + 1;
        $total = number_format($r['total'], 0, '.', ' ');
        $text .= "<b>{$num}.</b> " . tgEsc($r['analog_group']) . "\n";
        $text .= "  <b>{$total}</b> · {$r['avg_day']}/день · {$r['avg_rc']} рест.{$trendIcon}\n";
    }

    if ($topGrow || $topDrop) {
        $text .= "─────────────────────\n";
    }
    if ($topGrow) {
        $growParts = [];
        foreach ($topGrow as $name => $t) { $growParts[] = mb_substr($name, 0, 18) . " +{$t}%"; }
        $text .= "📈 " . implode(' · ', $growParts) . "\n";
    }
    if ($topDrop) {
        $dropParts = [];
        foreach ($topDrop as $name => $t) { $dropParts[] = mb_substr($name, 0, 18) . " {$t}%"; }
        $text .= "📉 " . implode(' · ', $dropParts) . "\n";
    }

    $btns = [
        [['text' => '🏪 Подробный отчёт', 'url' => $SITE_URL . '/analysis']],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// ═══ График доставок ═══

function cmdCards($chatId, $user = null, $editMsgId = null) {
    global $pdo;

    // Включаем режим поиска карточек
    if ($user) {
        setUserMode($user['name'], 'cards');
    }
    // Для всех (и ресторанов без аккаунта) — temp-файл (msg_id сохраним после отправки)
    tgStateSet($chatId, 'cards', ['bot_msg_id' => $editMsgId ?: 0]);

    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM cards");
    $s->execute();
    $total = $s->fetch()['cnt'];

    $s = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'last_update' LIMIT 1");
    $s->execute();
    $lastUpdate = $s->fetch()['value'] ?? '—';

    $text = "🔍 <b>Поиск карточек</b>\n";
    $text .= "<i>{$total} карточек · обн. {$lastUpdate}</i>\n";
    $text .= "─────────────────────\n";
    $text .= "Отправьте <b>артикул</b> или <b>название</b> товара.\n";
    $text .= "Бот найдёт карточку и покажет аналоги.";

    $btns = [
        [['text' => '❌ Выход из поиска', 'callback_data' => 'cmd_cards_exit']],
    ];
    if ($user) {
        $btns[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
    } else {
        $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']];
    }
    botSend($chatId, $text, ['inline_keyboard' => $btns], $editMsgId);
}

// Стемминг русских слов — обрезка окончаний до основы
function ruStem($word) {
    $word = mb_strtolower($word);
    $word = str_replace('ё', 'е', $word);
    $len = mb_strlen($word);
    if ($len <= 3) return $word;
    // Длинные окончания (3-4 буквы)
    $suffixes4 = ['ками','ений','ения','ться','ного','ному','ными','ного','нном','ться','шить','ения','ский','ская','ское','ские'];
    $suffixes3 = ['ами','ями','ому','ому','ных','ным','ной','ное','ные','ний','ого','ому','ить','ать','ять','ует','ает','ции','тся','ика','ику','ики','ике','ист','ные'];
    $suffixes2 = ['ов','ев','ей','ий','ый','ая','ое','ые','ой','ом','ам','ям','ах','ях','ми','ие','ия','ки','ка','ку','ке','ок','ек','он','ин','ть','ся','ны','на','но'];
    $suffixes1 = ['а','о','у','е','ы','и','ь','й','я'];
    foreach ($suffixes4 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes3 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes2 as $s) {
        $sl = mb_strlen($s);
        if ($len > $sl + 2 && mb_substr($word, -$sl) === $s) return mb_substr($word, 0, -$sl);
    }
    foreach ($suffixes1 as $s) {
        if ($len > 3 && mb_substr($word, -1) === $s) return mb_substr($word, 0, -1);
    }
    return $word;
}

// Словарь синонимов — расширяет запрос близкими словами
function expandSynonyms($words) {
    static $synonyms = [
        'картошка' => ['картофель','картошк','фри'],
        'картофель' => ['картошка','картошк','фри'],
        'помидор' => ['томат','томатн'],
        'томат' => ['помидор','помидорн'],
        'огурец' => ['огурч','корнишон'],
        'корнишон' => ['огурец','огурч'],
        'лук' => ['луков','репчат'],
        'курица' => ['куриц','курин','цыпл','наггетс','чикен'],
        'куриный' => ['курин','курица','цыпл','чикен'],
        'чикен' => ['курица','курин','куриц'],
        'наггетс' => ['наггетсы','курица','куриц'],
        'говядина' => ['говяж','говядин','ангус','бургер'],
        'говяжий' => ['говядин','говяж','ангус'],
        'свинина' => ['свинин','свиной','свин'],
        'булка' => ['булочк','булоч','хлеб'],
        'булочка' => ['булочк','булоч','булка','хлеб'],
        'хлеб' => ['булочк','булоч','булка'],
        'стакан' => ['стаканч','стаканов','стакан'],
        'стаканчик' => ['стакан','стаканч','стаканов'],
        'крышка' => ['крышеч','крышк'],
        'сок' => ['напиток','напит'],
        'напиток' => ['напит','сок','вода','газ'],
        'кола' => ['кока','пепси','газ','напит'],
        'вода' => ['питьев','аура','газ'],
        'молоко' => ['молоч','молок'],
        'сыр' => ['сырн','чиз','чеддер'],
        'чиз' => ['сыр','сырн','чеддер'],
        'салат' => ['салатн','зелен','латук','айсберг'],
        'кетчуп' => ['кетчуп','томатн','соус'],
        'майонез' => ['майонезн','соус'],
        'соус' => ['заправк','дип'],
        'дип' => ['соус','заправк'],
        'мороженое' => ['моро','пломбир','сандей','айс'],
        'кофе' => ['кофейн','капучин','латте','американо'],
        'капучино' => ['кофе','кофейн'],
        'масло' => ['масл','фритюр'],
        'фритюр' => ['масло','масл'],
        'коробка' => ['короб','упаков','тар'],
        'упаковка' => ['упаков','короб','коробк','тар'],
        'пакет' => ['пакетик','мешок','тар'],
        'салфетка' => ['салфет','бумаг'],
    ];
    $expanded = $words;
    foreach ($words as $w) {
        $wLower = mb_strtolower($w);
        if (isset($synonyms[$wLower])) {
            foreach ($synonyms[$wLower] as $syn) {
                $expanded[] = $syn;
            }
        }
    }
    return array_unique($expanded);
}

// Разбить текст на стемы
function stemWords($text) {
    $text = mb_strtolower($text);
    $text = str_replace('ё', 'е', $text);
    $words = preg_split('/[^а-яa-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stems = [];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 2) $stems[] = ruStem($w);
    }
    return $stems;
}

// Отправить сообщение с заменой предыдущего (антиспам)
function cardsSendReplace($chatId, $userMsgId, $botMsgId, $text, $keyboard = null) {
    global $BOT_TOKEN;
    if ($userMsgId) @deleteMessage($chatId, $userMsgId);
    if ($botMsgId) @deleteMessage($chatId, $botMsgId);
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $payload['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
    $resp = json_decode(curl_exec($ch), true); curl_close($ch);
    $newMsgId = $resp['result']['message_id'] ?? null;
    // Сохраняем новый msg_id для следующего поиска
    if ($newMsgId) tgStateSet($chatId, 'cards', ['bot_msg_id' => (int)$newMsgId]);
    return $newMsgId;
}

// Поиск карточки — прямой ответ без ИИ
function searchCardDirect($chatId, $query, $userMsgId = null, $botMsgId = null) {
    global $pdo;

    $normalize = function($s) {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/[^а-яa-z0-9]/u', '', $s);
    };

    $queryRaw = trim($query);
    if (mb_strlen($queryRaw) < 2) {
        cardsSendReplace($chatId, $userMsgId, $botMsgId, "Введите минимум 2 символа.", ['inline_keyboard' => [[['text' => '❌ Выход', 'callback_data' => 'cmd_cards_exit']]]]);
        return;
    }

    $s = $pdo->prepare("SELECT id, name, analogs FROM cards ORDER BY name");
    $s->execute();
    $allCards = $s->fetchAll();

    $q = $normalize($queryRaw);
    $results = [];

    // 1. Поиск по артикулу (точный и частичный)
    $articleMatch = null;
    if (preg_match('/\d{4,}(?:-\d+)?/', $queryRaw, $am)) {
        $articleMatch = $am[0];
    }

    if ($articleMatch) {
        foreach ($allCards as $c) {
            if ($c['id'] === $articleMatch) {
                $results[] = ['card' => $c, 'reason' => 'найдено по артикулу'];
                break;
            }
        }
        if (empty($results)) {
            foreach ($allCards as $c) {
                $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
                if (!is_array($analogs)) $analogs = [];
                if (in_array($articleMatch, $analogs)) {
                    $results[] = ['card' => $c, 'reason' => "артикул {$articleMatch} — аналог этого товара"];
                }
            }
        }
        if (empty($results)) {
            foreach ($allCards as $c) {
                if ($c['id'] && strpos($c['id'], $articleMatch) !== false) {
                    $results[] = ['card' => $c, 'reason' => 'часть артикула'];
                    if (count($results) >= 10) break;
                }
            }
        }
    }

    // 2. Текстовый поиск — сначала точное вхождение, потом по стемам (морфология)
    if (empty($results)) {
        // 2а. Точное вхождение подстроки (как раньше)
        foreach ($allCards as $c) {
            $normName = $normalize($c['name']);
            $normFull = $normalize($c['id'] ?? '') . $normName;
            if ($normFull && mb_strpos($normFull, $q) !== false) {
                $results[] = ['card' => $c, 'reason' => 'найдено по названию'];
                if (count($results) >= 10) break;
            }
        }
    }

    // 2б. Поиск по основам слов (стемминг) — каждое слово запроса ищем в названии
    if (empty($results)) {
        // Разбиваем запрос на слова, добавляем синонимы, затем стемим
        $rawWords = preg_split('/[^а-яёa-z0-9]+/iu', mb_strtolower($queryRaw), -1, PREG_SPLIT_NO_EMPTY);
        $expandedWords = expandSynonyms($rawWords);
        $queryStems = array_unique(array_map('ruStem', array_filter($expandedWords, fn($w) => mb_strlen($w) >= 2)));
        $queryWordCount = count($rawWords); // оригинальное число слов для scoring

        if (!empty($queryStems)) {
            $scored = [];
            foreach ($allCards as $c) {
                $nameStems = stemWords($c['name']);
                if (empty($nameStems)) continue;
                // Считаем сколько стемов из запроса (с синонимами) нашлись в названии
                $matched = 0;
                foreach ($queryStems as $qs) {
                    foreach ($nameStems as $ns) {
                        if (mb_strpos($ns, $qs) !== false || mb_strpos($qs, $ns) !== false) {
                            $matched++;
                            break;
                        }
                    }
                }
                if ($matched > 0) {
                    $scored[] = ['card' => $c, 'score' => $matched / count($queryStems), 'matched' => $matched];
                }
            }
            // Сортируем по доле совпавших слов (больше = лучше)
            usort($scored, function($a, $b) { return $b['score'] <=> $a['score'] ?: $b['matched'] <=> $a['matched']; });
            $added = [];
            foreach ($scored as $s) {
                if (isset($added[$s['card']['id']])) continue;
                $results[] = ['card' => $s['card'], 'reason' => 'найдено по названию'];
                $added[$s['card']['id']] = true;
                if (count($results) >= 10) break;
            }
        }
    }

    $exitBtn = [['text' => '❌ Выход', 'callback_data' => 'cmd_cards_exit']];

    if (empty($results)) {
        // Попробуем найти похожие по отдельным словам
        $suggestions = [];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($queryRaw));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) < 3) continue;
            $nw = $normalize($w);
            foreach ($allCards as $c) {
                if (mb_strpos($normalize($c['name']), $nw) !== false) {
                    $suggestions[$c['id']] = $c;
                    if (count($suggestions) >= 5) break 2;
                }
            }
        }

        $msg = "❌ По запросу «<b>" . tgEsc($queryRaw) . "</b>» карточек не найдено.\n\n";
        if (!empty($suggestions)) {
            $msg .= "Возможно, вы имели в виду:\n";
            foreach ($suggestions as $c) {
                $msg .= "• <b>" . tgEsc($c['id']) . "</b> " . tgEsc($c['name']) . "\n";
            }
            $msg .= "\nОтправьте артикул для точного поиска.";
        } else {
            $msg .= "Попробуйте:\n• Другой артикул или название\n• Часть названия (например, «стакан» вместо «стаканчик»)\n• Числовой артикул";
        }
        cardsSendReplace($chatId, $userMsgId, $botMsgId, $msg, ['inline_keyboard' => [$exitBtn]]);
        return;
    }

    // Собираем все артикулы (основные + аналоги) для проверки остатков
    $allSkus = [];
    foreach ($results as $r) {
        $c = $r['card'];
        $allSkus[] = $c['id'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (is_array($analogs)) {
            foreach ($analogs as $a) $allSkus[] = $a;
        }
    }
    $allSkus = array_unique(array_filter($allSkus));

    // Проверяем какие артикулы есть на остатках (analysis_data, юрлицо пользователя бота).
    // Если пользователь не привязан (анонимный режим карточек) — по умолчанию БК.
    $stockEntity = 'ООО "Бургер БК"';
    $cardUser = getUser($chatId);
    if ($cardUser) {
        $ue = getUserEntity($cardUser);
        if ($ue) $stockEntity = $ue;
    }
    $inStock = [];
    if ($allSkus) {
        $placeholders = implode(',', array_fill(0, count($allSkus), '?'));
        $params = array_values($allSkus);
        $params[] = $stockEntity;
        $st = $pdo->prepare("SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1 WHERE a.sku IN ({$placeholders}) AND a.legal_entity = ? AND a.stock > 0");
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $qpb = floatval($row['qty_per_box']) ?: 1;
            $inStock[$row['sku']] = ['name' => $row['name'], 'stock' => round(floatval($row['stock']) / $qpb, 1)];
        }
    }

    $text = "🔍 По запросу «<b>" . tgEsc($queryRaw) . "</b>»:\n\n";
    foreach ($results as $i => $r) {
        $c = $r['card'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (!is_array($analogs)) $analogs = [];

        // Ищем какой артикул из группы на остатках (основной + аналоги)
        $stockSku = null;
        $groupSkus = array_merge([$c['id']], $analogs);
        foreach ($groupSkus as $sku) {
            if (isset($inStock[$sku])) {
                $stockSku = $sku;
                break;
            }
        }

        $text .= "<code>{$c['id']} {$c['name']}</code>\n";
        if ($stockSku) {
            $stockQty = rtrim(rtrim(number_format($inStock[$stockSku]['stock'], 1, '.', ''), '0'), '.');
            if ($stockSku === $c['id']) {
                $text .= "  📦 <i>на остатках ({$stockQty} кор.)</i>\n";
            } else {
                $stockName = $inStock[$stockSku]['name'];
                $label = $stockName ? "{$stockSku} {$stockName}" : $stockSku;
                $text .= "  📦 <i>на остатках ({$stockQty} кор.): </i><code>{$label}</code>\n";
            }
        }
    }

    $text .= "\n<i>Нажмите на строку, чтобы скопировать</i>";

    // Обрезка по лимиту Telegram
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…";
    }

    cardsSendReplace($chatId, $userMsgId, $botMsgId, $text, ['inline_keyboard' => [$exitBtn]]);
}

function cmdSchedule($chatId, $user, $editMsgId = null, $dayNum = null) {
    global $pdo, $SITE_URL;

    $dayNames = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
    $dayShort = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $today = (int) date('N'); // 1=Пн, 7=Вс

    $entity = getUserEntity($user);
    // Определяем группу юрлица для фильтра
    $group = 'BK_VM';
    if ($entity && strpos($entity, 'Пицца') !== false) $group = 'PS';

    $sql = "SELECT r.number, r.address, r.city, ds.day_of_week, ds.delivery_time
            FROM delivery_schedule ds
            JOIN restaurants r ON r.id = ds.restaurant_id AND r.active = 1
            WHERE ds.delivery_time IS NOT NULL AND ds.delivery_time != ''
              AND r.legal_entity_group = ?
            ORDER BY ds.day_of_week, ds.delivery_time, r.number";
    $s = $pdo->prepare($sql); $s->execute([$group]);
    $all = $s->fetchAll();

    $backBtn = ['text' => '◂ Меню', 'callback_data' => 'cmd_menu'];

    if (!$all) {
        $btns = [
            [['text' => '🗓 График на сайте', 'url' => $SITE_URL . '/schedule']],
            [$backBtn],
        ];
        botSend($chatId, "🗓 <b>График доставок</b>\n<i>График пуст</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    // Группируем по дням
    $byDay = [];
    foreach ($all as $row) {
        $byDay[$row['day_of_week']][] = $row;
    }
    $totalDeliveries = count($all);

    if ($dayNum === null) {
        $es = $entity ? ' · ' . getEntityShort($entity) : '';
        $text = "🗓 <b>График доставок</b>{$es}\n";
        $text .= "<i>{$totalDeliveries} доставок в неделю</i>\n";
        $text .= "─────────────────────\n";

        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $isToday = ($d === $today);
            $mark = $isToday ? '📍 ' : '';
            $todayLabel = $isToday ? ' <i>(сегодня)</i>' : '';
            $text .= "{$mark}<b>{$dayNames[$d]}</b>{$todayLabel} — {$cnt}\n";
        }

        // Кнопки по дням
        $row1 = []; $row2 = [];
        for ($d = 1; $d <= 6; $d++) {
            $cnt = count($byDay[$d] ?? []);
            $mark = ($d === $today) ? '📍' : '';
            $btn = ['text' => "{$mark}{$dayShort[$d]} ({$cnt})", 'callback_data' => "sched_{$d}"];
            if ($d <= 3) $row1[] = $btn; else $row2[] = $btn;
        }
        $buttons = [$row1, $row2];
        $buttons[] = [['text' => '🗓 График на сайте', 'url' => $SITE_URL . '/schedule']];
        $buttons[] = [$backBtn];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    } else {
        // Детальный список на конкретный день
        $isToday = ($dayNum === $today);
        $todayLabel = $isToday ? ' <i>(сегодня)</i>' : '';
        $items = $byDay[$dayNum] ?? [];
        $cnt = count($items);
        $text = "🗓 <b>{$dayNames[$dayNum]}</b>{$todayLabel} · {$cnt} доставок\n";
        $text .= "─────────────────────\n";

        if ($items) {
            foreach ($items as $d) {
                $city = ($d['city'] && $d['city'] !== 'Минск') ? " ({$d['city']})" : '';
                $text .= "<b>#{$d['number']}</b> {$d['delivery_time']} — {$d['address']}{$city}\n";
            }
        } else {
            $text .= "<i>Нет доставок</i>\n";
        }

        // Кнопки навигации между днями
        $prevDay = $dayNum > 1 ? $dayNum - 1 : 6;
        $nextDay = $dayNum < 6 ? $dayNum + 1 : 1;
        $navRow = [
            ['text' => "← {$dayShort[$prevDay]}", 'callback_data' => "sched_{$prevDay}"],
            ['text' => '📋 Все дни', 'callback_data' => 'cmd_schedule'],
            ['text' => "{$dayShort[$nextDay]} →", 'callback_data' => "sched_{$nextDay}"],
        ];
        $buttons = [$navRow, [$backBtn]];
        botSend($chatId, $text, ['inline_keyboard' => $buttons], $editMsgId);
    }
}

// ═══ Полный анализ запасов (как на сайте) ═══

function cmdAnalysis($chatId, $user, $zone = null, $page = 0, $editMsgId = null) {
    global $pdo, $SITE_URL;
    $entity = getUserEntity($user);
    $entityShort = $entity ? getEntityShort($entity) : '';

    // Загружаем все товары с данными анализа, группируя по аналоговой группе
    $sql = "SELECT p.sku, p.name, p.analog_group, p.supplier, p.qty_per_box, p.category,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   a.stock, a.consumption, a.period_days
            FROM products p
            INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
            WHERE p.is_active = 1";
    $params = [];
    if ($entity) { $sql .= " AND p.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY p.analog_group, p.name";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) {
        $es = $entity ? ' · ' . $entityShort : '';
        $btns = [
            [['text' => '📊 Анализ на сайте', 'url' => $SITE_URL . '/analysis']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        botSend($chatId, "📊 <b>Анализ запасов</b>{$es}\n<i>Данных нет</i>", ['inline_keyboard' => $btns], $editMsgId);
        return;
    }

    // Группируем по аналоговой группе
    $groups = [];
    foreach ($items as $item) {
        $groupName = $item['analog_group'] ?: $item['sku'] . ' ' . $item['name'];
        if (!isset($groups[$groupName])) {
            $u = $item['uom'] ?? 'шт';
            $groups[$groupName] = [
                'name' => $groupName,
                'items' => [],
                'totalStock' => 0,
                'totalConsumption' => 0,
                'periodDays' => 30,
                'supplier' => $item['supplier'],
                'category' => $item['category'] ?: '',
                'uomLabel' => getUomLabel($u),
            ];
        }
        $groups[$groupName]['items'][] = $item;
        $groups[$groupName]['totalStock'] += $item['stock'];
        $groups[$groupName]['totalConsumption'] += $item['consumption'];
        if ($item['period_days'] > 0) {
            $groups[$groupName]['periodDays'] = $item['period_days'];
        }
    }

    // Рассчитываем дни для каждой группы и определяем зону
    $zoneGroups = ['red' => [], 'orange' => [], 'green' => [], 'purple' => []];
    foreach ($groups as &$g) {
        $stock = $g['totalStock'];
        $consumption = $g['totalConsumption'];
        $periodDays = max($g['periodDays'], 1);

        if ($consumption <= 0) {
            $g['days'] = $stock > 0 ? 999 : 0;
        } else {
            $dailyRate = $consumption / $periodDays;
            $g['days'] = round($stock / $dailyRate);
        }

        if ($g['days'] <= 5) {
            $g['zone'] = 'red';
        } elseif ($g['days'] <= 10) {
            $g['zone'] = 'orange';
        } elseif ($g['days'] <= 30) {
            $g['zone'] = 'green';
        } else {
            $g['zone'] = 'purple';
        }

        $zoneGroups[$g['zone']][] = $g;
    }
    unset($g);

    // Сортируем каждую зону по дням
    foreach ($zoneGroups as &$zg) {
        usort($zg, fn($a, $b) => $a['days'] - $b['days']);
    }
    unset($zg);

    $zoneCounts = [
        'red' => count($zoneGroups['red']),
        'orange' => count($zoneGroups['orange']),
        'green' => count($zoneGroups['green']),
        'purple' => count($zoneGroups['purple']),
    ];
    $total = array_sum($zoneCounts);

    // Если зона не указана — показываем сводку
    if (!$zone) {
        $es = $entity ? ' · ' . $entityShort : '';
        $text = "📊 <b>Анализ запасов</b>{$es}\n";
        $text .= "<i>{$total} групп товаров</i>\n";
        $text .= "─────────────────────\n";

        $text .= "🔴 Критично (≤5 дн.) — <b>{$zoneCounts['red']}</b>\n";
        $text .= "🟠 Внимание (6–10 дн.) — <b>{$zoneCounts['orange']}</b>\n";
        $text .= "🟢 Норма (11–30 дн.) — <b>{$zoneCounts['green']}</b>\n";
        $text .= "🟣 Излишки (30+ дн.) — <b>{$zoneCounts['purple']}</b>\n";

        // Показываем критичные, если есть
        if ($zoneCounts['red'] > 0) {
            $text .= "─────────────────────\n";
            $text .= "<b>⚠️ Критичные:</b>\n";
            foreach (array_slice($zoneGroups['red'], 0, 10) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🔴 <b>" . tgEsc($g['name']) . "</b> · {$g['days']}д. · {$g['totalStock']} ост. · {$daily}/д\n";
            }
            if ($zoneCounts['red'] > 10) {
                $text .= "<i>… +" . ($zoneCounts['red'] - 10) . " ещё</i>\n";
            }
        }

        // Показываем оранжевые
        if ($zoneCounts['orange'] > 0 && $zoneCounts['red'] == 0) {
            $text .= "─────────────────────\n";
            $text .= "<b>🟠 Внимание:</b>\n";
            foreach (array_slice($zoneGroups['orange'], 0, 8) as $g) {
                $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
                $text .= "🟠 <b>" . tgEsc($g['name']) . "</b> · {$g['days']}д. · {$g['totalStock']} ост. · {$daily}/д\n";
            }
            if ($zoneCounts['orange'] > 8) {
                $text .= "<i>… +" . ($zoneCounts['orange'] - 8) . " ещё</i>\n";
            }
        }

        // Кнопки для просмотра каждой зоны
        $buttons = [];
        if ($zoneCounts['red'] > 0) $buttons[] = ['text' => "🔴 Критичные ({$zoneCounts['red']})", 'callback_data' => 'analysis_red_0'];
        if ($zoneCounts['orange'] > 0) $buttons[] = ['text' => "🟠 Внимание ({$zoneCounts['orange']})", 'callback_data' => 'analysis_orange_0'];
        if ($zoneCounts['green'] > 0) $buttons[] = ['text' => "🟢 Норма ({$zoneCounts['green']})", 'callback_data' => 'analysis_green_0'];
        if ($zoneCounts['purple'] > 0) $buttons[] = ['text' => "🟣 Излишки ({$zoneCounts['purple']})", 'callback_data' => 'analysis_purple_0'];

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => '📊 Анализ на сайте', 'url' => $SITE_URL . '/analysis']];
        $rows[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
        $keyboard = ['inline_keyboard' => $rows];
        if ($editMsgId) {
            editMessage($chatId, $editMsgId, $text, $keyboard);
        } else {
            sendMessage($chatId, $text, $keyboard);
        }
        return;
    }

    // Показ конкретной зоны
    $zoneNames = ['red' => '🔴 Критично (0–5 дн.)', 'orange' => '🟠 Внимание (6–10 дн.)', 'green' => '🟢 Норма (11–30 дн.)', 'purple' => '🟣 Излишки (30+ дн.)'];
    $items = $zoneGroups[$zone] ?? [];
    $perPage = 15;
    $offset = $page * $perPage;
    $pageItems = array_slice($items, $offset, $perPage);
    $totalPages = ceil(count($items) / $perPage);

    if (!$pageItems) {
        sendMessage($chatId, "В этой зоне нет товаров.");
        return;
    }

    $es = $entity ? ' · ' . $entityShort : '';
    $text = "📊 <b>{$zoneNames[$zone]}</b>{$es}\n";
    $text .= "<i>" . count($items) . " групп";
    if ($totalPages > 1) $text .= " · стр. " . ($page + 1) . "/" . $totalPages;
    $text .= "</i>\n";
    $text .= "─────────────────────\n";

    foreach ($pageItems as $g) {
        $daily = $g['totalConsumption'] > 0 ? round($g['totalConsumption'] / max($g['periodDays'], 1), 1) : 0;
        $daysStr = $g['days'] >= 999 ? '∞' : $g['days'];
        $text .= "<b>" . tgEsc($g['name']) . "</b> · {$daysStr}д. · {$g['totalStock']} ост. · {$daily}/д\n";

        // Показываем состав группы, если > 1 товара
        if (count($g['items']) > 1) {
            foreach ($g['items'] as $item) {
                $iDaily = $item['period_days'] > 0 ? round($item['consumption'] / $item['period_days'], 1) : 0;
                $text .= "  · {$item['sku']}: {$item['stock']}, {$iDaily}/д\n";
            }
        }
    }

    // Навигация
    $navButtons = [];
    if ($page > 0) $navButtons[] = ['text' => '← Назад', 'callback_data' => "analysis_{$zone}_" . ($page - 1)];
    if ($page + 1 < $totalPages) $navButtons[] = ['text' => 'Далее →', 'callback_data' => "analysis_{$zone}_" . ($page + 1)];
    $navButtons[] = ['text' => '📊 Сводка', 'callback_data' => 'analysis_summary'];

    $keyboard = ['inline_keyboard' => [$navButtons, [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]];
    if ($editMsgId) {
        editMessage($chatId, $editMsgId, $text, $keyboard);
    } else {
        sendMessage($chatId, $text, $keyboard);
    }
}

// ═══ AI, lookup и helper функции вынесены в includes/ ═══

// ═══ Settings UI ═══

function getMenuButtons($user) {
    $entity = $user ? getUserEntity($user) : null;
    $buttons = [
        // Главное
        [['text' => '📅 Сегодня', 'callback_data' => 'cmd_today']],
        // Заказы и поставки
        [
            ['text' => '📦 Заказы', 'callback_data' => 'cmd_orders'],
            ['text' => '🚚 Поставки', 'callback_data' => 'cmd_deliveries'],
            ['text' => '📅 Планы', 'callback_data' => 'cmd_plans'],
        ],
        // Аналитика
        [
            ['text' => '📉 Остатки', 'callback_data' => 'cmd_stock'],
            ['text' => '📊 Расход', 'callback_data' => 'cmd_consumption'],
            ['text' => '📊 Анализ', 'callback_data' => 'cmd_analysis'],
        ],
        // Цены и реализация
        [
            ['text' => '💰 Цены', 'callback_data' => 'cmd_prices'],
            ['text' => '📋 ПСЦ', 'callback_data' => 'cmd_psc'],
            ['text' => '🏪 Продажи', 'callback_data' => 'cmd_sales'],
        ],
        // Инструменты
        [
            ['text' => '🔍 Карточки', 'web_app' => ['url' => 'https://supply-department.online/search-cards']],
            ['text' => '🗓 График', 'callback_data' => 'cmd_schedule'],
            ['text' => '📤 Экспорт', 'callback_data' => 'cmd_export'],
        ],
        // Рестораны
        [
            ['text' => '✏️ Корректировки', 'callback_data' => 'cmd_corrections'],
            ['text' => '🏪 Рестораны', 'callback_data' => 'cmd_rest_subs_stats'],
        ],
        [
            ['text' => '🛒 Заказы рестов', 'callback_data' => 'cmd_ro_status'],
            ['text' => '📨 Рассылка логинов', 'callback_data' => 'cmd_ro_send_logins'],
        ],
        // Файл заказа + ресторанное меню
        [
            ['text' => '📄 Файл заказа', 'callback_data' => 'cmd_upload_order_file'],
            ['text' => '🏪 Меню ресторана', 'callback_data' => 'start_role_restaurant'],
        ],
    ];
    if ($user && count($user['legal_entities']) > 1) {
        $short = $entity ? getEntityShort($entity) : '?';
        $buttons[] = [['text' => "🏢 {$short}", 'callback_data' => 'cmd_entity'], ['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']];
    } else {
        $buttons[] = [['text' => '⚙️ Настройки', 'callback_data' => 'cmd_settings']];
    }
    return $buttons;
}

function getMenuText($user) {
    $entity = $user ? getUserEntity($user) : null;
    $short = $entity ? getEntityShort($entity) : '';
    $today = date('d.m.Y');
    $dayNames = [1=>'понедельник',2=>'вторник',3=>'среда',4=>'четверг',5=>'пятница',6=>'суббота',7=>'воскресенье'];
    $dayName = $dayNames[(int)date('N')] ?? '';

    $lines = ["🍔 <b>Supply Department</b>"];
    $lines[] = "━━━━━━━━━━━━━━━━━━━━";
    if ($entity) $lines[] = "🏢 {$short} · {$today}, {$dayName}";
    else $lines[] = "📅 {$today}, {$dayName}";
    $lines[] = "";
    $lines[] = "Выберите раздел или задайте вопрос:";

    return implode("\n", $lines);
}

function showSettings($chatId, $msgId, $userName) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM telegram_settings WHERE user_name = ?");
    $s->execute([$userName]);
    $settings = $s->fetch();
    if (!$settings) return;

    $labels = [
        'daily_summary' => '📊 Ежедневная сводка',
        'data_updates' => '📥 Загрузка данных',
        'expiring_items' => '⚠️ Истекающие сроки',
        'restaurant_sales' => '🍽 Реализация ресторанов',
        'psc_expiry' => '📋 ПСЦ истекает',
        'price_changed' => '💰 Цены изменились',
        'overdue_delivery' => '📦 Просроченная поставка',
        'low_stock' => '📉 Остатки заканчиваются',
        'correction_notifications' => '✏️ Корректировки заказов',
        'chat_notifications' => '💬 Сообщения из ресторанов',
        'so_deadline_summary' => '🧾 Сводка заявок поставщикам',
    ];

    $text = "⚙️ <b>Настройки уведомлений</b>\n\nНажмите кнопку чтобы включить/выключить:";
    $buttons = [];
    foreach ($labels as $key => $label) {
        $on = $settings[$key] ? '✅' : '❌';
        $buttons[] = [['text' => "$on $label", 'callback_data' => "toggle_$key"]];
    }
    $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];

    $markup = ['inline_keyboard' => $buttons];

    if ($msgId) {
        editMessage($chatId, $msgId, $text, $markup);
    } else {
        sendMessage($chatId, $text, $markup);
    }
}

// ═══ Inline queries (поиск карточек в любом чате) ═══

if (isset($input['inline_query'])) {
    $iq = $input['inline_query'];
    $query = trim($iq['query'] ?? '');
    $inlineId = $iq['id'];

    $results = [];
    if (mb_strlen($query) >= 2) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
        $st = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE (id LIKE ? OR name LIKE ?) ORDER BY name LIMIT 10");
        $st->execute(["%{$escaped}%", "%{$escaped}%"]);
        $cards = $st->fetchAll();

        foreach ($cards as $i => $c) {
            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            $analogsStr = is_array($analogs) && $analogs ? "\nАналоги: " . implode(', ', $analogs) : '';
            $results[] = [
                'type' => 'article',
                'id' => (string)$i,
                'title' => "{$c['id']} {$c['name']}",
                'description' => $analogsStr ? 'Аналоги: ' . implode(', ', $analogs) : 'Нет аналогов',
                'input_message_content' => [
                    'message_text' => "<b>" . tgEsc($c['id']) . "</b> " . tgEsc($c['name']) . tgEsc($analogsStr),
                    'parse_mode' => 'HTML',
                ],
            ];
        }
    }

    $payload = json_encode(['inline_query_id' => $inlineId, 'results' => json_encode($results), 'cache_time' => 30]);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/answerInlineQuery");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['inline_query_id' => $inlineId, 'results' => json_encode($results), 'cache_time' => 30], CURLOPT_TIMEOUT => 5]);
    curl_exec($ch); curl_close($ch);
    exit;
}

// ═══ Callback queries (настройки) ═══

if (isset($input['callback_query'])) {
    $cb = $input['callback_query'];
    $chatId = $cb['message']['chat']['id'];
    $msgId = $cb['message']['message_id'];
    $data = $cb['data'] ?? '';

    // Rate-limit: больше 30 нажатий в минуту от одного chat_id — отказ.
    // Защищает БД и Telegram API от долбёжки. Использует ту же таблицу
    // failed_login_attempts, что и rate-limit для 6-значного кода, с
    // ключом 'tg_cb_{chat_id}'. Старые записи (>5 минут) чистим.
    try {
        $cbKey = 'tg_cb_' . $chatId;
        $pdo->prepare("DELETE FROM failed_login_attempts WHERE user_name = ? AND attempted_at < (NOW() - INTERVAL 5 MINUTE)")
            ->execute([$cbKey]);
        $cntCb = $pdo->prepare("SELECT COUNT(*) FROM failed_login_attempts WHERE user_name = ? AND attempted_at > (NOW() - INTERVAL 1 MINUTE)");
        $cntCb->execute([$cbKey]);
        if ((int)$cntCb->fetchColumn() >= 30) {
            answerCallback($cb['id'], 'Слишком часто. Подождите минуту.', true);
            exit;
        }
        $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name) VALUES (?, ?)")
            ->execute(['0.0.0.0', $cbKey]);
    } catch (Throwable $e) {
        // если таблицы нет или БД упала — пропускаем rate-limit и работаем
    }

    // Кнопки меню — всё редактируем в том же сообщении
    if (str_starts_with($data, 'cmd_')) {
        $cmd = substr($data, 4);
        $user = getUser($chatId);

        // Карточки доступны всем (и ресторанам без привязки аккаунта)
        if ($cmd === 'cards' || $cmd === 'cards_exit') {
            answerCallback($cb['id']);
            if ($cmd === 'cards') {
                cmdCards($chatId, $user, $msgId);
            } else {
                // cards_exit — выход из режима поиска
                tgStateClear($chatId, 'cards');
                if ($user) {
                    setUserMode($user['name'], null);
                    editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
                } else {
                    restShowMySubs($chatId, $msgId);
                }
            }
            exit;
        }

        if (!$user) {
            answerCallback($cb['id'], 'Нажмите /start для привязки аккаунта');
            exit;
        }
        answerCallback($cb['id']);
        switch ($cmd) {
            case 'menu':
                setUserMode($user['name'], null);
                tgStateClear($chatId, 'cards');
                tgStateClear($chatId, 'restord'); // сброс режима ввода заявки
                editMessage($chatId, $msgId, getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
                break;
            case 'orders': cmdOrders($chatId, $user, $msgId); break;
            case 'stock': cmdStock($chatId, $user, $msgId); break;
            case 'consumption': cmdConsumption($chatId, $user, $msgId); break;
            case 'prices': cmdPrices($chatId, $user, $msgId); break;
            case 'psc': cmdPsc($chatId, $user, $msgId); break;
            case 'analysis': cmdAnalysis($chatId, $user, null, 0, $msgId); break;
            case 'plans': cmdPlans($chatId, $user, $msgId); break;
            case 'deliveries': cmdDeliveries($chatId, $user, $msgId); break;
            case 'today': cmdToday($chatId, $user, $msgId); break;
            case 'export': cmdExport($chatId, $user, $msgId); break;
            case 'schedule': cmdSchedule($chatId, $user, $msgId); break;
            case 'entity':
                $entities = $user['legal_entities'];
                if (count($entities) <= 1) {
                    $current = getUserEntity($user);
                    $btns = [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]];
                    editMessage($chatId, $msgId, "🏢 Вам доступно одно юрлицо: <b>" . tgEsc($current) . "</b>", ['inline_keyboard' => $btns]);
                } else {
                    $current = getUserEntity($user);
                    $btns = [];
                    foreach ($entities as $idx => $le) {
                        $mark = ($le === $current) ? '✅ ' : '';
                        $short = getEntityShort($le);
                        $btns[] = [['text' => "{$mark}{$short} — {$le}", 'callback_data' => "entity_{$idx}"]];
                    }
                    $btns[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];
                    editMessage($chatId, $msgId, "🏢 <b>Выбор юрлица</b>\n\nТекущее: <b>" . tgEsc($current) . "</b>\n\nНажмите для переключения:", ['inline_keyboard' => $btns]);
                }
                break;
            case 'sales': cmdSales($chatId, $user, $msgId); break;
            // cards обрабатывается выше в отдельном блоке (доступен без аккаунта)
            case 'rest_subs_stats':
                if (!botRequireAdmin($user, $chatId, $msgId)) break;
                cmdRestSubsStats($chatId, $msgId);
                break;
            case 'corrections':
                if (!botRequireAdmin($user, $chatId, $msgId)) break;
                cmdCorrections($chatId, $msgId);
                break;
            case 'ro_status': cmdRoStatus($chatId, $user, $msgId); break;
            case 'ro_send_logins':
                if (!botRequireAdmin($user, $chatId, $msgId)) break;
                restRoSendLogins($chatId, $msgId);
                break;
            case 'upload_order_file':
                if (!botRequireAdmin($user, $chatId, $msgId)) break;
                // TTL 10 минут на режим загрузки — если админ передумал,
                // флаг не висит вечно (раньше /tmp хранил его до перезагрузки).
                tgStateSet($chatId, 'import', ['type' => 'order_file'], 600);
                editMessage($chatId, $msgId, "📄 <b>Файл заказа</b>\n─────────────────────\nОтправьте файл Excel для ресторанов.\nОн будет доступен всем ресторанам через бот.", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
                break;
            // import удалён из бота — используйте сайт
                break;
            case 'settings': showSettings($chatId, $msgId, $user['name']); break;
        }
        exit;
    }

    // График доставок — навигация по дням
    if (str_starts_with($data, 'sched_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id']);
        $dayNum = intval(substr($data, 6));
        if ($dayNum >= 1 && $dayNum <= 6) {
            cmdSchedule($chatId, $user, $msgId, $dayNum);
        }
        exit;
    }

    // Анализ запасов — навигация по зонам (редактируем то же сообщение)
    if (str_starts_with($data, 'analysis_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id']);
        $parts = explode('_', $data);
        // analysis_summary, analysis_red_0, analysis_orange_1 ...
        if ($parts[1] === 'summary') {
            cmdAnalysis($chatId, $user, null, 0, $msgId);
        } else {
            $zone = $parts[1];
            $page = intval($parts[2] ?? 0);
            cmdAnalysis($chatId, $user, $zone, $page, $msgId);
        }
        exit;
    }

    // «Сделал заказ» по напоминанию поставщику (rrack:supplierId:orderDay:date)
    if (str_starts_with($data, 'rrack:')) {
        $parts = explode(':', $data, 4);
        if (count($parts) !== 4) { answerCallback($cb['id'], 'Ошибка данных'); exit; }
        $supplierId = $parts[1];
        $orderDay = (int)$parts[2];
        $targetDate = $parts[3];

        // chat_id → ресторан (через ro_telegram_subs)
        $s = $pdo->prepare("SELECT restaurant_number, legal_entity_group, first_name, username FROM ro_telegram_subs WHERE chat_id = ? AND verified_at IS NOT NULL LIMIT 1");
        $s->execute([$chatId]);
        $tgUser = $s->fetch();
        if (!$tgUser) { answerCallback($cb['id'], 'Привязка не найдена'); exit; }

        // restaurant_id
        $r = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $r->execute([$tgUser['restaurant_number'], $tgUser['legal_entity_group']]);
        $restaurantId = (int)$r->fetchColumn();
        if (!$restaurantId) { answerCallback($cb['id'], 'Ресторан не найден'); exit; }

        // reminder_kind: для main delivery callback_data исторически несёт
        // legacy-UUID '00000000-…' — переводим в правильный kind.
        $isMain = ($supplierId === '00000000-0000-0000-0000-000000000000');
        $reminderKind = $isMain ? 'main_delivery' : 'supplier';
        $dbSupplierId = $isMain ? '' : $supplierId;

        $by = 'tg:' . ($tgUser['first_name'] ?: ($tgUser['username'] ?: $chatId));
        // Время сохраняем явно в Europe/Minsk, чтобы не зависеть от time_zone сессии MySQL
        $minskNow = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('Y-m-d H:i:s');
        $pdo->prepare("
            INSERT INTO reminder_acknowledgements (restaurant_id, reminder_kind, supplier_id, target_date, order_day, acknowledged_by, acknowledged_at, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'telegram')
            ON DUPLICATE KEY UPDATE
                acknowledged_by = VALUES(acknowledged_by),
                acknowledged_at = VALUES(acknowledged_at),
                source = VALUES(source)
        ")->execute([$restaurantId, $reminderKind, $dbSupplierId, $targetDate, $orderDay, $by, $minskNow]);

        // Дописываем отметку прямо в исходное сообщение и убираем кнопку — одним
        // редактированием, без отдельного сообщения. Текст берём из callback (Telegram
        // отдаёт его без HTML-тегов), экранируем и заново шлём с parse_mode=HTML.
        $minskTime = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('H:i');
        $origText = $cb['message']['text'] ?? '';
        $newText = htmlspecialchars($origText, ENT_QUOTES, 'UTF-8') . "\n\n✅ <b>Отмечено как сделано в {$minskTime}</b>";
        $edited = editMessage($chatId, $msgId, $newText, null);
        // Если отредактировать не вышло (например, сообщение слишком старое) — хотя бы
        // убираем кнопку, факт отметки уже сохранён в БД выше.
        if (!$edited) editMessageReplyMarkup($chatId, $msgId, null);
        answerCallback($cb['id'], '✓ Отмечено');
        exit;
    }

    // Открыть раздел напоминаний из меню ресторана
    if ($data === 'rest_reminders' || $data === 'rrmine') {
        answerCallback($cb['id']);
        rrShowMyReminders($pdo, $chatId, $msgId);
        exit;
    }

    // Включить/отключить себя как получателя напоминаний по основной поставке
    if ($data === 'rrtoggle_main') {
        $tgUser = rrFindRoSub($pdo, $chatId);
        if (!$tgUser) { answerCallback($cb['id'], 'Привязка не найдена'); exit; }
        $r = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $r->execute([$tgUser['restaurant_number'], $tgUser['legal_entity_group']]);
        $restaurantId = (int)$r->fetchColumn();
        if (!$restaurantId) { answerCallback($cb['id'], 'Ресторан не найден'); exit; }

        // Проверка: у ресторана хотя бы одна строка delivery_schedule с дедлайном
        $check = $pdo->prepare("SELECT 1 FROM delivery_schedule WHERE restaurant_id = ? AND order_day IS NOT NULL AND order_deadline IS NOT NULL LIMIT 1");
        $check->execute([$restaurantId]);
        if (!$check->fetchColumn()) { answerCallback($cb['id'], 'Основная поставка не настроена'); exit; }

        // Получаем (или создаём) подписку
        $sub = $pdo->prepare("SELECT id FROM restaurant_main_delivery_subscriptions WHERE restaurant_id = ?");
        $sub->execute([$restaurantId]);
        $subId = $sub->fetchColumn();
        $by = 'tg:' . ($tgUser['first_name'] ?: ($tgUser['username'] ?: $chatId));
        if (!$subId) {
            $pdo->prepare("INSERT INTO restaurant_main_delivery_subscriptions
                           (restaurant_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
                           VALUES (?, 1, 1, 1, ?)")
                ->execute([$restaurantId, $by]);
            $subId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE restaurant_main_delivery_subscriptions
                            SET is_enabled = 1, portal_enabled = 1, telegram_enabled = 1, updated_at = NOW()
                            WHERE id = ?")
                ->execute([(int)$subId]);
        }

        $existing = $pdo->prepare("SELECT id FROM restaurant_main_delivery_tg_subscribers WHERE subscription_id = ? AND ro_tg_sub_id = ? LIMIT 1");
        $existing->execute([(int)$subId, (int)$tgUser['id']]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $pdo->prepare("DELETE FROM restaurant_main_delivery_tg_subscribers WHERE id = ?")->execute([(int)$existingId]);
            answerCallback($cb['id'], 'Отключено');
        } else {
            $pdo->prepare("INSERT IGNORE INTO restaurant_main_delivery_tg_subscribers (subscription_id, ro_tg_sub_id, is_active) VALUES (?, ?, 1)")
                ->execute([(int)$subId, (int)$tgUser['id']]);
            answerCallback($cb['id'], 'Подключено ✓');
        }
        rrShowMyReminders($pdo, $chatId, $msgId);
        exit;
    }

    // Включить/отключить себя как получателя напоминаний по поставщику
    if (str_starts_with($data, 'rrtoggle:')) {
        $supplierId = substr($data, 9);
        if (!$supplierId) { answerCallback($cb['id'], 'Ошибка'); exit; }

        $tgUser = rrFindRoSub($pdo, $chatId);
        if (!$tgUser) { answerCallback($cb['id'], 'Привязка не найдена'); exit; }
        $r = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $r->execute([$tgUser['restaurant_number'], $tgUser['legal_entity_group']]);
        $restaurantId = (int)$r->fetchColumn();
        if (!$restaurantId) { answerCallback($cb['id'], 'Ресторан не найден'); exit; }

        // Проверка что у ресторана есть расписание именно с этим (локальным!) поставщиком
        $check = $pdo->prepare("
            SELECT 1 FROM supplier_schedules ss
            JOIN suppliers s ON s.id = ss.supplier_id
            WHERE ss.restaurant_id = ? AND ss.supplier_id = ? AND ss.is_active = 1
              AND s.is_active = 1 AND s.so_enabled = 0
            LIMIT 1
        ");
        $check->execute([$restaurantId, $supplierId]);
        if (!$check->fetchColumn()) { answerCallback($cb['id'], 'Поставщик не доступен'); exit; }

        // Получаем (или создаём) подписку (restaurant_id, supplier_id)
        $sub = $pdo->prepare("SELECT id FROM restaurant_reminder_subscriptions WHERE restaurant_id = ? AND supplier_id = ?");
        $sub->execute([$restaurantId, $supplierId]);
        $subId = $sub->fetchColumn();
        $by = 'tg:' . ($tgUser['first_name'] ?: ($tgUser['username'] ?: $chatId));
        if (!$subId) {
            $pdo->prepare("INSERT INTO restaurant_reminder_subscriptions
                           (restaurant_id, supplier_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
                           VALUES (?, ?, 1, 1, 1, ?)")
                ->execute([$restaurantId, $supplierId, $by]);
            $subId = (int)$pdo->lastInsertId();
        } else {
            // Убедимся что подписка включена и канал telegram активен
            $pdo->prepare("UPDATE restaurant_reminder_subscriptions
                            SET is_enabled = 1, portal_enabled = 1, telegram_enabled = 1, updated_at = NOW()
                            WHERE id = ?")
                ->execute([$subId]);
        }

        // Уже подписан? — отписываем. Не подписан — подписываем.
        $existing = $pdo->prepare("SELECT id FROM restaurant_reminder_tg_subscribers WHERE subscription_id = ? AND ro_tg_sub_id = ? LIMIT 1");
        $existing->execute([(int)$subId, (int)$tgUser['id']]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $pdo->prepare("DELETE FROM restaurant_reminder_tg_subscribers WHERE id = ?")->execute([(int)$existingId]);
            answerCallback($cb['id'], 'Отключено');
        } else {
            $pdo->prepare("INSERT IGNORE INTO restaurant_reminder_tg_subscribers (subscription_id, ro_tg_sub_id, is_active) VALUES (?, ?, 1)")
                ->execute([(int)$subId, (int)$tgUser['id']]);
            answerCallback($cb['id'], 'Подключено ✓');
        }
        rrShowMyReminders($pdo, $chatId, $msgId);
        exit;
    }

    // Отписаться от напоминаний по конкретной подписке (rrunsub:subscription_id)
    if (str_starts_with($data, 'rrunsub:')) {
        $subscriptionId = (int)substr($data, 8);
        if (!$subscriptionId) { answerCallback($cb['id'], 'Ошибка'); exit; }

        $tgUser = rrFindRoSub($pdo, $chatId);
        if (!$tgUser) { answerCallback($cb['id'], 'Привязка не найдена'); exit; }

        // Проверка что подписка действительно принадлежит этому ресторану
        $check = $pdo->prepare("
            SELECT r.number, r.legal_entity_group, su.short_name
            FROM restaurant_reminder_subscriptions s
            JOIN restaurants r ON r.id = s.restaurant_id
            JOIN suppliers su ON su.id = s.supplier_id
            WHERE s.id = ? LIMIT 1
        ");
        $check->execute([$subscriptionId]);
        $row = $check->fetch();
        if (!$row || $row['number'] != $tgUser['restaurant_number'] || $row['legal_entity_group'] !== $tgUser['legal_entity_group']) {
            answerCallback($cb['id'], 'Подписка не ваша'); exit;
        }

        // Удаляем только нашего пользователя из подписчиков
        $pdo->prepare("DELETE FROM restaurant_reminder_tg_subscribers WHERE subscription_id = ? AND ro_tg_sub_id = ?")
            ->execute([$subscriptionId, (int)$tgUser['id']]);

        answerCallback($cb['id'], "Отключено: " . $row['short_name']);
        rrShowMyReminders($pdo, $chatId, $msgId);
        exit;
    }

    // Приёмка поставки
    if (str_starts_with($data, 'receive_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        $orderId = substr($data, 8);
        $entity = getUserEntity($user);
        $sql = "SELECT id, supplier FROM orders WHERE id = ? AND received_at IS NULL";
        $p = [$orderId];
        if ($entity) { $sql .= " AND legal_entity = ?"; $p[] = $entity; }
        $s = $pdo->prepare($sql); $s->execute($p);
        $order = $s->fetch();
        if (!$order) { answerCallback($cb['id'], 'Поставка не найдена или уже принята'); exit; }
        $pdo->prepare("UPDATE orders SET received_at = NOW() WHERE id = ?")->execute([$orderId]);
        answerCallback($cb['id'], "✅ {$order['supplier']} принято!");
        cmdDeliveries($chatId, $user, $msgId);
        exit;
    }

    // Экспорт CSV
    if (str_starts_with($data, 'export_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        answerCallback($cb['id'], 'Генерирую файл...');
        $type = substr($data, 7);
        $entity = getUserEntity($user);
        $short = $entity ? getEntityShort($entity) : 'all';
        $date = date('Y-m-d');
        switch ($type) {
            case 'analysis':
                $csv = generateAnalysisCsv($entity);
                sendDocument($chatId, "analysis_{$short}_{$date}.csv", $csv, "📊 Анализ запасов · {$short} · {$date}");
                break;
            case 'orders':
                $csv = generateOrdersCsv($entity);
                sendDocument($chatId, "orders_{$short}_{$date}.csv", $csv, "📦 Заказы · {$short} · {$date}");
                break;
            case 'prices':
                $csv = generatePricesCsv($entity);
                sendDocument($chatId, "prices_{$short}_{$date}.csv", $csv, "💰 Цены · {$short} · {$date}");
                break;
        }
        exit;
    }

    // Выбор юрлица
    if (str_starts_with($data, 'entity_')) {
        $user = getUser($chatId);
        if (!$user) { answerCallback($cb['id'], 'Сначала привяжите аккаунт'); exit; }
        $idx = intval(substr($data, 7));
        $entities = $user['legal_entities'];
        if (isset($entities[$idx])) {
            setUserEntity($user['name'], $entities[$idx]);
            $short = getEntityShort($entities[$idx]);
            answerCallback($cb['id'], "Выбрано: {$short}");
            $menuBtns = ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]];
            editMessage($chatId, $msgId, "✅ Юрлицо переключено на <b>" . tgEsc($entities[$idx]) . "</b>\n\nТеперь все данные показываются для этого юрлица.", $menuBtns);
        } else {
            answerCallback($cb['id'], 'Ошибка выбора');
        }
        exit;
    }

    if (str_starts_with($data, 'toggle_')) {
        answerCallback($cb['id']);
        $field = substr($data, 7);
        $allowed = ['psc_expiry', 'overdue_delivery', 'price_changed', 'low_stock', 'daily_summary', 'data_updates', 'expiring_items', 'restaurant_sales', 'correction_notifications', 'chat_notifications', 'so_deadline_summary'];
        // Поля, доступные только менеджерам и администраторам
        $managerOnly = ['daily_summary', 'psc_expiry', 'correction_notifications', 'chat_notifications', 'so_deadline_summary'];
        if (in_array($field, $allowed)) {
            $u = $pdo->prepare("SELECT name, role FROM users WHERE telegram_chat_id = ?");
            $u->execute([$chatId]);
            $userRow = $u->fetch();
            $user = $userRow['name'] ?? null;
            if ($user) {
                $userRole = $userRow['role'] ?? 'user';
                if (in_array($field, $managerOnly) && !in_array($userRole, ['admin', 'manager'])) {
                    sendMessage($chatId, "⛔ Эта настройка доступна только менеджерам и администраторам.");
                } else {
                    $pdo->prepare("UPDATE telegram_settings SET `$field` = NOT `$field` WHERE user_name = ?")->execute([$user]);
                    showSettings($chatId, $msgId, $user);
                }
            }
        }
        exit;
    }

    // ═══ /start: выбор роли ═══
    if ($data === 'start_role_purchasing') {
        answerCallback($cb['id']);
        // Генерируем токен и даём кнопку для авторизации через сайт
        $token = bin2hex(random_bytes(16));
        $tgUsername = $cb['from']['username'] ?? null;
        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE telegram_chat_id = ?")->execute([$chatId]);
        $pdo->prepare("INSERT INTO telegram_link_tokens (token, telegram_chat_id, telegram_username, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))")
            ->execute([$token, $chatId, $tgUsername]);
        $linkUrl = "{$SITE_URL}/telegram-link?token={$token}";
        $keyboard = ['inline_keyboard' => [
            [['text' => '🔐 Войти через сайт', 'url' => $linkUrl]],
            [['text' => '◂ Назад', 'callback_data' => 'start_back']],
        ]];
        editMessage($chatId, $msgId, "🏢 <b>Отдел закупок</b>\n\nДля доступа нужно привязать Telegram к вашему аккаунту на сайте.\n\nНажмите кнопку ниже — откроется сайт, где нужно войти под своим логином.\n\n<i>Ссылка действительна 15 минут.</i>", $keyboard);
        exit;
    }

    if ($data === 'start_role_restaurant') {
        answerCallback($cb['id']);
        if (botCountActiveSubs($chatId) === 0) {
            $kb = ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'start_back']]]];
            editMessage($chatId, $msgId, botRestaurantLinkInstructions(), $kb);
        } else {
            restShowMySubs($chatId, $msgId);
        }
        exit;
    }

    if ($data === 'rest_link_help') {
        answerCallback($cb['id']);
        $kb = ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'start_role_restaurant']]]];
        editMessage($chatId, $msgId, botRestaurantLinkInstructions(), $kb);
        exit;
    }

    if ($data === 'start_back') {
        answerCallback($cb['id']);
        $keyboard = ['inline_keyboard' => [
            [['text' => '🏢 Отдел закупок', 'callback_data' => 'start_role_purchasing']],
            [['text' => '🏪 Ресторан', 'callback_data' => 'start_role_restaurant']],
        ]];
        editMessage($chatId, $msgId, "👋 <b>Supply Department</b>\n\nДобро пожаловать! Выберите, кто вы:", $keyboard);
        exit;
    }

    // ═══ Чат ресторана с отделом закупок ═══
    if ($data === 'chat_start') {
        answerCallback($cb['id']);
        chatStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'chat_rest_')) {
        answerCallback($cb['id']);
        $crRestNum = substr($data, 10);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $crRestNum)) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$crRestNum}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        chatInputMode($chatId, $msgId, $crRestNum);
        exit;
    }
    if (str_starts_with($data, 'chat_history_')) {
        answerCallback($cb['id']);
        $chRestNum = substr($data, 13);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $chRestNum)) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$chRestNum}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        chatShowHistory($chatId, $msgId, $chRestNum);
        exit;
    }
    if ($data === 'chat_cancel') {
        answerCallback($cb['id']);
        tgStateClear($chatId, 'chat');
        restShowMySubs($chatId, $msgId);
        exit;
    }

    // ═══ Файл заказа ═══
    if ($data === 'rest_order_file') {
        answerCallback($cb['id']);
        $file = $pdo->query("SELECT telegram_file_id, file_name, file_path, uploaded_at, uploaded_by FROM order_file ORDER BY id DESC LIMIT 1")->fetch();
        if ($file && $file['telegram_file_id']) {
            $updDate = date('d.m.Y H:i', strtotime($file['uploaded_at']));
            $caption = "📄 Файл заказа\nОбновлён: {$updDate}";
            if ($file['uploaded_by']) $caption .= "\nЗагрузил: {$file['uploaded_by']}";
            $payload = json_encode(['chat_id' => $chatId, 'document' => $file['telegram_file_id'], 'caption' => $caption]);
            $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendDocument");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 15]);
            curl_exec($ch); curl_close($ch);
        } else {
            sendMessage($chatId, "📄 Файл заказа пока не загружен.");
        }
        exit;
    }

    // ═══ Личный кабинет ресторана ═══
    if ($data === 'rest_cabinet') {
        answerCallback($cb['id']);
        $subs = botGetSubscribedRestaurants($pdo, $chatId);
        if (!$subs) {
            editMessage($chatId, $msgId, "Вы не подписаны ни на один ресторан.", ['inline_keyboard' => [
                [['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']],
            ]]);
            exit;
        }
        if (count($subs) === 1) {
            botOpenRestaurantCabinet($chatId, $msgId, $subs[0]['restaurant_number']);
            exit;
        }
        $btns = [];
        foreach ($subs as $sub) {
            $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
            $label = botFormatSubscribedRestaurant($sub['restaurant_number'], $sub['legal_entity_group']);
            $btns[] = [['text' => "{$label} — {$addr}", 'callback_data' => "rest_cab:{$sub['restaurant_number']}"]];
        }
        $btns[] = [['text' => '« Назад', 'callback_data' => 'start_role_restaurant']];
        editMessage($chatId, $msgId, "🏠 <b>Личный кабинет</b>\n\nВыберите ресторан:", ['inline_keyboard' => $btns]);
        exit;
    }

    if (str_starts_with($data, 'rest_cab:')) {
        answerCallback($cb['id']);
        $rcRestNum = substr($data, 9);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $rcRestNum)) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$rcRestNum}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        botOpenRestaurantCabinet($chatId, $msgId, $rcRestNum);
        exit;
    }

    // ═══ Подменю ресторана ═══
    if ($data === 'rest_menu_main') {
        answerCallback($cb['id']);
        restMenuMain($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_menu_supplier_legacy') {
        // Старый callback — теперь всё через единое меню поставщиков
        answerCallback($cb['id']);
        restMenuSupplier($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_menu_supplier') {
        answerCallback($cb['id']);
        restMenuSupplier($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_ro_orders') {
        answerCallback($cb['id']);
        restRoOrders($chatId, $msgId);
        exit;
    }
    // ═══ Камако / поставщики ═══
    if (preg_match('/^soord_sup_(.+)$/', $data, $m)) {
        answerCallback($cb['id']);
        soOrderSelectRest($chatId, $msgId, $m[1]);
        exit;
    }
    if (preg_match('/^soord_rest_(.+?)_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $m[2])) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$m[2]}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        soOrderSelectDay($chatId, $msgId, $m[1], $m[2]);
        exit;
    }
    if (preg_match('/^soord_day_(.+?)_(\d+)_back$/', $data, $m)) {
        answerCallback($cb['id']);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $m[2])) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$m[2]}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        soOrderSelectDay($chatId, $msgId, $m[1], $m[2]);
        exit;
    }
    if (preg_match('/^soord_day_(.+?)_(\d+)_(\d{4}-\d{2}-\d{2})$/', $data, $m)) {
        answerCallback($cb['id']);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $m[2])) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$m[2]}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        soOrderShowProducts($chatId, $msgId, $m[1], $m[2], $m[3]);
        exit;
    }
    if (preg_match('/^soord_closed_(.+?)_(\d+)_(\d{4}-\d{2}-\d{2})$/', $data, $m)) {
        answerCallback($cb['id'], 'Приём заявок на этот день уже завершён', true);
        exit;
    }
    // Поставка не нужна (заявка-отказ)
    if (preg_match('/^soord_skip_(.+?)_(\d+)_(\d{4}-\d{2}-\d{2})$/', $data, $m)) {
        answerCallback($cb['id']);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $m[2])) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$m[2]}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        soOrderSkipDelivery($chatId, $msgId, $m[1], $m[2], $m[3]);
        exit;
    }
    if (preg_match('/^sohist_sup_(.+)$/', $data, $m)) {
        answerCallback($cb['id']);
        soShowMyOrders($chatId, $msgId, $m[1]);
        exit;
    }
    if (preg_match('/^sohist_rest_(.+?)_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        soShowRestOrders($chatId, $msgId, $m[1], $m[2]);
        exit;
    }
    if ($data === 'rest_schedule') {
        answerCallback($cb['id']);
        restShowSchedule($chatId, $msgId);
        exit;
    }
    if ($data === 'rest_stock') {
        answerCallback($cb['id']);
        restStockStart($chatId, $msgId);
        exit;
    }
    // Сбор остатков
    if ($data === 'rest_sc_start') {
        answerCallback($cb['id']);
        restScStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'sc_col_')) {
        answerCallback($cb['id']);
        restScSelectRest($chatId, $msgId, intval(substr($data, 7)));
        exit;
    }
    if (str_starts_with($data, 'sc_rest_')) {
        answerCallback($cb['id']);
        // sc_rest_{collId}_{restNum}
        $parts = explode('_', substr($data, 8), 2);
        if (count($parts) === 2) restScShowProducts($chatId, $msgId, intval($parts[0]), $parts[1]);
        exit;
    }

    // ═══ Корректировки заказов ═══
    if ($data === 'corr_start') {
        answerCallback($cb['id']);
        corrStart($chatId, $msgId);
        exit;
    }
    if (str_starts_with($data, 'corr_rest_')) {
        answerCallback($cb['id']);
        $corrRestNum = substr($data, 10);
        if (!botIsSubscribedToRestaurant($pdo, $chatId, $corrRestNum)) {
            editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$corrRestNum}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
            exit;
        }
        corrShowDelivery($chatId, $msgId, $corrRestNum);
        exit;
    }
    if (str_starts_with($data, 'corr_date_')) {
        answerCallback($cb['id']);
        $parts = explode('_', substr($data, 10), 2);
        if (count($parts) === 2) {
            $cdRestNum = $parts[0];
            if (!botIsSubscribedToRestaurant($pdo, $chatId, $cdRestNum)) {
                editMessage($chatId, $msgId, "⛔ У вас нет доступа к ресторану №{$cdRestNum}.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_my_subs']]]]);
                exit;
            }
            corrStartInput($chatId, $msgId, $cdRestNum, $parts[1]);
        }
        exit;
    }
    if ($data === 'corr_submit') {
        answerCallback($cb['id']);
        corrSubmitBatch($chatId);
        exit;
    }
    if (str_starts_with($data, 'corr_history_')) {
        answerCallback($cb['id']);
        corrShowHistory($chatId, $msgId, intval(substr($data, 13)));
        exit;
    }
    // Взять в работу
    if (str_starts_with($data, 'corr_take_')) {
        $oneId = intval(substr($data, 10));
        $user = corrCheckBotAccess($pdo, $chatId, $oneId, $cb['id']);
        if (!$user) exit;
        // Проверяем, не взял ли уже кто-то другой
        $chk = $pdo->prepare("SELECT reviewer_name, reviewer_chat_id, status FROM order_corrections WHERE id = ?");
        $chk->execute([$oneId]);
        $chkRow = $chk->fetch();
        if ($chkRow && $chkRow['status'] === 'in_progress' && $chkRow['reviewer_chat_id'] && (string)$chkRow['reviewer_chat_id'] !== (string)$chatId) {
            answerCallback($cb['id'], "⚠️ Уже в работе у {$chkRow['reviewer_name']}", true);
            exit;
        }
        $ids = corrGetBatchPendingIds($pdo, $oneId);
        if (empty($ids)) {
            answerCallback($cb['id']);
            // Может уже все in_progress — проверим
            $nmRow2 = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
            $nmRow2->execute([$oneId]);
            $nmData2 = json_decode($nmRow2->fetchColumn() ?: '{}', true);
            corrUpdateAllReviewMessages($pdo, $nmData2['batch_ids'] ?? [$oneId]);
            exit;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE order_corrections SET status = 'in_progress', reviewer_name = ?, reviewer_chat_id = ? WHERE id IN ({$ph}) AND status = 'pending'");
        $upd->execute(array_merge([$user['name'], $chatId], $ids));
        if ($upd->rowCount() === 0) {
            // Кто-то успел раньше
            $who = $pdo->prepare("SELECT reviewer_name FROM order_corrections WHERE id = ?");
            $who->execute([$oneId]);
            $whoName = $who->fetchColumn() ?: 'другой сотрудник';
            answerCallback($cb['id'], "⚠️ Уже в работе у {$whoName}", true);
            exit;
        }
        answerCallback($cb['id']);
        $nmRow = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
        $nmRow->execute([$oneId]);
        $nmData = json_decode($nmRow->fetchColumn() ?: '{}', true);
        corrUpdateAllReviewMessages($pdo, $nmData['batch_ids'] ?? $ids);
        exit;
    }
    // Проверка: только взявший может рецензировать
    function corrCheckReviewer($pdo, $corrId, $chatId, $cbId) {
        $chk = $pdo->prepare("SELECT reviewer_chat_id, reviewer_name FROM order_corrections WHERE id = ?");
        $chk->execute([$corrId]);
        $r = $chk->fetch();
        if ($r && $r['reviewer_chat_id'] && (string)$r['reviewer_chat_id'] !== (string)$chatId) {
            answerCallback($cbId, "⚠️ В работе у {$r['reviewer_name']}", true);
            return false;
        }
        return true;
    }

    // Отдел закупок: принять одну позицию (corr_a_{id})
    if (str_starts_with($data, 'corr_a_')) {
        $corrId = intval(substr($data, 7));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        corrReview($pdo, $chatId, $msgId, [$corrId], 'approve');
        exit;
    }
    // Отдел закупок: отклонить одну позицию (corr_r_{id})
    if (str_starts_with($data, 'corr_r_')) {
        $corrId = intval(substr($data, 7));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        corrReview($pdo, $chatId, $msgId, [$corrId], 'reject');
        exit;
    }
    // Принять все pending в батче (по одному ID)
    if (str_starts_with($data, 'corr_aa_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, $corrId);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'approve');
        exit;
    }
    // Отклонить все pending в батче
    if (str_starts_with($data, 'corr_ra_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, $corrId);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'reject');
        exit;
    }
    // Комментарий ко всем pending в батче
    if (str_starts_with($data, 'corr_cm_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $ids = corrGetBatchPendingIds($pdo, $corrId);
        if (empty($ids)) { editMessage($chatId, $msgId, "⚠️ Все позиции уже обработаны."); exit; }
        $state = ['step' => 'review_comment', 'corr_ids' => $ids, 'msg_id' => $msgId];
        tgStateSet($chatId, 'corr', ['mode' => 'corr_review', 'state' => $state]);
        editMessage($chatId, $msgId, "💬 Введите комментарий.\nПосле ввода выберите действие:", ['inline_keyboard' => [
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]]);
        exit;
    }
    // Отправить результат ресторану
    if (str_starts_with($data, 'corr_send_')) {
        $corrId = intval(substr($data, 10));
        $user = corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id']);
        if (!$user) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $batchIds = corrGetBatchAllIds($pdo, $corrId);
        if ($batchIds) corrSendResultToRestaurant($pdo, $batchIds, $user['name']);
        exit;
    }
    // Итоговый комментарий после обработки всех позиций
    if (str_starts_with($data, 'corr_fc_')) {
        $corrId = intval(substr($data, 8));
        if (!corrCheckBotAccess($pdo, $chatId, $corrId, $cb['id'])) exit;
        if (!corrCheckReviewer($pdo, $corrId, $chatId, $cb['id'])) exit;
        answerCallback($cb['id']);
        $batchIds = corrGetBatchAllIds($pdo, $corrId);
        if (empty($batchIds)) { editMessage($chatId, $msgId, "⚠️ Заявка не найдена."); exit; }
        $state = ['step' => 'final_comment', 'batch_ids' => $batchIds, 'msg_id' => $msgId];
        tgStateSet($chatId, 'corr', ['mode' => 'corr_review', 'state' => $state]);
        editMessage($chatId, $msgId, "💬 Введите итоговый комментарий для ресторана:", ['inline_keyboard' => [
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]]);
        exit;
    }
    // Отправить с итоговым комментарием
    if ($data === 'corr_fc_send') {
        $corrSt = tgStateGet($chatId, 'corr');
        $state  = $corrSt['state'] ?? [];
        $comment = $state['final_comment'] ?? '';
        $batchIds = $state['batch_ids'] ?? [];
        $user = corrCheckBotAccess($pdo, $chatId, $batchIds, $cb['id']);
        if (!$user) exit;
        if ($batchIds && !corrCheckReviewer($pdo, $batchIds[0], $chatId, $cb['id'])) exit;
        tgStateClear($chatId, 'corr');
        answerCallback($cb['id']);
        if ($batchIds) corrSendResultToRestaurant($pdo, $batchIds, $user['name'], $comment);
        exit;
    }
    // Принять с комментарием (IDs и комментарий в state)
    if ($data === 'corr_cappr_go') {
        $corrSt = tgStateGet($chatId, 'corr');
        $state  = $corrSt['state'] ?? [];
        $comment = $state['review_comment'] ?? null;
        $ids = $state['corr_ids'] ?? [];
        if (!corrCheckBotAccess($pdo, $chatId, $ids, $cb['id'])) exit;
        if ($ids && !corrCheckReviewer($pdo, $ids[0], $chatId, $cb['id'])) exit;
        tgStateClear($chatId, 'corr');
        answerCallback($cb['id']);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'approve', $comment);
        exit;
    }
    // Отклонить с комментарием
    if ($data === 'corr_crej_go') {
        $corrSt = tgStateGet($chatId, 'corr');
        $state  = $corrSt['state'] ?? [];
        $comment = $state['review_comment'] ?? null;
        $ids = $state['corr_ids'] ?? [];
        if (!corrCheckBotAccess($pdo, $chatId, $ids, $cb['id'])) exit;
        if ($ids && !corrCheckReviewer($pdo, $ids[0], $chatId, $cb['id'])) exit;
        tgStateClear($chatId, 'corr');
        answerCallback($cb['id']);
        if ($ids) corrReview($pdo, $chatId, $msgId, $ids, 'reject', $comment);
        exit;
    }
    if ($data === 'corr_rev_cancel') {
        answerCallback($cb['id']);
        tgStateClear($chatId, 'corr');
        editMessage($chatId, $msgId, "Отменено.");
        exit;
    }

    // ═══ Овощи: просмотр заявок ресторана ═══
    if ($data === 'rest_my_orders') {
        answerCallback($cb['id']);
        soShowMyOrders($chatId, $msgId, soGetPlanetaSupplierId());
        exit;
    }

    if (str_starts_with($data, 'rest_orders_for_')) {
        answerCallback($cb['id']);
        $restNum = substr($data, 16);
        soShowRestOrders($chatId, $msgId, soGetPlanetaSupplierId(), $restNum);
        exit;
    }

    if (str_starts_with($data, 'rest_history_')) {
        answerCallback($cb['id']);
        $restNum = substr($data, 12);
        soShowRestOrders($chatId, $msgId, soGetPlanetaSupplierId(), $restNum);
        exit;
    }

    if (str_starts_with($data, 'rest_hist_')) {
        answerCallback($cb['id']);
        // Старые кнопки истории переводим на новый просмотр заявок поставщика
        $parts = explode('_', substr($data, 9), 2);
        if (count($parts) === 2) {
            soShowRestOrders($chatId, $msgId, soGetPlanetaSupplierId(), $parts[0]);
        }
        exit;
    }

    // Открытая подписка через бот удалена. Подписка теперь возможна только через
    // 6-значный код, выдаваемый в личном кабинете ресторана. См. botRestaurantLinkInstructions().

    if ($data === 'rest_my_subs') {
        answerCallback($cb['id']);
        restShowMySubs($chatId, $msgId);
        exit;
    }

    if ($data === 'rest_my_subs_manage') {
        answerCallback($cb['id']);
        restShowSubsManage($chatId, $msgId);
        exit;
    }

    if ($data === 'rest_notif_settings') {
        answerCallback($cb['id']);
        restNotifSettings($chatId, $msgId);
        exit;
    }

    if (str_starts_with($data, 'rest_notif_toggle_')) {
        $field = substr($data, 18); // so_reminders, so_sessions, confirmations, stock_reminders, stock_sessions
        answerCallback($cb['id']);
        restNotifToggle($chatId, $msgId, $field);
        exit;
    }

    if (str_starts_with($data, 'rest_unsub:')) {
        $parts = explode(':', $data, 3);
        $group = $parts[1] ?? 'BK_VM';
        $restNum = $parts[2] ?? '';
        $pdo->prepare("DELETE FROM ro_telegram_subs WHERE chat_id=? AND restaurant_number=? AND legal_entity_group=?")->execute([$chatId, $restNum, $group]);
        answerCallback($cb['id'], "Отписано от ресторана $restNum");
        restShowSubsManage($chatId, $msgId);
        exit;
    }

    // Колбэки veg_pick_rest / veg_pick_group: удалены — выбор ресторана в боте больше не выдаёт подписку.

    // Подача заявки через бота
    if ($data === 'restord_start') {
        answerCallback($cb['id']);
        soOrderSelectRest($chatId, $msgId, soGetPlanetaSupplierId());
        exit;
    }
    if (str_starts_with($data, 'restord_rest_')) {
        answerCallback($cb['id']);
        tgStateClear($chatId, 'restord'); // сброс режима ввода
        soOrderSelectDay($chatId, $msgId, soGetPlanetaSupplierId(), substr($data, 12));
        exit;
    }
    if (str_starts_with($data, 'restord_day_')) {
        answerCallback($cb['id']);
        $rest = substr($data, 11); // "27_2026-03-19"
        $sep = strpos($rest, '_');
        if ($sep !== false) {
            $restNum = substr($rest, 0, $sep);
            $date = substr($rest, $sep + 1);
            soOrderShowProducts($chatId, $msgId, soGetPlanetaSupplierId(), $restNum, $date);
        }
        exit;
    }
    if (str_starts_with($data, 'restord_skip_')) {
        answerCallback($cb['id']);
        $rest = substr($data, 12); // "27_2026-03-19"
        $sep = strpos($rest, '_');
        if ($sep !== false) {
            $restNum = substr($rest, 0, $sep);
            $date = substr($rest, $sep + 1);
            soOrderSkipDelivery($chatId, $msgId, soGetPlanetaSupplierId(), $restNum, $date);
        }
        exit;
    }

    // Старый экран пагинации выбора ресторана удалён вместе с открытой подпиской.

    // ═══ Опросы ═══
    if (preg_match('/^srv_start_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        surveyStart($chatId, $msgId, (int)$m[1]);
        exit;
    }
    // Отложить напоминание об опросе на 1 час
    if (preg_match('/^srv_snooze_(\d+)$/', $data, $m)) {
        $surveyId = (int)$m[1];
        $snoozeKey = "survey_snooze_{$surveyId}_{$chatId}";
        try {
            $pdo->prepare("INSERT INTO tg_notification_log (notification_type, notification_key, chat_id, sent_at)
                            VALUES ('survey_snooze', ?, ?, NOW())")
                ->execute([$snoozeKey, (int)$chatId]);
        } catch (Throwable $e) { /* ignore */ }
        // Убираем кнопки, чтобы пользователь не нажимал повторно
        editMessageReplyMarkup($chatId, $msgId, null);
        sendMessage($chatId, '⏰ Напомню через час. Если опрос закроется раньше — успейте пройти.');
        answerCallback($cb['id'], 'Отложено на час');
        exit;
    }
    if (preg_match('/^srv_rest_(\d+)_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        surveySelectRestaurant($chatId, $msgId, (int)$m[1], (int)$m[2]);
        exit;
    }
    if (preg_match('/^srv_ans_(\d+)_(\d+)_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        surveyProcessAnswer($chatId, $msgId, (int)$m[1], (int)$m[2], (int)$m[3]);
        exit;
    }
    if (preg_match('/^srv_skip_comment_(\d+)$/', $data, $m)) {
        answerCallback($cb['id']);
        $survState = surveyLoadState($chatId);
        if ($survState && (int)$survState['survey_id'] === (int)$m[1]) {
            surveyFinish($chatId, $msgId, $survState, null);
        }
        exit;
    }

    exit;
}

// ═══ Обработка сообщений ═══

$msg = $input['message'] ?? $input['edited_message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];

// ── Сообщения из рабочих групп ──────────────────────────────────────────
// В группе/супергруппе бот работает в безопасном FAQ-режиме: отвечает
// только когда к нему обратились (@упоминание или ответ на его сообщение).
// Отдаёт инструкции по порталу и справочные данные (остатки, номенклатура,
// аналоги), но НЕ цены/поставщиков/заказы. Всё остальное игнорирует.
$chatType = $msg['chat']['type'] ?? 'private';
if ($chatType === 'group' || $chatType === 'supergroup') {
    handleGroupMessage($chatId, $msg);
    exit;
}

// Режим чата с отделом закупок — обрабатывает и текст, и фото.
// TTL 1 час задан в самом tg_state.expires_at (см. chatStart в bot_chat.php).
$chatState = tgStateGet($chatId, 'chat');
if ($chatState !== null) {
    $chatRestNum = (string)($chatState['rest'] ?? '');
    $msgText = trim($msg['text'] ?? $msg['caption'] ?? '');
    $photoFileId = null;
    if (isset($msg['photo'])) {
        $photos = $msg['photo'];
        $photoFileId = end($photos)['file_id'] ?? null;
    }
    if ($msgText || $photoFileId) {
        if (str_starts_with($msgText, '/')) {
            tgStateClear($chatId, 'chat');
            // Пусть обработается как команда ниже
        } else {
            chatProcessMessage($chatId, $msgText, $chatRestNum, $msg['message_id'] ?? null, $msg['from'] ?? [], $photoFileId);
            exit;
        }
    } elseif (isset($msg['document']) || isset($msg['voice']) || isset($msg['video']) || isset($msg['sticker'])) {
        // В чате принимаем только текст и фото
        $userMsgId = $msg['message_id'] ?? null;
        if ($userMsgId) @deleteMessage($chatId, $userMsgId);
        exit;
    }
}

// Режим загрузки файла заказа. TTL 10 минут — выставляется при нажатии
// кнопки «Загрузить файл» (см. ниже, mode='import').
$importState = tgStateGet($chatId, 'import');
if ($importState !== null && isset($msg['document'])) {
    $importType = (string)($importState['type'] ?? '');
    tgStateClear($chatId, 'import');
    $user = getUser($chatId);
    // Дополнительная страховка: даже если флаг как-то остался в /tmp, файл
    // обработается только если у юзера роль admin/manager.
    $userRole = $user['role'] ?? '';
    $isAdminUploader = in_array($userRole, ['admin', 'manager'], true);
    if ($user && $isAdminUploader && $importType === 'order_file') {
        $fileId = $msg['document']['file_id'] ?? null;
        $fileName = $msg['document']['file_name'] ?? 'file';
        $fileSize = (int)($msg['document']['file_size'] ?? 0);
        // Лимит 5 МБ: Excel-файл заказа реально весит 100-500 КБ. Больше
        // значит ошибка/мусор/архив — рассылать ресторанам нет смысла.
        $maxFileBytes = 5 * 1024 * 1024;
        if ($fileId && $fileSize > $maxFileBytes) {
            $mb = round($fileSize / 1024 / 1024, 1);
            sendMessage($chatId, "❌ Файл слишком большой: <b>{$mb} МБ</b>. Максимум 5 МБ. Проверьте, что это именно Excel-файл заказа.");
            exit;
        }
        // Принимаем только реальные xlsx/xls. Иначе админ может случайно
        // отправить .pdf/.docx/что-угодно — и бот разошлёт мусор всем
        // ресторанам, они попытаются открыть и сломаются.
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileId && !in_array($fileExt, ['xlsx', 'xlsm', 'xls'], true)) {
            sendMessage($chatId, "❌ Поддерживаются только файлы <b>.xlsx</b>, <b>.xlsm</b> или <b>.xls</b>. Получено: <code>" . htmlspecialchars($fileExt ?: 'без расширения', ENT_QUOTES, 'UTF-8') . "</code>.\nЗагрузите Excel-файл заказа.");
            exit;
        }
        if ($fileId) {
            $pdo->prepare("INSERT INTO order_file (file_name, file_path, telegram_file_id, uploaded_by) VALUES (?, '', ?, ?)")
                ->execute([$fileName, $fileId, $user['name']]);

            // Уведомляем подписчиков ресторанов о новом файле — только подтверждённые
            // и не заблокировавшие бота. Имя загрузившего экранируем: если в name
            // случайно окажется <, & или " — parse_mode=HTML отвергнет сообщение и
            // часть ресторанов файл не получит.
            $restSubs = $pdo->query("
                SELECT DISTINCT chat_id FROM ro_telegram_subs
                WHERE verified_at IS NOT NULL
                  AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
            ")->fetchAll(PDO::FETCH_COLUMN);
            $uploaderName = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
            $notifSent = 0;
            foreach ($restSubs as $subCid) {
                if ((string)$subCid === (string)$chatId) continue; // не отправляем загрузившему
                $notifText = "📄 <b>Новый файл заказа</b>\n\nЗагружен: " . date('d.m.Y H:i') . "\nОт: {$uploaderName}";
                $notifPayload = json_encode([
                    'chat_id' => $subCid,
                    'document' => $fileId,
                    'caption' => $notifText,
                    'parse_mode' => 'HTML',
                ]);
                $nch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendDocument");
                curl_setopt_array($nch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $notifPayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                $nres = json_decode(curl_exec($nch), true); curl_close($nch);
                if (!empty($nres['ok'])) $notifSent++;
                // Telegram даёт ~30 msg/sec на бот, для документов ещё ниже.
                // 50мс ≈ 20 msg/sec — безопасно для рассылок 50+ ресторанам.
                usleep(50000);
            }

            $notifInfo = $restSubs ? " Отправлено ресторанам: {$notifSent}." : "";
            sendMessage($chatId, "✅ <b>Файл заказа обновлён</b>\n📄 " . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . "\n\nРестораны теперь могут скачать его через бот.{$notifInfo}", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
            exit;
        }
    }
    tgStateClear($chatId, 'import');
}

// Фото, документы, голос — вежливый ответ
if (!isset($msg['text']) && (isset($msg['photo']) || isset($msg['document']) || isset($msg['voice']) || isset($msg['video']) || isset($msg['sticker']))) {
    $user = getUser($chatId);
    if ($user) {
        sendMessage($chatId, "Я пока умею работать только с текстовыми сообщениями.\n\nПопробуйте задать вопрос текстом, например:\n• <i>Какой остаток молока?</i>\n• <i>Когда приедет Мираторг?</i>");
    }
    exit;
}

$text = trim($msg['text'] ?? '');

// 6-значный код — привязка аккаунта ресторана к Telegram
if (preg_match('/^\d{6}$/', $text)) {
    $code = $text;

    // Rate-limit: 10 неудачных попыток за 1 час с одного chat_id → блок.
    // Без этого 1 млн вариантов угадываются за пару часов через бота.
    $rateKey = 'tg_bind_' . $chatId;
    try {
        // Чистим старые записи и считаем неудачные попытки за окно.
        $pdo->prepare("DELETE FROM failed_login_attempts WHERE user_name = ? AND attempted_at < (NOW() - INTERVAL 1 HOUR)")
            ->execute([$rateKey]);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM failed_login_attempts WHERE user_name = ? AND attempted_at > (NOW() - INTERVAL 1 HOUR)");
        $cntStmt->execute([$rateKey]);
        if ((int)$cntStmt->fetchColumn() >= 10) {
            sendMessage($chatId, "❌ Слишком много неудачных попыток. Подождите час и попробуйте ещё раз. Если кода у вас нет — получите новый в личном кабинете.");
            exit;
        }
    } catch (Throwable $e) { /* если таблицы нет — продолжаем без rate-limit */ }

    // kind='bind' — это именно код привязки Telegram, выданный кабинетом.
    // 64-байтные auth-токены сюда тоже теоретически могли бы прийти при удачном
    // matched-формате, но защищаемся явно.
    $s = $pdo->prepare("SELECT id, telegram_chat_id, restaurant_number, legal_entity_group, ro_user_id FROM ro_tg_tokens WHERE token = ? AND kind = 'bind' AND expires_at > NOW() AND used = 0 LIMIT 1");
    $s->execute([$code]);
    $tok = $s->fetch();
    if ($tok && (int)($tok['telegram_chat_id'] ?? 0) === 0 && !empty($tok['restaurant_number'])) {
        // Атомарно гасим код — защита от ввода одного кода в двух чатах подряд.
        $claim = $pdo->prepare("UPDATE ro_tg_tokens SET used = 1 WHERE id = ? AND used = 0");
        $claim->execute([$tok['id']]);
        if ($claim->rowCount() !== 1) {
            sendMessage($chatId, "❌ Код уже был использован.\n\nПолучите новый код в личном кабинете (Профиль → Telegram → Получить код).");
        } else {
            $restNum = (int)$tok['restaurant_number'];
            $restGroup = ($tok['legal_entity_group'] ?? '') === 'PS' ? 'PS' : 'BK_VM';
            $roUserId = !empty($tok['ro_user_id']) ? (int)$tok['ro_user_id'] : null;
            // Подтверждённая подписка: пишем verified_at + verified_via, дедлайн перепривязки сбрасывается.
            // ro_users.telegram_chat_id больше не трогаем — это ломало других сотрудников ресторана.
            // Источник правды: ro_telegram_subs.
            botEnsureRestaurantSubscription($chatId, $restNum, $msg['from'] ?? [], $roUserId, 'code');
            $prettyRest = botFormatSubscribedRestaurant($restNum, $restGroup);
            sendMessage($chatId, "✅ <b>Telegram привязан!</b>\n\nРесторан {$prettyRest} успешно привязан к вашему Telegram и добавлен в ваши рестораны.\n\nТеперь вы будете получать уведомления о дедлайнах и сможете входить в личный кабинет через бота.");
        }
    } else {
        // Логируем неудачу для rate-limit.
        try {
            $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name) VALUES (?, ?)")
                ->execute(['0.0.0.0', $rateKey]);
        } catch (Throwable $e) {}
        sendMessage($chatId, "❌ Код недействителен или истёк.\n\nПолучите новый код в личном кабинете (Профиль → Telegram → Получить код).");
    }
    exit;
}

// Старая команда /veg удалена: открытая подписка по выбору ресторана отключена.
// Привязка к ресторану теперь возможна только через 6-значный код из личного кабинета.

// /start
if ($text === '/start') {
    tgStateClear($chatId, 'cards');
    $user = getUser($chatId);
    if ($user) {
        $greeting = "Привет, <b>" . tgEsc($user['name']) . "</b>! 👋\n\n";
        sendMessage($chatId, $greeting . getMenuText($user), ['inline_keyboard' => getMenuButtons($user)]);
    } else {
        // Показываем выбор роли: отдел закупок или ресторан
        $keyboard = ['inline_keyboard' => [
            [['text' => '🏢 Отдел закупок', 'callback_data' => 'start_role_purchasing']],
            [['text' => '🏪 Ресторан', 'callback_data' => 'start_role_restaurant']],
        ]];
        sendMessage($chatId, "👋 <b>Supply Department</b>\n\nДобро пожаловать! Выберите, кто вы:", $keyboard);
    }
    exit;
}

// /help или /menu
if ($text === '/help' || $text === '/menu') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    setUserMode($user['name'], null); // сброс режима
    tgStateClear($chatId, 'cards');
    tgStateClear($chatId, 'restord'); // сброс режима ввода заявки
    tgStateClear($chatId, 'soord'); // сброс режима ввода заявки поставщику
    $tips = "\n\n" . botCommandsHelpText()
          . "\n\n💡 <i>Можно также спросить текстом:</i>\n"
          . "• Какой остаток молока?\n"
          . "• Товары с запасом на 3 дня\n"
          . "• Что скоро просрочится?\n"
          . "• Когда доставка в ресторан 45?";
    sendMessage($chatId, getMenuText($user) . $tips, ['inline_keyboard' => getMenuButtons($user)]);
    exit;
}

// /reminders — список своих подписок на напоминания о подаче заявок поставщикам
if ($text === '/reminders') {
    rrShowMyReminders($pdo, $chatId);
    exit;
}

// /today — дашборд на сегодня
if ($text === '/today') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdToday($chatId, $user);
    exit;
}

// /export — экспорт CSV
if ($text === '/export') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdExport($chatId, $user);
    exit;
}

// /restaurant — меню ресторана
if ($text === '/restaurant') {
    if (botCountActiveSubs($chatId) === 0) {
        sendMessage($chatId, botRestaurantLinkInstructions());
    } else {
        restShowMySubs($chatId, null);
    }
    exit;
}

// /analysis — полный анализ запасов
if ($text === '/analysis') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdAnalysis($chatId, $user);
    exit;
}

// /deliveries — ближайшие поставки
if ($text === '/deliveries') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdDeliveries($chatId, $user);
    exit;
}

// /plans — планирование
if ($text === '/plans') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdPlans($chatId, $user);
    exit;
}

// /schedule — график доставок
if ($text === '/schedule') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdSchedule($chatId, $user);
    exit;
}

// /entity — переключение юрлица
if ($text === '/entity') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    $entities = $user['legal_entities'];
    if (count($entities) <= 1) {
        $current = getUserEntity($user);
        sendMessage($chatId, "🏢 Вам доступно одно юрлицо: <b>" . tgEsc($current) . "</b>");
        exit;
    }
    $current = getUserEntity($user);
    $buttons = [];
    foreach ($entities as $idx => $le) {
        $mark = ($le === $current) ? '✅ ' : '';
        $short = getEntityShort($le);
        $buttons[] = [['text' => "{$mark}{$short} — {$le}", 'callback_data' => "entity_{$idx}"]];
    }
    sendMessage($chatId, "🏢 <b>Выбор юрлица</b>\n\nТекущее: <b>" . tgEsc($current) . "</b>\n\nНажмите для переключения:", ['inline_keyboard' => $buttons]);
    exit;
}

// /settings
if ($text === '/settings') {
    $user = getUser($chatId);
    if (!$user) {
        sendMessage($chatId, "❌ Аккаунт не привязан. Отправьте свой email для привязки.");
        exit;
    }
    showSettings($chatId, null, $user['name']);
    exit;
}

// /cards — доступно всем (и ресторанам)
if ($text === '/cards') {
    $user = getUser($chatId);
    cmdCards($chatId, $user);
    exit;
}

// /orders
if ($text === '/orders') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdOrders($chatId, $user);
    exit;
}

// /stock
if ($text === '/stock') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdStock($chatId, $user);
    exit;
}

// /sales
if ($text === '/sales') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdSales($chatId, $user);
    exit;
}

// /consumption
if ($text === '/consumption') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdConsumption($chatId, $user);
    exit;
}

// /prices
if ($text === '/prices') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdPrices($chatId, $user);
    exit;
}

// /psc
if ($text === '/psc') {
    $user = getUser($chatId);
    if (!$user) { sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту."); exit; }
    cmdPsc($chatId, $user);
    exit;
}

// Режим сбора остатков. TTL 30 минут задан в tgStateSet (см. restScStart).
$scState = tgStateGet($chatId, 'sc');
if ($scState !== null) {
    if (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        restScProcessInput($chatId, $text, $userMsgId);
        exit;
    } else {
        tgStateClear($chatId, 'sc');
    }
}

// Режим остатков склада. TTL 30 минут задан в tgStateSet (см. restStockStart).
$stockState = tgStateGet($chatId, 'rest_stock');
if ($stockState !== null) {
    if (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        restStockSearch($chatId, $text, $userMsgId);
        exit;
    } else {
        tgStateClear($chatId, 'rest_stock');
    }
}

// Режим корректировки заказа (доступен и без аккаунта). TTL 30 минут
// выставляется в tgStateSet (см. corrStart в bot_rest.php).
$corrSt = tgStateGet($chatId, 'corr');
if ($corrSt !== null) {
    if (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        corrProcessTextInput($chatId, $text, (string)($corrSt['mode'] ?? 'corr_items'), $userMsgId);
        exit;
    } else {
        tgStateClear($chatId, 'corr');
    }
}

// Режим ввода комментария к опросу
if (!str_starts_with($text, '/')) {
    $userMsgId = $msg['message_id'] ?? null;
    if (surveyProcessComment($chatId, $text, $userMsgId)) exit;
}

// Режим ввода заявки поставщику (Камако и др.). TTL 30 минут задан в
// tgStateSet (см. soOrderShowProducts в bot_rest.php).
$soSt = tgStateGet($chatId, 'soord');
if ($soSt !== null) {
    $isNewSoMode = !empty($soSt['supplier_id']) && !empty($soSt['restaurant_number']) && !empty($soSt['delivery_date']);
    $isLegacySoMode = isset($soSt['mode']) && str_starts_with((string)$soSt['mode'], 'soord_');

    if ($isNewSoMode || $isLegacySoMode) {
        if (str_starts_with($text, '/')) {
            tgStateClear($chatId, 'soord');
        } else {
            tgStateClear($chatId, 'restord');
            $userMsgId = $msg['message_id'] ?? null;
            if ($userMsgId) @deleteMessage($chatId, $userMsgId);
            soOrderProcessInput($chatId, $text);
            exit;
        }
    }
}

// Режим поиска карточек — работает и без привязки аккаунта. TTL не нужен:
// активный режим автоматически прерывается при /команде, иначе хранится
// до явного выхода (раньше /tmp хранил 30 мин).
$cardsState = tgStateGet($chatId, 'cards');
if ($cardsState !== null) {
    if (!str_starts_with($text, '/')) {
        $userMsgId = $msg['message_id'] ?? null;
        $botMsgId = (int)($cardsState['bot_msg_id'] ?? 0) ?: null;
        searchCardDirect($chatId, $text, $userMsgId, $botMsgId);
        exit;
    } else {
        tgStateClear($chatId, 'cards');
    }
}

// Свободный текст — ответ на вопрос
$user = getUser($chatId);
if (!$user) {
    sendMessage($chatId, "🔒 Для доступа к боту нужно привязать Telegram к аккаунту.\n\nНажмите /start чтобы получить ссылку для входа.");
    exit;
}

// Быстрые ответы на приветствия и благодарности — без вызова ИИ
$quickReply = getQuickReply($text, $user);
if ($quickReply) {
    sendMessage($chatId, $quickReply, ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
    exit;
}

// Режим ввода — карточки
$userMode = getUserMode($user['name']);

if ($userMode === 'cards') {
    // Любая /-команда выходит из режима (обработается выше — этот код не должен сюда попасть)
    // На случай необработанных команд:
    if (str_starts_with($text, '/')) {
        setUserMode($user['name'], null);
        // Пусть проваливается в handleFreeText
    } else {
        $userMsgId = $msg['message_id'] ?? null;
        $cardsSt = tgStateGet($chatId, 'cards');
        $botMsgId = (int)($cardsSt['bot_msg_id'] ?? 0) ?: null;
        searchCardDirect($chatId, $text, $userMsgId, $botMsgId);
        exit;
    }
}

handleFreeText($chatId, $text, $user);
