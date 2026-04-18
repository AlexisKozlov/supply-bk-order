<?php
// ═══ Ресторанные подписки и уведомления ═══
// cmdVegStats, vegShowMySubs, vegShowRestaurants, vegShowSubsManage, vegNotifySubscribers
//
// ═══ Заявки поставщикам (SO) ═══
// soOrderSelectRest, soOrderSelectDay, soOrderShowProducts, soOrderSkipDelivery,
// soShowMyOrders, soShowRestOrders, soOrderProcessInput
//
// ═══ Корректировки заказов ═══
// corrStart, corrShowDelivery, corrProcessTextInput, corrSubmit, corrReview

// ═══ Хелперы для заявок поставщикам ═══

function soGetPlanetaSupplierId() {
    return 'bbbbbbbb-0000-4000-a000-000000000001';
}

function soGetRestaurantContext($pdo, $restNum) {
    $s = $pdo->prepare("
        SELECT id, number, legal_entity_group
        FROM restaurants
        WHERE number = ? AND active = 1
        ORDER BY id
        LIMIT 1
    ");
    $s->execute([(int)$restNum]);
    $rest = $s->fetch();
    if (!$rest) {
        return null;
    }

    $group = $rest['legal_entity_group'] ?: 'BK_VM';
    if ($group === 'PS') {
        $legalEntity = 'ООО "Пицца Стар"';
    } else {
        // Юрлицо BK_VM берём из аккаунта ресторана, не из магических номеров
        $leStmt = $pdo->prepare("SELECT legal_entity FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = 'BK_VM' AND is_active = 1 LIMIT 1");
        $leStmt->execute([(int)$restNum]);
        $legalEntity = $leStmt->fetchColumn() ?: 'ООО "Бургер БК"';
    }

    $rest['legal_entity'] = $legalEntity;
    return $rest;
}

function botGetRestaurantGroupByNumber($pdo, $restNum) {
    $s = $pdo->prepare("
        SELECT legal_entity_group
        FROM restaurants
        WHERE number = ? AND active = 1
        ORDER BY id
        LIMIT 1
    ");
    $s->execute([(int)$restNum]);
    $group = $s->fetchColumn();
    if ($group) {
        return $group;
    }
    return ((int)$restNum >= 1000) ? 'PS' : 'BK_VM';
}

function botGetRestaurantGroupTitle($group) {
    return $group === 'PS' ? 'Пицца Стар' : 'Бургер БК';
}

function botGetRestaurantGroupShort($group) {
    return $group === 'PS' ? 'ПС' : 'БК';
}

function botFormatSubscribedRestaurant($restaurantNumber, $group) {
    return formatRestaurantNumber($restaurantNumber) . ' (' . botGetRestaurantGroupShort($group) . ')';
}

function botGetSubscribedRestaurants($pdo, $chatId) {
    $s = $pdo->prepare("
        SELECT vs.restaurant_number, r.address, r.city, r.legal_entity_group
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.active = 1
        WHERE vs.chat_id = ?
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED), r.id
    ");
    $s->execute([$chatId]);
    $rows = $s->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $restNum = (string)($row['restaurant_number'] ?? '');
        if ($restNum === '' || isset($out[$restNum])) {
            continue;
        }
        $row['legal_entity_group'] = $row['legal_entity_group'] ?: botGetRestaurantGroupByNumber($pdo, $restNum);
        $out[$restNum] = $row;
    }

    return array_values($out);
}

function soGetSupplierName($pdo, $supplierId) {
    $s = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
    $s->execute([$supplierId]);
    return $s->fetchColumn() ?: 'Поставщик';
}

// Экранирование текста для HTML-сообщений Telegram (parse_mode=HTML).
// Названия товаров/поставщиков с символами <, >, & ломают парсер без этого.
function soEsc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function soGetSupplierSettingsBot($pdo, $supplierId) {
    $s = $pdo->prepare("SELECT supplier_id, is_accepting_orders, default_deadline_time, pause_message FROM so_supplier_settings WHERE supplier_id = ?");
    $s->execute([$supplierId]);
    $row = $s->fetch();
    if ($row) {
        return $row;
    }
    return [
        'supplier_id' => $supplierId,
        'is_accepting_orders' => 1,
        'default_deadline_time' => '14:00:00',
        'pause_message' => null,
    ];
}

/** Проверка дедлайна заявки (open/closed) */
function soBotCheckDeadline($pdo, $supplierId, $deliveryDate) {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');

    // 1. Переопределение на конкретную дату
    try {
        $s = $pdo->prepare("SELECT deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
        $s->execute([$supplierId, $deliveryDate]);
        $override = $s->fetch();
    } catch (PDOException $e) {
        $s = $pdo->prepare("SELECT deadline_time FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
        $s->execute([$supplierId, $deliveryDate]);
        $override = $s->fetch();
    }

    if ($override && !empty($override['is_closed'])) {
        return ['status' => 'closed', 'deadline' => null];
    }

    // 2. Правило по дню недели
    $rule = null;
    if ($supplierId) {
        $r = $pdo->prepare("SELECT deadline_dow, deadline_time FROM so_deadline_rules WHERE supplier_id = ? AND delivery_dow = ?");
        $r->execute([$supplierId, $deliveryDow]);
        $rule = $r->fetch();
    }

    // 3. Вычисляем дедлайн
    if ($override && !empty($override['deadline_time'])) {
        $deadlineDate = (new DateTime($deliveryDate, $tz))->modify('-1 day');
        $deadlineTime = $override['deadline_time'];
    } elseif ($rule) {
        $deadlineDow = (int)$rule['deadline_dow'];
        $deadlineTime = $rule['deadline_time'];
        $deliveryObj = new DateTime($deliveryDate, $tz);
        $deadlineDate = clone $deliveryObj;
        $diff = $deliveryDow - $deadlineDow;
        if ($diff <= 0) $diff += 7;
        $deadlineDate->modify("-{$diff} days");
    } else {
        $settings = soGetSupplierSettingsBot($pdo, $supplierId);
        $deadlineDate = (new DateTime($deliveryDate, $tz))->modify('-1 day');
        $deadlineTime = $settings['default_deadline_time'] ?? '14:00:00';
    }

    $deadlineDT = new DateTime($deadlineDate->format('Y-m-d') . ' ' . $deadlineTime, $tz);
    $deadlineStr = $deadlineDate->format('Y-m-d') . ' ' . substr($deadlineTime, 0, 5);

    if ($now < $deadlineDT) return ['status' => 'open', 'deadline' => $deadlineStr];
    return ['status' => 'closed', 'deadline' => $deadlineStr];
}

function soBotGetWebLink($pdo, $chatId, $supplierId, $restNum) {
    $restGroup = botGetRestaurantGroupByNumber($pdo, $restNum);
    $checkUser = $pdo->prepare("SELECT 1 FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
    $checkUser->execute([$restNum, $restGroup]);
    if (!$checkUser->fetch()) {
        return null;
    }

    $tgToken = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO ro_tg_tokens (token, telegram_chat_id, restaurant_number, legal_entity_group, expires_at, used)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)
    ")->execute([$tgToken, $chatId, $restNum, $restGroup]);

    $siteUrl = rtrim($_ENV['SITE_URL'] ?? (getenv('SITE_URL') ?: 'https://supply-department.online'), '/');
    $redirect = '/restaurant/orders/supplier/' . urlencode($supplierId);
    return "{$siteUrl}/restaurant/login?tg_token={$tgToken}&redirect=" . urlencode($redirect);
}

function soGetBotAvailableDates($pdo, $supplierId, $restNum) {
    $rest = soGetRestaurantContext($pdo, $restNum);
    if (!$rest) {
        return ['rest' => null, 'schedule' => [], 'available_dates' => [], 'settings' => soGetSupplierSettingsBot($pdo, $supplierId)];
    }

    $sch = $pdo->prepare("
        SELECT order_day, delivery_day
        FROM so_supplier_schedules
        WHERE supplier_id = ? AND restaurant_id = ? AND is_active = 1
        ORDER BY order_day
    ");
    $sch->execute([$supplierId, $rest['id']]);
    $schedule = $sch->fetchAll();

    $settings = soGetSupplierSettingsBot($pdo, $supplierId);
    if ((int)($settings['is_accepting_orders'] ?? 1) !== 1 || empty($schedule)) {
        return ['rest' => $rest, 'schedule' => $schedule, 'available_dates' => [], 'settings' => $settings];
    }

    $tz = new DateTimeZone('Europe/Minsk');
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0, 0);
    $weekStart = clone $today;
    $weekStart->modify('-' . ((int)$today->format('N') - 1) . ' days');
    $dayNamesFull = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];

    $availableDates = [];
    foreach ($schedule as $sc) {
        $orderDow = (int)$sc['order_day'];
        $deliveryDow = (int)$sc['delivery_day'];

        for ($w = 0; $w < 2; $w++) {
            $orderDateObj = (clone $weekStart)->modify('+' . ($orderDow - 1 + $w * 7) . ' days');
            $deliveryDateObj = (clone $weekStart)->modify('+' . ($deliveryDow - 1 + $w * 7) . ' days');
            if ($deliveryDow <= $orderDow) {
                $deliveryDateObj->modify('+7 days');
            }
            if ($deliveryDateObj < $today) {
                continue;
            }

            $deliveryDate = $deliveryDateObj->format('Y-m-d');
            $deadlineInfo = soBotCheckDeadline($pdo, $supplierId, $deliveryDate);

            $os = $pdo->prepare("
                SELECT o.id, o.status, o.submitted_at,
                       (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id AND COALESCE(admin_qty, quantity) > 0) as item_count
                FROM so_orders o
                WHERE o.supplier_id = ? AND o.restaurant_number = ? AND o.delivery_date = ?
                LIMIT 1
            ");
            $os->execute([$supplierId, $restNum, $deliveryDate]);
            $order = $os->fetch();

            $availableDates[] = [
                'order_date' => $orderDateObj->format('Y-m-d'),
                'order_day_name' => $dayNamesFull[$orderDow] ?? '',
                'delivery_date' => $deliveryDate,
                'delivery_day_name' => $dayNamesFull[$deliveryDow] ?? '',
                'deadline' => $deadlineInfo['deadline'],
                'deadline_status' => $deadlineInfo['status'],
                'order' => $order ? [
                    'id' => (int)$order['id'],
                    'status' => $order['status'],
                    'submitted_at' => $order['submitted_at'],
                    'item_count' => (int)$order['item_count'],
                    'is_skip' => ((int)$order['item_count']) === 0,
                ] : null,
            ];
        }
    }

    $seen = [];
    $availableDates = array_values(array_filter($availableDates, function ($dateInfo) use (&$seen) {
        if (isset($seen[$dateInfo['delivery_date']])) {
            return false;
        }
        $seen[$dateInfo['delivery_date']] = true;
        return true;
    }));

    usort($availableDates, function ($a, $b) {
        return strcmp($a['delivery_date'], $b['delivery_date']);
    });

    // Формируем итоговый список: до 2 «открытых/с заказом» дат + 1 ближайший закрытый
    // без заявки (для информации: «приём уже прошёл»).
    $openDates = [];
    $closedInfoDate = null;
    foreach ($availableDates as $d) {
        $isClosedEmpty = ($d['deadline_status'] === 'closed') && empty($d['order']);
        if ($isClosedEmpty) {
            if ($closedInfoDate === null) $closedInfoDate = $d;
        } else {
            if (count($openDates) < 2) $openDates[] = $d;
        }
    }
    $finalDates = $openDates;
    if ($closedInfoDate !== null) $finalDates[] = $closedInfoDate;
    usort($finalDates, fn($a, $b) => strcmp($a['delivery_date'], $b['delivery_date']));

    return ['rest' => $rest, 'schedule' => $schedule, 'available_dates' => $finalDates, 'settings' => $settings];
}

function soBotRestaurantHasDeliveryDate($pdo, $supplierId, $restNum, $deliveryDate) {
    $rest = soGetRestaurantContext($pdo, $restNum);
    if (!$rest || !$deliveryDate) {
        return false;
    }

    $sch = $pdo->prepare("
        SELECT order_day, delivery_day
        FROM so_supplier_schedules
        WHERE supplier_id = ? AND restaurant_id = ? AND is_active = 1
    ");
    $sch->execute([$supplierId, $rest['id']]);
    $schedule = $sch->fetchAll();
    if (!$schedule) {
        return false;
    }

    $tz = new DateTimeZone('Europe/Minsk');
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0, 0);
    $weekStart = clone $today;
    $weekStart->modify('-' . ((int)$today->format('N') - 1) . ' days');

    foreach ($schedule as $sc) {
        $orderDow = (int)$sc['order_day'];
        $deliveryDow = (int)$sc['delivery_day'];
        for ($w = 0; $w < 2; $w++) {
            $deliveryDateObj = (clone $weekStart)->modify('+' . ($deliveryDow - 1 + $w * 7) . ' days');
            if ($deliveryDow <= $orderDow) {
                $deliveryDateObj->modify('+7 days');
            }
            if ($deliveryDateObj < $today) {
                continue;
            }
            if ($deliveryDateObj->format('Y-m-d') === $deliveryDate) {
                return true;
            }
        }
    }
    return false;
}

// ═══ Овощи: статистика подписок (для админа) ═══

function cmdVegStats($chatId, $msgId) {
    global $pdo;

    // Все подписки
    $subs = $pdo->query("SELECT vs.restaurant_number, vs.chat_id, vs.created_at
        FROM veg_telegram_subs vs
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)")->fetchAll();

    // Все активные рестораны
    $allRests = $pdo->query("SELECT number, address, city, legal_entity_group FROM restaurants WHERE active = 1 ORDER BY CAST(number AS UNSIGNED), legal_entity_group")->fetchAll();
    $restInfo = [];
    foreach ($allRests as $r) $restInfo[$r['number']] = $r;

    // Группировка подписок по ресторану
    $subsByRest = [];
    $totalSubs = 0;
    foreach ($subs as $s) {
        $subsByRest[$s['restaurant_number']][] = $s;
        $totalSubs++;
    }

    $subscribedCount = count($subsByRest);
    $totalRests = count($allRests);
    $notSubscribed = [];
    foreach ($allRests as $r) {
        if (!isset($subsByRest[$r['number']])) {
            $notSubscribed[] = $r;
        }
    }

    $text = "🏪 <b>Подписки ресторанов на бот</b>\n\n";
    $text .= "📊 <b>Статистика:</b>\n";
    $text .= "  • Подписчиков всего: <b>{$totalSubs}</b>\n";
    $text .= "  • Ресторанов с подпиской: <b>{$subscribedCount}</b> / {$totalRests}\n";
    $text .= "  • Без подписки: <b>" . count($notSubscribed) . "</b>\n\n";

    // Подписанные рестораны
    if ($subsByRest) {
        $text .= "✅ <b>Подписаны:</b>\n";
        foreach ($subsByRest as $restNum => $restSubs) {
            $cnt = count($restSubs);
            $addr = isset($restInfo[$restNum]) ? mb_substr($restInfo[$restNum]['address'] ?: $restInfo[$restNum]['city'], 0, 30) : '';
            $prettyRn = botFormatSubscribedRestaurant($restNum, $restInfo[$restNum]['legal_entity_group'] ?? 'BK_VM');
            $text .= "  {$prettyRn} ({$cnt} чел.) — {$addr}\n";
        }
        $text .= "\n";
    }

    // Не подписанные
    if ($notSubscribed) {
        $text .= "❌ <b>Без подписки:</b>\n";
        $shown = 0;
        foreach ($notSubscribed as $r) {
            if ($shown >= 20) {
                $text .= "  <i>...и ещё " . (count($notSubscribed) - 20) . "</i>\n";
                break;
            }
            $addr = mb_substr($r['address'] ?: $r['city'], 0, 30);
            $prettyRn = botFormatSubscribedRestaurant($r['number'], $r['legal_entity_group'] ?? 'BK_VM');
            $text .= "  {$prettyRn} — {$addr}\n";
            $shown++;
        }
    }

    // Обрезаем если длинное
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…<i>обрезано</i>";
    }

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
}

// ═══ Овощи: функции подписки ═══

function vegShowMySubs($chatId, $msgId = null) {
    global $pdo;
    // Сброс режимов
    @unlink(sys_get_temp_dir() . "/cards_mode_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/corr_data_{$chatId}.json");
    @unlink(sys_get_temp_dir() . "/rest_stock_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/sc_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/sc_data_{$chatId}.json");
    @unlink(sys_get_temp_dir() . "/chat_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/soord_{$chatId}.txt");

    $subs = botGetSubscribedRestaurants($pdo, $chatId);
    $btns = [];

    if ($subs) {
        $restList = implode(', ', array_map(
            fn($sub) => botFormatSubscribedRestaurant($sub['restaurant_number'], $sub['legal_entity_group']),
            $subs
        ));

        // Статистика для приветствия
        $activeSc = $pdo->query("SELECT id, name FROM stock_collections WHERE status = 'active' LIMIT 1")->fetch();
        $orderFile = $pdo->query("SELECT file_name, uploaded_at FROM order_file ORDER BY id DESC LIMIT 1")->fetch();

        // Считаем непрочитанные корректировки
        $pendingCorr = 0;
        foreach ($subs as $sub) {
            $pc = $pdo->prepare("SELECT COUNT(*) FROM order_corrections WHERE restaurant_number = ? AND status IN ('approved','rejected') AND reviewed_at > NOW() - INTERVAL 24 HOUR");
            $pc->execute([$sub['restaurant_number']]);
            $pendingCorr += intval($pc->fetchColumn());
        }

        $text = "🍔 <b>Supply Department</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "🏪 Рестораны: <b>{$restList}</b>\n";

        if ($pendingCorr > 0) {
            $text .= "📬 Новых ответов на корректировки: <b>{$pendingCorr}</b>\n";
        }
        $text .= "\n";

        // ── Разделы ──
        $text .= "🏠 <b>Кабинет</b> — заказы, заявки, профиль\n";
        $text .= "📦 <b>Поставки</b> — график, корректировки\n";
        $text .= "🛒 <b>Заявки поставщикам</b> — Планета Ресторанов и другие\n";
        if ($activeSc) $text .= "📋 <b>Сбор остатков</b> — {$activeSc['name']}\n";

        $btns[] = [
            ['text' => '🏠 Личный кабинет', 'callback_data' => 'rest_cabinet'],
        ];
        $btns[] = [
            ['text' => '📦 Поставки', 'callback_data' => 'rest_menu_main'],
            ['text' => '🛒 Заказы', 'callback_data' => 'rest_ro_orders'],
        ];
        $btns[] = [['text' => '🛒 Заявки поставщикам', 'callback_data' => 'rest_menu_supplier']];
        if ($activeSc) {
            $btns[] = [['text' => "📋 Сбор остатков", 'callback_data' => 'rest_sc_start']];
        }

        // ── Инструменты ──
        $btns[] = [
            ['text' => '🔍 Карточки', 'web_app' => ['url' => 'https://supply-department.online/search-cards']],
            ['text' => '💬 Чат', 'callback_data' => 'chat_start'],
        ];

        if ($orderFile) {
            $updDate = date('d.m H:i', strtotime($orderFile['uploaded_at']));
            $btns[] = [['text' => "📄 Файл заказа ({$updDate})", 'callback_data' => 'rest_order_file']];
        }
    } else {
        $text = "🍔 <b>Supply Department</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "Добро пожаловать!\nПодпишитесь на ресторан для доступа.\n";

        $btns[] = [['text' => '🔍 Поиск карточек', 'web_app' => ['url' => 'https://supply-department.online/search-cards']]];
    }

    if ($subs) {
        $btns[] = [
            ['text' => '🔔 Подписки', 'callback_data' => 'veg_my_subs_manage'],
            ['text' => '⚙️ Уведомления', 'callback_data' => 'rest_notif_settings'],
        ];
        $btns[] = [['text' => '➕ Добавить ресторан', 'callback_data' => 'veg_pick_rest']];
    } else {
        $btns[] = [['text' => '➕ Подписаться на ресторан', 'callback_data' => 'veg_pick_rest']];
    }

    $markup = ['inline_keyboard' => $btns];
    if ($msgId) editMessage($chatId, $msgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
}

function vegShowRestaurantGroups($chatId, $msgId = null) {
    global $pdo;

    $counts = [];
    $s = $pdo->query("
        SELECT legal_entity_group, COUNT(*) AS cnt
        FROM restaurants
        WHERE active = 1 AND legal_entity_group IN ('BK_VM', 'PS')
        GROUP BY legal_entity_group
    ");
    foreach ($s->fetchAll() as $row) {
        $counts[$row['legal_entity_group']] = (int)$row['cnt'];
    }

    $text = "🏪 <b>Выберите юрлицо</b>\n\n";
    $text .= "Сначала выберите, к какому юрлицу относится ресторан:";

    $btns = [
        [['text' => '🍔 Бургер БК' . (!empty($counts['BK_VM']) ? " ({$counts['BK_VM']})" : ''), 'callback_data' => 'veg_pick_group:BK_VM']],
        [['text' => '🍕 Пицца Стар' . (!empty($counts['PS']) ? " ({$counts['PS']})" : ''), 'callback_data' => 'veg_pick_group:PS']],
        [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
    ];

    $markup = ['inline_keyboard' => $btns];
    if ($msgId) editMessage($chatId, $msgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
}

// Подменю: Основные поставки
function restMenuMain($chatId, $msgId) {
    $text = "📦 <b>Основные поставки</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "📅 <b>График</b> — когда и во сколько доставки\n";
    $text .= "✏️ <b>Корректировка</b> — добавить или убрать товар\n";
    $text .= "📦 <b>Остатки</b> — что есть на складе";

    $btns = [
        [
            ['text' => '📅 График', 'callback_data' => 'rest_schedule'],
            ['text' => '✏️ Корректировка', 'callback_data' => 'corr_start'],
        ],
        [
            ['text' => '📦 Остатки склада', 'callback_data' => 'rest_stock'],
            ['text' => '🛒 Мои заказы', 'callback_data' => 'rest_ro_orders'],
        ],
        [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Подменю: Заявки поставщикам
function restMenuSupplier($chatId, $msgId) {
    global $pdo;
    // Находим рестораны пользователя
    $s = $pdo->prepare("SELECT restaurant_number FROM veg_telegram_subs WHERE chat_id = ? ORDER BY CAST(restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $restNums = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!$restNums) {
        editMessage($chatId, $msgId, "Сначала подпишитесь на ресторан.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }
    // Находим поставщиков с расписанием для этих ресторанов
    $rIds = $pdo->prepare("SELECT id, number FROM restaurants WHERE number IN (" . implode(',', array_map('intval', $restNums)) . ") AND active = 1");
    $rIds->execute();
    $restMap = [];
    foreach ($rIds->fetchAll() as $r) $restMap[$r['id']] = $r['number'];
    if (empty($restMap)) {
        editMessage($chatId, $msgId, "Рестораны не найдены.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }
    $rIdList = implode(',', array_keys($restMap));
    $sups = $pdo->query("SELECT DISTINCT s.id, s.short_name FROM so_supplier_schedules ss JOIN suppliers s ON s.id = ss.supplier_id AND s.is_active = 1 WHERE ss.restaurant_id IN ({$rIdList}) AND ss.is_active = 1 ORDER BY s.short_name")->fetchAll();

    $text = "📦 <b>Заявки поставщикам</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    if (!$sups) {
        $text .= "Нет доступных поставщиков.";
        editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }
    // Если поставщик один — сразу к нему
    if (count($sups) === 1) {
        soOrderSelectRest($chatId, $msgId, $sups[0]['id']);
        return;
    }
    $text .= "Выберите поставщика:\n";
    $btns = [];
    foreach ($sups as $sup) {
        $btns[] = [['text' => $sup['short_name'], 'callback_data' => 'soord_sup_' . substr($sup['id'], 0, 36)]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Камако: выбор ресторана
function soOrderSelectRest($chatId, $msgId, $supplierId) {
    global $pdo;
    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");
    $subs = botGetSubscribedRestaurants($pdo, $chatId);
    $restNums = array_column($subs, 'restaurant_number');
    $supName = soGetSupplierName($pdo, $supplierId);

    if (!$restNums) {
        editMessage($chatId, $msgId, "Сначала подпишитесь на ресторан.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    if (count($restNums) === 1) {
        soOrderSelectDay($chatId, $msgId, $supplierId, $restNums[0]);
        return;
    }
    $text = "📦 <b>" . soEsc($supName) . "</b>\n\nВыберите ресторан:";
    $btns = [];
    foreach ($subs as $sub) {
        $rn = $sub['restaurant_number'];
        $label = botFormatSubscribedRestaurant($rn, $sub['legal_entity_group']);
        $btns[] = [['text' => $label, 'callback_data' => "soord_rest_{$supplierId}_{$rn}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_menu_supplier']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Камако: выбор дня
function soOrderSelectDay($chatId, $msgId, $supplierId, $restNum) {
    global $pdo;
    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");
    $supName = soGetSupplierName($pdo, $supplierId);
    $botData = soGetBotAvailableDates($pdo, $supplierId, $restNum);
    $rest = $botData['rest'];
    $settings = $botData['settings'];

    if (!$rest) {
        editMessage($chatId, $msgId, "Ресторан не найден.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    $dayNamesFull = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];

    $btns = [];
    $text = "📦 <b>" . soEsc($supName) . "</b> — Ресторан " . soEsc($restNum) . "\n\nВыберите день доставки:\n";

    foreach ($botData['available_dates'] as $dateInfo) {
        $deliveryDate = $dateInfo['delivery_date'];
        $deliveryDateObj = new DateTime($deliveryDate);
        $deliveryDow = (int)$deliveryDateObj->format('N');
        $dayLabel = $dayNamesFull[$deliveryDow] . ', ' . $deliveryDateObj->format('d.m');
        $hasOrder = !empty($dateInfo['order']);
        $isClosed = ($dateInfo['deadline_status'] ?? 'open') === 'closed';

        if ($hasOrder) {
            $mark = !empty($dateInfo['order']['is_skip']) ? ' 🚫' : ' ✅';
        } elseif ($isClosed) {
            $mark = ' ⏱';
        } else {
            $mark = '';
        }

        // Закрытый день без заявки — показываем только для информации (без ввода).
        if ($isClosed && !$hasOrder) {
            $btnLabel = '⏱ Приём завершён — ' . $dayLabel;
            $btns[] = [['text' => $btnLabel, 'callback_data' => "soord_closed_{$supplierId}_{$restNum}_{$deliveryDate}"]];
        } else {
            $btns[] = [['text' => $dayLabel . $mark, 'callback_data' => "soord_day_{$supplierId}_{$restNum}_{$deliveryDate}"]];
        }
    }

    if (empty($btns)) {
        if ((int)($settings['is_accepting_orders'] ?? 1) !== 1) {
            $pauseMessage = trim((string)($settings['pause_message'] ?? ''));
            $text .= "\nСейчас приём заявок закрыт." . ($pauseMessage ? "\n\n{$pauseMessage}" : '');
        } elseif (empty($botData['schedule'])) {
            $text .= "\nДля этого ресторана не настроен график поставок.";
        } else {
            $text .= "\nНет доступных дней для заявки.";
        }
    }

    $webUrl = soBotGetWebLink($pdo, $chatId, $supplierId, $restNum);
    if ($webUrl) {
        $btns[] = [['text' => '🌐 Через сайт', 'web_app' => ['url' => $webUrl]]];
    }

    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_menu_supplier']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Камако: показ товаров для ввода
function soOrderShowProducts($chatId, $msgId, $supplierId, $restNum, $deliveryDate) {
    global $pdo;
    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");
    $supName = soGetSupplierName($pdo, $supplierId);
    $rest = soGetRestaurantContext($pdo, $restNum);
    if (!$rest) {
        editMessage($chatId, $msgId, "Ресторан не найден.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }
    if (!soBotRestaurantHasDeliveryDate($pdo, $supplierId, $restNum, $deliveryDate)) {
        editMessage($chatId, $msgId, "Для этой даты нет настроенной поставки.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]]]]);
        return;
    }
    $le = $rest['legal_entity'];

    // Загружаем товары из шаблона (с учётом юрлица)
    $tpl = $pdo->prepare("SELECT product_id, sku, product_name, multiplicity, min_qty FROM so_templates WHERE supplier_id = ? AND legal_entity = ? AND is_active = 1 ORDER BY sort_order, product_name");
    $tpl->execute([$supplierId, $le]);
    $products = $tpl->fetchAll();

    if (!$products) {
        editMessage($chatId, $msgId, "📦 <b>" . soEsc($supName) . "</b>\n🏢 " . soEsc($le) . "\n\nНет товаров в шаблоне.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_menu_supplier']]]]);
        return;
    }

    // Существующий заказ
    $existingQty = [];
    $eo = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
    $eo->execute([$supplierId, $restNum, $deliveryDate, $le]);
    $order = $eo->fetch();
    if ($order) {
        $ei = $pdo->prepare("SELECT sku, COALESCE(admin_qty, quantity) as effective_qty FROM so_order_items WHERE order_id = ?");
        $ei->execute([$order['id']]);
        foreach ($ei->fetchAll() as $item) {
            $existingQty[$item['sku']] = $item['effective_qty'];
        }
    }

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    $d = new DateTime($deliveryDate);
    $dow = (int)$d->format('N');
    $dateLabel = $dayNames[$dow] . ', ' . $d->format('d.m');

    $hasExisting = !empty($existingQty);

    // Предыдущая заявка — последняя submitted/locked этого ресторана у этого поставщика
    $prevOrder = null;
    if (!$hasExisting) {
        $prev = $pdo->prepare("
            SELECT id, delivery_date FROM so_orders
            WHERE supplier_id = ? AND restaurant_number = ? AND legal_entity = ?
              AND status IN ('submitted','locked') AND delivery_date < ?
            ORDER BY delivery_date DESC LIMIT 1
        ");
        $prev->execute([$supplierId, $restNum, $le, $deliveryDate]);
        $prevRow = $prev->fetch();
        if ($prevRow) {
            $prevItemsStmt = $pdo->prepare("SELECT product_name, COALESCE(admin_qty, quantity) AS qty FROM so_order_items WHERE order_id = ? AND COALESCE(admin_qty, quantity) > 0 ORDER BY product_name");
            $prevItemsStmt->execute([$prevRow['id']]);
            $prevOrder = ['delivery_date' => $prevRow['delivery_date'], 'items' => $prevItemsStmt->fetchAll()];
        }
    }

    $text = "📦 <b>" . soEsc($supName) . "</b>\n";
    $text .= "🏢 " . soEsc($le) . "\n";
    $text .= "Ресторан: <b>" . soEsc($restNum) . "</b>\n";
    $text .= "Доставка: <b>{$dateLabel}</b>\n\n";

    // Показываем текущую заявку, если есть
    if ($hasExisting) {
        $text .= "✅ <b>Ваша текущая заявка:</b>\n";
        foreach ($products as $p) {
            $qty = $existingQty[$p['sku']] ?? 0;
            if ($qty > 0) {
                $text .= "• " . soEsc($p['product_name']) . ": <b>{$qty}</b>\n";
            }
        }
        $text .= "\nЧтобы изменить — скопируйте шаблон ниже, измените числа и отправьте:\n\n";
    } else {
        if ($prevOrder) {
            $prevDateObj = new DateTime($prevOrder['delivery_date']);
            $prevDateLabel = $prevDateObj->format('d.m');
            $text .= "📋 <b>Ваша предыдущая заявка от {$prevDateLabel}:</b>\n";
            foreach ($prevOrder['items'] as $it) {
                $text .= "• " . soEsc($it['product_name']) . ": <b>" . (0 + (float)$it['qty']) . "</b>\n";
            }
            $text .= "\nСкопируйте шаблон ниже, впишите количества и отправьте:\n\n";
        } else {
            $text .= "Скопируйте, измените количества и отправьте:\n\n";
        }
    }

    $text .= "<code>";
    foreach ($products as $p) {
        $qty = $existingQty[$p['sku']] ?? 0;
        $hint = '';
        if ($p['multiplicity'] && $p['multiplicity'] > 1) $hint = " (кр.{$p['multiplicity']})";
        if ($p['min_qty'] && $p['min_qty'] > 0) $hint .= " (мин.{$p['min_qty']})";
        $text .= soEsc($p['product_name']) . "{$hint}: {$qty}\n";
    }
    $text .= "</code>";

    // Сохраняем режим ввода
    $modeData = json_encode([
        'supplier_id' => $supplierId,
        'restaurant_number' => (string)$restNum,
        'delivery_date' => $deliveryDate,
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents(sys_get_temp_dir() . "/soord_{$chatId}.txt", $modeData);

    $btns = [
        [['text' => '🚫 Поставка не нужна', 'callback_data' => "soord_skip_{$supplierId}_{$restNum}_{$deliveryDate}"]],
        [['text' => '◂ Назад', 'callback_data' => "soord_day_{$supplierId}_{$restNum}_back"]],
    ];
    if ($msgId) editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
    else sendMessage($chatId, $text, ['inline_keyboard' => $btns]);
}

// Камако: «Поставка не нужна» — создаём пустую заявку-отказ
function soOrderSkipDelivery($chatId, $msgId, $supplierId, $restNum, $deliveryDate) {
    global $pdo;
    $supName = soGetSupplierName($pdo, $supplierId);
    $rest = soGetRestaurantContext($pdo, $restNum);
    if (!$rest) {
        editMessage($chatId, $msgId, "Ресторан не найден.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    $settings = soGetSupplierSettingsBot($pdo, $supplierId);
    if ((int)($settings['is_accepting_orders'] ?? 1) !== 1) {
        $msg = $settings['pause_message'] ?: 'Приём заявок для этого поставщика временно приостановлен.';
        editMessage($chatId, $msgId, "❌ {$msg}", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]]]]);
        return;
    }

    if (!soBotRestaurantHasDeliveryDate($pdo, $supplierId, $restNum, $deliveryDate)) {
        editMessage($chatId, $msgId, "❌ Для ресторана не настроена поставка на эту дату.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]]]]);
        return;
    }

    $dlStatus = soBotCheckDeadline($pdo, $supplierId, $deliveryDate);
    if ($dlStatus['status'] === 'closed') {
        editMessage($chatId, $msgId, "❌ Приём заявок на эту дату закрыт" . ($dlStatus['deadline'] ? " (дедлайн: {$dlStatus['deadline']})" : '') . ".", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]]]]);
        return;
    }
    $le = $rest['legal_entity'];

    // Сохраняем заявку-отказ (без позиций)
    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
        $old->execute([$supplierId, $restNum, $deliveryDate, $le]);
        $oldOrder = $old->fetch();
        $isUpdate = false;
        if ($oldOrder) {
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$oldOrder['id']]);
            $pdo->prepare("UPDATE so_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$oldOrder['id']]);
            $isUpdate = true;
        } else {
            $pdo->prepare("INSERT INTO so_orders (supplier_id, restaurant_number, delivery_date, order_date, status, submitted_at, legal_entity) VALUES (?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                ->execute([$supplierId, $restNum, $deliveryDate, $le]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        editMessage($chatId, $msgId, "❌ Ошибка сохранения: " . soEsc($e->getMessage()), ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]]]]);
        return;
    }

    // Уведомление другим подписчикам этого ресторана (кто не нажимал сам)
    try {
        $deliveryFmt = (new DateTime($deliveryDate))->format('d.m.Y');
        $title = $isUpdate ? '🚫 <b>Поставка отменена</b>' : '🚫 <b>Поставка не нужна</b>';
        $msg = $title . "\n\n";
        $msg .= "🏪 <b>Поставщик:</b> " . soEsc($supName) . "\n";
        $msg .= "📅 <b>Доставка:</b> " . $deliveryFmt . "\n\n";
        $msg .= "<i>Ресторан отметил, что поставка на эту дату не требуется.</i>";

        $subs = $pdo->prepare("SELECT DISTINCT chat_id FROM veg_telegram_subs WHERE restaurant_number = ? AND chat_id <> ?");
        $subs->execute([$restNum, $chatId]);
        foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            if ($cid && function_exists('sendMessage')) {
                @sendMessage($cid, $msg);
            }
        }
    } catch (Exception $e) {
        // не критично
    }

    // Подтверждение в чате
    $deliveryFmt = (new DateTime($deliveryDate))->format('d.m.Y');
    $confirmText = "🚫 <b>Поставка не нужна</b>\n\n";
    $confirmText .= "Поставщик: <b>" . soEsc($supName) . "</b>\n";
    $confirmText .= "Ресторан: <b>" . soEsc($restNum) . "</b>\n";
    $confirmText .= "Дата: <b>{$deliveryFmt}</b>\n\n";
    $confirmText .= "<i>Закупщик увидит, что на эту дату ваш ресторан ничего не заказывает.</i>";

    $btns = ['inline_keyboard' => [
        [['text' => '📦 К дням поставщика', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]],
        [['text' => '◂ В меню', 'callback_data' => 'veg_my_subs']],
    ]];
    editMessage($chatId, $msgId, $confirmText, $btns);
}

// Камако: обработка текстового ввода
function soOrderProcessInput($chatId, $text) {
    global $pdo;
    $modeFile = sys_get_temp_dir() . "/soord_{$chatId}.txt";
    if (!file_exists($modeFile)) {
        sendMessage($chatId, "❌ Сначала откройте заявку через меню бота.");
        return;
    }
    $mode = trim((string)file_get_contents($modeFile));
    @unlink($modeFile);

    $state = json_decode($mode, true);
    if (is_array($state)) {
        $supplierId = $state['supplier_id'] ?? '';
        $restNum = $state['restaurant_number'] ?? '';
        $deliveryDate = $state['delivery_date'] ?? '';
    } else {
        $parts = explode('_', $mode);
        if (count($parts) < 4) { sendMessage($chatId, "❌ Ошибка: попробуйте начать заново."); return; }
        $supplierId = $parts[1] ?? '';
        $restNum = $parts[2] ?? '';
        $deliveryDate = $parts[3] ?? '';
    }
    if (!$supplierId || !$restNum || !$deliveryDate) { sendMessage($chatId, "❌ Ошибка: попробуйте начать заново."); return; }
    $supName = soGetSupplierName($pdo, $supplierId);

    // Загружаем шаблон (с учётом юрлица)
    $rest = soGetRestaurantContext($pdo, $restNum);
    if (!$rest) { sendMessage($chatId, "❌ Ресторан не найден."); return; }
    $le = $rest['legal_entity'];
    $tpl = $pdo->prepare("SELECT product_id, sku, product_name, multiplicity, min_qty FROM so_templates WHERE supplier_id = ? AND legal_entity = ? AND is_active = 1");
    $tpl->execute([$supplierId, $le]);
    $products = $tpl->fetchAll();
    $prodMap = [];
    foreach ($products as $p) $prodMap[mb_strtolower(trim($p['product_name']))] = $p;

    // Парсим ввод
    $items = [];
    $lines = preg_split("/[\r\n]+/", trim($text));
    $matched = 0;
    foreach ($lines as $line) {
        if (!preg_match('/^(.+?):\s*([\d.,]+)\s*$/u', trim($line), $m)) continue;
        $name = mb_strtolower(trim($m[1]));
        // Убираем хинты в скобках (кр.6), (мин.12) и т.д.
        $name = trim(preg_replace('/\s*\(.*?\)/', '', $name));
        $qty = floatval(str_replace(',', '.', $m[2]));
        if ($qty <= 0) continue;
        // Поиск товара
        $found = null;
        if (isset($prodMap[$name])) { $found = $prodMap[$name]; }
        else { foreach ($prodMap as $pName => $p) { if (mb_strpos($pName, $name) !== false || mb_strpos($name, $pName) !== false) { $found = $p; break; } } }
        if (!$found) continue;
        // Проверка минимального количества
        $minQty = floatval($found['min_qty']);
        if ($minQty > 0 && $qty < $minQty) { $qty = $minQty; }
        // Округление по кратности
        $mult = floatval($found['multiplicity']);
        if ($mult > 0 && fmod($qty, $mult) > 0.001) { $qty = ceil($qty / $mult) * $mult; }
        $items[] = ['product_id' => $found['product_id'] ?? '', 'sku' => $found['sku'], 'product_name' => $found['product_name'], 'quantity' => $qty];
        $matched++;
    }

    if (!$items) { sendMessage($chatId, "❌ Не удалось распознать ни одной позиции. Скопируйте шаблон и измените числа."); return; }

    // Проверяем дедлайн
    $settings = soGetSupplierSettingsBot($pdo, $supplierId);
    if ((int)($settings['is_accepting_orders'] ?? 1) !== 1) {
        $msg = $settings['pause_message'] ?: 'Приём заявок для этого поставщика временно приостановлен.';
        sendMessage($chatId, "❌ {$msg}");
        return;
    }

    $dlStatus = soBotCheckDeadline($pdo, $supplierId, $deliveryDate);
    if ($dlStatus['status'] === 'closed') {
        sendMessage($chatId, "❌ Приём заявок на эту дату закрыт" . ($dlStatus['deadline'] ? " (дедлайн: {$dlStatus['deadline']})" : '') . ".");
        return;
    }
    if (!soBotRestaurantHasDeliveryDate($pdo, $supplierId, $restNum, $deliveryDate)) {
        sendMessage($chatId, "❌ На эту дату у ресторана нет поставки от этого поставщика.");
        return;
    }

    // Определяем юрлицо ресторана
    $le = $rest['legal_entity'];

    // Сохраняем заказ
    try {
        $pdo->beginTransaction();
        // Ищем существующую заявку — UPDATE сохраняет id, admin_qty и историю
        $old = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
        $old->execute([$supplierId, $restNum, $deliveryDate, $le]);
        $oldOrder = $old->fetch();
        if ($oldOrder) {
            $orderId = $oldOrder['id'];
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE so_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);
        } else {
            $pdo->prepare("INSERT INTO so_orders (supplier_id, restaurant_number, delivery_date, order_date, status, submitted_at, legal_entity) VALUES (?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                ->execute([$supplierId, $restNum, $deliveryDate, $le]);
            $orderId = $pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $it) { $ins->execute([$orderId, $it['product_id'], $it['sku'], $it['product_name'], $it['quantity']]); }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        sendMessage($chatId, "❌ Ошибка сохранения: " . soEsc($e->getMessage()));
        return;
    }

    $totalQty = array_sum(array_column($items, 'quantity'));
    $confirmText = "✅ <b>Заявка " . soEsc($supName) . " отправлена!</b>\n\n";
    $confirmText .= "Ресторан: <b>" . soEsc($restNum) . "</b>\n";
    $confirmText .= "Доставка: <b>{$deliveryDate}</b>\n";
    $confirmText .= "Позиций: <b>{$matched}</b>, всего: <b>{$totalQty}</b>\n\n";
    foreach ($items as $it) { $confirmText .= "• " . soEsc($it['product_name']) . ": <b>{$it['quantity']}</b>\n"; }

    $btns = ['inline_keyboard' => [
        [['text' => '📦 К заявкам', 'callback_data' => "soord_sup_{$supplierId}"]],
        [['text' => '◂ В меню', 'callback_data' => 'veg_my_subs']],
    ]];
    sendMessage($chatId, $confirmText, $btns);
}

function soFormatOrders($orders) {
    $text = '';
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    foreach ($orders as $order) {
        $dateObj = new DateTime($order['delivery_date']);
        $dow = (int)$dateObj->format('N');
        $text .= "📅 <b>" . ($dayNames[$dow] ?? '') . ' ' . $dateObj->format('d.m') . "</b>\n";
        if (empty($order['items'])) {
            $text .= "  <i>Поставка не нужна</i>\n\n";
            continue;
        }
        foreach ($order['items'] as $item) {
            $qty = ($item['admin_qty'] !== null && $item['admin_qty'] !== '') ? (float)$item['admin_qty'] : (float)$item['quantity'];
            if ($qty <= 0) {
                continue;
            }
            $qtyFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $text .= "  • " . soEsc($item['product_name']) . ": <b>{$qtyFmt}</b>\n";
        }
        $text .= "\n";
    }
    return $text;
}

function soShowMyOrders($chatId, $msgId, $supplierId) {
    global $pdo;
    $supName = soGetSupplierName($pdo, $supplierId);
    $subs = botGetSubscribedRestaurants($pdo, $chatId);

    if (!$subs) {
        editMessage($chatId, $msgId, "📋 У вас нет подписок.\nСначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']],
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    if (count($subs) === 1) {
        soShowRestOrders($chatId, $msgId, $supplierId, $subs[0]['restaurant_number']);
        return;
    }

    $text = "📋 <b>" . soEsc($supName) . "</b>\n\nВыберите ресторан:";
    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $prettyRest = botFormatSubscribedRestaurant($sub['restaurant_number'], $sub['legal_entity_group']);
        $btns[] = [['text' => "🏪 {$prettyRest} — {$addr}", 'callback_data' => "sohist_rest_{$supplierId}_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_menu_supplier']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

function soShowRestOrders($chatId, $msgId, $supplierId, $restNum) {
    global $pdo;
    $supName = soGetSupplierName($pdo, $supplierId);
    $s = $pdo->prepare("
        SELECT id, delivery_date, submitted_at
        FROM so_orders
        WHERE supplier_id = ? AND restaurant_number = ?
        ORDER BY delivery_date DESC, id DESC
        LIMIT 10
    ");
    $s->execute([$supplierId, $restNum]);
    $orders = $s->fetchAll();

    $text = "📋 <b>" . soEsc($supName) . "</b>\n";
    $text .= "🏪 Ресторан <b>" . soEsc(formatRestaurantNumber($restNum)) . "</b>\n\n";

    $usedLegacyHistory = false;
    $formattedOrders = [];
    $existingDates = [];
    if ($orders) {
        $orderIds = array_column($orders, 'id');
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $itemsStmt = $pdo->prepare("
            SELECT order_id, product_name, quantity, admin_qty
            FROM so_order_items
            WHERE order_id IN ({$ph})
            ORDER BY product_name
        ");
        $itemsStmt->execute($orderIds);
        $itemsByOrder = [];
        foreach ($itemsStmt->fetchAll() as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($orders as $order) {
            $formattedOrders[] = [
                'delivery_date' => $order['delivery_date'],
                'items' => $itemsByOrder[$order['id']] ?? [],
            ];
            $existingDates[$order['delivery_date']] = true;
        }
    }

    if ($supplierId === soGetPlanetaSupplierId()) {
        $legacyStmt = $pdo->prepare("
            SELECT vo.delivery_date, sp.product_name, vo.quantity, vo.admin_qty
            FROM veg_orders vo
            JOIN veg_session_products sp ON sp.id = vo.product_id AND sp.session_id = vo.session_id
            WHERE vo.restaurant_number = ?
            ORDER BY vo.delivery_date DESC, sp.sort_order, sp.product_name
        ");
        $legacyStmt->execute([$restNum]);
        $legacyByDate = [];
        foreach ($legacyStmt->fetchAll() as $row) {
            $legacyByDate[$row['delivery_date']][] = $row;
        }

        foreach ($legacyByDate as $deliveryDate => $legacyItems) {
            if (isset($existingDates[$deliveryDate])) {
                continue;
            }
            $hasPositive = false;
            foreach ($legacyItems as $item) {
                $qty = ($item['admin_qty'] !== null && $item['admin_qty'] !== '') ? (float)$item['admin_qty'] : (float)$item['quantity'];
                if ($qty > 0) {
                    $hasPositive = true;
                    break;
                }
            }
            if (!$hasPositive) {
                continue;
            }
            $formattedOrders[] = [
                'delivery_date' => $deliveryDate,
                'items' => $legacyItems,
            ];
            $usedLegacyHistory = true;
        }
    }

    if (!$formattedOrders) {
        $text .= "<i>Заявок пока нет.</i>";
    } else {
        usort($formattedOrders, function ($a, $b) {
            return strcmp($b['delivery_date'], $a['delivery_date']);
        });
        $formattedOrders = array_slice($formattedOrders, 0, 10);
        $text .= soFormatOrders($formattedOrders);
    }

    if ($usedLegacyHistory) {
        $text .= "ℹ️ Часть старых заявок показана из архива.\n";
    }

    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3980) . "\n\n…";
    }

    $subsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM veg_telegram_subs WHERE chat_id = ?");
    $subsCountStmt->execute([$chatId]);
    $subsCount = (int)$subsCountStmt->fetchColumn();
    $menuBackCallback = 'rest_menu_supplier';
    $backCallback = $subsCount > 1 ? "sohist_sup_{$supplierId}" : $menuBackCallback;
    $btns = [
        [['text' => '📝 Подать или изменить заявку', 'callback_data' => "soord_rest_{$supplierId}_{$restNum}"]],
        [['text' => '◂ Назад', 'callback_data' => $backCallback]],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// График доставок для ресторана (все подписанные рестораны)
function restShowSchedule($chatId, $msgId) {
    global $pdo;

    $restNums = array_column(botGetSubscribedRestaurants($pdo, $chatId), 'restaurant_number');

    if (!$restNums) {
        editMessage($chatId, $msgId, "📅 Сначала подпишитесь на ресторан.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    $ph = implode(',', array_fill(0, count($restNums), '?'));
    $st = $pdo->prepare("SELECT r.number, ds.day_of_week, ds.delivery_time
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number IN ({$ph}) AND ds.delivery_time IS NOT NULL AND ds.delivery_time != ''
        ORDER BY CAST(r.number AS UNSIGNED), ds.day_of_week");
    $st->execute($restNums);
    $rows = $st->fetchAll();

    $text = "📅 <b>График доставок</b>\n";
    $text .= "─────────────────────\n\n";

    if (!$rows) {
        $text .= "<i>График не найден для ваших ресторанов.</i>";
    } else {
        // Группируем по ресторану
        $byRest = [];
        foreach ($rows as $r) $byRest[$r['number']][] = $r;

        foreach ($byRest as $num => $schedule) {
            $text .= "🏪 <b>Ресторан {$num}</b>\n";
            foreach ($schedule as $s) {
                $day = $dayNames[$s['day_of_week']] ?? '?';
                $text .= "  {$day}: {$s['delivery_time']}\n";
            }
            $text .= "\n";
        }
    }

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']]]]);
}

// ═══ Остатки склада для ресторанов ═══

function restStockStart($chatId, $msgId) {
    @unlink(sys_get_temp_dir() . "/rest_stock_{$chatId}.txt");
    $text = "📦 <b>Остатки склада</b>\n";
    $text .= "─────────────────────\n";
    $text .= "Введите <b>название</b> или <b>артикул</b> товара.\n";
    $text .= "Бот покажет сколько на складе.";

    file_put_contents(sys_get_temp_dir() . "/rest_stock_{$chatId}.txt", $msgId);

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [
        [['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']],
    ]]);
}

function restStockSearch($chatId, $query, $userMsgId = null) {
    global $pdo, $BOT_TOKEN;

    $modeFile = sys_get_temp_dir() . "/rest_stock_{$chatId}.txt";
    $botMsgId = file_exists($modeFile) ? (intval(trim(@file_get_contents($modeFile))) ?: null) : null;

    $q = trim($query);
    if (mb_strlen($q) < 2) {
        $state = ['dummy' => 1]; // для corrReplace
        if (!isset($state['msg_id'])) $state['msg_id'] = $botMsgId;
        corrReplace($chatId, $userMsgId, $state, "Введите минимум 2 символа.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']],
        ]]);
        if (!empty($state['msg_id'])) @file_put_contents($modeFile, $state['msg_id']);
        return;
    }

    // Определяем юрлицо по подписке пользователя
    $subInfo = $pdo->prepare("
        SELECT vs.restaurant_number, r.legal_entity_group
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.active = 1
        WHERE vs.chat_id = ? LIMIT 1
    ");
    $subInfo->execute([$chatId]);
    $sub = $subInfo->fetch();
    $subGroup = $sub['legal_entity_group'] ?? 'BK_VM';
    if ($subGroup === 'PS') {
        $stockEntity = 'ООО "Пицца Стар"';
    } else {
        $leStmt = $pdo->prepare("SELECT legal_entity FROM ro_users WHERE restaurant_number = ? AND is_active = 1 LIMIT 1");
        $leStmt->execute([$sub['restaurant_number'] ?? 0]);
        $stockEntity = $leStmt->fetchColumn() ?: 'ООО "Бургер БК"';
    }

    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $st = $pdo->prepare("
        SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box
        FROM analysis_data a
        LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
        WHERE a.legal_entity = ?
            AND (a.sku LIKE ? OR p.name LIKE ?)
        ORDER BY a.stock DESC
        LIMIT 10
    ");
    $st->execute([$stockEntity, "%{$escaped}%", "%{$escaped}%"]);
    $results = $st->fetchAll();

    $text = "📦 <b>Остатки по запросу «{$q}»</b>\n";
    $text .= "─────────────────────\n";

    if (!$results) {
        $text .= "<i>Ничего не найдено.</i>\n";
        $text .= "Попробуйте другое название или артикул.";
    } else {
        // Если есть товары с остатком — прячем нулевые, кроме случая когда все нулевые
        $hasStock = false;
        foreach ($results as $r) { if (floatval($r['stock']) > 0) { $hasStock = true; break; } }

        foreach ($results as $r) {
            $qpb = floatval($r['qty_per_box']) ?: 1;
            $boxes = round(floatval($r['stock']) / $qpb, 1);
            if ($hasStock && $boxes <= 0) continue; // скрываем нулевые если есть другие
            $boxesFmt = rtrim(rtrim(number_format($boxes, 1, '.', ''), '0'), '.');
            $name = $r['name'] ?: $r['sku'];
            $icon = $boxes <= 0 ? '⚠️' : ($boxes < 5 ? '🟡' : '🟢');
            $text .= "{$icon} {$r['sku']} {$name} — <b>{$boxesFmt} кор.</b>\n";
        }
        $text .= "\n<i>Введите другой запрос или вернитесь назад.</i>";
    }

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    // Удаляем сообщение пользователя + старое бота, отправляем новое
    $state = ['msg_id' => $botMsgId];
    corrReplace($chatId, $userMsgId, $state, $text, ['inline_keyboard' => [
        [['text' => '✏️ Корректировка заказа', 'callback_data' => 'corr_start']],
        [['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']],
    ]]);
    if (!empty($state['msg_id'])) @file_put_contents($modeFile, $state['msg_id']);
}

// ═══ Сбор остатков через бота ═══

function restScStart($chatId, $msgId) {
    global $pdo, $SITE_URL;

    // Ищем активные сборы
    $st = $pdo->query("SELECT id, name, legal_entity FROM stock_collections WHERE status = 'active' ORDER BY created_at DESC");
    $collections = $st->fetchAll();

    if (!$collections) {
        editMessage($chatId, $msgId, "📋 <b>Сбор остатков</b>\n\nНет активных сборов.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']],
        ]]);
        return;
    }

    if (count($collections) === 1) {
        restScSelectRest($chatId, $msgId, $collections[0]['id']);
        return;
    }

    $btns = [];
    foreach ($collections as $c) {
        $btns[] = [['text' => "📋 {$c['name']}", 'callback_data' => "sc_col_{$c['id']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_menu_main']];
    editMessage($chatId, $msgId, "📋 <b>Сбор остатков</b>\n\nВыберите сбор:", ['inline_keyboard' => $btns]);
}

function restScSelectRest($chatId, $msgId, $collectionId) {
    global $pdo;

    // Подписки пользователя
    $subs = botGetSubscribedRestaurants($pdo, $chatId);

    if (!$subs) {
        editMessage($chatId, $msgId, "📋 Сначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'rest_sc_start']],
        ]]);
        return;
    }

    // Название сбора
    $col = $pdo->prepare("SELECT name FROM stock_collections WHERE id = ?");
    $col->execute([$collectionId]);
    $colName = $col->fetchColumn() ?: 'Сбор';

    // Проверяем кто уже заполнил
    $filled = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
    $filled->execute([$collectionId]);
    $filledSet = array_flip($filled->fetchAll(PDO::FETCH_COLUMN));

    if (count($subs) === 1) {
        restScShowProducts($chatId, $msgId, $collectionId, $subs[0]['restaurant_number']);
        return;
    }

    $btns = [];
    foreach ($subs as $sub) {
        $done = isset($filledSet[$sub['restaurant_number']]) ? ' ✅' : '';
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 30);
        $prettyRest = formatRestaurantNumber($sub['restaurant_number']);
        $btns[] = [['text' => "🏪 {$prettyRest} — {$addr}{$done}", 'callback_data' => "sc_rest_{$collectionId}_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'rest_sc_start']];
    editMessage($chatId, $msgId, "📋 <b>{$colName}</b>\n\nВыберите ресторан:", ['inline_keyboard' => $btns]);
}

function restScShowProducts($chatId, $msgId, $collectionId, $restNum) {
    global $pdo;

    $col = $pdo->prepare("SELECT name, status FROM stock_collections WHERE id = ?");
    $col->execute([$collectionId]);
    $colRow = $col->fetch();
    if (!$colRow) {
        editMessage($chatId, $msgId, "📋 Сбор не найден.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_sc_start']]]]);
        return;
    }
    if ($colRow['status'] !== 'active') {
        editMessage($chatId, $msgId, "📋 Сбор «{$colRow['name']}» закрыт.\nОтправка данных невозможна.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_sc_start']]]]);
        return;
    }
    $colName = $colRow['name'];

    // Товары
    $st = $pdo->prepare("SELECT id, product_name, product_sku, unit, note FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order, id");
    $st->execute([$collectionId]);
    $products = $st->fetchAll();

    if (!$products) {
        editMessage($chatId, $msgId, "📋 В сборе нет товаров.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'rest_sc_start']]]]);
        return;
    }

    // Существующие данные
    $existing = [];
    $ex = $pdo->prepare("SELECT product_id, stock FROM stock_collection_data WHERE collection_id = ? AND restaurant_number = ?");
    $ex->execute([$collectionId, $restNum]);
    foreach ($ex->fetchAll() as $r) $existing[$r['product_id']] = $r['stock'];

    $unitLabels = ['boxes' => 'кор', 'pieces' => 'шт', 'kg' => 'кг', 'liters' => 'л'];

    $text = "📋 <b>{$colName}</b>\n";
    $text .= "🏪 Ресторан <b>" . formatRestaurantNumber($restNum) . "</b>\n";
    $text .= "─────────────────────\n";

    if (!empty($existing)) {
        $text .= "✅ <b>Уже заполнено:</b>\n";
        foreach ($products as $p) {
            if (isset($existing[$p['id']])) {
                $u = $unitLabels[$p['unit']] ?? $p['unit'];
                $text .= "  • {$p['product_name']} — <b>{$existing[$p['id']]}</b> {$u}\n";
            }
        }
        $text .= "─────────────────────\n";
        $text .= "Чтобы обновить — отправьте данные заново.\n\n";
    }

    $text .= "Введите остатки, <b>по одной строке</b>:\n\n<code>";
    foreach ($products as $p) {
        $u = $unitLabels[$p['unit']] ?? $p['unit'];
        $val = $existing[$p['id']] ?? 0;
        $text .= "{$p['product_name']} ({$u}): {$val}\n";
    }
    $text .= "</code>\n";
    $text .= "<i>Скопируйте, измените числа и отправьте.</i>";

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    // Сохраняем контекст
    $state = json_encode(['collection_id' => $collectionId, 'rest' => $restNum, 'products' => $products]);
    file_put_contents(sys_get_temp_dir() . "/sc_{$chatId}.txt", "sc_input");
    file_put_contents(sys_get_temp_dir() . "/sc_data_{$chatId}.json", $state);

    // Кнопка Mini App если есть токен
    $btns = [];
    $token = $pdo->prepare("SELECT token FROM stock_collection_tokens WHERE collection_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $token->execute([$collectionId]);
    $tok = $token->fetchColumn();
    if ($tok) {
        $url = ($GLOBALS['SITE_URL'] ?? 'https://supply-department.online') . "/stock-form/{$tok}";
        $btns[] = [['text' => '🌐 Заполнить на сайте', 'web_app' => ['url' => $url]]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => "sc_col_{$collectionId}"]];

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

function restScProcessInput($chatId, $text, $userMsgId) {
    global $pdo;

    $dataFile = sys_get_temp_dir() . "/sc_data_{$chatId}.json";
    $state = json_decode(@file_get_contents($dataFile), true);
    if (!$state) { @unlink(sys_get_temp_dir() . "/sc_{$chatId}.txt"); return; }

    $products = $state['products'];
    $collectionId = $state['collection_id'];
    $restNum = $state['rest'];

    // Проверяем что сбор ещё активен
    $colCheck = $pdo->prepare("SELECT status FROM stock_collections WHERE id = ?");
    $colCheck->execute([$collectionId]);
    $colStatus = $colCheck->fetchColumn();
    if ($colStatus !== 'active') {
        @unlink(sys_get_temp_dir() . "/sc_{$chatId}.txt");
        @unlink($dataFile);
        sendMessage($chatId, "⛔ Сбор закрыт. Отправка данных невозможна.");
        return;
    }

    // Парсим ввод: каждая строка = "название: количество" или просто числа по порядку
    $lines = preg_split('/[\n\r]+/', trim($text));
    $values = [];

    if (count($lines) === count($products)) {
        // Пробуем как список значений по порядку
        $allNumeric = true;
        foreach ($lines as $line) {
            $parts = preg_split('/[:=]\s*/', trim($line));
            $val = str_replace(',', '.', trim(end($parts)));
            if (!is_numeric($val)) { $allNumeric = false; break; }
            $values[] = floatval($val);
        }
        if (!$allNumeric) $values = [];
    }

    if (empty($values)) {
        // Пробуем парсить "название: число"
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (preg_match('/[:=]\s*([\d.,]+)\s*$/', $line, $m)) {
                $values[] = floatval(str_replace(',', '.', $m[1]));
            } elseif (is_numeric(str_replace(',', '.', $line))) {
                $values[] = floatval(str_replace(',', '.', $line));
            }
        }
    }

    if (count($values) !== count($products)) {
        $state2 = ['msg_id' => null];
        corrReplace($chatId, $userMsgId, $state2, "⚠️ Количество значений (" . count($values) . ") не совпадает с количеством товаров (" . count($products) . ").\n\nВведите по одному числу на строку, в том же порядке.");
        return;
    }

    // Сохраняем
    $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at) VALUES (?, ?, ?, ?, 'form', NOW()) ON DUPLICATE KEY UPDATE stock = VALUES(stock), submitted_at = NOW()");
    foreach ($products as $i => $p) {
        $ins->execute([$collectionId, $p['id'], $restNum, $values[$i]]);
    }

    // Чистим
    @unlink(sys_get_temp_dir() . "/sc_{$chatId}.txt");
    @unlink($dataFile);

    $unitLabels = ['boxes' => 'кор', 'pieces' => 'шт', 'kg' => 'кг', 'liters' => 'л'];
    $confirmText = "✅ <b>Остатки сохранены!</b>\n🏪 Ресторан <b>" . formatRestaurantNumber($restNum) . "</b>\n─────────────────────\n";
    foreach ($products as $i => $p) {
        $u = $unitLabels[$p['unit']] ?? $p['unit'];
        $v = rtrim(rtrim(number_format($values[$i], 2, '.', ''), '0'), '.');
        $confirmText .= "  • {$p['product_name']} — <b>{$v}</b> {$u}\n";
    }

    // Антиспам — удаляем сообщение пользователя, обновляем бота
    $state2 = ['msg_id' => null];
    corrReplace($chatId, $userMsgId, $state2, $confirmText, ['inline_keyboard' => [
        [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
    ]]);
}

function vegShowRestaurants($chatId, $msgId = null, $page = 0, $group = 'BK_VM') {
    global $pdo;
    $group = $group === 'PS' ? 'PS' : 'BK_VM';
    $groupTitle = botGetRestaurantGroupTitle($group);
    $s = $pdo->prepare("
        SELECT number, address, city
        FROM restaurants
        WHERE active = 1 AND legal_entity_group = ?
        ORDER BY CAST(number AS UNSIGNED)
    ");
    $s->execute([$group]);
    $allRests = $s->fetchAll();

    // Уже подписанные
    $s2 = $pdo->prepare("SELECT restaurant_number FROM veg_telegram_subs WHERE chat_id=?");
    $s2->execute([$chatId]);
    $subbed = array_column($s2->fetchAll(), 'restaurant_number');

    if (!$allRests) {
        editMessage($chatId, $msgId, "🏪 <b>{$groupTitle}</b>\n\nДля этого юрлица рестораны не найдены.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'veg_pick_rest']],
        ]]);
        return;
    }

    $perPage = 10;
    $total = count($allRests);
    $pages = ceil($total / $perPage);
    $page = max(0, min($page, $pages - 1));
    $slice = array_slice($allRests, $page * $perPage, $perPage);

    $text = "🏪 <b>{$groupTitle}</b>\n";
    $text .= "Страница " . ($page + 1) . "/{$pages}\n\n";
    $text .= "✅ = подписаны. Нажмите чтобы отписаться.\n";

    $btns = [];
    foreach ($slice as $r) {
        $num = $r['number'];
        $mark = in_array($num, $subbed) ? '✅ ' : '';
        $addr = mb_substr($r['address'] ?: $r['city'], 0, 35);
        $btns[] = [['text' => "{$mark}{$num} — {$addr}", 'callback_data' => "veg_sub:{$group}:{$num}"]];
    }

    // Навигация
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '◀', 'callback_data' => "veg_page:{$group}:" . ($page - 1)];
    if ($page < $pages - 1) $nav[] = ['text' => '▶', 'callback_data' => "veg_page:{$group}:" . ($page + 1)];
    if ($nav) $btns[] = $nav;
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_pick_rest']];

    $markup = ['inline_keyboard' => $btns];
    if ($msgId) editMessage($chatId, $msgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
}

// Callback veg_my_subs_manage — список с кнопками отписки
function vegShowSubsManage($chatId, $msgId) {
    global $pdo;
    $subs = botGetSubscribedRestaurants($pdo, $chatId);

    if (!$subs) {
        editMessage($chatId, $msgId, "У вас нет активных подписок.", ['inline_keyboard' => [
            [['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']],
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    $text = "🔔 <b>Ваши подписки</b>\n\nНажмите чтобы отписаться:";
    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $prettyRest = botFormatSubscribedRestaurant($sub['restaurant_number'], $sub['legal_entity_group']);
        $btns[] = [['text' => "❌ {$prettyRest} — {$addr}", 'callback_data' => "veg_unsub:{$sub['legal_entity_group']}:{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// ═══ Настройки уведомлений ресторана ═══

function restNotifSettings($chatId, $msgId) {
    global $pdo;
    $s = $pdo->prepare("SELECT notify_veg_reminders, notify_veg_sessions, notify_confirmations, notify_stock_reminders, notify_stock_sessions FROM veg_telegram_subs WHERE chat_id = ? LIMIT 1");
    $s->execute([$chatId]);
    $row = $s->fetch();
    if (!$row) {
        editMessage($chatId, $msgId, "Сначала подпишитесь на ресторан.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    $on = '✅'; $off = '❌';
    $settings = [
        ['field' => 'veg_reminders',   'label' => 'Напоминания о заявках',   'val' => $row['notify_veg_reminders']],
        ['field' => 'veg_sessions',    'label' => 'Новые периоды приёма',    'val' => $row['notify_veg_sessions']],
        ['field' => 'confirmations',   'label' => 'Подтверждения заявок',   'val' => $row['notify_confirmations']],
        ['field' => 'stock_reminders', 'label' => 'Напоминания об остатках','val' => $row['notify_stock_reminders']],
        ['field' => 'stock_sessions',  'label' => 'Новые сборы остатков',   'val' => $row['notify_stock_sessions']],
    ];

    $text = "⚙️ <b>Настройки уведомлений</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "Нажмите, чтобы включить или выключить:\n\n";

    $btns = [];
    foreach ($settings as $s) {
        $icon = $s['val'] ? $on : $off;
        $text .= "{$icon} {$s['label']}\n";
        $btns[] = [['text' => "{$icon} {$s['label']}", 'callback_data' => "rest_notif_toggle_{$s['field']}"]];
    }

    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

function restNotifToggle($chatId, $msgId, $field) {
    global $pdo;
    $map = [
        'veg_reminders'   => 'notify_veg_reminders',
        'veg_sessions'    => 'notify_veg_sessions',
        'confirmations'   => 'notify_confirmations',
        'stock_reminders' => 'notify_stock_reminders',
        'stock_sessions'  => 'notify_stock_sessions',
    ];
    $col = $map[$field] ?? null;
    if (!$col) return;

    $s = $pdo->prepare("SELECT {$col} FROM veg_telegram_subs WHERE chat_id = ? LIMIT 1");
    $s->execute([$chatId]);
    $current = $s->fetchColumn();
    $newVal = $current ? 0 : 1;

    $pdo->prepare("UPDATE veg_telegram_subs SET {$col} = ? WHERE chat_id = ?")->execute([$newVal, $chatId]);
    restNotifSettings($chatId, $msgId);
}
// Функция отправки уведомления подписчикам ресторана
function vegNotifySubscribers($pdo, $botToken, $restaurantNumber, $text) {
    global $SITE_URL;
    $s = $pdo->prepare("SELECT chat_id FROM veg_telegram_subs WHERE restaurant_number=?");
    $s->execute([$restaurantNumber]);
    $chatIds = $s->fetchAll(PDO::FETCH_COLUMN);
    foreach ($chatIds as $cid) {
        $data = json_encode(['chat_id' => $cid, 'text' => $text, 'parse_mode' => 'HTML']);
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
        curl_exec($ch); curl_close($ch);
    }
}

// ═══════════════════════════════════════════════
// ═══ КОРРЕКТИРОВКИ ЗАКАЗОВ ═══
// ═══════════════════════════════════════════════

// Ближайшие доставки ресторана с дедлайнами корректировок
function corrGetNextDeliveries($pdo, $restNum, $limit = 3) {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $st = $pdo->prepare("SELECT ds.day_of_week, ds.delivery_time FROM delivery_schedule ds JOIN restaurants r ON r.id = ds.restaurant_id WHERE r.number = ?");
    $st->execute([$restNum]);
    $schedule = [];
    foreach ($st->fetchAll() as $row) $schedule[(int)$row['day_of_week']] = $row['delivery_time'];
    if (!$schedule) return [];

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    $results = [];
    for ($i = 1; $i <= 7 && count($results) < $limit; $i++) {
        $check = clone $now;
        $check->modify("+{$i} days");
        $dow = (int)$check->format('N');
        if (!isset($schedule[$dow])) continue;
        $deadline = clone $check;
        $deadline->modify('-1 day');
        while ((int)$deadline->format('N') >= 6) $deadline->modify('-1 day');
        $deadline->setTime(11, 30, 0);
        if ($now > $deadline) continue;
        $results[] = [
            'date' => $check->format('Y-m-d'),
            'date_fmt' => $dayNames[$dow] . ' ' . $check->format('d.m'),
            'time' => $schedule[$dow],
            'deadline' => $deadline,
            'deadline_fmt' => $dayNames[(int)$deadline->format('N')] . ' ' . $deadline->format('d.m H:i'),
        ];
    }
    return $results;
}

function corrFmtQty($v) { return rtrim(rtrim(number_format(floatval($v), 2, '.', ''), '0'), '.'); }
function corrEsc($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Получить все pending ID батча по одному ID записи
function corrGetBatchPendingIds($pdo, $oneId) {
    $c = $pdo->prepare("SELECT restaurant_number, delivery_date, restaurant_chat_id, notify_messages FROM order_corrections WHERE id = ?");
    $c->execute([$oneId]);
    $row = $c->fetch();
    if (!$row) return [];
    // Сначала пробуем batch_ids из notify_messages
    $nm = json_decode($row['notify_messages'] ?? '{}', true);
    if (!empty($nm['batch_ids'])) {
        $ph = implode(',', array_fill(0, count($nm['batch_ids']), '?'));
        $st = $pdo->prepare("SELECT id FROM order_corrections WHERE id IN ({$ph}) AND status IN ('pending', 'in_progress')");
        $st->execute($nm['batch_ids']);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
    // Fallback: по ресторану + дате + отправителю
    $st = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? AND status IN ('pending', 'in_progress')");
    $st->execute([$row['restaurant_number'], $row['delivery_date'], $row['restaurant_chat_id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function corrGetBatchAllIds($pdo, $oneId) {
    $c = $pdo->prepare("SELECT notify_messages, restaurant_number, delivery_date, restaurant_chat_id FROM order_corrections WHERE id = ?");
    $c->execute([$oneId]);
    $row = $c->fetch();
    if (!$row) return [];
    $nm = json_decode($row['notify_messages'] ?? '{}', true);
    if (!empty($nm['batch_ids'])) return $nm['batch_ids'];
    $st = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ?");
    $st->execute([$row['restaurant_number'], $row['delivery_date'], $row['restaurant_chat_id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// Панель корректировок для закупщиков
function cmdCorrections($chatId, $msgId) {
    global $pdo;

    // Ожидающие
    $st = $pdo->query("SELECT oc.*,
        (SELECT COUNT(*) FROM order_corrections oc2 WHERE oc2.restaurant_number = oc.restaurant_number AND oc2.delivery_date = oc.delivery_date AND oc2.restaurant_chat_id = oc.restaurant_chat_id AND oc2.status = 'pending') as batch_pending
        FROM order_corrections oc WHERE oc.status = 'pending' ORDER BY oc.created_at DESC");
    $pending = $st->fetchAll();

    // Группируем по батчам
    $batches = [];
    foreach ($pending as $p) {
        $key = "{$p['restaurant_number']}_{$p['delivery_date']}_{$p['restaurant_chat_id']}";
        if (!isset($batches[$key])) {
            $batches[$key] = ['rest' => $p['restaurant_number'], 'date' => $p['delivery_date'], 'submitter' => $p['submitter_name'], 'items' => [], 'ids' => []];
        }
        $batches[$key]['items'][] = $p;
        $batches[$key]['ids'][] = $p['id'];
    }

    // Статистика
    $totalPending = count($pending);
    $statsRow = $pdo->query("SELECT
        SUM(DATE(created_at) = CURDATE()) as today_total,
        SUM(status = 'approved' AND DATE(reviewed_at) = CURDATE()) as today_approved,
        SUM(status = 'rejected' AND DATE(reviewed_at) = CURDATE()) as today_rejected
        FROM order_corrections")->fetch();
    $todayCount = intval($statsRow['today_total'] ?? 0);
    $approvedToday = intval($statsRow['today_approved'] ?? 0);
    $rejectedToday = intval($statsRow['today_rejected'] ?? 0);

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];

    $text = "✏️ <b>Корректировки заказов</b>\n";
    $text .= "─────────────────────\n";
    $text .= "⏳ Ожидают: <b>{$totalPending}</b>\n";
    $text .= "📊 Сегодня: {$todayCount} подано, {$approvedToday} принято, {$rejectedToday} отклонено\n";
    $text .= "─────────────────────\n";

    if (empty($batches)) {
        $text .= "\n<i>Нет заявок на рассмотрении</i>";
    } else {
        $text .= "\n<b>На рассмотрении:</b>\n\n";
        foreach ($batches as $b) {
            $dow = (int)(new DateTime($b['date']))->format('N');
            $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($b['date']));
            $from = $b['submitter'] ? " ({$b['submitter']})" : '';
            $text .= "🏪 <b>Рест. {$b['rest']}</b>{$from} | {$dateFmt}\n";
            foreach ($b['items'] as $item) {
                $actionLabel = $item['action'] === 'add' ? 'Добавить' : 'Убрать';
                $uom = $item['unit_of_measure'] ?: 'кор.';
                $name = mb_substr($item['product_name'], 0, 30);
                $text .= "  • {$actionLabel}: {$name} — " . corrFmtQty($item['quantity']) . " {$uom}\n";
            }
            $text .= "\n";
        }
    }

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    $btns = [];
    // Кнопки быстрых действий для каждого батча (до 5)
    $shown = 0;
    foreach ($batches as $b) {
        if ($shown >= 5) break;
        $dow = (int)(new DateTime($b['date']))->format('N');
        $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($b['date']));
        $firstId = $b['ids'][0];
        $btns[] = [
            ['text' => "✅ Рест.{$b['rest']} {$dateFmt}", 'callback_data' => "corr_aa_{$firstId}"],
            ['text' => "❌", 'callback_data' => "corr_ra_{$firstId}"],
        ];
        $shown++;
    }
    $btns[] = [['text' => '📜 История', 'callback_data' => 'corr_history_0']];
    $btns[] = [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']];

    if ($msgId) editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
    else sendMessage($chatId, $text, ['inline_keyboard' => $btns]);
}

// История корректировок (страницы)
function corrShowHistory($chatId, $msgId, $page = 0) {
    global $pdo;
    $perPage = 10;
    $offset = $page * $perPage;
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];

    $total = $pdo->query("SELECT COUNT(*) FROM order_corrections WHERE status != 'pending'")->fetchColumn();

    $st = $pdo->prepare("SELECT * FROM order_corrections WHERE status != 'pending' ORDER BY reviewed_at DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset));
    $st->execute();
    $items = $st->fetchAll();

    $text = "📜 <b>История корректировок</b>\n";
    $text .= "─────────────────────\n";

    if (!$items) {
        $text .= "<i>Нет обработанных заявок</i>";
    } else {
        foreach ($items as $c) {
            $si = $c['status'] === 'approved' ? '✅' : '❌';
            $dow = (int)(new DateTime($c['delivery_date']))->format('N');
            $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($c['delivery_date']));
            $actionLabel = $c['action'] === 'add' ? 'Доб.' : 'Убр.';
            $uom = $c['unit_of_measure'] ?: 'кор.';
            $name = mb_substr($c['product_name'], 0, 25);
            $reviewDate = $c['reviewed_at'] ? date('d.m H:i', strtotime($c['reviewed_at'])) : '';
            $prettyRest = formatRestaurantNumber($c['restaurant_number']);
            $text .= "{$si} Р-н <b>{$prettyRest}</b> {$dateFmt} | {$actionLabel} {$name} " . corrFmtQty($c['quantity']) . " {$uom}";
            if ($c['reviewer_name']) $text .= " — {$c['reviewer_name']}";
            $text .= " <i>{$reviewDate}</i>\n";
        }
    }

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    $btns = [];
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '◂ Назад', 'callback_data' => 'corr_history_' . ($page - 1)];
    if ($offset + $perPage < $total) $nav[] = ['text' => 'Далее ▸', 'callback_data' => 'corr_history_' . ($page + 1)];
    if ($nav) $btns[] = $nav;
    $btns[] = [['text' => '◂ Корректировки', 'callback_data' => 'cmd_corrections']];

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Шаг 1: выбор ресторана
function corrStart($chatId, $msgId) {
    global $pdo;
    @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt");
    @unlink(sys_get_temp_dir() . "/corr_data_{$chatId}.json");

    $subs = botGetSubscribedRestaurants($pdo, $chatId);
    if (!$subs) {
        editMessage($chatId, $msgId, "✏️ <b>Корректировка заказа</b>\n\nСначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']],
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }
    // Если подписан на один ресторан — сразу к выбору даты
    if (count($subs) === 1) {
        corrShowDelivery($chatId, $msgId, $subs[0]['restaurant_number']);
        return;
    }

    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $prettyRest = formatRestaurantNumber($sub['restaurant_number']);
        $btns[] = [['text' => "🏪 {$prettyRest} — {$addr}", 'callback_data' => "corr_rest_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, "✏️ <b>Корректировка заказа</b>\n\nВыберите ресторан:", ['inline_keyboard' => $btns]);
}

// Шаг 2: выбор даты доставки
function corrShowDelivery($chatId, $msgId, $restNum) {
    global $pdo;
    $deliveries = corrGetNextDeliveries($pdo, $restNum);
    if (!$deliveries) {
        editMessage($chatId, $msgId, "✏️ <b>Ресторан {$restNum}</b>\n\nНет доступных дат для корректировки.\nДедлайн на ближайшую поставку уже прошёл.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'corr_start']],
        ]]);
        return;
    }
    $text = "✏️ <b>Ресторан {$restNum}</b>\n\nНа какую дату доставки корректировка?";
    $btns = [];
    foreach ($deliveries as $d) {
        $btns[] = [['text' => "📅 {$d['date_fmt']} (до {$d['deadline_fmt']})", 'callback_data' => "corr_date_{$restNum}_{$d['date']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'corr_start']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Шаг 3: ввод списка позиций — сразу переход к вводу
function corrStartInput($chatId, $msgId, $restNum, $deliveryDate) {
    global $pdo;
    $deliveries = corrGetNextDeliveries($pdo, $restNum, 10);
    $found = null;
    foreach ($deliveries as $d) { if ($d['date'] === $deliveryDate) { $found = $d; break; } }
    if (!$found) {
        editMessage($chatId, $msgId, "⛔ Дедлайн корректировки прошёл.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "corr_rest_{$restNum}"]]]]);
        return;
    }

    // Показываем существующие заявки на эту дату
    $st = $pdo->prepare("SELECT * FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY created_at DESC");
    $st->execute([$restNum, $deliveryDate, $chatId]);
    $existing = $st->fetchAll();

    $state = ['rest' => $restNum, 'date' => $deliveryDate, 'items' => [], 'step' => 'items', 'msg_id' => $msgId];
    @file_put_contents(sys_get_temp_dir() . "/corr_{$chatId}.txt", "corr_items");
    @file_put_contents(sys_get_temp_dir() . "/corr_data_{$chatId}.json", json_encode($state));

    $text = "✏️ <b>Ресторан {$restNum}</b> | {$found['date_fmt']}\n";
    $text .= "⏰ Дедлайн: {$found['deadline_fmt']}\n";

    if ($existing) {
        $text .= "\n<b>Ранее отправленные:</b>\n";
        foreach ($existing as $e) {
            $icon = $e['action'] === 'add' ? '➕' : '➖';
            $si = ['pending'=>'⏳','approved'=>'✅','rejected'=>'❌'][$e['status']];
            $text .= "{$si}{$icon} {$e['product_name']} — " . corrFmtQty($e['quantity']) . " кор.\n";
        }
    }

    $text .= "\n─────────────────────\n";
    $text .= "📝 Введите позиции, <b>по одной на строку</b>:\n\n";
    $text .= "<code>молоко 5\n68803 3\nстаканы 200 шт\n-кетчуп 2</code>\n\n";
    $text .= "➕ Без минуса = добавить\n";
    $text .= "➖ С минусом = убрать\n";
    $text .= "📦 По умолчанию — коробки. Для штук: <b>шт</b>\n";
    $text .= "<i>Желательно указывать артикул или название из 1С</i>";

    $btns = [[['text' => '◂ Отмена', 'callback_data' => "corr_rest_{$restNum}"]]];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Удалить сообщения пользователя и бота, отправить новое внизу. Возвращает message_id нового.
function corrReplace($chatId, $userMsgId, &$state, $text, $keyboard = null) {
    global $BOT_TOKEN;
    if ($userMsgId) @deleteMessage($chatId, $userMsgId);
    if (!empty($state['msg_id'])) @deleteMessage($chatId, $state['msg_id']);
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $payload['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
    $resp = json_decode(curl_exec($ch), true); curl_close($ch);
    $newMsgId = $resp['result']['message_id'] ?? null;
    if ($newMsgId) $state['msg_id'] = $newMsgId;
    return $newMsgId;
}

// Обработка текстового ввода
function corrProcessTextInput($chatId, $text, $mode, $userMsgId = null) {
    global $pdo;
    $dataFile = sys_get_temp_dir() . "/corr_data_{$chatId}.json";
    $state = json_decode(@file_get_contents($dataFile), true);
    if (!$state) { @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt"); return; }

    $step = $state['step'] ?? '';

    if ($step === 'items' || $step === 'confirm') {
        // Парсим список позиций (добавляем к уже накопленным)
        $lines = preg_split('/[\n\r]+/', trim($text));
        $items = $state['items'] ?? [];
        $errors = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $action = 'add';
            if (mb_substr($line, 0, 1) === '-') {
                $action = 'remove';
                $line = trim(mb_substr($line, 1));
            } elseif (mb_substr($line, 0, 1) === '+') {
                $line = trim(mb_substr($line, 1));
            } elseif (preg_match('/^(убрать|удалить|убери|удали)\s+/iu', $line, $rm)) {
                $action = 'remove';
                $line = trim(mb_substr($line, mb_strlen($rm[0])));
            } elseif (preg_match('/^(добавить|добавь)\s+/iu', $line, $am)) {
                $line = trim(mb_substr($line, mb_strlen($am[0])));
            }
            // Формат: название количество [единица]
            // к, к., кор, коробок, кейс = коробки; ш, ш., шт, штук = штуки
            if (preg_match('/^(.+?)\s+([\d.,]+)\s*(к\.?|кор\.?|коробо[кч]|коробки|кейс(?:ов|а)?|ш\.?|шт\.?|штук[иа]?|)\s*$/iu', $line, $m)) {
                $name = trim($m[1]);
                $qty = floatval(str_replace(',', '.', $m[2]));
                if ($qty <= 0) { $errors[] = "«{$line}» — неверное кол-во"; continue; }
                $rawUnit = mb_strtolower(trim($m[3]));
                $unit = 'кор.'; // по умолчанию
                if (preg_match('/^(ш|шт|штук)/u', $rawUnit)) $unit = 'шт.';
                $items[] = ['action' => $action, 'name' => $name, 'qty' => $qty, 'unit' => $unit];
            } else {
                $errors[] = "«{$line}» — формат: название кол-во";
            }
        }

        if (empty($items)) {
            $errText = $errors ? "⚠️ " . implode("\n", $errors) . "\n\n" : '';
            $errText .= "Введите позиции в формате:\n<code>молоко 5</code>";
            corrReplace($chatId, $userMsgId, $state, $errText);
            @file_put_contents($dataFile, json_encode($state));
            return;
        }

        // Ищем товары в базе только если введён артикул (числовой)
        foreach ($items as &$item) {
            if (isset($item['sku'])) continue;
            $q = $item['name'];
            // Подбираем только если похоже на артикул (4+ цифр)
            if (preg_match('/^\d{4,}$/', $q)) {
                $st = $pdo->prepare("SELECT sku, name FROM products WHERE sku = ? AND is_active = 1 LIMIT 1");
                $st->execute([$q]);
                $found = $st->fetch();
                if ($found) {
                    $item['sku'] = $found['sku'];
                    $item['product_name'] = $found['name'];
                    continue;
                }
            }
            $item['sku'] = '-';
            $item['product_name'] = $q;
        }
        unset($item);

        $state['items'] = $items;
        $state['step'] = 'confirm';

        // Сводка
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        $dow = (int)(new DateTime($state['date']))->format('N');
        $summary = "📋 <b>Корректировка: рест. " . formatRestaurantNumber($state['rest']) . "</b>\n";
        $summary .= "📅 {$dayNames[$dow]} " . date('d.m', strtotime($state['date'])) . "\n";
        $summary .= "─────────────────────\n";
        foreach ($items as $item) {
            $actionLabel = $item['action'] === 'add' ? 'Добавить' : 'Убрать';
            $label = $item['sku'] !== '-' ? "<b>{$item['sku']}</b> {$item['product_name']}" : $item['product_name'];
            $summary .= "• {$actionLabel}: {$label} — " . corrFmtQty($item['qty']) . " {$item['unit']}\n";
        }
        if ($errors) $summary .= "\n⚠️ " . implode("\n", $errors) . "\n";
        $summary .= "─────────────────────\n";
        $summary .= "<i>Можете отправить ещё позиции или нажмите «Отправить»</i>";

        $btns = ['inline_keyboard' => [
            [
                ['text' => '✅ Отправить', 'callback_data' => 'corr_submit'],
                ['text' => '❌ Отмена', 'callback_data' => "corr_rest_{$state['rest']}"],
            ],
        ]];

        corrReplace($chatId, $userMsgId, $state, $summary, $btns);
        @file_put_contents($dataFile, json_encode($state));
        return;
    }

    if ($step === 'review_comment') {
        $state['review_comment'] = trim($text);
        $reviewText = "💬 Комментарий: «{$state['review_comment']}»\n\nВыберите действие:";
        // IDs хранятся в state, кнопки используют фиксированные callback
        $btns = ['inline_keyboard' => [
            [
                ['text' => '✅ Принять', 'callback_data' => 'corr_cappr_go'],
                ['text' => '❌ Отклонить', 'callback_data' => 'corr_crej_go'],
            ],
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]];
        corrReplace($chatId, $userMsgId, $state, $reviewText, $btns);
        @file_put_contents($dataFile, json_encode($state));
        return;
    }

    if ($step === 'final_comment') {
        $state['final_comment'] = trim($text);
        $reviewText = "💬 Комментарий: «{$state['final_comment']}»\n\nОтправить результат ресторану?";
        $btns = ['inline_keyboard' => [
            [['text' => '📩 Отправить', 'callback_data' => 'corr_fc_send']],
            [['text' => '◂ Отмена', 'callback_data' => 'corr_rev_cancel']],
        ]];
        corrReplace($chatId, $userMsgId, $state, $reviewText, $btns);
        @file_put_contents($dataFile, json_encode($state));
        return;
    }
}

// Отправка пакета корректировок
function corrSubmitBatch($chatId) {
    global $pdo, $BOT_TOKEN;
    $dataFile = sys_get_temp_dir() . "/corr_data_{$chatId}.json";
    $state = json_decode(@file_get_contents($dataFile), true);
    if (!$state || empty($state['items'])) { sendMessage($chatId, "Нет позиций для отправки."); return; }

    // Проверяем дедлайн
    $deliveries = corrGetNextDeliveries($pdo, $state['rest'], 10);
    $found = null;
    foreach ($deliveries as $d) { if ($d['date'] === $state['date']) { $found = $d; break; } }
    if (!$found) {
        sendMessage($chatId, "⛔ Дедлайн корректировки прошёл.");
        @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt");
        @unlink($dataFile);
        return;
    }

    // Имя подавшего
    $subSt = $pdo->prepare("SELECT first_name, username FROM veg_telegram_subs WHERE chat_id = ? AND restaurant_number = ? LIMIT 1");
    $subSt->execute([$chatId, $state['rest']]);
    $sub = $subSt->fetch();
    $submitterName = $sub ? ($sub['first_name'] ?: ('@' . $sub['username'])) : null;

    // Определяем группу юрлиц ресторана (для фильтра в админке)
    $restGroup = botGetRestaurantGroupByNumber($pdo, $state['rest']);

    // INSERT всех позиций
    $corrIds = [];
    $ins = $pdo->prepare("INSERT INTO order_corrections (restaurant_number, restaurant_chat_id, legal_entity_group, submitter_name, delivery_date, action, product_sku, product_name, quantity, unit_of_measure) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($state['items'] as $item) {
        $ins->execute([$state['rest'], $chatId, $restGroup, $submitterName, $state['date'], $item['action'], $item['sku'], $item['product_name'], $item['qty'], $item['unit'] ?? 'кор.']);
        $corrIds[] = $pdo->lastInsertId();
    }

    // Чистим temp
    @unlink(sys_get_temp_dir() . "/corr_{$chatId}.txt");
    @unlink($dataFile);

    // Отбивка ресторану
    $cnt = count($state['items']);
    $word = $cnt === 1 ? 'позиция' : ($cnt < 5 ? 'позиции' : 'позиций');
    $doneText = "✅ <b>Отправлено: {$cnt} {$word}</b>\n\nОтдел закупок рассмотрит и пришлёт результат.";
    $doneBtns = ['inline_keyboard' => [
        [['text' => '📋 Ещё корректировка', 'callback_data' => "corr_date_{$state['rest']}_{$state['date']}"]],
        [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
    ]];
    $msgId = $state['msg_id'] ?? null;
    if ($msgId) editMessage($chatId, $msgId, $doneText, $doneBtns);
    else sendMessage($chatId, $doneText, $doneBtns);

    // Уведомляем закупщиков — одно сообщение со всеми позициями
    corrNotifyPurchasersBatch($pdo, $corrIds, $state, $submitterName);
}

// Уведомление закупщикам (пакетное)
function corrNotifyPurchasersBatch($pdo, $corrIds, $state, $submitterName) {
    global $BOT_TOKEN;
    $st = $pdo->query("SELECT u.telegram_chat_id, u.name FROM telegram_settings ts JOIN users u ON u.name = ts.user_name WHERE ts.correction_notifications = 1 AND u.telegram_chat_id IS NOT NULL");
    $recipients = $st->fetchAll();
    if (!$recipients) return;

    // Генерируем сообщение и кнопки
    $msgData = corrBuildReviewMessage($pdo, $corrIds, $state['rest'], $state['date'], $submitterName);

    $sentMessages = [];
    foreach ($recipients as $r) {
        $payload = json_encode([
            'chat_id' => $r['telegram_chat_id'],
            'text' => $msgData['text'],
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($msgData['keyboard']),
        ]);
        $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch); curl_close($ch);
        $respData = json_decode($resp, true);
        if (isset($respData['result']['message_id'])) {
            $sentMessages[] = ['chat_id' => $r['telegram_chat_id'], 'message_id' => $respData['result']['message_id']];
        }
    }
    // Сохраняем batch_ids + message_ids в каждой записи
    $nmJson = json_encode(['batch_ids' => $corrIds, 'messages' => $sentMessages]);
    foreach ($corrIds as $cid) {
        $pdo->prepare("UPDATE order_corrections SET notify_messages = ? WHERE id = ?")->execute([$nmJson, $cid]);
    }
}

// Построить текст + кнопки для сообщения закупщику
function corrBuildReviewMessage($pdo, $batchIds, $restNum = null, $date = null, $submitterName = null, $viewerChatId = null) {
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];

    // Загружаем все позиции батча одним запросом
    $items = [];
    if ($batchIds) {
        $ph = implode(',', array_fill(0, count($batchIds), '?'));
        $st = $pdo->prepare("SELECT * FROM order_corrections WHERE id IN ({$ph}) ORDER BY id");
        $st->execute($batchIds);
        $items = $st->fetchAll();
    }
    if (!$items) return ['text' => 'Заявка не найдена.', 'keyboard' => ['inline_keyboard' => []]];

    $first = $items[0];
    if (!$restNum) $restNum = $first['restaurant_number'];
    if (!$date) $date = $first['delivery_date'];
    if (!$submitterName) $submitterName = $first['submitter_name'];
    $dow = (int)(new DateTime($date))->format('N');
    $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($date));
    $from = $submitterName ? " ({$submitterName})" : '';

    $text = "📦 <b>Корректировка заказа</b>\n";
    $text .= "🏪 Ресторан <b>" . formatRestaurantNumber($restNum) . "</b>{$from} | {$dateFmt}\n";
    $text .= "─────────────────────\n";

    $pendingIds = [];
    $inProgressBy = null;
    foreach ($items as $c) {
        $actionLabel = $c['action'] === 'add' ? 'Добавить' : 'Убрать';
        $pname = corrEsc($c['product_name']);
        $name = $c['product_sku'] !== '-' ? "{$c['product_sku']} {$pname}" : $pname;
        $uom = $c['unit_of_measure'] ?: 'кор.';
        $qty = corrFmtQty($c['quantity']) . " {$uom}";

        if ($c['status'] === 'pending') {
            $line = "⏳ {$actionLabel}: {$name} — {$qty}";
            $pendingIds[] = $c['id'];
        } elseif ($c['status'] === 'in_progress') {
            $line = "🔄 {$actionLabel}: {$name} — {$qty}";
            if ($c['reviewer_name']) { $line .= " <i>— в работе у {$c['reviewer_name']}</i>"; $inProgressBy = $c['reviewer_name']; }
        } elseif ($c['status'] === 'approved') {
            $doneLabel = $c['action'] === 'add' ? 'Добавлено' : 'Убрано';
            $line = "✅ {$doneLabel}: {$name} — {$qty}";
            if ($c['reviewer_name']) $line .= " <i>({$c['reviewer_name']})</i>";
        } else {
            $line = "❌ Отклонено: {$name} — {$qty}";
            if ($c['reviewer_name']) $line .= " <i>({$c['reviewer_name']})</i>";
        }
        if ($c['review_comment']) $line .= "\n   💬 «{$c['review_comment']}»";
        $text .= $line . "\n";
    }

    // Проверяем есть ли позиции в работе
    $hasInProgress = false;
    foreach ($items as $c) { if ($c['status'] === 'in_progress') { $hasInProgress = true; break; } }

    $keyboard = ['inline_keyboard' => []];

    if ($hasInProgress) {
        // В работе — определяем кто взял
        $reviewerChatId = null;
        foreach ($items as $c) { if ($c['status'] === 'in_progress' && $c['reviewer_chat_id']) { $reviewerChatId = $c['reviewer_chat_id']; break; } }
        $isReviewer = $viewerChatId && $reviewerChatId && (string)$viewerChatId === (string)$reviewerChatId;

        if ($isReviewer) {
            // Кнопки только для того, кто взял в работу
            $workIds = [];
            foreach ($items as $c) { if ($c['status'] === 'in_progress') $workIds[] = $c['id']; }
            foreach ($workIds as $pid) {
                $c = null;
                foreach ($items as $item) { if ($item['id'] == $pid) { $c = $item; break; } }
                if (!$c) continue;
                $short = mb_substr($c['product_name'], 0, 25);
                $qty = corrFmtQty($c['quantity']);
                $uom = $c['unit_of_measure'] === 'шт.' ? 'шт' : 'кор';
                $keyboard['inline_keyboard'][] = [
                    ['text' => "✅ {$short} {$qty}{$uom}", 'callback_data' => "corr_a_{$pid}"],
                    ['text' => "❌", 'callback_data' => "corr_r_{$pid}"],
                ];
            }
            if (count($workIds) > 1) {
                $firstId = $workIds[0];
                $keyboard['inline_keyboard'][] = [
                    ['text' => '✅ Всё принять', 'callback_data' => "corr_aa_{$firstId}"],
                    ['text' => '❌ Всё отклонить', 'callback_data' => "corr_ra_{$firstId}"],
                ];
            }
            $firstId = $workIds[0];
            $keyboard['inline_keyboard'][] = [
                ['text' => '💬 С комментарием', 'callback_data' => "corr_cm_{$firstId}"],
            ];
        }
        // Для остальных — никаких кнопок, только текст «в работе у ...» (уже есть в строке выше)
    } elseif (!empty($pendingIds)) {
        // Ещё не взяли — показываем кнопку "Взять в работу"
        $firstId = $pendingIds[0];
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔄 Взять в работу', 'callback_data' => "corr_take_{$firstId}"],
        ];
    } else {
        // Все обработаны — проверяем, отправлен ли результат
        $nm = json_decode($items[0]['notify_messages'] ?? '{}', true);
        if (!empty($nm['result_sent'])) {
            $text .= "\n✅ <i>Результат отправлен ресторану</i>";
        } else {
            $firstId = $items[0]['id'];
            $keyboard['inline_keyboard'][] = [
                ['text' => '📩 Отправить результат', 'callback_data' => "corr_send_{$firstId}"],
            ];
            $keyboard['inline_keyboard'][] = [
                ['text' => '💬 Добавить комментарий', 'callback_data' => "corr_fc_{$firstId}"],
            ];
            $text .= "\n⏳ <i>Ожидает отправки ресторану</i>";
        }
    }

    return ['text' => $text, 'keyboard' => $keyboard];
}

// Обновить сообщения у всех закупщиков (перестроить текст+кнопки)
function corrUpdateAllReviewMessages($pdo, $batchIds) {
    if (empty($batchIds)) return;
    // Берём notify_messages из первой записи
    $st = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
    $st->execute([$batchIds[0]]);
    $row = $st->fetch();
    $nm = json_decode($row['notify_messages'] ?? '{}', true);
    $messages = $nm['messages'] ?? [];
    if (!$messages) return;

    // Каждому получателю — своё сообщение (с кнопками только для того кто взял)
    foreach ($messages as $m) {
        $msgData = corrBuildReviewMessage($pdo, $batchIds, null, null, null, $m['chat_id']);
        editMessage($m['chat_id'], $m['message_id'], $msgData['text'], $msgData['keyboard']);
    }
}

// Проверить, все ли позиции батча обработаны — если да, обновить сообщения (кнопка «Отправить»)
function corrCheckBatchComplete($pdo, $batchIds, $reviewerName) {
    if (empty($batchIds)) return;
    // Просто обновляем сообщения — corrBuildReviewMessage покажет кнопки «Отправить» / «Добавить комментарий»
    corrUpdateAllReviewMessages($pdo, $batchIds);
}

// Отправить итоговое уведомление ресторану
function corrSendResultToRestaurant($pdo, $batchIds, $reviewerName, $finalComment = null) {
    if (empty($batchIds)) return;
    $ph = implode(',', array_fill(0, count($batchIds), '?'));
    $st = $pdo->prepare("SELECT * FROM order_corrections WHERE id IN ({$ph}) ORDER BY id");
    $st->execute($batchIds);
    $items = $st->fetchAll();
    $restChatId = null;
    foreach ($items as $c) {
        if (!$restChatId) $restChatId = $c['restaurant_chat_id'];
        if ($c['status'] === 'pending' || $c['status'] === 'in_progress') return; // ещё не все
    }
    if (!$items || !$restChatId) return;

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    $first = $items[0];
    $dow = (int)(new DateTime($first['delivery_date']))->format('N');
    $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($first['delivery_date']));

    $text = "📋 <b>Результат корректировки заказа</b>\n";
    $prettyFirstRest = formatRestaurantNumber($first['restaurant_number']);
    $text .= "🏪 Ресторан <b>{$prettyFirstRest}</b> | Доставка: {$dateFmt}\n";
    $text .= "─────────────────────\n";
    foreach ($items as $c) {
        $uom = $c['unit_of_measure'] ?: 'кор.';
        $qty = corrFmtQty($c['quantity']) . " {$uom}";
        if ($c['status'] === 'approved') {
            $label = $c['action'] === 'add' ? 'Добавлено' : 'Убрано';
            $text .= "✅ <b>{$label}:</b> {$c['product_name']} — {$qty}\n";
            if ($c['review_comment']) $text .= "    💬 {$c['review_comment']}\n";
        } else {
            $label = $c['action'] === 'add' ? 'Добавить' : 'Убрать';
            $text .= "❌ <b>Отклонено</b> ({$label}): {$c['product_name']} — {$qty}\n";
            if ($c['review_comment']) $text .= "    Причина: {$c['review_comment']}\n";
        }
    }
    if ($finalComment) {
        $text .= "\n💬 <b>Комментарий:</b> {$finalComment}\n";
    }
    $text .= "─────────────────────\n";
    $text .= "Обработал: {$reviewerName}";

    sendMessage($restChatId, $text);

    // Помечаем result_sent в notify_messages
    $nmSt = $pdo->prepare("SELECT notify_messages FROM order_corrections WHERE id = ?");
    $nmSt->execute([$batchIds[0]]);
    $nmRow = $nmSt->fetch();
    $nm = json_decode($nmRow['notify_messages'] ?? '{}', true);
    $nm['result_sent'] = true;
    $ph2 = implode(',', array_fill(0, count($batchIds), '?'));
    $pdo->prepare("UPDATE order_corrections SET notify_messages = ? WHERE id IN ({$ph2})")->execute(array_merge([json_encode($nm)], $batchIds));

    // Обновляем сообщения закупщиков — убираем кнопки, добавляем отметку «отправлено»
    corrUpdateAllReviewMessages($pdo, $batchIds);
}

// Принять / Отклонить корректировку (одну или несколько)
function corrReview($pdo, $chatId, $msgId, $corrIds, $action, $comment = null) {
    $st = $pdo->prepare("SELECT u.name FROM users u WHERE u.telegram_chat_id = ?");
    $st->execute([$chatId]);
    $user = $st->fetch();
    if (!$user) return;

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $batchIds = [];

    foreach ($corrIds as $corrId) {
        // Атомарный UPDATE — только если ещё pending
        $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending', 'in_progress')");
        $upd->execute([$newStatus, $chatId, $user['name'], $comment, $corrId]);
        if ($upd->rowCount() === 0) continue; // уже обработано другим

        $corr = $pdo->prepare("SELECT * FROM order_corrections WHERE id = ?");
        $corr->execute([$corrId]);
        $c = $corr->fetch();
        if (!$c) continue;

        // Определяем batch
        if (empty($batchIds)) {
            $nm = json_decode($c['notify_messages'] ?? '{}', true);
            $batchIds = $nm['batch_ids'] ?? [$corrId];
        }
    }

    if (empty($batchIds)) {
        editMessage($chatId, $msgId, "⚠️ Заявка уже обработана.");
        return;
    }

    // Обновляем сообщение у всех закупщиков
    corrUpdateAllReviewMessages($pdo, $batchIds);

    // Если все позиции батча обработаны — отбивка ресторану
    corrCheckBatchComplete($pdo, $batchIds, $user['name']);
}

// ═══ Заказы ресторанов (временный модуль ro_*) ═══

function restRoOrders($chatId, $msgId) {
    global $pdo;

    // Получаем подписки ресторан��
    $restNums = array_column(botGetSubscribedRestaurants($pdo, $chatId), 'restaurant_number');

    if (empty($restNums)) {
        editMessage($chatId, $msgId, "Нет подписок на рестораны.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    // Текущая сессия
    $session = $pdo->query("SELECT * FROM ro_sessions WHERE status = 'active' AND week_end >= CURDATE() ORDER BY week_start DESC LIMIT 1")->fetch();

    $text = "🛒 <b>Заказы ресторанов</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";

    if (!$session) {
        $text .= "❌ Сейчас нет активной сессии приёма заявок.\n";
        $text .= "Обратитесь в отдел закупок.\n";
        $btns = [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]];
        editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return;
    }

    $text .= "📅 Сессия: <b>" . date('d.m', strtotime($session['week_start'])) . " — " . date('d.m', strtotime($session['week_end'])) . "</b>\n\n";

    // Показываем заказы для каждого ресторана
    $ph = implode(',', array_fill(0, count($restNums), '?'));
    $orders = $pdo->prepare("
        SELECT o.restaurant_number, o.delivery_date, o.status, o.submitted_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
        FROM ro_orders o
        WHERE o.session_id = ? AND o.restaurant_number IN ({$ph})
        ORDER BY o.restaurant_number, o.delivery_date
    ");
    $params = [$session['id'], ...$restNums];
    $orders->execute($params);
    $orderRows = $orders->fetchAll();

    // Группируем по ресторану
    $byRest = [];
    foreach ($orderRows as $o) {
        $byRest[$o['restaurant_number']][] = $o;
    }

    $dayNames = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб'];
    $statusIcons = ['submitted' => '✅', 'edited' => '📝', 'draft' => '📋', 'locked' => '🔒'];

    foreach ($restNums as $rn) {
        $prettyRn = formatRestaurantNumber($rn);
        $text .= "🏪 <b>Ресторан {$prettyRn}:</b>\n";
        if (isset($byRest[$rn])) {
            foreach ($byRest[$rn] as $o) {
                $dow = (int)(new DateTime($o['delivery_date']))->format('N');
                $dayName = $dayNames[$dow] ?? '';
                $dateStr = date('d.m', strtotime($o['delivery_date']));
                $icon = $statusIcons[$o['status']] ?? '❓';
                $qty = $o['total_qty'] ? round($o['total_qty']) : 0;
                $text .= "  {$icon} {$dayName} {$dateStr} — {$o['item_count']} поз., {$qty} кор.\n";
            }
        } else {
            $text .= "  ⚪ Заявок нет\n";
        }
        $text .= "\n";
    }

    $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
    $btns = [
        [['text' => '🛒 Подать заявку', 'url' => "{$siteUrl}/restaurant"]],
        [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Статус заявок ресторанов (для закупщиков)
function cmdRoStatus($chatId, $user, $msgId) {
    global $pdo;

    $session = $pdo->query("SELECT * FROM ro_sessions WHERE status = 'active' AND week_end >= CURDATE() ORDER BY week_start DESC LIMIT 1")->fetch();

    $text = "🛒 <b>Заказы ресторанов</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";

    if (!$session) {
        $text .= "❌ Нет активной сессии.\n";
        $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
        $btns = [
            [['text' => '🌐 Открыть на сайте', 'url' => "{$siteUrl}/restaurant-orders"]],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ];
        editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return;
    }

    $text .= "📅 Сессия: " . date('d.m', strtotime($session['week_start'])) . " — " . date('d.m', strtotime($session['week_end'])) . "\n\n";

    // Статистика по дням
    $tz = new DateTimeZone('Europe/Minsk');
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];

    $weekStart = new DateTime($session['week_start']);
    $weekEnd = new DateTime($session['week_end']);

    for ($d = clone $weekStart; $d <= $weekEnd; $d->modify('+1 day')) {
        $dow = (int)$d->format('N');
        if ($dow > 6) continue;
        $dateStr = $d->format('Y-m-d');
        $dayName = $dayNames[$dow] ?? '';
        $dateFmt = $d->format('d.m');

        // Сколько ресторанов с доставкой в этот день
        $totalRests = $pdo->prepare("SELECT COUNT(DISTINCT r.id) FROM restaurants r JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ? WHERE r.active = 1");
        $totalRests->execute([$dow]);
        $total = (int)$totalRests->fetchColumn();

        // Сколько подали
        $submitted = $pdo->prepare("SELECT COUNT(*) FROM ro_orders WHERE session_id = ? AND delivery_date = ? AND status != 'draft'");
        $submitted->execute([$session['id'], $dateStr]);
        $sub = (int)$submitted->fetchColumn();

        $icon = $sub === $total && $total > 0 ? '✅' : ($sub > 0 ? '🟡' : '⚪');
        $text .= "{$icon} {$dayName} {$dateFmt}: <b>{$sub}/{$total}</b>\n";
    }

    $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
    $btns = [
        [['text' => '🌐 Панель на сайте', 'url' => "{$siteUrl}/restaurant-orders"]],
        [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Рассылка логинов ресторанам (команда для закупщиков)
function restRoSendLogins($chatId, $msgId) {
    global $pdo;

    // Все учётки ro_users с подписками в боте
    $users = $pdo->query("
        SELECT DISTINCT ru.restaurant_number, r.city, r.address
        FROM ro_users ru
        JOIN restaurants r ON r.number = ru.restaurant_number AND r.active = 1
        WHERE ru.is_active = 1
        ORDER BY ru.restaurant_number
    ")->fetchAll();

    if (empty($users)) {
        editMessage($chatId, $msgId, "Нет активных учёток ресторанов. Создайте их в разделе «Заказы ресторанов» на сайте.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'cmd_menu']]]]);
        return;
    }

    // Находим подписки
    $subs = $pdo->query("SELECT DISTINCT chat_id, restaurant_number FROM veg_telegram_subs")->fetchAll();
    $subMap = [];
    foreach ($subs as $s) {
        $subMap[$s['restaurant_number']][] = $s['chat_id'];
    }

    $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    $sent = 0;
    $notFound = 0;

    foreach ($users as $u) {
        $rn = $u['restaurant_number'];
        $chatIds = $subMap[$rn] ?? [];
        if (empty($chatIds)) {
            $notFound++;
            continue;
        }

        $addr = $u['city'] . ($u['address'] ? ', ' . $u['address'] : '');
        $prettyRn = formatRestaurantNumber($rn);
        $msgText = "🛒 <b>Заказы ресторанов — новая система</b>\n";
        $msgText .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $msgText .= "Для подачи заявок используйте веб-форму:\n\n";
        $msgText .= "🏪 Ресторан: <b>{$prettyRn}</b> ({$addr})\n";
        $msgText .= "🔗 Ссылка: {$siteUrl}/restaurant\n";
        $msgText .= "👤 Логин: <b>{$prettyRn}</b>\n";
        $msgText .= "🔑 Пароль выдаёт отдел закупок\n\n";
        $msgText .= "⏰ Дедлайн подачи заявки: <b>до 10:00</b> (день перед доставкой)\n";

        $keyboard = json_encode(['inline_keyboard' => [
            [['text' => '🛒 Открыть форму заказа', 'url' => "{$siteUrl}/restaurant"]],
        ]]);

        foreach ($chatIds as $cid) {
            $result = sendTelegramMessage($botToken, $cid, $msgText, 'HTML');
            if ($result) $sent++;
        }
    }

    $text = "✅ Рассылка завершена\n\n";
    $text .= "📨 Отправлено: {$sent}\n";
    $text .= "❌ Без подписки в боте: {$notFound}\n";
    $text .= "\n<i>Пароли не рассылаются автоматически. Сообщите их ресторанам отдельно.</i>";

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'cmd_menu']]]]);
}
