<?php
// ═══ Овощи: функции подписки и уведомлений ═══
// cmdVegStats, vegShowMySubs, vegShowRestaurants, vegShowSubsManage,
// vegGetFormLink, vegShowMyOrders, vegShowRestOrders, vegNotifySubscribers
//
// ═══ Корректировки заказов ═══
// corrStart, corrShowDelivery, corrSearchProduct, corrProcessTextInput,
// corrSubmit, corrNotifyPurchasers, corrGetNextDeliveries, corrReview

// ═══ Овощи: статистика подписок (для админа) ═══

function cmdVegStats($chatId, $msgId) {
    global $pdo;

    // Все подписки
    $subs = $pdo->query("SELECT vs.restaurant_number, vs.chat_id, vs.created_at
        FROM veg_telegram_subs vs
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)")->fetchAll();

    // Все активные рестораны
    $allRests = $pdo->query("SELECT number, address, city FROM restaurants WHERE active=1 AND legal_entity_group='BK_VM' ORDER BY CAST(number AS UNSIGNED)")->fetchAll();
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

    $text = "🥬 <b>Подписки на овощные уведомления</b>\n\n";
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
            $text .= "  {$restNum} ({$cnt} чел.) — {$addr}\n";
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
            $text .= "  {$r['number']} — {$addr}\n";
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

    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ?
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

    $btns = [];

    if ($subs) {
        $restNums = array_column($subs, 'restaurant_number');
        $restList = implode(', ', $restNums);

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
        $text .= "📦 <b>Поставки</b> — график, корректировки, остатки\n";
        $text .= "🥬 <b>Овощи</b> — заявки, мои заявки\n";
        if ($activeSc) $text .= "📋 <b>Сбор остатков</b> — {$activeSc['name']}\n";

        $btns[] = [
            ['text' => '📦 Поставки', 'callback_data' => 'rest_menu_main'],
            ['text' => '🥬 Овощи', 'callback_data' => 'rest_menu_veg'],
        ];
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
            ['text' => '➕ Добавить', 'callback_data' => 'veg_pick_rest'],
        ];
    } else {
        $btns[] = [['text' => '➕ Подписаться на ресторан', 'callback_data' => 'veg_pick_rest']];
    }

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
        [['text' => '📦 Остатки склада', 'callback_data' => 'rest_stock']],
        [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Подменю: Овощи
function restMenuVeg($chatId, $msgId) {
    $text = "🥬 <b>Овощи</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "📝 <b>Заявка</b> — подать через бот или сайт\n";
    $text .= "📋 <b>Мои заявки</b> — статус отправленных";

    $btns = [
        [
            ['text' => '📝 Подать заявку', 'callback_data' => 'vegord_start'],
            ['text' => '📋 Мои заявки', 'callback_data' => 'veg_my_orders'],
        ],
    ];
    $formLink = vegGetFormLink();
    if ($formLink) {
        $btns[] = [['text' => '🌐 Через сайт', 'web_app' => ['url' => $formLink]]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// График доставок для ресторана (все подписанные рестораны)
function restShowSchedule($chatId, $msgId) {
    global $pdo;

    $s = $pdo->prepare("SELECT vs.restaurant_number FROM veg_telegram_subs vs WHERE vs.chat_id = ? ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $restNums = $s->fetchAll(PDO::FETCH_COLUMN);

    if (!$restNums) {
        editMessage($chatId, $msgId, "📅 Сначала подпишитесь на ресторан.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]);
        return;
    }

    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];
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

    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $st = $pdo->prepare("
        SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box
        FROM analysis_data a
        LEFT JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = a.sku COLLATE utf8mb4_unicode_ci
            AND p.legal_entity COLLATE utf8mb4_unicode_ci = a.legal_entity COLLATE utf8mb4_unicode_ci
        WHERE a.legal_entity = 'ООО \"Бургер БК\"'
            AND (a.sku LIKE ? OR p.name LIKE ?)
        ORDER BY a.stock DESC
        LIMIT 10
    ");
    $st->execute(["%{$escaped}%", "%{$escaped}%"]);
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
    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ? ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

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
        $btns[] = [['text' => "🏪 {$sub['restaurant_number']} — {$addr}{$done}", 'callback_data' => "sc_rest_{$collectionId}_{$sub['restaurant_number']}"]];
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
    $text .= "🏪 Ресторан <b>{$restNum}</b>\n";
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
    $confirmText = "✅ <b>Остатки сохранены!</b>\n🏪 Ресторан <b>{$restNum}</b>\n─────────────────────\n";
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

function vegShowRestaurants($chatId, $msgId = null, $page = 0) {
    global $pdo;
    // Все активные рестораны (дедупликация)
    $s = $pdo->query("SELECT number, address, city FROM restaurants WHERE active=1 AND legal_entity_group='BK_VM' ORDER BY CAST(number AS UNSIGNED)");
    $allRests = $s->fetchAll();

    // Уже подписанные
    $s2 = $pdo->prepare("SELECT restaurant_number FROM veg_telegram_subs WHERE chat_id=?");
    $s2->execute([$chatId]);
    $subbed = array_column($s2->fetchAll(), 'restaurant_number');

    $perPage = 10;
    $total = count($allRests);
    $pages = ceil($total / $perPage);
    $page = max(0, min($page, $pages - 1));
    $slice = array_slice($allRests, $page * $perPage, $perPage);

    $text = "🏪 <b>Выберите ресторан</b> (стр. " . ($page + 1) . "/$pages)\n\n";
    $text .= "✅ = подписаны. Нажмите чтобы отписаться.\n";

    $btns = [];
    foreach ($slice as $r) {
        $num = $r['number'];
        $mark = in_array($num, $subbed) ? '✅ ' : '';
        $addr = mb_substr($r['address'] ?: $r['city'], 0, 35);
        $btns[] = [['text' => "{$mark}{$num} — {$addr}", 'callback_data' => "veg_sub_{$num}"]];
    }

    // Навигация
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '◀', 'callback_data' => 'veg_page_' . ($page - 1)];
    if ($page < $pages - 1) $nav[] = ['text' => '▶', 'callback_data' => 'veg_page_' . ($page + 1)];
    if ($nav) $btns[] = $nav;
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];

    $markup = ['inline_keyboard' => $btns];
    if ($msgId) editMessage($chatId, $msgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
}

// Callback veg_my_subs_manage — список с кнопками отписки
function vegShowSubsManage($chatId, $msgId) {
    global $pdo;
    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ?
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

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
        $btns[] = [['text' => "❌ {$sub['restaurant_number']} — {$addr}", 'callback_data' => "veg_unsub_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Получить ссылку на форму заявки (активный токен)
function vegGetFormLink() {
    global $pdo, $SITE_URL;
    $token = $pdo->query("SELECT token FROM veg_tokens WHERE expires_at > NOW() ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if (!$token) return null;
    return "{$SITE_URL}/veg-order/{$token}";
}

// Просмотр заявок — список ресторанов, на которые подписан
function vegShowMyOrders($chatId, $msgId) {
    global $pdo;
    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ?
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

    if (!$subs) {
        editMessage($chatId, $msgId, "📋 У вас нет подписок.\nСначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']],
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    $text = "📋 <b>Мои заявки</b>\n\nВыберите ресторан:";
    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $btns[] = [['text' => "🏪 {$sub['restaurant_number']} — {$addr}", 'callback_data' => "veg_orders_rest_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Показать заявки конкретного ресторана
function vegShowRestOrders($chatId, $msgId, $restNum) {
    global $pdo;

    // Активная сессия
    $session = $pdo->query("SELECT id, name FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) {
        editMessage($chatId, $msgId, "📋 <b>Заявки ресторана {$restNum}</b>\n\nНет активной сессии.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_orders']],
        ]]);
        return;
    }

    // Заявки
    $s = $pdo->prepare("SELECT vo.delivery_date, sp.product_name, sp.unit, vo.quantity
        FROM veg_orders vo
        JOIN veg_session_products sp ON sp.id = vo.product_id AND sp.session_id = vo.session_id
        WHERE vo.session_id = ? AND vo.restaurant_number = ?
        ORDER BY vo.delivery_date, sp.sort_order, sp.product_name");
    $s->execute([$session['id'], $restNum]);
    $orders = $s->fetchAll();

    $text = "📋 <b>Заявки ресторана {$restNum}</b>\n";
    $text .= "📝 Сессия: {$session['name']}\n\n";

    if (!$orders) {
        $text .= "<i>Заявок пока нет.</i>\n";
    } else {
        $byDate = [];
        foreach ($orders as $o) {
            $byDate[$o['delivery_date']][] = $o;
        }
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        foreach ($byDate as $date => $items) {
            $dow = (int)(new DateTime($date))->format('N');
            $dayName = $dayNames[$dow] ?? '';
            $dateFmt = (new DateTime($date))->format('d.m');
            $text .= "📅 <b>{$dayName} {$dateFmt}</b>\n";
            $allZero = true;
            foreach ($items as $item) {
                if (floatval($item['quantity']) > 0) $allZero = false;
            }
            if ($allZero) {
                $text .= "  <i>Поставка не нужна</i>\n";
            } else {
                foreach ($items as $item) {
                    if (floatval($item['quantity']) > 0) {
                        $text .= "  • {$item['product_name']}: <b>{$item['quantity']}</b> {$item['unit']}\n";
                    }
                }
            }
            $text .= "\n";
        }
    }

    $btns = [];
    $formLink = vegGetFormLink();
    if ($formLink) {
        $btns[] = [['text' => '📝 Заполнить/изменить заявку', 'url' => $formLink]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_orders']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// ═══ Подача заявки на овощи через бота ═══

// Шаг 1: выбор ресторана для заявки
function vegStartOrder($chatId, $msgId = null) {
    global $pdo;
    $session = $pdo->query("SELECT id, name FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) {
        $text = "🥬 <b>Подача заявки</b>\n\n<i>Нет активной сессии сбора заявок.</i>";
        $btns = [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]];
        if ($msgId) editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        else sendMessage($chatId, $text, ['inline_keyboard' => $btns]);
        return;
    }

    // Рестораны, на которые подписан
    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address FROM veg_telegram_subs vs LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM' WHERE vs.chat_id = ? ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

    if (!$subs) {
        $text = "🥬 <b>Подача заявки</b>\n\nСначала подпишитесь на ресторан.";
        $btns = [[['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']], [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]];
        if ($msgId) editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        else sendMessage($chatId, $text, ['inline_keyboard' => $btns]);
        return;
    }

    $text = "🥬 <b>Подача заявки</b>\n📝 Сессия: {$session['name']}\n\nВыберите ресторан:";
    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: '', 0, 30);
        $btns[] = [['text' => "🏪 {$sub['restaurant_number']} — {$addr}", 'callback_data' => "vegord_rest_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    if ($msgId) editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
    else sendMessage($chatId, $text, ['inline_keyboard' => $btns]);
}

// Шаг 2: выбор дня доставки
function vegOrderSelectDay($chatId, $msgId, $restNum) {
    global $pdo;
    $session = $pdo->query("SELECT id, name, date_from, date_to FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) { editMessage($chatId, $msgId, "Нет активной сессии.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']]]]); return; }

    // Дни доставки для этого ресторана
    $s = $pdo->prepare("SELECT day_of_week FROM veg_delivery_days WHERE restaurant_number = ? ORDER BY day_of_week");
    $s->execute([$restNum]);
    $days = $s->fetchAll(PDO::FETCH_COLUMN);

    if (!$days) {
        editMessage($chatId, $msgId, "🥬 Для ресторана {$restNum} не настроены дни доставки.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => 'vegord_start']]]]);
        return;
    }

    // Дедлайны
    $dlRows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules")->fetchAll();
    $deadlines = [];
    foreach ($dlRows as $r) $deadlines[(int)$r['delivery_dow']] = $r;

    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];

    $text = "🥬 <b>Ресторан {$restNum}</b>\n📝 {$session['name']}\n\nВыберите день доставки:";
    $btns = [];

    // Диапазон дат сессии (если задан — показываем только дни внутри него)
    $sessionFrom = $session['date_from'] ? new DateTime($session['date_from'], $tz) : null;
    $sessionTo = $session['date_to'] ? new DateTime($session['date_to'], $tz) : null;
    if ($sessionTo) $sessionTo->setTime(23, 59, 59);

    // Ищем ближайшие даты доставки (7 дней вперёд)
    for ($i = 0; $i <= 7; $i++) {
        $check = clone $now;
        $check->modify("+{$i} days");
        $checkDow = (int)$check->format('N');
        if (!in_array($checkDow, $days)) continue;
        if (!isset($deadlines[$checkDow])) continue;
        // Пропускаем дни вне диапазона сессии
        if ($sessionFrom && $check < $sessionFrom) continue;
        if ($sessionTo && $check > $sessionTo) continue;

        $rule = $deadlines[$checkDow];
        $deadlineDow = (int)$rule['deadline_dow'];
        $diff = $checkDow - $deadlineDow;
        if ($diff <= 0) $diff += 7;
        $deadline = clone $check;
        $deadline->modify("-{$diff} days");
        $timeParts = explode(':', $rule['deadline_time']);
        $deadline->setTime((int)$timeParts[0], (int)($timeParts[1] ?? 0));

        $minutesLeft = ($deadline->getTimestamp() - $now->getTimestamp()) / 60;
        $dateStr = $check->format('Y-m-d');
        $dateFmt = $check->format('d.m');
        $dayName = $dayNames[$checkDow] ?? '';

        // Проверяем есть ли уже заявка
        $existing = $pdo->prepare("SELECT COUNT(*) FROM veg_orders WHERE session_id=? AND restaurant_number=? AND delivery_date=?");
        $existing->execute([$session['id'], $restNum, $dateStr]);
        $hasOrder = $existing->fetchColumn() > 0;

        if ($minutesLeft >= 0) {
            $status = $hasOrder ? '✅' : '';
            $btns[] = [['text' => "{$dayName} {$dateFmt} {$status}", 'callback_data' => "vegord_day_{$restNum}_{$dateStr}"]];
        } elseif ($hasOrder) {
            $btns[] = [['text' => "{$dayName} {$dateFmt} ✅ (дедлайн прошёл)", 'callback_data' => "vegord_day_{$restNum}_{$dateStr}"]];
        }
    }

    if (!$btns) {
        $text .= "\n\n<i>Нет доступных дней для заявки.</i>";
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'vegord_start']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Проверка дедлайна для даты доставки
function vegCheckDeadline($deliveryDate) {
    global $pdo;
    $dlRows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules")->fetchAll();
    $deadlines = [];
    foreach ($dlRows as $r) $deadlines[(int)$r['delivery_dow']] = $r;

    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $delDt = new DateTime($deliveryDate, $tz);
    $dow = (int)$delDt->format('N');

    if (!isset($deadlines[$dow])) return true; // нет правила — разрешаем
    $rule = $deadlines[$dow];
    $deadlineDow = (int)$rule['deadline_dow'];
    $deadline = clone $delDt;
    $diff = $dow - $deadlineDow;
    if ($diff <= 0) $diff += 7;
    $deadline->modify("-{$diff} days");
    $timeParts = explode(':', $rule['deadline_time']);
    $deadline->setTime((int)$timeParts[0], (int)($timeParts[1] ?? 0), 0);

    return $now < $deadline; // true = дедлайн ещё не прошёл
}

// Шаг 3: показ товаров для ввода количеств
function vegOrderShowProducts($chatId, $msgId, $restNum, $deliveryDate) {
    global $pdo;
    $session = $pdo->query("SELECT id, name FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) return;

    // Проверяем дедлайн
    if (!vegCheckDeadline($deliveryDate)) {
        // Показываем что было в заявке (только просмотр)
        $ords = $pdo->prepare("SELECT sp.product_name, sp.unit, vo.quantity, vo.admin_qty
            FROM veg_orders vo
            JOIN veg_session_products sp ON sp.id = vo.product_id
            WHERE vo.session_id = ? AND vo.restaurant_number = ? AND vo.delivery_date = ?
            ORDER BY sp.sort_order, sp.product_name");
        $ords->execute([$session['id'], $restNum, $deliveryDate]);
        $rows = $ords->fetchAll();

        $dateFmt = date('d.m', strtotime($deliveryDate));
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        $dow = (int)date('N', strtotime($deliveryDate));
        $text = "📋 <b>Заявка: рест. {$restNum}</b>\n";
        $text .= "📅 {$dayNames[$dow]} {$dateFmt}\n";
        $text .= "⏰ <i>Дедлайн прошёл — изменить нельзя</i>\n";
        $text .= "─────────────────────\n";

        if ($rows) {
            $allZero = true;
            foreach ($rows as $r) {
                $q = ($r['admin_qty'] !== null && $r['admin_qty'] !== '') ? floatval($r['admin_qty']) : floatval($r['quantity']);
                if ($q > 0) $allZero = false;
            }
            if ($allZero) {
                $text .= "<i>Поставка не нужна</i>\n";
            } else {
                foreach ($rows as $r) {
                    $q = ($r['admin_qty'] !== null && $r['admin_qty'] !== '') ? floatval($r['admin_qty']) : floatval($r['quantity']);
                    $unit = $r['unit'] === 'pcs' ? 'шт' : 'кг';
                    $qFmt = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
                    if ($r['admin_qty'] !== null && $r['admin_qty'] !== '') {
                        $origQ = rtrim(rtrim(number_format(floatval($r['quantity']), 2, '.', ''), '0'), '.');
                        $text .= "  • {$r['product_name']}: <b>{$qFmt}</b> {$unit} <i>(было {$origQ})</i>\n";
                    } else {
                        $text .= "  • {$r['product_name']}: <b>{$qFmt}</b> {$unit}\n";
                    }
                }
            }
        } else {
            $text .= "<i>Заявка не была подана</i>\n";
        }

        editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return;
    }

    // Товары сессии (с кратностью)
    $s = $pdo->prepare("SELECT sp.id, sp.product_name, sp.unit, sp.multiplicity, sp.sort_order FROM veg_session_products sp WHERE sp.session_id = ? ORDER BY sp.sort_order, sp.product_name");
    $s->execute([$session['id']]);
    $products = $s->fetchAll();

    // Существующие количества
    $s = $pdo->prepare("SELECT product_id, quantity FROM veg_orders WHERE session_id=? AND restaurant_number=? AND delivery_date=?");
    $s->execute([$session['id'], $restNum, $deliveryDate]);
    $existing = [];
    foreach ($s->fetchAll() as $r) $existing[$r['product_id']] = $r['quantity'];

    // Предыдущая заявка: сначала из текущей сессии (другие даты), потом из предыдущих сессий
    $prevByProduct = [];
    $prevDate = null;
    // 1. Текущая сессия — заявки этого ресторана на другие (более ранние) даты доставки
    $ps = $pdo->prepare("
        SELECT sp.product_name, sp.unit, o.quantity, o.admin_qty, o.delivery_date
        FROM veg_orders o
        JOIN veg_session_products sp ON sp.id = o.product_id
        WHERE o.session_id = ? AND o.restaurant_number = ? AND o.delivery_date < ?
        ORDER BY o.delivery_date DESC, sp.sort_order
    ");
    $ps->execute([$session['id'], $restNum, $deliveryDate]);
    $prevItems = $ps->fetchAll();
    if ($prevItems) {
        $prevDate = $prevItems[0]['delivery_date'];
        foreach ($prevItems as $pi) {
            if ($pi['delivery_date'] !== $prevDate) break; // только последний день
            if (!isset($prevByProduct[$pi['product_name']])) {
                $qty = ($pi['admin_qty'] !== null && $pi['admin_qty'] !== '') ? $pi['admin_qty'] : $pi['quantity'];
                $unit = $pi['unit'] === 'pcs' ? 'шт' : 'кг';
                $prevByProduct[$pi['product_name']] = rtrim(rtrim(number_format(floatval($qty), 2, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }
    }
    // 2. Если в текущей сессии нет — ищем в предыдущих сессиях (до 5 назад)
    if (!$prevByProduct) {
        $prevSessStmt = $pdo->prepare("SELECT id FROM veg_sessions WHERE id < ? ORDER BY id DESC LIMIT 5");
        $prevSessStmt->execute([$session['id']]);
        while ($prevSessRow = $prevSessStmt->fetch()) {
            $po = $pdo->prepare("
                SELECT sp.product_name, sp.unit, o.quantity, o.admin_qty, o.delivery_date
                FROM veg_orders o
                JOIN veg_session_products sp ON sp.id = o.product_id
                WHERE o.session_id = ? AND o.restaurant_number = ?
                ORDER BY o.delivery_date DESC, sp.sort_order
            ");
            $po->execute([$prevSessRow['id'], $restNum]);
            $prevItems = $po->fetchAll();
            if ($prevItems) {
                $prevDate = $prevItems[0]['delivery_date'];
                foreach ($prevItems as $pi) {
                    if ($pi['delivery_date'] !== $prevDate) break;
                    if (!isset($prevByProduct[$pi['product_name']])) {
                        $qty = ($pi['admin_qty'] !== null && $pi['admin_qty'] !== '') ? $pi['admin_qty'] : $pi['quantity'];
                        $unit = $pi['unit'] === 'pcs' ? 'шт' : 'кг';
                        $prevByProduct[$pi['product_name']] = rtrim(rtrim(number_format(floatval($qty), 2, '.', ''), '0'), '.') . ' ' . $unit;
                    }
                }
                break; // нашли — выходим
            }
        }
    }

    $dateFmt = date('d.m', strtotime($deliveryDate));
    $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
    $dow = (int)date('N', strtotime($deliveryDate));
    $dayName = $dayNames[$dow] ?? '';

    $text = "🥬 <b>Заявка: рест. {$restNum}</b>\n";
    $text .= "📅 {$dayName} {$dateFmt}\n";
    $text .= "─────────────────────\n";

    // Если заявка на этот день уже подана — показываем её
    $hasExisting = !empty($existing);
    if ($hasExisting) {
        $allZero = true;
        foreach ($products as $p) {
            if (floatval($existing[$p['id']] ?? 0) > 0) { $allZero = false; break; }
        }
        if ($allZero) {
            $text .= "✅ <b>Заявка подана:</b> поставка не нужна\n";
        } else {
            $text .= "✅ <b>Ваша заявка:</b>\n";
            foreach ($products as $p) {
                $qty = floatval($existing[$p['id']] ?? 0);
                if ($qty > 0) {
                    $unit = $p['unit'] === 'pcs' ? 'шт' : 'кг';
                    $qFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
                    $text .= "  • {$p['product_name']}: <b>{$qFmt}</b> {$unit}\n";
                }
            }
        }
        $text .= "─────────────────────\n";
        $text .= "Чтобы <b>изменить</b>, отправьте новые количества:\n";
    } else {
        // Показываем предыдущую заявку как ориентир
        if ($prevByProduct) {
            $prevDateFmt = date('d.m', strtotime($prevDate));
            $text .= "📋 <b>Пред. заявка ({$prevDateFmt}):</b>\n";
            foreach ($prevByProduct as $name => $qtyStr) {
                $text .= "  • {$name} — <b>{$qtyStr}</b>\n";
            }
            $text .= "─────────────────────\n";
        }
        $text .= "Отправьте количества в формате:\n";
    }

    if (!$products) {
        $text .= "<i>Товары не настроены.</i>";
        editMessage($chatId, $msgId, $text, ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return;
    }

    // Показываем кратность
    $hasMultiplicity = false;
    foreach ($products as $p) {
        if ($p['multiplicity'] > 0) { $hasMultiplicity = true; break; }
    }

    $text .= "<code>";
    foreach ($products as $p) {
        $unit = $p['unit'] === 'pcs' ? 'шт' : 'кг';
        $qty = $existing[$p['id']] ?? 0;
        $qFmt = rtrim(rtrim(number_format(floatval($qty), 2, '.', ''), '0'), '.');
        $text .= "{$p['product_name']}: {$qFmt}\n";
    }
    $text .= "</code>\n";

    if ($hasMultiplicity) {
        $text .= "\n⚠️ <b>Кратность заказа:</b>\n";
        foreach ($products as $p) {
            if ($p['multiplicity'] > 0) {
                $unit = $p['unit'] === 'pcs' ? 'шт' : 'кг';
                $m = rtrim(rtrim(number_format($p['multiplicity'], 2, '.', ''), '0'), '.');
                $text .= "  • {$p['product_name']}: кратно <b>{$m}</b> {$unit}\n";
            }
        }
    }

    $text .= "\nСкопируйте, измените числа и отправьте.\n";
    $text .= "Или <b>0</b> если товар не нужен.";

    // Сохраняем контекст ввода (через файл — работает и для ресторанов без аккаунта)
    @file_put_contents(sys_get_temp_dir() . "/vegord_{$chatId}.txt", "vegord_{$restNum}_{$deliveryDate}_{$session['id']}");

    $btns = [
        [['text' => '🚫 Поставка не нужна', 'callback_data' => "vegord_skip_{$restNum}_{$deliveryDate}"]],
        [['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]],
    ];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Поставка не нужна — все товары = 0
function vegOrderSkipDay($chatId, $msgId, $restNum, $deliveryDate) {
    global $pdo;
    $session = $pdo->query("SELECT id FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) return;

    // Проверяем дедлайн
    if (!vegCheckDeadline($deliveryDate)) {
        editMessage($chatId, $msgId, "⏰ Дедлайн для этой даты доставки уже прошёл.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return;
    }

    $s = $pdo->prepare("SELECT id, product_name, unit FROM veg_session_products WHERE session_id = ? ORDER BY sort_order, product_name");
    $s->execute([$session['id']]);
    $products = $s->fetchAll();
    if (!$products) return;

    $ins = $pdo->prepare("INSERT INTO veg_orders (session_id, product_id, restaurant_number, delivery_date, quantity, submitted_at)
        VALUES (?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE quantity = 0, submitted_at = NOW()");
    foreach ($products as $p) {
        $ins->execute([$session['id'], $p['id'], $restNum, $deliveryDate]);
    }

    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");

    $dateFmt = date('d.m', strtotime($deliveryDate));
    $msg = "✅ <b>Заявка сохранена!</b>\n\n🏪 Ресторан <b>{$restNum}</b>\n📅 Доставка: {$dateFmt}\n\n";
    $msg .= "<i>Все товары: 0 (поставка не нужна)</i>";

    editMessage($chatId, $msgId, $msg, ['inline_keyboard' => [
        [['text' => '📋 Мои заявки', 'callback_data' => 'veg_my_orders']],
        [['text' => '◂ Меню овощей', 'callback_data' => 'veg_my_subs']],
    ]]);
}

// Обработка введённых количеств
function vegOrderProcessInput($chatId, $text, $mode) {
    global $pdo;
    // mode = vegord_{restNum}_{deliveryDate}_{sessionId}
    $parts = explode('_', $mode);
    if (count($parts) < 4) return false;
    $restNum = $parts[1];
    $deliveryDate = $parts[2];
    $sessionId = (int)$parts[3];

    // Проверяем дедлайн
    if (!vegCheckDeadline($deliveryDate)) {
        @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");
        sendMessage($chatId, "⏰ Дедлайн для этой даты доставки уже прошёл. Заявка не сохранена.", ['inline_keyboard' => [[['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return true;
    }

    // Товары сессии (с кратностью)
    $s = $pdo->prepare("SELECT id, product_name, unit, multiplicity FROM veg_session_products WHERE session_id = ? ORDER BY sort_order, product_name");
    $s->execute([$sessionId]);
    $products = $s->fetchAll();

    if (!$products) { sendMessage($chatId, "Товары не найдены."); return true; }

    // Парсим ввод — формат "Название: количество" на каждой строке
    $lines = preg_split('/\n/', trim($text));
    $quantities = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (preg_match('/^(.+?):\s*([\d.,]+)\s*$/u', $line, $m)) {
            $name = trim($m[1]);
            $qty = floatval(str_replace(',', '.', $m[2]));
            $quantities[$name] = $qty;
        }
    }

    if (empty($quantities)) {
        sendMessage($chatId, "Не удалось распознать данные. Отправьте в формате:\n<code>Название: количество</code>", ['inline_keyboard' => [[['text' => '❌ Отменить', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return true;
    }

    // Сопоставляем с товарами, проверяем кратность и сохраняем
    $saved = 0;
    $ins = $pdo->prepare("INSERT INTO veg_orders (session_id, product_id, restaurant_number, delivery_date, quantity, submitted_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), submitted_at = NOW()");
    $resultLines = [];
    $roundedLines = [];

    foreach ($products as $p) {
        foreach ($quantities as $name => $qty) {
            if (mb_strtolower($name) === mb_strtolower($p['product_name']) || mb_strpos(mb_strtolower($p['product_name']), mb_strtolower($name)) !== false) {
                $unit = $p['unit'] === 'pcs' ? 'шт' : 'кг';
                $mult = floatval($p['multiplicity'] ?? 0);

                // Проверка и округление кратности
                if ($qty > 0 && $mult > 0) {
                    $rounded = ceil($qty / $mult) * $mult;
                    if (abs($rounded - $qty) > 0.001) {
                        $roundedLines[] = "  ⚠️ {$p['product_name']}: {$qty} → <b>{$rounded}</b> {$unit} (кратно {$mult})";
                        $qty = $rounded;
                    }
                }

                $ins->execute([$sessionId, $p['id'], $restNum, $deliveryDate, $qty]);
                if ($qty > 0) {
                    $qtyFmt = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
                    $resultLines[] = "• {$p['product_name']}: <b>{$qtyFmt}</b> {$unit}";
                }
                $saved++;
                break;
            }
        }
    }

    // Сбрасываем режим
    @unlink(sys_get_temp_dir() . "/vegord_{$chatId}.txt");

    if ($saved === 0) {
        sendMessage($chatId, "Не удалось сопоставить товары. Проверьте названия.", ['inline_keyboard' => [[['text' => '🔄 Повторить', 'callback_data' => "vegord_day_{$restNum}_{$deliveryDate}"], ['text' => '◂ Назад', 'callback_data' => "vegord_rest_{$restNum}"]]]]);
        return true;
    }

    $dateFmt = date('d.m', strtotime($deliveryDate));
    $msg = "✅ <b>Заявка сохранена!</b>\n\n🏪 Ресторан <b>{$restNum}</b>\n📅 Доставка: {$dateFmt}\n\n";
    if (empty($resultLines)) {
        $msg .= "<i>Все товары: 0 (ничего не нужно)</i>";
    } else {
        $msg .= implode("\n", $resultLines);
    }
    if ($roundedLines) {
        $msg .= "\n\n📐 <b>Округлено по кратности:</b>\n" . implode("\n", $roundedLines);
    }

    sendMessage($chatId, $msg, ['inline_keyboard' => [
        [['text' => '📋 Мои заявки', 'callback_data' => 'veg_my_orders']],
        [['text' => '◂ Меню овощей', 'callback_data' => 'veg_my_subs']],
    ]]);

    return true;
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
            $text .= "{$si} Р-н <b>{$c['restaurant_number']}</b> {$dateFmt} | {$actionLabel} {$name} " . corrFmtQty($c['quantity']) . " {$uom}";
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

    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ? ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();
    if (!$subs) {
        editMessage($chatId, $msgId, "✏️ <b>Корректировка заказа</b>\n\nСначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '➕ Подписаться', 'callback_data' => 'veg_pick_rest']],
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }
    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $btns[] = [['text' => "🏪 {$sub['restaurant_number']} — {$addr}", 'callback_data' => "corr_rest_{$sub['restaurant_number']}"]];
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
        $summary = "📋 <b>Корректировка: рест. {$state['rest']}</b>\n";
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

    // INSERT всех позиций
    $corrIds = [];
    $ins = $pdo->prepare("INSERT INTO order_corrections (restaurant_number, restaurant_chat_id, submitter_name, delivery_date, action, product_sku, product_name, quantity, unit_of_measure) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($state['items'] as $item) {
        $ins->execute([$state['rest'], $chatId, $submitterName, $state['date'], $item['action'], $item['sku'], $item['product_name'], $item['qty'], $item['unit'] ?? 'кор.']);
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
    $text .= "🏪 Ресторан <b>{$restNum}</b>{$from} | {$dateFmt}\n";
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
    $text .= "🏪 Ресторан <b>{$first['restaurant_number']}</b> | Доставка: {$dateFmt}\n";
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
