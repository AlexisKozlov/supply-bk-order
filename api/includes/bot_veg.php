<?php
// ═══ Овощи: функции подписки и уведомлений ═══
// cmdVegStats, vegShowMySubs, vegShowRestaurants, vegShowSubsManage,
// vegGetFormLink, vegShowMyOrders, vegShowRestOrders, vegNotifySubscribers

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
    $s = $pdo->prepare("SELECT vs.restaurant_number, r.address, r.city
        FROM veg_telegram_subs vs
        LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
        WHERE vs.chat_id = ?
        ORDER BY CAST(vs.restaurant_number AS UNSIGNED)");
    $s->execute([$chatId]);
    $subs = $s->fetchAll();

    $text = "🥬 <b>Заявки на овощи</b>\n\n";
    if ($subs) {
        $text .= "Вы подписаны на уведомления:\n";
        foreach ($subs as $sub) {
            $addr = $sub['address'] ? mb_substr($sub['address'], 0, 40) : $sub['city'];
            $text .= "  • Ресторан <b>{$sub['restaurant_number']}</b> — {$addr}\n";
        }
        $text .= "\nВы будете получать напоминания о дедлайнах и подтверждения заявок.\n";
    } else {
        $text .= "Вы ещё не подписаны ни на один ресторан.\n";
    }
    $text .= "\nВыберите действие:";

    $btns = [
        [['text' => '➕ Подписаться на ресторан', 'callback_data' => 'veg_pick_rest']],
    ];
    if ($subs) {
        $btns[] = [['text' => '📝 Подать заявку', 'callback_data' => 'vegord_start']];
        $btns[] = [['text' => '📋 Мои заявки', 'callback_data' => 'veg_my_orders']];
        $btns[] = [['text' => '🔔 Мои подписки (нажмите для отписки)', 'callback_data' => 'veg_my_subs_manage']];
    }
    $formLink = vegGetFormLink();
    if ($formLink) {
        $btns[] = [['text' => '🌐 Заявка через сайт', 'url' => $formLink]];
    }
    $btns[] = [['text' => '◂ Закрыть', 'callback_data' => 'start_back']];
    $markup = ['inline_keyboard' => $btns];

    if ($msgId) editMessage($chatId, $msgId, $text, $markup);
    else sendMessage($chatId, $text, $markup);
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
        WHERE vo.session_id = ? AND vo.restaurant_number = ? AND vo.quantity > 0
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
            foreach ($items as $item) {
                $text .= "  • {$item['product_name']}: <b>{$item['quantity']}</b> {$item['unit']}\n";
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

        $status = $hasOrder ? '✅' : ($minutesLeft < 0 ? '⏰ истёк' : '');
        $label = "{$dayName} {$dateFmt} {$status}";

        if ($minutesLeft >= 0 || $hasOrder) {
            $btns[] = [['text' => $label, 'callback_data' => "vegord_day_{$restNum}_{$dateStr}"]];
        }
    }

    if (!$btns) {
        $text .= "\n\n<i>Нет доступных дней для заявки.</i>";
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'vegord_start']];
    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
}

// Шаг 3: показ товаров для ввода количеств
function vegOrderShowProducts($chatId, $msgId, $restNum, $deliveryDate) {
    global $pdo;
    $session = $pdo->query("SELECT id, name FROM veg_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$session) return;

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
        WHERE o.session_id = ? AND o.restaurant_number = ? AND o.delivery_date < ? AND (o.quantity > 0 OR (o.admin_qty IS NOT NULL AND o.admin_qty > 0))
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
                WHERE o.session_id = ? AND o.restaurant_number = ? AND (o.quantity > 0 OR (o.admin_qty IS NOT NULL AND o.admin_qty > 0))
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

    // Показываем предыдущую заявку
    if ($prevByProduct) {
        $prevDateFmt = date('d.m', strtotime($prevDate));
        $text .= "📋 <b>Пред. заявка ({$prevDateFmt}):</b>\n";
        foreach ($prevByProduct as $name => $qtyStr) {
            $text .= "  • {$name} — <b>{$qtyStr}</b>\n";
        }
        $text .= "─────────────────────\n";
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

    $text .= "Отправьте количества в формате:\n<code>";
    foreach ($products as $p) {
        $unit = $p['unit'] === 'pcs' ? 'шт' : 'кг';
        $qty = $existing[$p['id']] ?? 0;
        $text .= "{$p['product_name']}: {$qty}\n";
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
