<?php
/**
 * Права в Telegram-боте.
 *
 * Раньше меню закупок было жёстким списком из 18 кнопок, одинаковым для всех:
 * привязанный сотрудник видел цены, ПСЦ, реализацию ресторанов и выгрузки,
 * даже если на портале эти модули ему закрыты. Проверки стояли только на
 * приёмке поставки и корректировках.
 *
 * Здесь одна карта «команда бота → модуль портала + нужный уровень». Её
 * используют оба места: сборка меню (что показать) и обработчик нажатия
 * (что разрешить). Скрыть кнопку мало — по callback_data её можно позвать
 * руками, поэтому проверка обязательна и на действии.
 */

/**
 * Команда бота → [ключ модуля, минимальный уровень, 'admin' если только админам].
 * Ключи модулей — как в $ROLE_TEMPLATES (единственное число: order, а не orders).
 */
const BOT_CMD_ACCESS = [
    'today'             => ['order', 'view'],
    'orders'            => ['order', 'view'],
    'deliveries'        => ['order', 'view'],
    'plans'             => ['planning', 'view'],
    'stock'             => ['analysis', 'view'],
    'consumption'       => ['analysis', 'view'],
    'analysis'          => ['analysis', 'view'],
    'prices'            => ['pricing', 'view'],
    'psc'               => ['pricing', 'view'],
    'sales'             => ['restaurant-sales', 'view'],
    'schedule'          => ['delivery-schedule', 'view'],
    'export'            => ['analysis', 'view'],
    'ro_status'         => ['restaurant-orders', 'view'],
    // Ниже — то, что и раньше было только для админов. Политику не меняем,
    // но теперь кнопка ещё и не показывается остальным.
    'rest_subs_stats'   => ['restaurant-orders', 'view', 'admin'],
    'corrections'       => ['corrections', 'view', 'admin'],
    'ro_send_logins'    => ['restaurant-orders', 'full', 'admin'],
    'upload_order_file' => ['order', 'edit', 'admin'],
];

/**
 * Источники данных для ответов на свободный текст → модуль портала.
 * Без этой карты кнопки были спрятаны по правам, а те же цифры спокойно
 * доставались вопросом словами: «сколько стоит сыр», «реализация по ресторанам».
 */
const BOT_LOOKUP_ACCESS = [
    'lookupProduct'   => ['cards', 'view'],
    'lookupCards'     => ['cards', 'view'],
    'lookupOrders'    => ['order', 'view'],
    'lookupDeliveries'=> ['order', 'view'],
    'lookupStockDays' => ['analysis', 'view'],
    'lookupShelfLife' => ['shelf-life', 'view'],
    'lookupSupplier'  => ['database', 'view'],
    'lookupSchedule'  => ['delivery-schedule', 'view'],
    'lookupPlans'     => ['planning', 'view'],
    'lookupPrices'    => ['pricing', 'view'],
    'lookupSales'     => ['restaurant-sales', 'view'],
];

/** Кнопки внутри «Экспорта» — у каждой выгрузки свой модуль. */
const BOT_EXPORT_ACCESS = [
    'export_analysis' => ['analysis', 'view'],
    'export_orders'   => ['order', 'view'],
    'export_prices'   => ['pricing', 'view'],
];

/**
 * Инструменты ИИ-помощника → модуль портала.
 * Модель получает только доступные человеку инструменты, и они же ещё раз
 * проверяются при выполнении: список у модели — подсказка, а не защита.
 * run_sql ограничен ролью внутри самого инструмента (admin/manager).
 */
const BOT_TOOL_ACCESS = [
    'search_product'    => ['analysis', 'view'],
    'get_stock_critical'=> ['analysis', 'view'],
    'get_summary'       => ['analysis', 'view'],
    'get_orders'        => ['order', 'view'],
    'get_deliveries'    => ['order', 'view'],
    'get_prices'        => ['pricing', 'view'],
    'get_psc'           => ['pricing', 'view'],
    'get_shelf_life'    => ['shelf-life', 'view'],
    'get_supplier_info' => ['database', 'view'],
    'get_schedule'      => ['delivery-schedule', 'view'],
    'get_plans'         => ['planning', 'view'],
    'get_sales'         => ['restaurant-sales', 'view'],
    'get_tenders'       => ['tenders', 'view'],
    'search_card'       => ['cards', 'view'],
];

/** Доступен ли инструмент ИИ этому человеку. */
function botToolAllowed($user, string $tool): bool {
    // Произвольный SELECT — только админу и менеджеру (сам инструмент это тоже
    // проверяет; здесь убираем его из списка, чтобы модель не тратила вызов).
    if ($tool === 'run_sql') return in_array($user['role'] ?? '', ['admin', 'manager'], true);
    $rule = BOT_TOOL_ACCESS[$tool] ?? null;
    if (!$rule) return true;
    return botCan($user, $rule[0], $rule[1]);
}

