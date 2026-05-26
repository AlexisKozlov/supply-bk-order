<?php
/**
 * RPC-эндпоинты (публичные и приватные).
 * Подключается из index.php.
 */

function rpcNormalizeStockCollectionBatches($batches, $allowExpiry = true) {
    $out = [];
    if (!is_array($batches)) return $out;
    foreach ($batches as $batch) {
        if (!is_array($batch)) continue;
        $expiry = trim((string)($batch['expiry_date'] ?? ''));
        $stock = $batch['stock'] ?? null;
        if (!is_numeric($stock)) continue;
        $stockVal = round(floatval($stock), 2);
        if ($stockVal < 0 || $stockVal > 999999) continue;
        if ($allowExpiry && $expiry !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $expiry);
            if (!$dt || $dt->format('Y-m-d') !== $expiry) continue;
            $out[] = ['expiry_date' => $expiry, 'stock' => $stockVal];
        } else {
            $out[] = ['expiry_date' => null, 'stock' => $stockVal];
        }
    }
    return $out;
}

// ═══ RPC (публичные — без API-ключа) ═══
if ($endpoint === 'rpc') {
    $fn = $subpoint ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // --- Публичные RPC (доступны без авторизации) ---
    require __DIR__ . '/rpc/public.php';

    // --- Приватные RPC (требуют авторизацию) ---
    // Список RPC, доступных также по ресторанной сессии (cookie ro_session).
    // Каждый из этих эндпоинтов внутри ещё раз проверяет, кто звонит, через
    // $bugReportCaller() — лишнего доступа не выдаём.
    $RO_ALLOWED_RPC = ['create_bug_report', 'get_bug_reports', 'get_bug_report', 'get_bug_reports_count', 'reply_bug_report', 'create_download_token'];
    if (!checkAuth($pdo)) {
        $roAllowed = false;
        if (in_array($fn, $RO_ALLOWED_RPC, true)) {
            if (!function_exists('roGetRestaurantSession')) {
                require_once __DIR__ . '/restaurant_orders.php';
            }
            if (function_exists('roGetRestaurantSession') && roGetRestaurantSession($pdo)) {
                $roAllowed = true;
            }
        }
        if (!$roAllowed) respond(['error' => 'Unauthorized'], 401);
    }

    // Получаем имя авторизованного пользователя из сессии (для защиты от подмены user_name)
    $authUser = getSessionUser($pdo);
    $authUserName = $authUser ? $authUser['name'] : '';

    // Конфигурация RBAC — единый источник правды для фронтенда
    // ════════════════════════════════════════════════════════════════════
    // Отправка заявки поставщику по email с портала (с фирменного ящика
    // noreply@). Reply-To = info@, чтобы поставщик мог ответить нормально.
    // Логируется в order_email_log для аудита.
    // ════════════════════════════════════════════════════════════════════
    require __DIR__ . "/rpc/email.php";

    // ════════════════════════════════════════════════════════════════════
    // Отправка прогнозного плана поставщику по email с портала
    // (account=order, Reply-To=order@). По аналогии с send_supplier_order_email.
    // Тема: «План для <supplier> от <ЮЛ> на <P1>—<Pn>».
    // Тело — короткий текст со списком периодов; детали в Excel-вложении.
    // ════════════════════════════════════════════════════════════════════

    require __DIR__ . '/rpc/users_rbac.php';

    // Одноразовый download-токен для скачивания файла. Заменяет
    // session_token в ?token=. Живёт 15 минут, пишется в download_tokens,
    // принимается uploads-обработчиками через ?dl=. Принимает file_path
    // (относительно api/uploads/) для аудита; реальная авторизация на
    // конкретный файл всё равно делается uploads.php.

    require __DIR__ . '/rpc/sales.php';

    // ═══ DEFICIT: приватные RPC ═══
    require __DIR__ . '/rpc/deficit.php';

    // ═══ STOCK COLLECTION: приватные RPC ═══
    require __DIR__ . '/rpc/stock_collection.php';

    // ═══ График возврата кег ═══
    require __DIR__ . '/rpc/kr_admin.php';


    require __DIR__ . '/rpc/notifications.php';
    require __DIR__ . '/rpc/online.php';
    // (batch_update_received_qty ушёл в rpc/orders.php)



    require __DIR__ . '/rpc/warehouse_cells.php';

    // (replace_order_items / delete_order / replace_item_order / calculate_adu ушли в rpc/orders.php)



    // ═══ PRICING: импорт залоговых цен (xlsx с листами Сухой/Холод/Мороз) ═══
    require __DIR__ . '/rpc/prices.php';

    // ═══ Тендеры: сохранить тендер целиком ═══
    require __DIR__ . '/rpc/tenders.php';

    // ═══ Рецептуры: импорт из JSON (парсинг на фронте) ═══
    require __DIR__ . '/rpc/pallets.php';
    require __DIR__ . '/rpc/recipes.php';

    // ═══ Паллетовка: импорт справочника ═══
    // (find_recipes_by_names ушёл в rpc/recipes.php вместе с остальными)

    // ═══ Рецептуры: поиск по именам (для автопривязки) ═══

    require __DIR__ . '/rpc/bug_reports.php';


    // Снэпшот цены закупки в позиции заказа. Берёт актуальную цену из
    // product_prices на момент сохранения и проставляет в каждую позицию.
    // Это нужно, чтобы исторические суммы заказов в дашборде/отчётах не
    // «ехали» при последующем изменении прайса.
    require __DIR__ . '/rpc/orders.php';


    // ═══ Распределение новинок (dist_*) ═══

    require __DIR__ . '/rpc/dist.php';

    // ═══ Telegram Bot Admin ═══
    // Все tg_admin_* — только для роли admin: рассылки, webhook, отвязки, статистика.

    require __DIR__ . '/rpc/tg_admin.php';

    // ═══ Корректировки заказов ═══

    require __DIR__ . '/rpc/corrections.php';

    // ═══ Оплаты поставщиков ═══

    require __DIR__ . '/rpc/payments.php';

    require __DIR__ . '/rpc/dashboard.php';

    // ═══ Чат с ресторанами ═══

    require __DIR__ . '/rpc/chat.php';

    require __DIR__ . '/rpc/protocols.php';

    require __DIR__ . '/rpc/surveys.php';

    // ═══ Модуль "График поставок" (supplier-schedule) ═══
    // Управление расписанием подачи заявок и доставок:
    // - supplier_schedules: связка поставщик↔ресторан + дни заказа/доставки
    // - supplier_schedule_deadlines: точечный дедлайн на (поставщик, ресторан, день)
    // - supplier_default_deadlines: дефолтные дедлайны на уровне поставщика (read-only здесь,
    //   редактируется в модуле "Заявки поставщикам" при register-supplier)

    require __DIR__ . '/rpc/schedules.php';

    respond(['error'=>'Not found'], 404);
}