/** Права пользователя с учётом шаблона роли. Кэш на время запроса. */
function botUserPerms($user) {
    global $pdo, $ROLE_TEMPLATES;
    static $cache = [];
    $name = $user['name'] ?? '';
    if ($name === '') return [];
    if (isset($cache[$name])) return $cache[$name];
    $raw = $user['permissions'] ?? null;
    if ($raw === null) {
        $st = $pdo->prepare("SELECT permissions FROM users WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        $raw = $st->fetchColumn() ?: null;
    }
    return $cache[$name] = resolvePermissions($user['role'] ?? 'user', $raw, $ROLE_TEMPLATES);
}

/** Модули, скрытые пользователем в портале. На доступ не влияют — только на меню. */
function botHiddenModules($user) {
    $raw = $user['hidden_modules'] ?? null;
    if (is_string($raw)) $raw = json_decode($raw, true);
    return is_array($raw) ? $raw : [];
}

/** Есть ли у пользователя нужный уровень доступа к модулю. */
function botCan($user, string $module, string $level = 'view'): bool {
    global $ACCESS_LEVELS;
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    $perms = botUserPerms($user);
    $have = $ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0;
    return $have >= ($ACCESS_LEVELS[$level] ?? 1);
}

/**
 * Разрешена ли команда бота. Команды вне карты (меню, настройки, юрлицо,
 * карточки) доступны всем привязанным — они ничего не показывают из модулей.
 */
function botCmdAllowed($user, string $cmd): bool {
    if (!$user) return false;
    $rule = BOT_CMD_ACCESS[$cmd] ?? null;
    if (!$rule) return true;
    if (($rule[2] ?? '') === 'admin' && ($user['role'] ?? '') !== 'admin') return false;
    return botCan($user, $rule[0], $rule[1]);
}

/** Показывать ли кнопку: право есть И модуль не спрятан самим пользователем. */
function botCmdVisible($user, string $cmd): bool {
    if (!botCmdAllowed($user, $cmd)) return false;
    $rule = BOT_CMD_ACCESS[$cmd] ?? null;
    if (!$rule) return true;
    return !in_array($rule[0], botHiddenModules($user), true);
}

/**
 * Список slash-команд под конкретного человека.
 * Общий список Telegram показывал всем одинаково — рестораны видели два
 * десятка команд закупок, которые им ничего не отвечают.
 */
function botCommandsForUser($user): array {
    $base = [
        ['command' => 'start',      'description' => 'Начать работу'],
        ['command' => 'menu',       'description' => 'Главное меню'],
        ['command' => 'help',       'description' => 'Помощь по командам'],
        ['command' => 'restaurant', 'description' => 'Меню ресторана'],
        ['command' => 'reminders',  'description' => 'Напоминания о заявках'],
        ['command' => 'cards',      'description' => 'Поиск карточек товаров'],
    ];
    if (!$user) return $base;

    $byCmd = [
        'today'       => 'Сводка на сегодня',
        'orders'      => 'Заказы за 7 дней',
        'deliveries'  => 'Ближайшие поставки',
        'plans'       => 'Планирование',
        'stock'       => 'Критичные остатки',
        'analysis'    => 'Анализ запасов',
        'consumption' => 'Топ расхода',
        'prices'      => 'Изменения цен',
        'psc'         => 'Протоколы согласования цен',
        'schedule'    => 'График доставок',
        'sales'       => 'Реализация ресторанов',
        'export'      => 'Выгрузки CSV',
    ];
    foreach ($byCmd as $cmd => $descr) {
        if (botCmdVisible($user, $cmd)) $base[] = ['command' => $cmd, 'description' => $descr];
    }
    $base[] = ['command' => 'entity',   'description' => 'Переключить юрлицо'];
    $base[] = ['command' => 'settings', 'description' => 'Настройки уведомлений'];
    return $base;
}

/**
 * Обновить меню команд Telegram для одного чата.
 * Вызывается при привязке аккаунта и при смене прав — тихо, ошибки не важны.
 */
function botSyncChatCommands($chatId, $user): void {
    $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? (getenv('TELEGRAM_BOT_TOKEN') ?: '');
    if (!$token) return;
    $payload = json_encode([
        'commands' => botCommandsForUser($user),
        'scope'    => ['type' => 'chat', 'chat_id' => (int)$chatId],
        'language_code' => 'ru',
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init("https://api.telegram.org/bot{$token}/setMyCommands");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Отказ в команде отдела закупок. Ресторану не говорим «привяжите аккаунт» —
 * его чат уже привязан, просто к ресторану: показываем, куда идти.
 */
function botDenyPurchasing($chatId): void {
    if (function_exists('botCountActiveSubs') && botCountActiveSubs($chatId) > 0) {
        sendMessage(
            $chatId,
            "Эта команда — для отдела закупок, а ваш чат привязан к ресторану.",
            ['inline_keyboard' => [[['text' => '🏪 Меню ресторана', 'callback_data' => 'start_role_restaurant']]]]
        );
        return;
    }
    sendMessage($chatId, "🔒 Нажмите /start чтобы привязать Telegram к аккаунту.");
}
