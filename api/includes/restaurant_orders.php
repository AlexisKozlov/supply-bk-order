<?php
/**
 * API заказов ресторанов — временный модуль.
 * Подключается из index.php. Все переменные ($pdo, $endpoint, $subpoint, $method, $body) доступны через global.
 *
 * Маршруты:
 *   POST   ro/login           — логин ресторана
 *   POST   ro/logout          — выход
 *   POST   ro/validate        — проверка сессии
 *   GET    ro/my-info         — инфо о ресторане + текущая сессия + дедлайны
 *   GET    ro/my-surveys      — мои опросы
 *   GET    ro/my-survey/:id   — один опрос
 *   GET    ro/products        — товары для формы (из шаблона или stock_malling)
 *   GET    ro/my-orders       — мои заказы (история)
 *   GET    ro/my-order/:date  — мой заказ на дату
 *   POST   ro/submit-order    — отправить заказ
 *   POST   ro/submit-survey   — отправить ответ на опрос
 *   POST   ro/repeat-order    — повторить предыдущий заказ
 *
 *   Для отдела закупок (требуется сессия основного приложения):
 *   GET    ro/admin/status         — статус заявок на дату
 *   GET    ro/admin/order/:id      — детали заказа
 *   PATCH  ro/admin/order/:id      — редактировать заказ
 *   POST   ro/admin/session        — создать/управлять сессией
 *   POST   ro/admin/extend-deadline — продлить дедлайн
 *   GET    ro/admin/export/:format — выгрузка заказов (Excel / JSON)
 *   GET    ro/admin/templates      — шаблоны
 *   POST   ro/admin/templates      — сохранить шаблон
 *   POST   ro/admin/users          — управление учётками ресторанов
 *   GET    ro/admin/users          — список учёток
 */

if ($endpoint !== 'ro') return;

// ═══ Хелперы ═══

function roRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function roInferGroupFromRestaurantNumber($restaurantNumber) {
    return ((int)$restaurantNumber >= 1000) ? 'PS' : 'BK_VM';
}

function roNormalizeLegalEntityGroup($group, $restaurantNumber = null) {
    $g = strtoupper(trim((string)$group));
    if ($g === 'PS' || $g === 'BK_VM') return $g;
    return roInferGroupFromRestaurantNumber($restaurantNumber);
}

function roGetRestaurantRow($pdo, $restaurantNumber, $group = null) {
    $resolvedGroup = roNormalizeLegalEntityGroup($group, $restaurantNumber);
    $s = $pdo->prepare("
        SELECT id, number, region, city, address, legal_entity_group
        FROM restaurants
        WHERE number = ? AND active = 1 AND legal_entity_group = ?
        LIMIT 1
    ");
    $s->execute([(int)$restaurantNumber, $resolvedGroup]);
    return $s->fetch() ?: null;
}

/**
 * Отправляет в Telegram-подписки ресторана уведомление о входе с нового
 * устройства. «Новое» = UA отсутствует среди активных сессий ресторана.
 * Тихая функция: если бот не настроен или подписок нет — ничего не делает.
 */
function roNotifyNewDeviceLogin($pdo, $restaurantNumber, $legalEntityGroup, $ip, $ua, $loginSource) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;
    try {
        $subs = $pdo->prepare("
            SELECT DISTINCT chat_id FROM ro_telegram_subs
            WHERE restaurant_number = ? AND legal_entity_group = ?
              AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
        ");
        $subs->execute([(int)$restaurantNumber, $legalEntityGroup]);
        $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);
        if (!$chatIds) return;
        $label = roMakeDeviceLabel($ua) ?: 'Неизвестное устройство';
        $displayNumber = function_exists('formatRestaurantNumber')
            ? formatRestaurantNumber((int)$restaurantNumber)
            : (string)$restaurantNumber;
        $when = date('d.m.Y H:i');
        $ipText = $ip ? htmlspecialchars((string)$ip, ENT_QUOTES) : '—';
        $sourceText = $loginSource === 'Telegram' ? 'через Telegram-ссылку' : 'по паролю';
        $msg  = "🔔 <b>Новый вход в кабинет ресторана {$displayNumber}</b>\n\n";
        $msg .= "Устройство: <b>" . htmlspecialchars($label, ENT_QUOTES) . "</b>\n";
        $msg .= "IP: <code>{$ipText}</code>\n";
        $msg .= "Когда: {$when}\n";
        $msg .= "Способ: {$sourceText}\n\n";
        $msg .= "Если это не вы — смените пароль через бота и нажмите «Выйти со всех устройств» в кабинете.";
        sendTelegramBulk($botToken, $chatIds, $msg);
    } catch (Throwable $e) {
        // Уведомление — best effort; не должно ломать логин.
    }
}

function roGetRestaurantSession($pdo) {
    $user = roReadActiveSessionRow($pdo);
    if (!$user) return null;
    $rest = roGetRestaurantRow($pdo, $user['restaurant_number'], $user['legal_entity_group'] ?? null);
    $user['region'] = $rest['region'] ?? '';
    $user['city'] = $rest['city'] ?? '';
    $user['address'] = $rest['address'] ?? '';
    if (empty($user['legal_entity_group']) && !empty($rest['legal_entity_group'])) {
        $user['legal_entity_group'] = $rest['legal_entity_group'];
    }
    return $user;
}

function roGetSurveyForRestaurant($pdo, $surveyId, $rest) {
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $stmt = $pdo->prepare("
        SELECT s.id, s.title, s.description, s.legal_entity_group, s.status, s.allow_comment,
               s.sent_at, s.created_at,
               sr.id AS response_id, sr.comment AS response_comment, sr.submitted_at AS response_submitted_at
        FROM surveys s
        LEFT JOIN survey_responses sr
          ON sr.survey_id = s.id
         AND sr.restaurant_number = ?
        WHERE s.id = ?
          AND s.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LIMIT 1
    ");
    $stmt->execute([(int)$rest['restaurant_number'], (int)$surveyId, $group]);
    $survey = $stmt->fetch();
    if (!$survey) return null;
    if (($survey['status'] ?? '') !== 'active') return null;

    $questionsStmt = $pdo->prepare("
        SELECT id, text, type, files_required, sort_order
        FROM survey_questions
        WHERE survey_id = ?
        ORDER BY sort_order, id
    ");
    $questionsStmt->execute([(int)$surveyId]);
    $questions = $questionsStmt->fetchAll();

    $optionsStmt = $pdo->prepare("
        SELECT id, text, sort_order
        FROM survey_options
        WHERE question_id = ?
        ORDER BY sort_order, id
    ");
    foreach ($questions as &$question) {
        $optionsStmt->execute([(int)$question['id']]);
        $question['options'] = $optionsStmt->fetchAll();
    }
    unset($question);

    $survey['questions'] = $questions;
    $survey['already_answered'] = !empty($survey['response_id']);
    $survey['comment'] = $survey['response_comment'] ?? null;
    $survey['submitted_at'] = $survey['response_submitted_at'] ?? null;
    unset($survey['response_comment'], $survey['response_submitted_at']);

    if (!empty($survey['response_id'])) {
        $answersStmt = $pdo->prepare("
            SELECT sa.question_id, sq.type, sa.option_id, sa.numeric_value, sa.text_value
            FROM survey_answers sa
            JOIN survey_questions sq ON sq.id = sa.question_id
            WHERE sa.response_id = ?
        ");
        $answersStmt->execute([(int)$survey['response_id']]);
        $answers = [];
        foreach ($answersStmt->fetchAll() as $answer) {
            $questionId = (int)$answer['question_id'];
            $answers[$questionId] = [
                'option_id' => $answer['option_id'] !== null ? (int)$answer['option_id'] : null,
                'numeric_value' => $answer['numeric_value'] !== null ? (int)$answer['numeric_value'] : null,
                'text_value' => $answer['text_value'] ?? null,
                'type' => $answer['type'] ?: 'choice',
            ];
        }
        $survey['answers'] = $answers;
    } else {
        $survey['answers'] = new stdClass();
    }

    // Файлы по вопросам: либо привязанные к этому response_id (если уже сабмитнули),
    // либо черновики (response_id IS NULL для этой пары survey+ресторан).
    $survey['files'] = roLoadSurveyFiles($pdo, (int)$surveyId, (int)$rest['restaurant_number'], $group, $survey['response_id'] ?? null);
    return $survey;
}

/**
 * Загружает список файлов опроса, сгруппированный по question_id.
 * Если передан $responseId — берёт привязанные к нему файлы (просмотр ответа).
 * Если $responseId NULL — берёт черновики ресторана (ещё не сабмитнутый ответ).
 */
function roLoadSurveyFiles(PDO $pdo, $surveyId, $restaurantNumber, $group, $responseId = null) {
    if ($responseId) {
        $st = $pdo->prepare("
            SELECT id, question_id, file_path, file_name, mime_type, file_size, created_at
            FROM survey_response_files
            WHERE response_id = ?
            ORDER BY id
        ");
        $st->execute([(int)$responseId]);
    } else {
        $st = $pdo->prepare("
            SELECT id, question_id, file_path, file_name, mime_type, file_size, created_at
            FROM survey_response_files
            WHERE response_id IS NULL
              AND survey_id = ?
              AND restaurant_number = ?
              AND legal_entity_group = ?
            ORDER BY id
        ");
        $st->execute([(int)$surveyId, (int)$restaurantNumber, (string)$group]);
    }
    $byQuestion = [];
    foreach ($st->fetchAll() as $row) {
        $qid = (int)$row['question_id'];
        $byQuestion[$qid] ??= [];
        $byQuestion[$qid][] = [
            'id'         => (int)$row['id'],
            'file_name'  => $row['file_name'],
            'mime_type'  => $row['mime_type'],
            'file_size'  => (int)$row['file_size'],
            'created_at' => $row['created_at'],
            'url'        => '/api/' . ltrim((string)$row['file_path'], '/'),
        ];
    }
    return $byQuestion;
}

function roGetActiveSession($pdo, $group = 'BK_VM') {
    $group = $group ?: 'BK_VM';
    $permanentStart = '2000-01-01';
    $permanentEnd = '2099-12-31';
    if (!roSessionsSupportGroups($pdo)) {
        if ($group !== 'BK_VM') return null;
        $pdo->prepare("
            INSERT INTO ro_sessions (week_start, week_end, status, created_by)
            VALUES (?, ?, 'active', 'permanent')
            ON DUPLICATE KEY UPDATE week_end = VALUES(week_end), status = 'active', created_by = 'permanent'
        ")->execute([$permanentStart, $permanentEnd]);
        $s = $pdo->query("
            SELECT *,
                   'BK_VM' AS effective_legal_entity_group
            FROM ro_sessions
            WHERE status = 'active'
            ORDER BY created_by = 'permanent' DESC, week_end DESC, id DESC
            LIMIT 1
        ");
        $session = $s->fetch() ?: null;
        if ($session) $session['legal_entity_group'] = 'BK_VM';
        return $session;
    }
    $pdo->prepare("
        INSERT INTO ro_sessions (week_start, week_end, legal_entity_group, status, created_by)
        VALUES (?, ?, ?, 'active', 'permanent')
        ON DUPLICATE KEY UPDATE week_end = VALUES(week_end), status = 'active', created_by = 'permanent'
    ")->execute([$permanentStart, $permanentEnd, $group]);
    if ($group === 'BK_VM') {
        $s = $pdo->prepare("
            SELECT *,
                   CASE
                       WHEN legal_entity_group IS NULL OR legal_entity_group = '' THEN 'BK_VM'
                       ELSE legal_entity_group
                   END AS effective_legal_entity_group
            FROM ro_sessions
            WHERE (legal_entity_group = ? OR legal_entity_group IS NULL OR legal_entity_group = '')
              AND status = 'active'
            ORDER BY created_by = 'permanent' DESC, week_end DESC, id DESC
            LIMIT 1
        ");
        $s->execute([$group]);
    } else {
        $s = $pdo->prepare("
            SELECT *,
                   legal_entity_group AS effective_legal_entity_group
            FROM ro_sessions
            WHERE legal_entity_group = ?
              AND status = 'active'
            ORDER BY created_by = 'permanent' DESC, week_end DESC, id DESC
            LIMIT 1
        ");
        $s->execute([$group]);
    }
    $session = $s->fetch() ?: null;
    if ($session && empty($session['legal_entity_group']) && !empty($session['effective_legal_entity_group'])) {
        $session['legal_entity_group'] = $session['effective_legal_entity_group'];
    }
    return $session;
}

function roGetSessionForDate($pdo, $group, $date) {
    $group = roNormalizeLegalEntityGroup($group ?: 'BK_VM');
    return roGetActiveSession($pdo, $group);
}

function roNormalizeRestaurantOrdersLegalEntity($legalEntity, $group = null) {
    $legalEntity = trim((string)$legalEntity);
    if ($legalEntity !== '') return $legalEntity;
    $group = roNormalizeLegalEntityGroup($group ?: 'BK_VM');
    return $group === 'PS' ? 'ООО "Пицца Стар"' : 'ООО "Бургер БК"';
}

function roRestaurantOrdersEnabled($pdo = null, $legalEntity = null, $group = null) {
    $legalEntity = roNormalizeRestaurantOrdersLegalEntity($legalEntity, $group);
    if ($pdo) {
        try {
            $s = $pdo->prepare("SELECT restaurant_orders_enabled FROM ro_module_settings WHERE legal_entity = ? LIMIT 1");
            $s->execute([$legalEntity]);
            $row = $s->fetch();
            if ($row) return (int)$row['restaurant_orders_enabled'] === 1;
        } catch (Throwable $e) {
            // Если миграция ещё не применена, считаем модуль включённым.
        }
    }
    return true;
}

function roRequireRestaurantOrdersEnabled($pdo, $legalEntity = null, $group = null) {
    if (!roRestaurantOrdersEnabled($pdo, $legalEntity, $group)) {
        roRespond(['error' => 'Основная поставка временно отключена'], 403);
    }
}

function roFormatCttRestaurantLabel($restaurantNumber, $city, $address) {
    $number = (int)$restaurantNumber;
    $city = trim((string)$city);
    $address = trim((string)$address);
    if ($address === '') return (string)$number;
    if ($city !== '' && mb_strtolower($city) !== 'минск' && mb_stripos($address, $city) === false) {
        return $number . ' — г. ' . $city . ', ' . $address;
    }
    return $number . ' — ' . $address;
}

function roFormatRestaurantTelegramLabel($restaurantNumber, $city = '', $address = '', $group = null) {
    $number = (int)$restaurantNumber;
    $group = roNormalizeLegalEntityGroup($group, $restaurantNumber);
    $groupTitle = $group === 'PS' ? 'Пицца Стар' : 'БК/ВМ';
    $city = trim((string)$city);
    $address = trim((string)$address);
    $location = '';
    if ($address !== '') {
        $location = ' — ' . $address;
        if ($city !== '' && mb_strtolower($city) !== 'минск' && mb_stripos($address, $city) === false) {
            $location = ' — г. ' . $city . ', ' . $address;
        }
    } elseif ($city !== '') {
        $location = ' — ' . $city;
    }
    return '№' . $number . ' (' . $groupTitle . ')' . $location;
}

function roFormatCttWeight($weightBruttoGrams) {
    $tons = ((float)$weightBruttoGrams) / 1000000;
    $formatted = rtrim(rtrim(number_format($tons, 6, '.', ''), '0'), '.');
    return $formatted !== '' ? $formatted : '0';
}

function roGetCttPrefixByGroup($group) {
    return strtoupper((string)$group) === 'PS' ? 'DODO' : 'BK';
}

function roWarehouseStockLegalEntityForRestaurant($rest) {
    $group = $rest['legal_entity_group'] ?? '';
    $number = (string)($rest['restaurant_number'] ?? '');
    if ($group === 'PS') return 'ООО "Пицца Стар"';
    if ($number === '3') return 'ООО "Воглия Матта"';
    return 'ООО "Бургер БК"';
}

// Полное название юрлица → короткое имя для stock_malling.customer
// «ООО "Бургер БК"» → «Бургер БК», «ООО "Воглия Матта"» → «Воглия Матта», «ООО "Пицца Стар"» → «Пицца Стар»
function roShortCustomerName($legalEntity) {
    if (!$legalEntity) return null;
    if (preg_match('/Пицца\s*Стар/u', $legalEntity)) return 'Пицца Стар';
    if (preg_match('/Воглия\s*Матта/u', $legalEntity)) return 'Воглия Матта';
    if (preg_match('/Бургер\s*БК/u', $legalEntity)) return 'Бургер БК';
    return null;
}

// Остаток склада по SKU+юрлицу. Возвращает массив:
//   ['qty' => float, 'date' => 'YYYY-MM-DD'|null, 'source' => 'shelf_life'|'ro_balances',
//    'nearest_expiry' => 'YYYY-MM-DD'|null, 'expiry_status' => string|null, 'batches' => [...]?]
// или null, если данных нет ни в одной таблице.
// Источники:
//   - stock_malling (модуль «Сроки годности») — количество + срок годности по партиям
//   - ro_stock_balances (модуль «Заказы ресторанов» → «Остатки склада»)
// Выбирается источник с самой свежей датой. При равенстве — stock_malling (там ещё и сроки годности).
function roGetStockForSku($pdo, $sku, $legalEntity) {
    if (!$sku || !$legalEntity) return null;

    $productInfo = null;
    try {
        $p = $pdo->prepare("SELECT external_code, name FROM products WHERE sku = ? AND legal_entity = ? AND is_active = 1 LIMIT 1");
        $p->execute([$sku, $legalEntity]);
        $productInfo = $p->fetch() ?: null;
    } catch (Exception $e) {
        $productInfo = null;
    }
    $externalCode = trim((string)($productInfo['external_code'] ?? ''));
    $productName = trim((string)($productInfo['name'] ?? ''));

    // === stock_malling ===
    $shelfData = null;
    $customer = roShortCustomerName($legalEntity);
    if ($customer) {
        $s = $pdo->prepare("
            SELECT warehouse, production_date, expiry_date, expiry_status, quantity, uploaded_at
            FROM stock_malling
            WHERE customer = ?
              AND (
                product_name = ?
                OR product_name LIKE CONCAT(?, ' %')
                OR product_name LIKE CONCAT(?, ' - %')
                OR product_name LIKE CONCAT('% - ', ?, ' %')
                OR (? != '' AND product_name LIKE CONCAT(?, ' %'))
                OR (? != '' AND product_name LIKE CONCAT(?, ' - %'))
              )
            ORDER BY expiry_date IS NULL, expiry_date ASC
        ");
        $s->execute([
            $customer,
            $productName,
            $sku,
            $sku,
            $sku,
            $externalCode,
            $externalCode,
            $externalCode,
            $externalCode,
        ]);
        $rows = $s->fetchAll();
        if (!empty($rows)) {
            $totalQty = 0;
            $batches = [];
            $uploadedAt = null;
            foreach ($rows as $r) {
                $q = (float)$r['quantity'];
                $totalQty += $q;
                $batches[] = [
                    'qty' => $q,
                    'expiry' => $r['expiry_date'],
                    'production' => $r['production_date'],
                    'status' => $r['expiry_status'],
                    'warehouse' => $r['warehouse'],
                ];
                if (!$uploadedAt || $r['uploaded_at'] > $uploadedAt) $uploadedAt = $r['uploaded_at'];
            }
            $shelfData = [
                'qty' => $totalQty,
                'date' => $uploadedAt ? date('Y-m-d', strtotime($uploadedAt)) : null,
                'source' => 'shelf_life',
                'nearest_expiry' => $rows[0]['expiry_date'] ?: null,
                'expiry_status' => $rows[0]['expiry_status'] ?: null,
                'batches' => $batches,
            ];
        }
    }

    // === ro_stock_balances (самая свежая дата, сумма по складам) ===
    $balData = null;
    $s = $pdo->prepare("SELECT MAX(balance_date) FROM ro_stock_balances WHERE sku = ? AND legal_entity = ?");
    $s->execute([$sku, $legalEntity]);
    $lastDate = $s->fetchColumn();
    if ($lastDate) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM ro_stock_balances WHERE sku = ? AND legal_entity = ? AND balance_date = ?");
        $s->execute([$sku, $legalEntity, $lastDate]);
        $qty = (float)$s->fetchColumn();
        $balData = [
            'qty' => $qty, 'date' => $lastDate, 'source' => 'ro_balances',
            'nearest_expiry' => null, 'expiry_status' => null, 'batches' => null,
        ];
    }

    // === Выбор по свежести ===
    if ($shelfData && $balData) {
        // Если данные со сроками не старее складских остатков, считаем остаток
        // именно по stock_malling: там есть партии и сроки годности.
        if (!$balData['date'] || ($shelfData['date'] && $shelfData['date'] >= $balData['date'])) {
            return $shelfData;
        }

        // Только когда ro_stock_balances новее, берём оттуда количество,
        // но сроки годности всё равно добавляем из stock_malling.
        if ($balData['date'] > $shelfData['date']) {
            $balData['nearest_expiry'] = $shelfData['nearest_expiry'];
            $balData['expiry_status'] = $shelfData['expiry_status'];
            $balData['batches'] = $shelfData['batches'];
            $balData['expiry_source'] = 'shelf_life';
            return $balData;
        }
    }
    return $shelfData ?: $balData;
}

function roWarehouseStorageMode($warehouse) {
    $w = mb_strtolower((string)$warehouse, 'UTF-8');
    if (strpos($w, 'холод') !== false && strpos($w, 'мороз') !== false) return ['key' => 'mixed', 'label' => 'Холод/Мороз'];
    if (strpos($w, 'мороз') !== false || strpos($w, 'заморож') !== false) return ['key' => 'frozen', 'label' => 'Мороз'];
    if (strpos($w, 'холод') !== false || strpos($w, 'охлаж') !== false) return ['key' => 'cold', 'label' => 'Холод'];
    if (strpos($w, 'сух') !== false || strpos($w, 'dry') !== false) return ['key' => 'dry', 'label' => 'Сухой сток'];
    return ['key' => 'other', 'label' => $warehouse ?: 'Без режима'];
}

function roNormalizeLookupText($value) {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$value)), 'UTF-8');
}

function roFindProductForShelfRow($productName, $productsBySku, $productsByExternal, $productsByName) {
    $name = trim((string)$productName);
    $external = '';
    $sku = '';
    if (preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', $name, $m)) {
        $external = trim($m[1]);
        $sku = trim($m[2]);
    } elseif (preg_match('/^\s*([^\s]+)/u', $name, $m)) {
        $sku = trim($m[1]);
    }
    if ($sku !== '' && isset($productsBySku[$sku])) return $productsBySku[$sku];
    if ($external !== '' && isset($productsByExternal[$external])) return $productsByExternal[$external];
    $norm = roNormalizeLookupText($name);
    if ($norm !== '' && isset($productsByName[$norm])) return $productsByName[$norm];
    return null;
}

function roSessionsSupportGroups($pdo) {
    static $supported = null;
    if ($supported !== null) return $supported;
    try {
        $s = $pdo->query("SHOW COLUMNS FROM ro_sessions LIKE 'legal_entity_group'");
        $supported = (bool)$s->fetch();
    } catch (Exception $e) {
        $supported = false;
    }
    return $supported;
}

function roGetSessionById($pdo, $sessionId) {
    if (!roSessionsSupportGroups($pdo)) {
        $s = $pdo->prepare("SELECT *, 'BK_VM' AS legal_entity_group FROM ro_sessions WHERE id = ? LIMIT 1");
        $s->execute([(int)$sessionId]);
        return $s->fetch() ?: null;
    }
    $s = $pdo->prepare("SELECT * FROM ro_sessions WHERE id = ? LIMIT 1");
    $s->execute([(int)$sessionId]);
    return $s->fetch() ?: null;
}

function roIsDateOpen($pdo, $sessionId, $deliveryDate) {
    $s = $pdo->prepare("SELECT is_open FROM ro_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
    $s->execute([$sessionId, $deliveryDate]);
    $row = $s->fetch();
    return $row && $row['is_open'];
}

function roGetDeadlines($pdo, $sessionId, $deliveryDate) {
    // Сначала проверяем переопределения
    $s = $pdo->prepare("SELECT soft_deadline, hard_deadline FROM ro_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
    $s->execute([$sessionId, $deliveryDate]);
    $override = $s->fetch();
    if ($override) {
        return [
            'soft' => $override['soft_deadline'],
            'hard' => $override['hard_deadline'],
            'edit_until' => $override['hard_deadline'],
        ];
    }
    // Стандартные дедлайны
    return [
        'soft' => '10:00:00',
        'hard' => '13:00:00',
        'edit_until' => '13:00:00',
    ];
}

function roGetDeadlineStatus($pdo, $sessionId, $deliveryDate) {
    // Приём открыт только если админ явно открыл эту дату
    if (!roIsDateOpen($pdo, $sessionId, $deliveryDate)) {
        $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
        return ['status' => 'not_open', 'deadlines' => $deadlines];
    }

    $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    // Дедлайны относятся к дню ПЕРЕД доставкой (день подачи заявки)
    $orderDate = (new DateTime($deliveryDate))->modify('-1 day')->format('Y-m-d');

    if ($today < $orderDate) {
        // День подачи ещё не наступил — разрешаем подавать заранее
        return ['status' => 'open', 'deadlines' => $deadlines];
    }
    if ($today > $orderDate) {
        return ['status' => 'closed', 'deadlines' => $deadlines]; // День прошёл
    }
    // Сегодня = день подачи
    $currentTime = $now->format('H:i:s');
    if ($currentTime < $deadlines['soft']) {
        return ['status' => 'open', 'deadlines' => $deadlines];
    }
    if ($currentTime < $deadlines['hard']) {
        return ['status' => 'warning', 'deadlines' => $deadlines]; // Мягкий дедлайн прошёл
    }
    return ['status' => 'closed', 'deadlines' => $deadlines]; // Жёсткий дедлайн прошёл
}

function roCanEdit($pdo, $sessionId, $deliveryDate) {
    // Если дата не открыта — редактировать нельзя
    if (!roIsDateOpen($pdo, $sessionId, $deliveryDate)) return false;

    $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $orderDate = (new DateTime($deliveryDate))->modify('-1 day')->format('Y-m-d');
    // До дня подачи — всегда можно
    if ($today < $orderDate) return true;
    // После дня подачи — нельзя
    if ($today > $orderDate) return false;
    // В день подачи — до edit_until
    return $now->format('H:i:s') < $deadlines['edit_until'];
}

// roGetLegalEntity перенесён в api/includes/legal_entities.php — доступен во всех местах
// (включая cron_telegram.php и telegram_bot.php), где нужно узнать юрлицо ресторана.

function roGetTodayMinsk() {
    $tz = new DateTimeZone('Europe/Minsk');
    return (new DateTime('now', $tz))->format('Y-m-d');
}

function roNormalizeSku($sku) {
    $sku = preg_replace('/\s+/u', '', (string)$sku);
    return trim((string)$sku);
}

function roNormalizeStockProductCode($code) {
    $code = roNormalizeSku($code);
    if (preg_match('/^\d+\.0+$/', $code)) {
        $code = preg_replace('/\.0+$/', '', $code);
    }
    return $code;
}

function roRestaurantHasDeliveryDate($pdo, $restaurantNumber, $legalEntityGroup, $deliveryDate) {
    if (!$deliveryDate) return false;
    $dow = (int)(new DateTime($deliveryDate))->format('N');
    $group = $legalEntityGroup ?: 'BK_VM';
    $s = $pdo->prepare("
        SELECT 1
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ?
          AND r.active = 1
          AND r.legal_entity_group = ?
          AND ds.day_of_week = ?
          AND TRIM(COALESCE(ds.delivery_time, '')) <> ''
        LIMIT 1
    ");
    $s->execute([(int)$restaurantNumber, $group, $dow]);
    return (bool)$s->fetchColumn();
}

/**
 * Запись события в журнал изменений заказов ресторанов.
 * Вызывается из всех мест, где меняется состояние ro_orders/ro_order_items.
 * @param array $e ['order_id', 'restaurant_number', 'delivery_date', 'action',
 *                  'actor_name', 'actor_type', 'sku', 'product_name',
 *                  'old_value', 'new_value', 'details' (array|null)]
 */
function roLogAudit($pdo, $e) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $pdo->prepare("INSERT INTO ro_audit_log
            (order_id, restaurant_number, delivery_date, action, actor_name, actor_type, actor_ip, sku, product_name, old_value, new_value, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $e['order_id'] ?? null,
                $e['restaurant_number'] ?? null,
                $e['delivery_date'] ?? null,
                $e['action'],
                $e['actor_name'] ?? null,
                $e['actor_type'] ?? 'system',
                $ip,
                $e['sku'] ?? null,
                $e['product_name'] ?? null,
                isset($e['old_value']) && $e['old_value'] !== null ? (string)$e['old_value'] : null,
                isset($e['new_value']) && $e['new_value'] !== null ? (string)$e['new_value'] : null,
                isset($e['details']) && $e['details'] !== null ? json_encode($e['details'], JSON_UNESCAPED_UNICODE) : null,
            ]);
    } catch (Exception $ex) {
        // Лог не критичен — не ломаем основной запрос
        error_log('roLogAudit failed: ' . $ex->getMessage());
    }
}

function roNotifyRestaurant($pdo, $restaurantNumber, $message, $legalEntityGroup = null) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;

    // ── Rate-limit: не более 10 уведомлений на ресторан за 1 минуту ──────────
    // Считаем количество уже отправленных уведомлений этому ресторану за последнюю минуту.
    $rateLimitMax = 10;
    $rateLimitWindow = 60; // секунд
    try {
        $rlCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM tg_notification_log
             WHERE notification_type = 'ro_notify'
               AND legal_entity = ?
               AND sent_at > NOW() - INTERVAL ? SECOND"
        );
        $rlCheck->execute([(string)(int)$restaurantNumber, $rateLimitWindow]);
        $rlCount = (int)$rlCheck->fetchColumn();
        if ($rlCount >= $rateLimitMax) {
            error_log("Rate-limit exceeded for restaurant_number={$restaurantNumber}: skipped");
            return false;
        }
        // Записываем маркер до отправки — чтобы параллельные запросы тоже видели счётчик.
        $pdo->prepare(
            "INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id) VALUES ('ro_notify', ?, 0)"
        )->execute([(string)(int)$restaurantNumber]);
    } catch (Exception $e) {
        // Ошибка rate-limit не должна блокировать уведомление — продолжаем без проверки.
        error_log("roNotifyRestaurant rate-limit check failed: " . $e->getMessage());
    }

    // Источник правды о подписках ресторана — ro_telegram_subs.
    // ro_users.telegram_chat_id больше не используется (см. миграцию verified_*).
    // Фильтр по legal_entity_group обязателен: номера ресторанов BK_VM и PS
    // могут совпадать, и без группы уведомление улетело бы чужой группе.
    $chatIds = [];
    $sql = "
        SELECT DISTINCT chat_id FROM ro_telegram_subs
        WHERE restaurant_number = ?
          AND notify_confirmations = 1
          AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
    ";
    $params = [(int)$restaurantNumber];
    if ($legalEntityGroup) {
        $group = ($legalEntityGroup === 'PS') ? 'PS' : 'BK_VM';
        $sql .= " AND legal_entity_group = ?";
        $params[] = $group;
    }
    $s = $pdo->prepare($sql);
    $s->execute($params);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
        $chatId = trim((string)$chatId);
        if ($chatId !== '') $chatIds[$chatId] = true;
    }

    foreach (array_keys($chatIds) as $chatId) {
        sendTelegramMessage($botToken, $chatId, $message);
    }
}

function roBroadcastReaderKey($restaurantUser) {
    $number = (int)($restaurantUser['restaurant_number'] ?? 0);
    $group = roNormalizeLegalEntityGroup($restaurantUser['legal_entity_group'] ?? null, $number);
    return 'ro:' . $number . ':' . $group;
}

function roAggregateOrderItems($items) {
    $aggregated = [];
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        if ($qty < 0) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        if ($sku === '') continue;
        if (!isset($aggregated[$sku])) {
            $aggregated[$sku] = [
                'sku' => $sku,
                'product_name' => $item['product_name'] ?? '',
                'category' => $item['category'] ?? 'Сухой',
                'quantity' => 0,
                'comment' => $item['comment'] ?? null,
            ];
        }
        $aggregated[$sku]['quantity'] += $qty;
        if (!empty($item['comment']) && empty($aggregated[$sku]['comment'])) {
            $aggregated[$sku]['comment'] = $item['comment'];
        }
    }
    return $aggregated;
}

function roHasMultiplicityViolation($qty, $multiplicity) {
    $qty = floatval($qty);
    $multiplicity = floatval($multiplicity);
    if ($qty <= 0 || $multiplicity <= 1) return false;
    $ratio = $qty / $multiplicity;
    return abs($ratio - round($ratio)) > 0.0001;
}

function roFindMultiplicityViolations($pdo, $legalEntity, $aggregatedItems) {
    if (!$legalEntity || empty($aggregatedItems)) return [];
    $skus = array_values(array_unique(array_filter(array_keys($aggregatedItems), fn($sku) => $sku !== '')));
    if (empty($skus)) return [];

    $ph = implode(',', array_fill(0, count($skus), '?'));
    $tplParams = array_merge([$legalEntity], $skus);
    $tplStmt = $pdo->prepare("
        SELECT sku, category, product_name, COALESCE(NULLIF(multiplicity, 0), 1) AS multiplicity
        FROM ro_templates
        WHERE legal_entity = ?
          AND is_active = 1
          AND sku IN ({$ph})
    ");
    $tplStmt->execute($tplParams);

    $templateMap = [];
    $templateBySku = [];
    foreach ($tplStmt->fetchAll() as $row) {
        $key = $row['sku'] . '|' . ($row['category'] ?? '');
        $templateMap[$key] = $row;
        if (!isset($templateBySku[$row['sku']])) {
            $templateBySku[$row['sku']] = $row;
        }
    }

    $productParams = array_merge([$legalEntity], $skus);
    $s = $pdo->prepare("
        SELECT sku, name, COALESCE(multiplicity, 1) AS multiplicity
        FROM products
        WHERE legal_entity = ?
          AND is_active = 1
          AND sku IN ({$ph})
    ");
    $s->execute($productParams);

    $productMap = [];
    foreach ($s->fetchAll() as $row) {
        $productMap[$row['sku']] = $row;
    }

    $violations = [];
    foreach ($aggregatedItems as $sku => $item) {
        $category = $item['category'] ?? '';
        $template = $templateMap[$sku . '|' . $category] ?? $templateBySku[$sku] ?? null;
        $product = $productMap[$sku] ?? null;
        $multiplicity = floatval($template['multiplicity'] ?? ($product['multiplicity'] ?? 1));
        $quantity = floatval($item['quantity'] ?? 0);
        if (!roHasMultiplicityViolation($quantity, $multiplicity)) continue;
        $violations[] = [
            'sku' => $sku,
            'product_name' => $template['product_name'] ?? ($product['name'] ?? ($item['product_name'] ?? '')),
            'quantity' => $quantity,
            'multiplicity' => $multiplicity,
        ];
    }

    return $violations;
}

function roFormatMultiplicityValue($value) {
    $num = floatval($value);
    if (abs($num - round($num)) < 0.0001) return (string)intval(round($num));
    return rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
}

function roRespondMultiplicityError($violations) {
    if (empty($violations)) return;
    $first = $violations[0];
    $message = 'Товар ' . $first['sku'] . ' «' . $first['product_name'] . '»: количество '
        . roFormatMultiplicityValue($first['quantity'])
        . ' должно быть кратно '
        . roFormatMultiplicityValue($first['multiplicity']);
    if (count($violations) > 1) {
        $message .= '. Некратных позиций: ' . count($violations);
    }
    roRespond(['error' => $message], 400);
}

function roNormalizeImportText($value) {
    $s = mb_strtolower((string)$value, 'UTF-8');
    $s = str_replace(['ё'], ['е'], $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function roParseImportQty($value) {
    $s = str_replace([' ', ','], ['', '.'], trim((string)$value));
    return is_numeric($s) ? (float)$s : 0.0;
}

function roParseUtDate($value) {
    $s = trim((string)$value);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return '';
}

function roBuildUtImportPreview($pdo, $filePath, $selectedDate, $sessionUser, $legalEntity = null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
        roRespond(['error' => 'Выберите дату доставки перед импортом'], 400);
    }

    require_once __DIR__ . '/../lib/SimpleXLSX.php';
    $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
    if (!$xlsx) roRespond(['error' => 'Не удалось прочитать Excel файл'], 400);

    $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
    if (!$entityGroup) {
        $allowedGroups = roGetSessionUserGroups($sessionUser);
        $entityGroup = $allowedGroups[0] ?? 'BK_VM';
    }
    roEnsureGroupAccess($sessionUser, $entityGroup);

    $session = roGetActiveSession($pdo, $entityGroup);
    if (!$session) roRespond(['error' => 'Нет активной сессии приёма заявок'], 400);

    $entities = getEntitiesInGroup($entityGroup);
    $entityPh = implode(',', array_fill(0, count($entities), '?'));

    $productsStmt = $pdo->prepare("
        SELECT sku, name, category, legal_entity, COALESCE(multiplicity, 1) AS multiplicity
        FROM products
        WHERE legal_entity IN ({$entityPh})
        ORDER BY is_active DESC, id ASC
    ");
    $productsStmt->execute($entities);
    $productsBySku = [];
    $productsByName = [];
    foreach ($productsStmt->fetchAll() as $p) {
        $sku = trim((string)$p['sku']);
        if ($sku !== '' && !isset($productsBySku[$sku])) $productsBySku[$sku] = $p;
        $n = roNormalizeImportText($p['name'] ?? '');
        if ($n !== '' && !isset($productsByName[$n])) $productsByName[$n] = $p;
    }

    $tplStmt = $pdo->prepare("
        SELECT sku, product_name, category, legal_entity, COALESCE(NULLIF(multiplicity, 0), 1) AS multiplicity
        FROM ro_templates
        WHERE legal_entity IN ({$entityPh}) AND is_active = 1
    ");
    $tplStmt->execute($entities);
    $templatesByLeSku = [];
    $templatesBySku = [];
    foreach ($tplStmt->fetchAll() as $t) {
        $templatesByLeSku[$t['legal_entity'] . '|' . $t['sku']] = $t;
        if (!isset($templatesBySku[$t['sku']])) $templatesBySku[$t['sku']] = $t;
    }

    $restsStmt = $pdo->prepare("SELECT id, number, city, address, legal_entity_group FROM restaurants WHERE active = 1 AND legal_entity_group = ?");
    $restsStmt->execute([$entityGroup]);
    $restaurantsByNumber = [];
    foreach ($restsStmt->fetchAll() as $r) {
        $restaurantsByNumber[(string)(int)$r['number']] = $r;
    }

    $existingStmt = $pdo->prepare("
        SELECT restaurant_number, id
        FROM ro_orders
        WHERE delivery_date = ?
          AND legal_entity_group = ?
    ");
    $existingStmt->execute([$selectedDate, $entityGroup]);
    $existingByRestaurant = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existingByRestaurant[(string)(int)$row['restaurant_number']] = (int)$row['id'];
    }

    $orders = [];
    $fileDates = [];
    $unmatchedMap = [];
    $missingRestaurants = [];
    $missingTemplateMap = [];
    $rowsTotal = 0;
    $rowsMatched = 0;
    $rowsSkippedDeleted = 0;

    foreach ($xlsx->sheetNames() as $sheetIdx => $sheetName) {
        $rows = $xlsx->rows($sheetIdx);
        if (empty($rows)) continue;

        $headerRow = -1;
        $cols = ['del' => -1, 'product' => -1, 'restaurant' => -1, 'date' => -1, 'qty' => -1];
        for ($r = 0; $r < min(20, count($rows)); $r++) {
            foreach ($rows[$r] as $c => $value) {
                $v = roNormalizeImportText($value);
                if ($v === 'del') $cols['del'] = $c;
                if ($v === 'номенклатура' || $v === 'товар') $cols['product'] = $c;
                if ($v === 'ресторан') $cols['restaurant'] = $c;
                if ($v === 'желаемая дата поступления' || $v === 'дата поступления') $cols['date'] = $c;
                if ($v === 'кол во' || $v === 'количество') $cols['qty'] = $c;
            }
            if ($cols['product'] >= 0 && $cols['restaurant'] >= 0 && $cols['date'] >= 0 && $cols['qty'] >= 0) {
                $headerRow = $r;
                break;
            }
        }
        if ($headerRow < 0) continue;

        for ($r = $headerRow + 1; $r < count($rows); $r++) {
            $del = mb_strtolower(trim((string)($rows[$r][$cols['del']] ?? '')), 'UTF-8');
            // Строки с пометкой удаления в 1С УТ теперь тоже импортируются,
            // позже позиция помечается комментарием «Удалено в 1С УТ».
            $isDeletedRow = ($del === 'да' || $del === 'yes' || $del === 'true');
            if ($isDeletedRow) $rowsSkippedDeleted++;

            $productRaw = trim((string)($rows[$r][$cols['product']] ?? ''));
            $restRaw = trim((string)($rows[$r][$cols['restaurant']] ?? ''));
            $date = roParseUtDate($rows[$r][$cols['date']] ?? '');
            $qty = roParseImportQty($rows[$r][$cols['qty']] ?? 0);
            if ($productRaw === '' || $restRaw === '' || $date === '' || $qty <= 0) continue;

            $rowsTotal++;
            $fileDates[$date] = true;

            if (!preg_match('/(\d+)/', $restRaw, $rm)) {
                $missingRestaurants[$restRaw] = ['restaurant' => $restRaw, 'rows' => ($missingRestaurants[$restRaw]['rows'] ?? 0) + 1];
                continue;
            }
            $restNumber = (string)(int)$rm[1];
            if (!isset($restaurantsByNumber[$restNumber])) {
                $missingRestaurants[$restNumber] = ['restaurant' => $restRaw, 'rows' => ($missingRestaurants[$restNumber]['rows'] ?? 0) + 1];
                continue;
            }

            $sku = '';
            $excelName = $productRaw;
            if (preg_match('/^(\S+)\s+(.+)$/u', $productRaw, $pm)) {
                $sku = trim($pm[1]);
                $excelName = trim($pm[2]);
            }

            $product = $productsBySku[$sku]
                ?? $productsByName[roNormalizeImportText($productRaw)]
                ?? $productsByName[roNormalizeImportText($excelName)]
                ?? null;
            if (!$product) {
                $key = ($sku ?: $productRaw) . '|' . $excelName;
                if (!isset($unmatchedMap[$key])) {
                    $unmatchedMap[$key] = ['sku' => $sku, 'name' => $excelName, 'rows' => 0, 'quantity' => 0];
                }
                $unmatchedMap[$key]['rows']++;
                $unmatchedMap[$key]['quantity'] += $qty;
                continue;
            }

            $le = roGetLegalEntity($pdo, $restNumber, $entityGroup);
            if ($sessionUser && !checkLegalEntityAccess($sessionUser, $le)) continue;

            $finalSku = trim((string)$product['sku']);
            $template = $templatesByLeSku[$le . '|' . $finalSku] ?? $templatesBySku[$finalSku] ?? null;
            $category = $template['category'] ?? ($product['category'] ?: 'Сухой');
            $productName = $template['product_name'] ?? ($product['name'] ?: $excelName);
            $multiplicity = (float)($template['multiplicity'] ?? $product['multiplicity'] ?? 1);

            if (!$template) {
                $mtKey = $le . '|' . $finalSku;
                if (!isset($missingTemplateMap[$mtKey])) {
                    $missingTemplateMap[$mtKey] = [
                        'legal_entity' => $le,
                        'sku' => $finalSku,
                        'product_name' => $productName,
                        'category' => $category,
                        'multiplicity' => $multiplicity > 0 ? $multiplicity : 1,
                    ];
                }
            }

            if (!isset($orders[$restNumber])) {
                $rest = $restaurantsByNumber[$restNumber];
                $orders[$restNumber] = [
                    'restaurant_number' => (int)$restNumber,
                    'city' => $rest['city'] ?? '',
                    'address' => $rest['address'] ?? '',
                    'legal_entity' => $le,
                    'items' => [],
                ];
            }
            if (!isset($orders[$restNumber]['items'][$finalSku])) {
                $orders[$restNumber]['items'][$finalSku] = [
                    'sku' => $finalSku,
                    'product_name' => $productName,
                    'category' => $category,
                    'quantity' => 0,
                    'comment' => null,
                    '_has_active' => false,
                ];
            }
            $orders[$restNumber]['items'][$finalSku]['quantity'] += $qty;
            if (!$isDeletedRow) $orders[$restNumber]['items'][$finalSku]['_has_active'] = true;
            $rowsMatched++;
        }
    }

    $dates = array_keys($fileDates);
    sort($dates);
    if (count($dates) !== 1 || $dates[0] !== $selectedDate) {
        roRespond([
            'error' => 'Дата в файле не совпадает с выбранной датой',
            'file_dates' => $dates,
            'selected_date' => $selectedDate,
        ], 400);
    }

    $ordersOut = [];
    $skippedExisting = [];
    foreach ($orders as $restNumber => $order) {
        // Позиция, у которой все строки в файле помечены удалёнными в 1С УТ,
        // получает комментарий-пометку. Если есть хоть одна активная строка — не помечаем.
        foreach ($order['items'] as &$itRef) {
            if (empty($itRef['_has_active'])) {
                $itRef['comment'] = 'Удалено в 1С УТ';
            }
            unset($itRef['_has_active']);
        }
        unset($itRef);
        $order['items'] = array_values($order['items']);
        usort($order['items'], fn($a, $b) => strcmp(($a['category'] ?? '') . ($a['product_name'] ?? ''), ($b['category'] ?? '') . ($b['product_name'] ?? '')));
        if (isset($existingByRestaurant[$restNumber])) {
            $order['existing_order_id'] = $existingByRestaurant[$restNumber];
            $skippedExisting[] = [
                'restaurant_number' => (int)$restNumber,
                'order_id' => $existingByRestaurant[$restNumber],
                'items_count' => count($order['items']),
            ];
        }
        $ordersOut[] = $order;
    }
    usort($ordersOut, fn($a, $b) => $a['restaurant_number'] <=> $b['restaurant_number']);

    return [
        'selected_date' => $selectedDate,
        'legal_entity_group' => $entityGroup,
        'session_id' => (int)$session['id'],
        'summary' => [
            'rows_total' => $rowsTotal,
            'rows_matched' => $rowsMatched,
            'rows_skipped_deleted' => $rowsSkippedDeleted,
            'orders_to_create' => count(array_filter($ordersOut, fn($o) => empty($o['existing_order_id']))),
            'orders_skipped_existing' => count($skippedExisting),
            'orders_can_overwrite' => count($skippedExisting),
            'items_to_create' => array_sum(array_map(fn($o) => empty($o['existing_order_id']) ? count($o['items']) : 0, $ordersOut)),
            'items_can_overwrite' => array_sum(array_map(fn($o) => !empty($o['existing_order_id']) ? count($o['items']) : 0, $ordersOut)),
            'unmatched_count' => count($unmatchedMap),
            'missing_template_count' => count($missingTemplateMap),
            'missing_restaurants_count' => count($missingRestaurants),
        ],
        'orders' => $ordersOut,
        'skipped_existing' => $skippedExisting,
        'unmatched' => array_values($unmatchedMap),
        'missing_templates' => array_values($missingTemplateMap),
        'missing_restaurants' => array_values($missingRestaurants),
    ];
}

function roCommitUtImport($pdo, $payload, $sessionUser, $addMissingTemplates, $overwriteMode = 'none', $overwriteRestaurants = []) {
    $selectedDate = $payload['selected_date'] ?? '';
    $entityGroup = $payload['legal_entity_group'] ?? 'BK_VM';
    $orders = $payload['orders'] ?? [];
    $missingTemplates = $payload['missing_templates'] ?? [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || empty($orders)) {
        roRespond(['error' => 'Нет заказов для импорта'], 400);
    }
    roEnsureGroupAccess($sessionUser, $entityGroup);
    $session = roGetActiveSession($pdo, $entityGroup);
    if (!$session) roRespond(['error' => 'Нет активной сессии приёма заявок'], 400);

    $actor = resolveActorName($pdo, $sessionUser, 'отдел закупок');
    $created = 0;
    $overwritten = 0;
    $skipped = [];
    $itemsCreated = 0;
    $itemsOverwritten = 0;
    $templatesAdded = 0;
    $overwriteMode = in_array($overwriteMode, ['all', 'selected'], true) ? $overwriteMode : 'none';
    $overwriteSet = [];
    foreach ((array)$overwriteRestaurants as $rn) {
        $rn = (int)$rn;
        if ($rn > 0) $overwriteSet[$rn] = true;
    }

    $pdo->beginTransaction();
    try {
        if ($addMissingTemplates && !empty($missingTemplates)) {
            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM ro_templates WHERE legal_entity = ? AND category = ?");
            $tplCheck = $pdo->prepare("SELECT id FROM ro_templates WHERE legal_entity = ? AND category = ? AND sku = ? LIMIT 1");
            $tplInsert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, multiplicity, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $sortCache = [];
            foreach ($missingTemplates as $tpl) {
                $le = $tpl['legal_entity'] ?? '';
                $category = $tpl['category'] ?? 'Сухой';
                $sku = trim((string)($tpl['sku'] ?? ''));
                if (!$le || !$sku) continue;
                if ($sessionUser && !checkLegalEntityAccess($sessionUser, $le)) continue;
                $tplCheck->execute([$le, $category, $sku]);
                if ($tplCheck->fetch()) continue;
                $key = $le . '|' . $category;
                if (!isset($sortCache[$key])) {
                    $sortStmt->execute([$le, $category]);
                    $sortCache[$key] = (int)$sortStmt->fetchColumn();
                }
                $sortCache[$key] += 10;
                $mult = (float)($tpl['multiplicity'] ?? 1);
                $tplInsert->execute([$le, $category, $sku, trim((string)($tpl['product_name'] ?? $sku)), $mult > 0 ? $mult : 1, $sortCache[$key]]);
                $templatesAdded++;
            }
        }

        $existingStmt = $pdo->prepare("
            SELECT id
            FROM ro_orders
            WHERE restaurant_number = ?
              AND delivery_date = ?
              AND legal_entity_group = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $orderInsert = $pdo->prepare("INSERT INTO ro_orders (session_id, restaurant_number, delivery_date, status, submitted_at, updated_by, legal_entity, legal_entity_group, comment) VALUES (?, ?, ?, 'submitted', NOW(), ?, ?, ?, ?)");
        $orderUpdate = $pdo->prepare("UPDATE ro_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW(), updated_by = ?, legal_entity = ?, legal_entity_group = ?, comment = ? WHERE id = ?");
        $itemsDelete = $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?");
        $itemInsert = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($orders as $order) {
            $restNumber = (int)($order['restaurant_number'] ?? 0);
            $le = $order['legal_entity'] ?? roGetLegalEntity($pdo, $restNumber, $entityGroup);
            if (!$restNumber || empty($order['items'])) continue;
            if ($sessionUser && !checkLegalEntityAccess($sessionUser, $le)) continue;

            $existingStmt->execute([$restNumber, $selectedDate, $entityGroup]);
            $existingId = $existingStmt->fetchColumn();
            if ($existingId) {
                $shouldOverwrite = $overwriteMode === 'all' || ($overwriteMode === 'selected' && isset($overwriteSet[$restNumber]));
                if (!$shouldOverwrite) {
                    $skipped[] = ['restaurant_number' => $restNumber, 'order_id' => (int)$existingId];
                    continue;
                }
                $orderId = (int)$existingId;
                $orderUpdate->execute([$actor, $le, $entityGroup, 'Перезаписано импортом из 1С УТ', $orderId]);
                $itemsDelete->execute([$orderId]);
            } else {
                $orderInsert->execute([$session['id'], $restNumber, $selectedDate, $actor, $le, $entityGroup, 'Импорт из 1С УТ']);
                $orderId = (int)$pdo->lastInsertId();
            }

            $totalQty = 0;
            $totalItems = 0;
            foreach (roAggregateOrderItems($order['items']) as $item) {
                $itemInsert->execute([$orderId, $item['sku'], $item['product_name'], $item['category'], $item['quantity'], $item['comment']]);
                $totalQty += (float)$item['quantity'];
                $totalItems++;
                if ($existingId) $itemsOverwritten++;
                else $itemsCreated++;
            }
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $restNumber,
                'delivery_date' => $selectedDate,
                'action' => $existingId ? 'order_updated' : 'order_created',
                'actor_name' => $actor,
                'actor_type' => 'admin',
                'new_value' => $totalItems . ' поз. / ' . $totalQty . ' кор.',
                'details' => ['source' => '1c_ut_import', 'overwrite' => (bool)$existingId],
            ]);
            if ($existingId) $overwritten++;
            else $created++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('roCommitUtImport error: ' . $e->getMessage());
        roRespond(['error' => 'Ошибка импорта заказов'], 500);
    }

    return [
        'success' => true,
        'created' => $created,
        'overwritten' => $overwritten,
        'items_created' => $itemsCreated,
        'items_overwritten' => $itemsOverwritten,
        'templates_added' => $templatesAdded,
        'skipped_existing' => $skipped,
    ];
}

function roGetSessionUserGroups($sessionUser) {
    if (!$sessionUser) return [];
    if (($sessionUser['role'] ?? '') === 'admin') return ['BK_VM', 'PS'];
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) {
        $userEntities = json_decode($userEntities, true);
    }
    if (!is_array($userEntities) || empty($userEntities)) return [];
    $groups = [];
    foreach ($userEntities as $entity) {
        $group = getEntityGroup($entity);
        if ($group && !in_array($group, $groups, true)) $groups[] = $group;
    }
    return $groups;
}

function roGetAllowedLegalEntities($sessionUser) {
    $entities = [];
    foreach (roGetSessionUserGroups($sessionUser) as $group) {
        foreach (getEntitiesInGroup($group) as $entity) {
            if (!in_array($entity, $entities, true)) $entities[] = $entity;
        }
    }
    return $entities;
}

function roEnsureGroupAccess($sessionUser, $group) {
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $allowed = roGetSessionUserGroups($sessionUser);
    if (!$group || !in_array($group, $allowed, true)) {
        roRespond(['error' => 'Нет доступа к данным этого юрлица'], 403);
    }
}

function roEnsureRestaurantAccess($pdo, $sessionUser, $restaurantNumber, $group = null) {
    $resolvedGroup = roNormalizeLegalEntityGroup($group, $restaurantNumber);
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $s = $pdo->prepare("SELECT legal_entity_group FROM restaurants WHERE number = ? AND legal_entity_group = ? AND active = 1 LIMIT 1");
    $s->execute([(int)$restaurantNumber, $resolvedGroup]);
    $group = $s->fetchColumn();
    if (!$group) {
        roRespond(['error' => 'Ресторан не найден'], 404);
    }
    roEnsureGroupAccess($sessionUser, $group);
}

function roCabinetPostMatchesRestaurantSql($alias = 'p') {
    return "(
        {$alias}.target_mode = 'all'
        OR ({$alias}.target_mode = 'group' AND {$alias}.target_group = ?)
        OR EXISTS (
            SELECT 1
            FROM ro_cabinet_post_restaurants rcp
            WHERE rcp.post_id = {$alias}.id
              AND rcp.restaurant_number = ?
              AND rcp.legal_entity_group = ?
        )
    )";
}

function roCabinetAttachFiles($pdo, &$posts) {
    if (!$posts) return;
    $ids = array_values(array_filter(array_map(fn($p) => (int)($p['id'] ?? 0), $posts)));
    if (!$ids) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("
        SELECT id, post_id, file_path, file_name, mime_type, file_size, created_at
        FROM ro_cabinet_post_files
        WHERE post_id IN ($ph)
        ORDER BY id
    ");
    $s->execute($ids);
    $filesByPost = [];
    foreach ($s->fetchAll() as $file) {
        $file['url'] = '/api/' . ltrim($file['file_path'], '/');
        $filesByPost[(int)$file['post_id']][] = $file;
    }
    foreach ($posts as &$post) {
        $post['files'] = $filesByPost[(int)$post['id']] ?? [];
    }
    unset($post);
}

function roCabinetParseRestaurantTargets($value) {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = preg_split('/[\s,;]+/', $value);
        }
    }
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        $number = null;
        $group = null;
        if (is_array($item)) {
            $number = $item['number'] ?? $item['restaurant_number'] ?? null;
            $group = $item['legal_entity_group'] ?? $item['group'] ?? null;
        } else {
            $parsed = parseRestaurantInput($item);
            if ($parsed) {
                $number = $parsed['number'];
                $group = $parsed['group'];
            }
        }
        if ($number !== null) {
            $n = (int)$number;
            if ($n > 0) {
                $g = roNormalizeLegalEntityGroup($group, $n);
                $out[$n . '|' . $g] = ['number' => $n, 'group' => $g];
            }
        }
    }
    return array_values($out);
}

function roCabinetSaveUploadedFiles($pdo, $postId) {
    if (empty($_FILES['files'])) return [];
    $files = $_FILES['files'];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

    $uploadDir = __DIR__ . '/../uploads/restaurant_info/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'text/plain' => 'txt',
    ];
    $saved = [];
    for ($i = 0; $i < count($names); $i++) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errors[$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) roRespond(['error' => 'Ошибка загрузки файла'], 400);
        if (($sizes[$i] ?? 0) > 15 * 1024 * 1024) roRespond(['error' => 'Файл слишком большой. Максимум 15 МБ'], 400);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpNames[$i]);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) roRespond(['error' => 'Недопустимый формат файла'], 400);
        $ext = $allowed[$mime];
        $filename = 'ro_info_' . (int)$postId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($tmpNames[$i], $dest)) roRespond(['error' => 'Не удалось сохранить файл'], 500);
        $path = 'uploads/restaurant_info/' . $filename;
        $origName = mb_substr((string)$names[$i], 0, 255);
        $stmt = $pdo->prepare("
            INSERT INTO ro_cabinet_post_files (post_id, file_path, file_name, mime_type, file_size)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([(int)$postId, $path, $origName, $mime, (int)$sizes[$i]]);
        $saved[] = [
            'id' => (int)$pdo->lastInsertId(),
            'post_id' => (int)$postId,
            'file_path' => $path,
            'file_name' => $origName,
            'mime_type' => $mime,
            'file_size' => (int)$sizes[$i],
            'url' => '/api/' . $path,
        ];
    }
    return $saved;
}

function roCabinetTelegramChatIds($pdo, $targetMode, $targetGroup, $targets) {
    $verifiedSql = "(rs.verified_at IS NOT NULL OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))";
    $subGroupSql = "CONVERT(COALESCE(NULLIF(rs.legal_entity_group, ''), CASE WHEN rs.restaurant_number >= 1000 THEN 'PS' ELSE 'BK_VM' END) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $groupParamSql = "CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $params = [];
    $where = [
        "rs.chat_id IS NOT NULL",
        "rs.chat_id != ''",
        $verifiedSql,
    ];

    if ($targetMode === 'group') {
        $where[] = "{$subGroupSql} = {$groupParamSql}";
        $params[] = roNormalizeLegalEntityGroup($targetGroup ?: 'BK_VM');
    } elseif ($targetMode === 'restaurants') {
        if (!$targets) return [];
        $parts = [];
        foreach ($targets as $target) {
            $parts[] = "(rs.restaurant_number = ? AND {$subGroupSql} = {$groupParamSql})";
            $params[] = (int)$target['number'];
            $params[] = roNormalizeLegalEntityGroup($target['group'] ?? null, (int)$target['number']);
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $sql = "
        SELECT DISTINCT rs.chat_id
        FROM ro_telegram_subs rs
        JOIN restaurants r
          ON r.number = rs.restaurant_number
         AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = {$subGroupSql}
         AND r.active = 1
        WHERE " . implode(' AND ', $where) . "
    ";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
}

function roCabinetTelegramRequest($botToken, $method, $payload, $multipart = false) {
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/{$method}");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $multipart ? $payload : json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if (!$multipart) {
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($result === false || $curlErr) {
        error_log("[roCabinetTelegramRequest] {$method} curl error: " . ($curlErr ?: 'unknown'));
        return false;
    }
    $data = json_decode($result, true);
    if ($httpCode !== 200 || !is_array($data) || empty($data['ok'])) {
        $desc = is_array($data) ? ($data['description'] ?? 'no description') : 'bad response';
        error_log("[roCabinetTelegramRequest] {$method} error http={$httpCode}: {$desc}");
        return false;
    }
    return true;
}

function roCabinetTelegramFileAbsPath($file) {
    $rel = ltrim((string)($file['file_path'] ?? ''), '/');
    if ($rel === '') return '';
    $abs = realpath(__DIR__ . '/../' . $rel);
    $base = realpath(__DIR__ . '/../uploads/restaurant_info');
    if (!$abs || !$base || strpos($abs, $base) !== 0 || !is_file($abs)) return '';
    return $abs;
}

function roCabinetTelegramSendFile($botToken, $chatId, $file, $caption = '', $keyboard = null) {
    $abs = roCabinetTelegramFileAbsPath($file);
    if (!$abs) return false;
    $mime = (string)($file['mime_type'] ?? 'application/octet-stream');
    $name = (string)($file['file_name'] ?? basename($abs));
    $isImage = str_starts_with($mime, 'image/');
    $field = $isImage ? 'photo' : 'document';
    $method = $isImage ? 'sendPhoto' : 'sendDocument';
    $payload = [
        'chat_id' => $chatId,
        $field => new CURLFile($abs, $mime, $name),
    ];
    if ($caption !== '') {
        $payload['caption'] = $caption;
        $payload['parse_mode'] = 'HTML';
    }
    if ($keyboard) {
        $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    return roCabinetTelegramRequest($botToken, $method, $payload, true);
}

function roCabinetTelegramSendImageGroup($botToken, $chatId, $files, $caption = '') {
    $media = [];
    $payload = ['chat_id' => $chatId];
    $idx = 0;
    foreach ($files as $file) {
        $abs = roCabinetTelegramFileAbsPath($file);
        if (!$abs) continue;
        $mime = (string)($file['mime_type'] ?? '');
        if (!str_starts_with($mime, 'image/')) continue;
        $name = (string)($file['file_name'] ?? basename($abs));
        $field = 'photo' . $idx;
        $item = [
            'type' => 'photo',
            'media' => 'attach://' . $field,
        ];
        if ($idx === 0 && $caption !== '') {
            $item['caption'] = $caption;
            $item['parse_mode'] = 'HTML';
        }
        $media[] = $item;
        $payload[$field] = new CURLFile($abs, $mime, $name);
        $idx++;
    }
    if (count($media) < 2) return false;
    $payload['media'] = json_encode($media, JSON_UNESCAPED_UNICODE);
    return roCabinetTelegramRequest($botToken, 'sendMediaGroup', $payload, true);
}

function roCabinetSendTelegramPost($pdo, $title, $message, $targetMode, $targetGroup, $targets, $files = []) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if (!$botToken) return 0;

    $chatIds = roCabinetTelegramChatIds($pdo, $targetMode, $targetGroup, $targets);
    if (!$chatIds) return 0;

    $safeTitle = htmlspecialchars(mb_substr($title ?: 'Важная информация', 0, 255), ENT_QUOTES, 'UTF-8');
    $safeMessage = tgFormatPostMessage(mb_substr($message ?: '', 0, 3000));
    $text = "ℹ️ <b>{$safeTitle}</b>\n\n{$safeMessage}";
    if (count($files) > 1) {
        $text .= "\n\nВложения доступны в личном кабинете ресторана.";
    }

    $siteUrl = rtrim($_ENV['SITE_URL'] ?? getenv('SITE_URL') ?: 'https://supply-department.online', '/');
    $keyboard = ['inline_keyboard' => [[
        ['text' => 'Открыть кабинет', 'url' => $siteUrl . '/restaurant/info'],
    ]]];

    if (count($files) === 1) {
        $captionTitle = htmlspecialchars(mb_substr($title ?: 'Важная информация', 0, 150), ENT_QUOTES, 'UTF-8');
        $captionMessageRaw = mb_substr($message ?: '', 0, 800);
        if (mb_strlen($message ?: '') > 800) $captionMessageRaw .= '...';
        $captionMessage = tgFormatPostMessage($captionMessageRaw);
        $fileCaption = "ℹ️ <b>{$captionTitle}</b>\n\n{$captionMessage}";
        $sent = 0;
        foreach ($chatIds as $chatId) {
            if (roCabinetTelegramSendFile($botToken, $chatId, $files[0], $fileCaption, $keyboard)) $sent++;
        }
        return $sent;
    }

    $imageFiles = array_values(array_filter($files, fn($file) => str_starts_with((string)($file['mime_type'] ?? ''), 'image/')));
    $otherFiles = array_values(array_filter($files, fn($file) => !str_starts_with((string)($file['mime_type'] ?? ''), 'image/')));

    if (count($imageFiles) > 1 && !$otherFiles) {
        $captionTitle = htmlspecialchars(mb_substr($title ?: 'Важная информация', 0, 150), ENT_QUOTES, 'UTF-8');
        $captionMessageRaw = mb_substr($message ?: '', 0, 800);
        if (mb_strlen($message ?: '') > 800) $captionMessageRaw .= '...';
        $captionMessage = tgFormatPostMessage($captionMessageRaw);
        $fileCaption = "ℹ️ <b>{$captionTitle}</b>\n\n{$captionMessage}";
        $sent = 0;
        $chunks = array_chunk($imageFiles, 10);
        foreach ($chatIds as $chatId) {
            $chatOk = false;
            foreach ($chunks as $chunkIndex => $chunk) {
                if (count($chunk) === 1) {
                    $ok = roCabinetTelegramSendFile($botToken, $chatId, $chunk[0], $chunkIndex === 0 ? $fileCaption : '');
                } else {
                    $ok = roCabinetTelegramSendImageGroup($botToken, $chatId, $chunk, $chunkIndex === 0 ? $fileCaption : '');
                }
                if ($ok) $chatOk = true;
            }
            if ($chatOk) $sent++;
        }
        return $sent;
    }

    $sent = sendTelegramBulk($botToken, $chatIds, $text, 'HTML', $keyboard);
    if (count($files) > 1) {
        foreach ($chatIds as $chatId) {
            foreach (array_chunk($imageFiles, 10) as $chunk) {
                if (count($chunk) > 1) {
                    roCabinetTelegramSendImageGroup($botToken, $chatId, $chunk);
                } elseif (count($chunk) === 1) {
                    roCabinetTelegramSendFile($botToken, $chatId, $chunk[0]);
                }
            }
            foreach ($otherFiles as $file) {
                roCabinetTelegramSendFile($botToken, $chatId, $file);
            }
        }
    }
    return $sent;
}

function roApplyAllowedGroupsSql($sessionUser, &$where, &$params, $expr) {
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $groups = roGetSessionUserGroups($sessionUser);
    if (empty($groups)) {
        $where[] = '1=0';
        return;
    }
    $ph = implode(',', array_fill(0, count($groups), '?'));
    $where[] = "{$expr} IN ({$ph})";
    foreach ($groups as $group) $params[] = $group;
}

// ═══ Публичные маршруты (ресторанная авторизация) ═══

$roAction = $subpoint ?? '';
$roParts = explode('/', $uri);
// uri = "ro/action/param" → roParts = ["ro", "action", "param"]
$roParam = $roParts[2] ?? null;

// --- Авторизация через Telegram ---
if ($roAction === 'tg-auth' && $method === 'POST') {
    $tgToken = $body['tg_token'] ?? '';
    $acceptedDataRules = !empty($body['accepted_data_rules']);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!$tgToken) {
        roRespond(['success' => false, 'error' => 'Токен не указан'], 400);
    }
    if (!$acceptedDataRules) {
        roRespond(['success' => false, 'error' => 'Подтвердите согласие с правилами использования портала'], 400);
    }

    // Защита от перебора токенов: 10 попыток в минуту с одного IP.
    if (!checkRateLimit($pdo, $clientIp, 10, 1)) {
        roRespond(['success' => false, 'error' => 'Слишком много попыток. Подождите минуту'], 429);
    }

    // Ищем токен. kind='auth' — принимаем ТОЛЬКО длинные токены входа,
    // 6-значные коды привязки (kind='bind') здесь не должны проходить.
    $s = $pdo->prepare("SELECT id, telegram_chat_id, restaurant_number, legal_entity_group FROM ro_tg_tokens WHERE token = ? AND kind = 'auth' AND expires_at > NOW() AND used = 0 LIMIT 1");
    $s->execute([$tgToken]);
    $tgAuth = $s->fetch();
    if (!$tgAuth) {
        recordFailedLogin($pdo, $clientIp, "tg_auth_invalid");
        roRespond(['success' => false, 'error' => 'Ссылка недействительна или истекла']);
    }

    // Атомарно помечаем токен использованным. Если параллельный запрос успел
    // первым (rowCount=0) — отказываем, чтобы ссылка действительно была одноразовой.
    $claim = $pdo->prepare("UPDATE ro_tg_tokens SET used = 1 WHERE id = ? AND used = 0");
    $claim->execute([$tgAuth['id']]);
    if ($claim->rowCount() !== 1) {
        roRespond(['success' => false, 'error' => 'Ссылка уже была использована']);
    }

    // Если в токене явно указан ресторан (например, выбран в меню Камако) — используем его
    $restNum = $tgAuth['restaurant_number'] ?? null;
    if (!$restNum) {
        // Иначе берём первую подписку этого чата
        $s = $pdo->prepare("SELECT restaurant_number FROM ro_telegram_subs WHERE chat_id = ? AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW())) LIMIT 1");
        $s->execute([$tgAuth['telegram_chat_id']]);
        $sub = $s->fetch();
        if (!$sub) {
            roRespond(['success' => false, 'error' => 'Вы не подписаны ни на один ресторан в боте']);
        }
        $restNum = $sub['restaurant_number'];
    } else {
        // Проверяем, что chatId из токена реально подписан на этот ресторан
        $subCheck = $pdo->prepare("SELECT 1 FROM ro_telegram_subs WHERE chat_id = ? AND restaurant_number = ? AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW())) LIMIT 1");
        $subCheck->execute([$tgAuth['telegram_chat_id'], $restNum]);
        if (!$subCheck->fetchColumn()) {
            roRespond(['success' => false, 'error' => 'У вас нет доступа к этому ресторану'], 403);
        }
    }
    $restGroup = roNormalizeLegalEntityGroup($tgAuth['legal_entity_group'] ?? null, $restNum);

    // Проверяем, есть ли учётка ресторана
    $s = $pdo->prepare("SELECT id, restaurant_number, legal_entity, legal_entity_group FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
    $s->execute([$restNum, $restGroup]);
    $user = $s->fetch();
    if (!$user) {
        roRespond(['success' => false, 'error' => "Учётная запись ресторана {$restNum} не найдена. Обратитесь в отдел закупок."]);
    }

    // Создаём сессию. Логин через Telegram-ссылку = подтверждённое доверенное
    // устройство, поэтому remember=true (живёт 30 дней).
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $isNewDevice = !roIsKnownDevice($pdo, $user['id'], $ua);
    $session = roIssueSession($pdo, $user['id'], true, $ip, $ua);
    if (!$session) {
        roRespond(['success' => false, 'error' => 'Не удалось создать сессию'], 500);
    }
    $pdo->prepare("UPDATE ro_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    roSetSessionCookie($session['token'], $session['expires_unix']);
    recordPortalConsent($pdo, 'restaurant', $restGroup . ':' . $restNum, 'Ресторан ' . $restNum . ' ' . $restGroup);

    $rest = roGetRestaurantRow($pdo, $restNum, $restGroup);
    if (!$rest) {
        roRespond(['success' => false, 'error' => "Ресторан {$restNum} не найден или отключён"]);
    }

    if ($isNewDevice) {
        roNotifyNewDeviceLogin($pdo, $restNum, $restGroup, $ip, $ua, 'Telegram');
    }

    roRespond([
        'success' => true,
        'token' => $session['token'],
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'legal_entity_group' => $rest['legal_entity_group'] ?? $restGroup,
            'region' => $rest['region'] ?? '',
            'city' => $rest['city'] ?? '',
            'address' => $rest['address'] ?? '',
        ],
    ]);
}

// --- Логин ---
if ($roAction === 'login' && $method === 'POST') {
    $rawEmail = trim((string)($body['email'] ?? ''));
    $restNum  = intval($body['restaurant_number'] ?? 0);
    $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
    $password = $body['password'] ?? '';
    $acceptedDataRules = !empty($body['accepted_data_rules']);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Можно прислать либо restaurant_number, либо email — но что-то одно должно быть.
    $loginByEmail = ($rawEmail !== '');
    if ($loginByEmail) {
        if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
            roRespond(['success' => false, 'error' => 'Введите корректный email или номер ресторана'], 400);
        }
        $rawEmail = mb_strtolower($rawEmail);
    } elseif (!$restNum) {
        roRespond(['success' => false, 'error' => 'Введите номер ресторана и пароль'], 400);
    }
    if (!$password) {
        roRespond(['success' => false, 'error' => 'Введите пароль'], 400);
    }
    if (!$acceptedDataRules) {
        roRespond(['success' => false, 'error' => 'Подтвердите согласие с правилами использования портала'], 400);
    }

    if (!checkRateLimit($pdo, $clientIp, 15, 10)) {
        roRespond(['success' => false, 'error' => 'Слишком много попыток. Подождите 10 минут'], 429);
    }
    // Ключ rate-limit отдельный для email и для номера, чтобы атака на один ресторан
    // не блокировала вход других через email и наоборот.
    $rateKey = $loginByEmail ? "rest_email_{$rawEmail}" : "rest_{$restNum}";
    if (!checkAccountRateLimit($pdo, $rateKey, 5, 10)) {
        roRespond(['success' => false, 'error' => 'Слишком много неудачных попыток. Подождите 10 минут'], 429);
    }

    if ($loginByEmail) {
        // Логин по email — только для подтверждённых адресов. Иначе любой
        // подбросит свой неподтверждённый email и сможет атаковать чужой пароль.
        $s = $pdo->prepare("SELECT id, restaurant_number, password_hash, legal_entity, legal_entity_group, last_login_at FROM ro_users WHERE email = ? AND email_verified_at IS NOT NULL AND is_active = 1 LIMIT 1");
        $s->execute([$rawEmail]);
        $user = $s->fetch();
        // restGroup может прийти неверным (фронт не знает группу по email) —
        // используем фактический из найденной учётки.
        if ($user) {
            $restGroup = $user['legal_entity_group'];
            $restNum   = (int)$user['restaurant_number'];
        }
    } else {
        $s = $pdo->prepare("SELECT id, restaurant_number, password_hash, legal_entity, legal_entity_group, last_login_at FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
        $s->execute([$restNum, $restGroup]);
        $user = $s->fetch();
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($pdo, $clientIp, $rateKey);
        $errMsg = $loginByEmail ? 'Неверный email или пароль' : 'Неверный номер ресторана или пароль';
        roRespond(['success' => false, 'error' => $errMsg]);
    }

    // Параллельные сессии разрешены — больше не блокируем логин из-за чужой
    // активной сессии. Лимит и LRU-вытеснение делает roIssueSession.
    $remember = array_key_exists('remember', $body) ? !empty($body['remember']) : true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    // Проверяем «новое устройство» до записи сессии — иначе сами же будем найдены.
    $isNewDevice = !roIsKnownDevice($pdo, $user['id'], $ua);

    $session = roIssueSession($pdo, $user['id'], $remember, $ip, $ua);
    if (!$session) {
        roRespond(['success' => false, 'error' => 'Не удалось создать сессию'], 500);
    }
    $pdo->prepare("UPDATE ro_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    roSetSessionCookie($session['token'], $session['expires_unix']);
    recordPortalConsent($pdo, 'restaurant', $restGroup . ':' . $restNum, 'Ресторан ' . $restNum . ' ' . $restGroup);

    // Инфо о ресторане
    $rest = roGetRestaurantRow($pdo, $restNum, $restGroup);
    if (!$rest) {
        roRespond(['success' => false, 'error' => "Ресторан {$restNum} не найден или отключён"]);
    }

    if ($isNewDevice) {
        roNotifyNewDeviceLogin($pdo, $restNum, $restGroup, $ip, $ua, 'пароль');
    }

    roRespond([
        'success' => true,
        'token' => $session['token'],
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'legal_entity_group' => $rest['legal_entity_group'] ?? $restGroup,
            'region' => $rest['region'] ?? '',
            'city' => $rest['city'] ?? '',
            'address' => $rest['address'] ?? '',
        ],
    ]);
}

// --- Валидация сессии ---
if ($roAction === 'validate' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) {
        roRespond(['valid' => false]);
    }
    roRespond(['valid' => true, 'restaurant' => [
        'number' => $rest['restaurant_number'],
        'legal_entity' => $rest['legal_entity'],
        'legal_entity_group' => $rest['legal_entity_group'] ?? 'BK_VM',
        'region' => $rest['region'] ?? '',
        'city' => $rest['city'] ?? '',
        'address' => $rest['address'] ?? '',
    ]]);
}

// --- Выход (с текущего устройства) ---
if ($roAction === 'logout' && $method === 'POST') {
    // Удаляем только сессию этого устройства; параллельные сессии в других
    // браузерах/телефонах продолжают жить.
    $token = roGetSessionToken();
    if ($token) {
        roRevokeSessionByToken($pdo, $token);
    }
    roClearSessionCookie();
    roRespond(['success' => true]);
}

// --- Активные устройства ресторана ---
if ($roAction === 'sessions' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $currentToken = roGetSessionToken();
    $s = $pdo->prepare("
        SELECT id, created_at, last_seen_at, expires_at, remember,
               ip_address, user_agent, device_label, token
        FROM ro_user_sessions
        WHERE ro_user_id = ? AND expires_at > NOW()
        ORDER BY last_seen_at DESC
    ");
    $s->execute([(int)$rest['id']]);
    $list = [];
    foreach ($s->fetchAll() as $row) {
        $list[] = [
            'id'            => (int)$row['id'],
            'created_at'    => $row['created_at'],
            'last_seen_at'  => $row['last_seen_at'],
            'expires_at'    => $row['expires_at'],
            'remember'      => (int)$row['remember'] === 1,
            'ip_address'    => $row['ip_address'],
            'device_label'  => $row['device_label'] ?: 'Устройство',
            'is_current'    => hash_equals((string)$row['token'], (string)$currentToken),
        ];
    }
    roRespond(['success' => true, 'sessions' => $list]);
}

// --- Отозвать конкретную сессию ---
if ($roAction === 'sessions-revoke' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $sessionId = (int)($body['session_id'] ?? 0);
    if ($sessionId <= 0) roRespond(['success' => false, 'error' => 'Не указана сессия'], 400);
    // Удалить можно только сессию своего ресторана. Если это текущая сессия —
    // тоже удаляем и стираем cookie, фронт после этого получит 401 и редиректнет на логин.
    $st = $pdo->prepare("SELECT token FROM ro_user_sessions WHERE id = ? AND ro_user_id = ? LIMIT 1");
    $st->execute([$sessionId, (int)$rest['id']]);
    $row = $st->fetch();
    if (!$row) roRespond(['success' => false, 'error' => 'Сессия не найдена'], 404);
    $pdo->prepare("DELETE FROM ro_user_sessions WHERE id = ?")->execute([$sessionId]);
    $currentToken = roGetSessionToken();
    if ($currentToken && hash_equals((string)$row['token'], (string)$currentToken)) {
        roClearSessionCookie();
    }
    roRespond(['success' => true]);
}

// --- Выйти со всех остальных устройств ---
if ($roAction === 'sessions-revoke-others' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $currentToken = roGetSessionToken();
    $removed = roRevokeAllSessionsForUser($pdo, (int)$rest['id'], $currentToken);
    roRespond(['success' => true, 'removed' => $removed]);
}

// --- Heartbeat: где сейчас ресторан в кабинете (для /admin → «Рестораны онлайн») ---
if ($roAction === 'heartbeat' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['success' => false], 401);
    $page = trim((string)($body['page'] ?? ''));
    if (mb_strlen($page) > 120) $page = mb_substr($page, 0, 120);
    $pdo->prepare("UPDATE ro_users SET last_page = ?, last_seen_at = NOW() WHERE id = ?")
        ->execute([$page !== '' ? $page : null, $rest['id']]);
    roRespond(['success' => true]);
}

// --- Смена пароля ---
if ($roAction === 'change-password' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit($pdo, $clientIp, 10, 10)) {
        roRespond(['error' => 'Слишком много попыток. Подождите 10 минут'], 429);
    }
    $oldPass = $body['old_password'] ?? '';
    $newPass = $body['new_password'] ?? '';
    if (!$oldPass || !$newPass) roRespond(['error' => 'Заполните оба поля'], 400);
    if (mb_strlen($newPass) < 8) roRespond(['error' => 'Новый пароль слишком короткий (минимум 8 символов)'], 400);
    // Проверяем старый пароль
    $s = $pdo->prepare("SELECT id, password_hash FROM ro_users WHERE id = ? AND is_active = 1");
    $s->execute([$rest['id']]);
    $user = $s->fetch();
    if (!$user || !password_verify($oldPass, $user['password_hash'])) {
        recordFailedLogin($pdo, $clientIp, "ro_chpass_{$rest['restaurant_number']}");
        roRespond(['error' => 'Неверный текущий пароль']);
    }
    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE ro_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?")->execute([$newHash, $user['id']]);
    // После смены пароля гасим все ОСТАЛЬНЫЕ сессии этого ресторана.
    // Текущую (этого устройства) оставляем — иначе сразу выкинуло бы на логин.
    roRevokeAllSessionsForUser($pdo, (int)$user['id'], roGetSessionToken());
    roLogAudit($pdo, [
        'action'            => 'password_changed',
        'actor_type'        => 'restaurant',
        'restaurant_number' => $rest['restaurant_number'],
        'actor_name'        => 'Ресторан ' . $rest['restaurant_number'],
    ]);
    roRespond(['success' => true]);
}

// --- Проверка активного сбора остатков ---
if ($roAction === 'stock-collection-status' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    // Сборы видны на уровне группы юрлиц (BK+VM делят, PS отдельно).
    $where = ["sc.status = 'active'", "sc.legal_entity_group = ?"];
    $params = [$group];
    $sql = "SELECT sc.id, sc.name, sc.created_at,
                (SELECT COUNT(DISTINCT scd.product_id) FROM stock_collection_data scd
                 JOIN stock_collection_products scp ON scp.id = scd.product_id AND scp.collection_id = sc.id
                 WHERE scd.restaurant_number = ?) as submitted_count,
                (SELECT COUNT(*) FROM stock_collection_products scp2 WHERE scp2.collection_id = sc.id) as total_products
            FROM stock_collections sc WHERE " . implode(' AND ', $where) . " ORDER BY sc.id DESC";
    $s = $pdo->prepare($sql);
    $s->execute(array_merge([$rest['restaurant_number']], $params));
    $collections = $s->fetchAll();
    if (!$collections) {
        roRespond(['active' => false, 'collections' => []]);
    }
    $collection = $collections[0];
    $items = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
            'submitted' => ((int)$row['total_products'] > 0) && ((int)$row['submitted_count'] >= (int)$row['total_products']),
            'submitted_count' => (int)$row['submitted_count'],
            'total_products' => (int)$row['total_products'],
        ];
    }, $collections);
    roRespond([
        'active' => true,
        'collection' => [
            'id' => (int)$collection['id'],
            'name' => $collection['name'],
            'submitted' => ((int)$collection['total_products'] > 0) && ((int)$collection['submitted_count'] >= (int)$collection['total_products']),
            'submitted_count' => (int)$collection['submitted_count'],
            'total_products' => (int)$collection['total_products'],
        ],
        'collections' => $items,
    ]);
}

// --- Данные активного сбора остатков для ресторана (товары + его значения) ---
if ($roAction === 'stock-collection-data' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $collectionId = intval($_GET['collection_id'] ?? 0);
    // Сборы видны на уровне группы юрлиц (BK+VM делят, PS отдельно).
    $where = ["sc.status = 'active'", "sc.legal_entity_group = ?"];
    $params = [$group];
    $sql = "SELECT id, name, created_at FROM stock_collections sc WHERE " . implode(' AND ', $where);
    if ($collectionId > 0) {
        $sql .= " AND sc.id = ?";
        $params[] = $collectionId;
    } else {
        $sql .= " ORDER BY id DESC LIMIT 1";
    }
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $coll = $s->fetch();
    if (!$coll) roRespond(['active' => false]);

    // Товары сбора
    $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
    $hasNote = dbColumnExists($pdo, 'stock_collection_products', 'note');
    $productCols = ['id', 'product_name', 'product_sku', 'unit'];
    if ($hasNeedExpiry) $productCols[] = 'need_expiry';
    $productCols[] = 'sort_order';
    if ($hasNote) $productCols[] = 'note';
    $p = $pdo->prepare("SELECT " . implode(', ', $productCols) . " FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order, id");
    $p->execute([$coll['id']]);
    $products = $p->fetchAll();

    // Ранее сохранённые значения этого ресторана
    $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
    $dataCols = ['product_id', 'stock'];
    if ($hasExpiryDate) $dataCols[] = 'expiry_date';
    $dataCols[] = 'submitted_at';
    $orderBy = $hasExpiryDate ? 'ORDER BY product_id, expiry_date, id' : 'ORDER BY product_id, id';
    $d = $pdo->prepare("SELECT " . implode(', ', $dataCols) . " FROM stock_collection_data WHERE collection_id = ? AND restaurant_number = ? {$orderBy}");
    $d->execute([$coll['id'], $rest['restaurant_number']]);
    $values = [];
    $batches = [];
    $lastSubmittedAt = null;
    foreach ($d->fetchAll() as $row) {
        $pid = (int)$row['product_id'];
        $values[$pid] = ($values[$pid] ?? 0) + (float)$row['stock'];
        if (!isset($batches[$pid])) $batches[$pid] = [];
        $batches[$pid][] = [
            'stock' => (float)$row['stock'],
            'expiry_date' => $hasExpiryDate ? $row['expiry_date'] : null,
            'submitted_at' => $row['submitted_at'],
        ];
        if (!$lastSubmittedAt || $row['submitted_at'] > $lastSubmittedAt) {
            $lastSubmittedAt = $row['submitted_at'];
        }
    }

    roRespond([
        'active' => true,
        'collection' => [
            'id' => (int)$coll['id'],
            'name' => $coll['name'],
        ],
        'products' => $products,
        'values' => $values,
        'batches' => $batches,
        'last_submitted_at' => $lastSubmittedAt,
    ]);
}

function roNormalizeStockCollectionBatches($item, $allowExpiry = true) {
    $batches = [];
    if (!is_array($item)) return $batches;

    // Принимаем число с запятой («5,5») и число с пробелами («5 000»).
    $parseStock = function ($v) {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace([',', ' ', "\xC2\xA0"], ['.', '', ''], (string)$v);
        return is_numeric($s) ? (float)$s : null;
    };

    if (isset($item['batches']) && is_array($item['batches'])) {
        foreach ($item['batches'] as $batch) {
            if (!is_array($batch)) continue;
            $expiry = trim((string)($batch['expiry_date'] ?? ''));
            $stock = $parseStock($batch['stock'] ?? null);
            if ($stock === null) continue;
            $stockVal = round($stock, 2);
            if ($stockVal < 0 || $stockVal > 999999) continue;
            if ($allowExpiry && $expiry !== '') {
                $dt = DateTime::createFromFormat('Y-m-d', $expiry);
                if (!$dt || $dt->format('Y-m-d') !== $expiry) continue;
                $batches[] = ['expiry_date' => $expiry, 'stock' => $stockVal];
            } else {
                $batches[] = ['expiry_date' => null, 'stock' => $stockVal];
            }
        }
        return $batches;
    }

    $stock = $parseStock($item['stock'] ?? null);
    $expiry = trim((string)($item['expiry_date'] ?? ''));
    if ($stock !== null) {
        $stockVal = round($stock, 2);
        if ($stockVal >= 0 && $stockVal <= 999999) {
            if ($allowExpiry && $expiry !== '') {
                $dt = DateTime::createFromFormat('Y-m-d', $expiry);
                if ($dt && $dt->format('Y-m-d') === $expiry) {
                    $batches[] = ['expiry_date' => $expiry, 'stock' => $stockVal];
                }
            } else {
                $batches[] = ['expiry_date' => null, 'stock' => $stockVal];
            }
        }
    }

    return $batches;
}

// --- Сохранение остатков ресторана (из личного кабинета) ---
if ($roAction === 'stock-collection-submit' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $collId = intval($body['collection_id'] ?? 0);
    $items = $body['items'] ?? [];
    if ($collId <= 0) roRespond(['error' => 'Не указан сбор'], 400);
    if (!is_array($items)) roRespond(['error' => 'Некорректные данные'], 400);

    // Проверяем, что сбор активен и принадлежит группе юрлиц ресторана.
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $check = $pdo->prepare("SELECT id, legal_entity_group FROM stock_collections WHERE id = ? AND status = 'active'");
    $check->execute([$collId]);
    $coll = $check->fetch();
    if (!$coll) roRespond(['error' => 'Сбор не найден или уже закрыт'], 404);
    if ($coll['legal_entity_group'] !== $group) roRespond(['error' => 'Сбор не для вашего юрлица'], 403);

    // Загружаем допустимые product_id для этой коллекции
    $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
    $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
    $productCols = ['id'];
    if ($hasNeedExpiry) $productCols[] = 'need_expiry';
    $validPids = $pdo->prepare("SELECT " . implode(', ', $productCols) . " FROM stock_collection_products WHERE collection_id = ?");
    $validPids->execute([$collId]);
    $allowedSet = [];
    foreach ($validPids->fetchAll() as $row) {
        $allowedSet[(int)$row['id']] = $hasNeedExpiry ? ((int)$row['need_expiry'] === 1) : false;
    }

    $del = $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ?");
    if ($hasExpiryDate) {
        $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, expiry_date, stock, source, submitted_at) VALUES (?, ?, ?, ?, ?, 'form', NOW())");
    } else {
        $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at) VALUES (?, ?, ?, ?, 'form', NOW())");
    }
    // Загружаем имена товаров для информативных сообщений об ошибках.
    $namesStmt = $pdo->prepare("SELECT id, product_name FROM stock_collection_products WHERE collection_id = ?");
    $namesStmt->execute([$collId]);
    $productNames = [];
    foreach ($namesStmt->fetchAll() as $row) {
        $productNames[(int)$row['id']] = $row['product_name'];
    }

    $pdo->beginTransaction();
    try {
        $saved = 0;
        $skippedUnknown = 0;
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid <= 0) continue;
            if (!array_key_exists($pid, $allowedSet)) {
                $skippedUnknown++;
                continue;
            }
            $batches = roNormalizeStockCollectionBatches($item, $hasExpiryDate);
            if (!$batches) {
                $name = $productNames[$pid] ?? ('id=' . $pid);
                roRespond(['error' => 'У товара «' . $name . '» не указано количество (или указано некорректно)'], 400);
            }
            if ($allowedSet[$pid]) {
                foreach ($batches as $batch) {
                    // Срок обязателен только если остаток > 0
                    if (empty($batch['expiry_date']) && (float)$batch['stock'] > 0) {
                        $name = $productNames[$pid] ?? ('id=' . $pid);
                        error_log('stock-collection-submit: empty expiry_date for pid=' . $pid . ', item=' . json_encode($item, JSON_UNESCAPED_UNICODE));
                        roRespond(['error' => 'У товара «' . $name . '» нужно указать срок годности (или поставьте остаток 0)'], 400);
                    }
                }
            }
            $del->execute([$collId, $pid, $rest['restaurant_number']]);
            foreach ($batches as $batch) {
                if ($hasExpiryDate) {
                    $ins->execute([$collId, $pid, $rest['restaurant_number'], $batch['expiry_date'], $batch['stock']]);
                } else {
                    $ins->execute([$collId, $pid, $rest['restaurant_number'], $batch['stock']]);
                }
                $saved++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('ro stock-collection-submit error: ' . $e->getMessage());
        roRespond(['error' => 'Не удалось сохранить остатки. Попробуйте ещё раз или обратитесь в отдел закупок.'], 500);
    }
    if ($saved === 0) {
        roRespond(['error' => 'Ничего не сохранено. Проверьте, что введены количества (' . $skippedUnknown . ' позиций пропущено как неизвестные)'], 400);
    }
    roRespond(['success' => true, 'saved' => $saved]);
}

// --- Остатки склада для кабинета ресторана (из модуля «Сроки годности») ---
if ($roAction === 'warehouse-stock' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $legalEntity = roWarehouseStockLegalEntityForRestaurant($rest);
    $customer = roShortCustomerName($legalEntity);
    if (!$customer) roRespond(['error' => 'Не удалось определить юр. лицо ресторана'], 400);

    $prodStmt = $pdo->prepare("
        SELECT sku, external_code, gtin, name, analog_group, category
        FROM products
        WHERE legal_entity = ? AND is_active = 1
    ");
    $prodStmt->execute([$legalEntity]);
    $productsBySku = [];
    $productsByExternal = [];
    $productsByName = [];
    foreach ($prodStmt->fetchAll() as $p) {
        $sku = trim((string)($p['sku'] ?? ''));
        $ext = trim((string)($p['external_code'] ?? ''));
        $name = roNormalizeLookupText($p['name'] ?? '');
        if ($sku !== '') $productsBySku[$sku] = $p;
        if ($ext !== '') $productsByExternal[$ext] = $p;
        if ($name !== '') $productsByName[$name] = $p;
    }

    $s = $pdo->prepare("
        SELECT customer, warehouse, product_name, production_date, expiry_date, expiry_status, quantity, uploaded_at
        FROM stock_malling
        WHERE customer = ?
        ORDER BY product_name, expiry_date IS NULL, expiry_date ASC
    ");
    $s->execute([$customer]);

    $groups = [];
    $latestUpload = null;
    $today = new DateTimeImmutable('today');
    foreach ($s->fetchAll() as $row) {
        $qty = round((float)($row['quantity'] ?? 0), 2);
        if ($qty <= 0) continue;
        $expiry = $row['expiry_date'] ?: null;
        if ($expiry) {
            $exp = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry) ?: new DateTimeImmutable($expiry);
            if ($exp < $today) continue;
        }
        $product = roFindProductForShelfRow($row['product_name'] ?? '', $productsBySku, $productsByExternal, $productsByName);
        $sku = trim((string)($product['sku'] ?? ''));
        $external = trim((string)($product['external_code'] ?? ''));
        if (!$sku && preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', (string)$row['product_name'], $m)) $sku = trim($m[2]);
        if (!$external && preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', (string)$row['product_name'], $m)) $external = trim($m[1]);

        $key = $sku ? 'sku:' . $sku : 'name:' . roNormalizeLookupText($row['product_name']);
        $storage = roWarehouseStorageMode($row['warehouse'] ?? '');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'sku' => $sku,
                'external_code' => $external,
                'gtin' => $product['gtin'] ?? '',
                'name' => $product['name'] ?? preg_replace('/^\s*[^\s]+\s+-\s*[^\s]+\s+/u', '', (string)$row['product_name']),
                'raw_name' => $row['product_name'],
                'analog_group' => $product['analog_group'] ?? '',
                'category' => $product['category'] ?? '',
                'storage_key' => $storage['key'],
                'storage_label' => $storage['label'],
                'quantity' => 0,
                'nearest_expiry' => null,
                'nearest_status' => null,
                'days_left' => null,
                'has_expired' => false,
                'batches' => [],
            ];
        }
        $g =& $groups[$key];
        $g['quantity'] = round($g['quantity'] + $qty, 2);
        if ($g['storage_key'] === 'other' && $storage['key'] !== 'other') {
            $g['storage_key'] = $storage['key'];
            $g['storage_label'] = $storage['label'];
        }
        if ($expiry && (!$g['nearest_expiry'] || $expiry < $g['nearest_expiry'])) {
            $g['nearest_expiry'] = $expiry;
            $g['nearest_status'] = $row['expiry_status'] ?: null;
        }
        $g['batches'][] = [
            'warehouse' => $row['warehouse'],
            'quantity' => $qty,
            'production_date' => $row['production_date'] ?: null,
            'expiry_date' => $expiry,
            'expiry_status' => $row['expiry_status'] ?: null,
        ];
        unset($g);
        if (!empty($row['uploaded_at']) && (!$latestUpload || $row['uploaded_at'] > $latestUpload)) {
            $latestUpload = $row['uploaded_at'];
        }
    }

    $items = array_values($groups);
    foreach ($items as &$item) {
        usort($item['batches'], function($a, $b) {
            return ($a['expiry_date'] ?: '9999-12-31') <=> ($b['expiry_date'] ?: '9999-12-31');
        });
        if ($item['nearest_expiry']) {
            $exp = DateTimeImmutable::createFromFormat('!Y-m-d', $item['nearest_expiry']) ?: new DateTimeImmutable($item['nearest_expiry']);
            $item['days_left'] = (int)$today->diff($exp)->format('%r%a');
        }
    }
    unset($item);
    usort($items, fn($a, $b) => strcmp($a['storage_label'], $b['storage_label']) ?: strcmp($a['name'], $b['name']));

    roRespond([
        'legal_entity' => $legalEntity,
        'customer' => $customer,
        'uploaded_at' => $latestUpload,
        'items' => $items,
    ]);
}

// --- Привязка Telegram: генерация токена ---
if ($roAction === 'telegram-link' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    // Каждый сотрудник может иметь свою привязку, поэтому код выдаём всегда —
    // даже если у этой учётки уже есть какие-то связанные chat_id. Источник
    // правды о связях — ro_telegram_subs.
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    // Привязываем код к конкретному ro_user, чтобы бот знал, чьё подтверждение получено.
    // kind='bind' — этот код используется ТОЛЬКО для привязки Telegram через бота;
    // эндпоинт /api/ro/tg-auth его не примет (см. фильтр kind='auth').
    $pdo->prepare("INSERT INTO ro_tg_tokens (token, kind, telegram_chat_id, restaurant_number, legal_entity_group, ro_user_id, expires_at, used) VALUES (?, 'bind', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)")
        ->execute([$code, 0, (string)$rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM', (int)$rest['id']]);
    roRespond(['success' => true, 'code' => $code, 'expires_in' => 600]);
}

// --- Отвязка Telegram (по chat_id) ---
// Любой залогиненный сотрудник того же ресторана может отвязать любой Telegram —
// это нужно, например, чтобы быстро отрубить уволенного.
if ($roAction === 'telegram-unlink' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $chatId = isset($body['chat_id']) ? (string)$body['chat_id'] : '';
    $restNum = (int)($rest['restaurant_number'] ?? 0);
    $restGroup = $rest['legal_entity_group'] ?? 'BK_VM';
    if ($chatId === '') {
        // Старая семантика: «отвязать всё на этой учётке».
        $pdo->prepare("UPDATE ro_users SET telegram_chat_id = NULL WHERE id = ?")
            ->execute([$rest['id']]);
        $pdo->prepare("DELETE FROM ro_telegram_subs WHERE restaurant_number = ? AND legal_entity_group = ?")
            ->execute([$restNum, $restGroup]);
    } else {
        // Удаляем подписку только этого chat_id у текущего ресторана.
        $pdo->prepare("DELETE FROM ro_telegram_subs WHERE chat_id = ? AND restaurant_number = ? AND legal_entity_group = ?")
            ->execute([(int)$chatId, $restNum, $restGroup]);
        // Если это был «основной» chat_id у ro_users — тоже сбросим, иначе он повиснет.
        $pdo->prepare("UPDATE ro_users SET telegram_chat_id = NULL WHERE telegram_chat_id = ? AND restaurant_number = ? AND legal_entity_group = ?")
            ->execute([(int)$chatId, $restNum, $restGroup]);
    }
    roRespond(['success' => true]);
}

// --- Статус привязки текущего сотрудника ---
if ($roAction === 'telegram-status' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    // У ресторанов общий аккаунт, отдельных логинов сотрудников нет.
    // Поэтому кабинет не может честно определить "мой Telegram":
    // каждый сотрудник должен иметь возможность получить новый код привязки.
    roRespond([
        'linked' => false,
        'chat_id' => null,
        'first_name' => null,
        'username' => null,
        'linked_at' => null,
    ]);
}

// --- Список всех привязанных Telegram-аккаунтов этого ресторана ---
if ($roAction === 'telegram-links' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $restNum = (int)($rest['restaurant_number'] ?? 0);
    $restGroup = $rest['legal_entity_group'] ?? 'BK_VM';
    $s = $pdo->prepare("
        SELECT rs.chat_id, rs.first_name, rs.username, rs.verified_at, rs.must_reverify_by,
               rs.verified_via, rs.verified_ro_user_id
        FROM ro_telegram_subs rs
        WHERE rs.restaurant_number = ? AND rs.legal_entity_group = ?
        ORDER BY (rs.verified_at IS NOT NULL) DESC, rs.created_at DESC
    ");
    $s->execute([$restNum, $restGroup]);
    $links = [];
    foreach ($s->fetchAll() as $r) {
        $links[] = [
            'chat_id' => (string)$r['chat_id'],
            'first_name' => $r['first_name'],
            'username' => $r['username'],
            'verified' => !empty($r['verified_at']),
            'verified_at' => $r['verified_at'],
            'must_reverify_by' => $r['must_reverify_by'],
            'is_self' => false,
        ];
    }
    roRespond(['links' => $links]);
}

if ($roAction === 'broadcasts' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $readerKey = roBroadcastReaderKey($rest);
    $s = $pdo->prepare("
        SELECT id, title, message, created_by, created_at
        FROM notifications
        WHERE type = 'ro_broadcast'
          AND created_at > NOW() - INTERVAL 30 DAY
          AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))
          AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $s->execute([$readerKey, $readerKey]);
    roRespond(['broadcasts' => $s->fetchAll()]);
}

if ($roAction === 'broadcast-read' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $ids = $body['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) roRespond(['error' => 'Нет ID'], 400);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) roRespond(['error' => 'Нет ID'], 400);
    $readerKey = roBroadcastReaderKey($rest);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$readerKey], $ids, [$readerKey]);
    $pdo->prepare("
        UPDATE notifications
        SET read_by = JSON_ARRAY_APPEND(COALESCE(read_by, '[]'), '$', ?)
        WHERE id IN ($ph)
          AND type = 'ro_broadcast'
          AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))
    ")->execute($params);
    roRespond(['success' => true]);
}

if ($roAction === 'cabinet-posts' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $group = roNormalizeLegalEntityGroup($rest['legal_entity_group'] ?? null, $rest['restaurant_number']);
    $restaurantNumber = (int)$rest['restaurant_number'];
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

    $sql = "
        SELECT p.id, p.title, p.message, p.target_mode, p.target_group, p.show_popup,
               p.published_at, p.created_by, p.created_at,
               r.read_at
        FROM ro_cabinet_posts p
        LEFT JOIN ro_cabinet_post_reads r
          ON r.post_id = p.id
         AND r.restaurant_number = ?
         AND r.legal_entity_group = ?
        WHERE p.is_published = 1
          AND p.deleted_at IS NULL
          AND (p.published_at IS NULL OR p.published_at <= NOW())
          AND " . roCabinetPostMatchesRestaurantSql('p') . "
        ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
        LIMIT {$limit}
    ";
    $s = $pdo->prepare($sql);
    $s->execute([$restaurantNumber, $group, $group, $restaurantNumber, $group]);
    $posts = $s->fetchAll();
    roCabinetAttachFiles($pdo, $posts);
    foreach ($posts as &$post) {
        $post['is_read'] = !empty($post['read_at']);
    }
    unset($post);
    roRespond(['posts' => $posts]);
}

if ($roAction === 'cabinet-post-read' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $ids = $body['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) roRespond(['error' => 'Нет ID'], 400);
    $group = roNormalizeLegalEntityGroup($rest['legal_entity_group'] ?? null, $rest['restaurant_number']);
    $restaurantNumber = (int)$rest['restaurant_number'];
    $check = $pdo->prepare("
        SELECT p.id
        FROM ro_cabinet_posts p
        WHERE p.id = ?
          AND p.is_published = 1
          AND p.deleted_at IS NULL
          AND " . roCabinetPostMatchesRestaurantSql('p') . "
        LIMIT 1
    ");
    $ins = $pdo->prepare("
        INSERT INTO ro_cabinet_post_reads (post_id, restaurant_number, legal_entity_group, read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
    ");
    foreach ($ids as $id) {
        $check->execute([$id, $group, $restaurantNumber, $group]);
        if ($check->fetchColumn()) {
            $ins->execute([$id, $restaurantNumber, $group]);
        }
    }
    roRespond(['success' => true]);
}

if ($roAction === 'my-surveys' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $stmt = $pdo->prepare("
        SELECT s.id, s.title, s.description, s.allow_comment, s.status, s.sent_at, s.created_at,
               (SELECT COUNT(*) FROM survey_questions sq WHERE sq.survey_id = s.id) AS questions_count,
               sr.id AS response_id, sr.submitted_at
        FROM surveys s
        LEFT JOIN survey_responses sr
          ON sr.survey_id = s.id
         AND sr.restaurant_number = ?
        WHERE s.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
          AND s.status = 'active'
        ORDER BY
          CASE WHEN sr.id IS NULL AND s.status = 'active' THEN 0 ELSE 1 END,
          COALESCE(s.sent_at, s.created_at) DESC,
          s.id DESC
    ");
    $stmt->execute([(int)$rest['restaurant_number'], $group]);
    $surveys = $stmt->fetchAll();

    foreach ($surveys as &$survey) {
        $survey['already_answered'] = !empty($survey['response_id']);
    }
    unset($survey);

    roRespond(['surveys' => $surveys]);
}

if ($roAction === 'my-survey' && $method === 'GET' && $roParam) {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $survey = roGetSurveyForRestaurant($pdo, (int)$roParam, $rest);
    if (!$survey) roRespond(['error' => 'Опрос не найден'], 404);

    roRespond(['survey' => $survey]);
}

if ($roAction === 'submit-survey' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $surveyId = (int)($body['survey_id'] ?? 0);
    if (!$surveyId) roRespond(['error' => 'Не указан опрос'], 400);

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $surveyStmt = $pdo->prepare("
        SELECT id, allow_comment, status
        FROM surveys
        WHERE id = ?
          AND legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LIMIT 1
    ");
    $surveyStmt->execute([$surveyId, $group]);
    $survey = $surveyStmt->fetch();
    if (!$survey) roRespond(['error' => 'Опрос не найден'], 404);
    if (($survey['status'] ?? '') !== 'active') roRespond(['error' => 'Опрос уже закрыт'], 400);

    $existsStmt = $pdo->prepare("
        SELECT id
        FROM survey_responses
        WHERE survey_id = ? AND restaurant_number = ?
        LIMIT 1
    ");
    $existsStmt->execute([$surveyId, (int)$rest['restaurant_number']]);
    if ($existsStmt->fetch()) roRespond(['error' => 'Вы уже ответили на этот опрос'], 400);

    $rawAnswers = $body['answers'] ?? [];
    if (!is_array($rawAnswers)) roRespond(['error' => 'Некорректные ответы'], 400);

    $answerMap = [];
    foreach ($rawAnswers as $key => $value) {
        if (is_array($value)) {
            $questionId = (int)($value['question_id'] ?? 0);
            $answerMap[$questionId] = [
                'option_id' => (int)($value['option_id'] ?? 0),
                'numeric_value' => isset($value['numeric_value']) ? (int)$value['numeric_value'] : null,
                'text_value' => isset($value['text_value']) ? trim((string)$value['text_value']) : null,
            ];
            continue;
        } else {
            $questionId = (int)$key;
            $answerMap[$questionId] = [
                'option_id' => (int)$value,
                'numeric_value' => null,
                'text_value' => null,
            ];
            continue;
        }
    }
    $answerMap = array_filter($answerMap, fn($answer, $questionId) => (int)$questionId > 0, ARRAY_FILTER_USE_BOTH);

    $qStmt = $pdo->prepare("
        SELECT sq.id AS question_id, sq.type, sq.files_required, so.id AS option_id
        FROM survey_questions sq
        LEFT JOIN survey_options so ON so.question_id = sq.id
        WHERE sq.survey_id = ?
        ORDER BY sq.sort_order, sq.id, so.sort_order, so.id
    ");
    $qStmt->execute([$surveyId]);
    $questionOptions = [];
    $questionTypes = [];
    $questionFilesRequired = [];
    foreach ($qStmt->fetchAll() as $row) {
        $questionId = (int)$row['question_id'];
        $questionTypes[$questionId] = $row['type'] ?: 'choice';
        $questionFilesRequired[$questionId] = (int)$row['files_required'] === 1;
        $questionOptions[$questionId] ??= [];
        if ($row['option_id'] !== null) $questionOptions[$questionId][] = (int)$row['option_id'];
    }

    if (!$questionTypes) roRespond(['error' => 'В опросе нет вопросов'], 400);

    // Считаем сколько файлов уже лежит в черновике у этого ресторана по каждому вопросу.
    $filesCountStmt = $pdo->prepare("
        SELECT question_id, COUNT(*) AS cnt
        FROM survey_response_files
        WHERE response_id IS NULL AND survey_id = ? AND restaurant_number = ? AND legal_entity_group = ?
        GROUP BY question_id
    ");
    $filesCountStmt->execute([$surveyId, (int)$rest['restaurant_number'], $group]);
    $draftFilesCount = [];
    foreach ($filesCountStmt->fetchAll() as $row) {
        $draftFilesCount[(int)$row['question_id']] = (int)$row['cnt'];
    }

    // Для files-вопросов в payload могут не приходить answers; гарантируем валидацию по типу.
    $nonFileQuestions = array_filter($questionTypes, fn($t) => $t !== 'files');
    if (count($answerMap) !== count($nonFileQuestions)) {
        $missing = array_diff(array_keys($nonFileQuestions), array_keys($answerMap));
        if ($missing) roRespond(['error' => 'Ответьте на все вопросы'], 400);
    }

    foreach ($questionTypes as $questionId => $type) {
        if ($type === 'files') {
            $cnt = (int)($draftFilesCount[$questionId] ?? 0);
            if ($questionFilesRequired[$questionId] && $cnt === 0) {
                roRespond(['error' => 'Загрузите хотя бы один файл'], 400);
            }
            continue;
        }
        $answer = $answerMap[$questionId] ?? null;
        if ($type === 'scale') {
            $score = (int)($answer['numeric_value'] ?? 0);
            if ($score < 1 || $score > 10) roRespond(['error' => 'Одна из оценок указана неверно'], 400);
        } elseif ($type === 'text') {
            if (trim((string)($answer['text_value'] ?? '')) === '') roRespond(['error' => 'Ответьте на все вопросы'], 400);
        } else {
            $selectedOptionId = (int)($answer['option_id'] ?? 0);
            $optionIds = $questionOptions[$questionId] ?? [];
            if (!$selectedOptionId || !in_array($selectedOptionId, $optionIds, true)) {
                roRespond(['error' => 'Один из ответов выбран неверно'], 400);
            }
        }
    }

    $comment = null;
    if (!empty($survey['allow_comment'])) {
        $commentText = trim((string)($body['comment'] ?? ''));
        $comment = ($commentText !== '') ? $commentText : null;
    }

    $pdo->beginTransaction();
    try {
        $insertResponse = $pdo->prepare("
            INSERT INTO survey_responses (survey_id, restaurant_number, legal_entity_group, comment)
            VALUES (?, ?, ?, ?)
        ");
        $insertResponse->execute([
            $surveyId,
            (int)$rest['restaurant_number'],
            $group,
            $comment,
        ]);
        $responseId = (int)$pdo->lastInsertId();

        $insertAnswer = $pdo->prepare("
            INSERT INTO survey_answers (response_id, question_id, option_id, numeric_value, text_value)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($answerMap as $questionId => $answer) {
            $type = $questionTypes[(int)$questionId] ?? 'choice';
            $insertAnswer->execute([
                $responseId,
                (int)$questionId,
                $type === 'choice' ? (int)($answer['option_id'] ?? 0) : null,
                $type === 'scale' ? (int)($answer['numeric_value'] ?? 0) : null,
                $type === 'text' ? trim((string)($answer['text_value'] ?? '')) : null,
            ]);
        }

        // Привязываем все черновые файлы этого ресторана к свежему response_id.
        // Файлы уже физически на диске, тут только меняем response_id с NULL на конкретный.
        $pdo->prepare("
            UPDATE survey_response_files
            SET response_id = ?
            WHERE response_id IS NULL AND survey_id = ? AND restaurant_number = ? AND legal_entity_group = ?
        ")->execute([$responseId, $surveyId, (int)$rest['restaurant_number'], $group]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e->getCode() ?? null) === '23000') {
            roRespond(['error' => 'Ответ уже был сохранён'], 400);
        }
        roRespond(['error' => 'Не удалось сохранить ответ'], 500);
    }

    roRespond(['success' => true]);
}

/**
 * Допустимые типы файлов для вложений к опросу. Ключ — MIME, значение — расширение.
 * Картинки + PDF + основные Office-форматы. Любые .exe/.zip/.tar в список не входят.
 */
function roSurveyFileAllowedMimes() {
    return [
        'image/jpeg'  => 'jpg',
        'image/png'   => 'png',
        'image/heic'  => 'heic',
        'image/heif'  => 'heic',
        'image/webp'  => 'webp',
        'image/gif'   => 'gif',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'   => 'xlsx',
        'application/vnd.ms-excel'                                            => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword'                                                  => 'doc',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-powerpoint'                                       => 'ppt',
        'text/plain'  => 'txt',
        'text/csv'    => 'csv',
    ];
}

define('RO_SURVEY_FILE_MAX_BYTES', 25 * 1024 * 1024); // 25 МБ
define('RO_SURVEY_FILE_MAX_PER_QUESTION', 20);

// --- Загрузка файла к вопросу-файлы (черновик до submit) ---
if ($roAction === 'survey-file-upload' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $surveyId = (int)($_POST['survey_id'] ?? 0);
    $questionId = (int)($_POST['question_id'] ?? 0);
    if (!$surveyId || !$questionId) roRespond(['error' => 'Не указаны опрос и вопрос'], 400);
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            roRespond(['error' => 'Файл слишком большой'], 400);
        }
        roRespond(['error' => 'Файл не получен'], 400);
    }

    $group = $rest['legal_entity_group'] ?? 'BK_VM';

    // Проверка: опрос активный, этого юрлица, ответ ещё не сабмитнут.
    $sq = $pdo->prepare("
        SELECT s.status, sq.type, sq.id AS question_id
        FROM surveys s
        JOIN survey_questions sq ON sq.survey_id = s.id
        WHERE s.id = ?
          AND sq.id = ?
          AND s.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LIMIT 1
    ");
    $sq->execute([$surveyId, $questionId, $group]);
    $row = $sq->fetch();
    if (!$row) roRespond(['error' => 'Опрос или вопрос не найден'], 404);
    if ($row['status'] !== 'active') roRespond(['error' => 'Опрос уже закрыт'], 400);
    if ($row['type'] !== 'files') roRespond(['error' => 'К этому вопросу нельзя прикреплять файлы'], 400);

    $alreadyAnswered = $pdo->prepare("SELECT id FROM survey_responses WHERE survey_id = ? AND restaurant_number = ? LIMIT 1");
    $alreadyAnswered->execute([$surveyId, (int)$rest['restaurant_number']]);
    if ($alreadyAnswered->fetch()) roRespond(['error' => 'Вы уже отправили ответ на этот опрос'], 400);

    // Лимит N файлов на вопрос (среди черновиков этого ресторана).
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM survey_response_files
        WHERE response_id IS NULL AND survey_id = ? AND question_id = ? AND restaurant_number = ? AND legal_entity_group = ?
    ");
    $cntStmt->execute([$surveyId, $questionId, (int)$rest['restaurant_number'], $group]);
    if ((int)$cntStmt->fetchColumn() >= RO_SURVEY_FILE_MAX_PER_QUESTION) {
        roRespond(['error' => 'Достигнут лимит ' . RO_SURVEY_FILE_MAX_PER_QUESTION . ' файлов на вопрос'], 400);
    }

    $tmp = $_FILES['file']['tmp_name'];
    $origName = (string)($_FILES['file']['name'] ?? 'file');
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size <= 0) roRespond(['error' => 'Пустой файл'], 400);
    if ($size > RO_SURVEY_FILE_MAX_BYTES) {
        roRespond(['error' => 'Файл больше 25 МБ'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp) ?: '';
    finfo_close($finfo);
    $allowed = roSurveyFileAllowedMimes();
    if (!isset($allowed[$mime])) {
        roRespond(['error' => 'Неподдерживаемый тип файла'], 400);
    }
    $ext = $allowed[$mime];

    $year = date('Y');
    $month = date('m');
    $dir = __DIR__ . "/../uploads/survey_files/{$year}/{$month}/";
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        roRespond(['error' => 'Не удалось создать каталог'], 500);
    }
    $filename = sprintf(
        'survey_%d_q%d_r%d_%s.%s',
        $surveyId,
        $questionId,
        (int)$rest['restaurant_number'],
        bin2hex(random_bytes(8)),
        $ext
    );
    $dest = $dir . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        roRespond(['error' => 'Не удалось сохранить файл'], 500);
    }
    @chmod($dest, 0644);
    $relPath = "uploads/survey_files/{$year}/{$month}/{$filename}";
    $safeOrigName = mb_substr(preg_replace('/[\x00-\x1F]/u', '', $origName), 0, 255);

    $ins = $pdo->prepare("
        INSERT INTO survey_response_files
          (survey_id, question_id, restaurant_number, legal_entity_group, response_id, file_path, file_name, mime_type, file_size)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)
    ");
    $ins->execute([
        $surveyId,
        $questionId,
        (int)$rest['restaurant_number'],
        $group,
        $relPath,
        $safeOrigName,
        $mime,
        $size,
    ]);
    $fileId = (int)$pdo->lastInsertId();

    roRespond([
        'success' => true,
        'file' => [
            'id'         => $fileId,
            'question_id'=> $questionId,
            'file_name'  => $safeOrigName,
            'mime_type'  => $mime,
            'file_size'  => $size,
            'url'        => '/api/' . $relPath,
        ],
    ]);
}

// --- Удалить свой черновой файл опроса ---
if ($roAction === 'survey-file-remove' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $fileId = (int)($body['id'] ?? 0);
    if (!$fileId) roRespond(['error' => 'Не указан файл'], 400);
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $st = $pdo->prepare("
        SELECT id, file_path
        FROM survey_response_files
        WHERE id = ?
          AND response_id IS NULL
          AND restaurant_number = ?
          AND legal_entity_group = ?
        LIMIT 1
    ");
    $st->execute([$fileId, (int)$rest['restaurant_number'], $group]);
    $row = $st->fetch();
    if (!$row) roRespond(['error' => 'Файл не найден или уже отправлен в составе ответа'], 404);
    $pdo->prepare("DELETE FROM survey_response_files WHERE id = ?")->execute([$fileId]);
    $abs = __DIR__ . '/../' . ltrim((string)$row['file_path'], '/');
    if (is_file($abs)) @unlink($abs);
    roRespond(['success' => true]);
}

// --- Инфо: текущая сессия, расписание, дедлайны ---
if ($roAction === 'my-info' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    // Данные учётной записи ресторана для UI (модалка с email и т.п.).
    $accountInfo = [
        'email'          => $rest['email'] ?? null,
        'email_verified' => !empty($rest['email_verified_at']),
    ];

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    if (!roRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $group)) {
        roRespond([
            'restaurant_orders_enabled' => false,
            'session' => null,
            'delivery_days' => [],
            'account' => $accountInfo,
        ]);
    }

    $session = roGetActiveSession($pdo, $group);
    if (!$session) {
        roRespond([
            'restaurant_orders_enabled' => true,
            'session' => null,
            'delivery_days' => [],
            'account' => $accountInfo,
        ]);
    }

    // Расписание основной доставки этого ресторана. Дни, где заполнено только
    // dough_time для теста Пицца Стар, не должны открывать основной заказ.
    $ds = $pdo->prepare("
        SELECT ds.day_of_week, ds.delivery_time
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ? AND r.active = 1
          AND r.legal_entity_group = ?
          AND TRIM(COALESCE(ds.delivery_time, '')) <> ''
        ORDER BY ds.day_of_week
    ");
    $ds->execute([$rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM']);
    $schedule = $ds->fetchAll();

    // Формируем ближайшие дни доставки. Сессия теперь техническая и постоянная,
    // поэтому нельзя перебирать весь диапазон 2000-2099.
    $dayNames = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];
    $deliveryDays = [];

    $today = new DateTime(roGetTodayMinsk());
    $weekStart = (clone $today)->modify('-14 days');
    $weekEnd = (clone $today)->modify('+45 days');

    foreach ($schedule as $sch) {
        $dow = (int)$sch['day_of_week'];
        // Находим первую дату этого дня недели в рамках сессии
        $date = clone $weekStart;
        $currentDow = (int)$date->format('N'); // 1=Mon
        $diff = $dow - $currentDow;
        if ($diff < 0) $diff += 7;
        $date->modify("+{$diff} days");

        // Перебираем все вхождения этого дня недели в рамках сессии
        while ($date <= $weekEnd) {
            $dateStr = $date->format('Y-m-d');

            // Проверяем есть ли уже заказ
            $os = $pdo->prepare("
                SELECT id, status, submitted_at
                FROM ro_orders
                WHERE restaurant_number = ?
                  AND delivery_date = ?
                  AND legal_entity_group = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $os->execute([$rest['restaurant_number'], $dateStr, $group]);
            $order = $os->fetch();

            $dateOpen = roIsDateOpen($pdo, $session['id'], $dateStr);
            $todayStr = roGetTodayMinsk();

            // Показываем дату если: приём открыт ИЛИ уже есть заказ ИЛИ дата сегодня/в будущем
            // (даже если приём закрыт — ресторан должен видеть свой график)
            if ($dateOpen || $order || $dateStr >= $todayStr) {
                $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $dateStr);

                $deliveryDays[] = [
                    'date' => $dateStr,
                    'day_of_week' => $dow,
                    'day_name' => $dayNames[$dow] ?? '',
                    'delivery_time' => $sch['delivery_time'],
                    'deadline_status' => $deadlineStatus['status'],
                    'deadlines' => $deadlineStatus['deadlines'],
                    'can_edit' => roCanEdit($pdo, $session['id'], $dateStr),
                    'order' => $order ? [
                        'id' => (int)$order['id'],
                        'status' => $order['status'],
                        'submitted_at' => $order['submitted_at'],
                    ] : null,
                ];
            }

            $date->modify('+7 days');
        }
    }

    // Сортируем по дате
    usort($deliveryDays, function($a, $b) { return strcmp($a['date'], $b['date']); });

    roRespond([
        'restaurant_orders_enabled' => true,
        'server_time' => time() * 1000, // мс UTC, фронт считает дельту против часов устройства
        'session' => [
            'id' => (int)$session['id'],
            'week_start' => $session['week_start'],
            'week_end' => $session['week_end'],
            'status' => $session['status'],
        ],
        'delivery_days' => $deliveryDays,
        'account' => $accountInfo,
    ]);
}

// ════════════════════════════════════════════════════════════════════
// Email учётной записи ресторана: сохранение и подтверждение по ссылке.
// Логин по-прежнему по номеру + паролю. Email — для уведомлений и
// будущего сброса пароля через email (этап B).
// ════════════════════════════════════════════════════════════════════

if ($roAction === 'set-email' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        roRespond(['error' => 'Введите корректный email'], 400);
    }
    $email = mb_strtolower($email);
    if (mb_strlen($email) > 255) {
        roRespond(['error' => 'Слишком длинный email'], 400);
    }

    $userId = (int)$rest['id'];
    $currentEmail = $rest['email'] ?? null;
    $alreadyVerified = !empty($rest['email_verified_at']);

    // Если уже подтверждён и адрес тот же — ничего не делаем.
    if ($alreadyVerified && $currentEmail === $email) {
        roRespond(['success' => true, 'already_verified' => true]);
    }

    // Сохраняем email. При смене email сбрасываем verified.
    if ($currentEmail !== $email) {
        $upd = $pdo->prepare("UPDATE ro_users SET email = ?, email_verified_at = NULL WHERE id = ?");
        $upd->execute([$email, $userId]);
    }

    // Чистим старые неиспользованные токены этого юзера, чтобы письма не плодились.
    $pdo->prepare("UPDATE ro_email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
        ->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $pdo->prepare("INSERT INTO ro_email_verification_tokens (user_id, email, token, expires_at, ip_address) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)")
        ->execute([$userId, $email, $token, $clientIp]);

    require_once __DIR__ . '/mail_send.php';
    require_once __DIR__ . '/mail_templates.php';

    $siteUrl   = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
    $verifyUrl = $siteUrl . '/restaurant/verify-email?token=' . $token;
    $restNum   = function_exists('formatRestaurantNumber')
        ? formatRestaurantNumber($rest['restaurant_number'])
        : (string)$rest['restaurant_number'];

    $bodyHtml = '<p style="margin:0 0 12px;">Для ресторана №<strong>' . htmlspecialchars($restNum, ENT_QUOTES, 'UTF-8') . '</strong> в кабинете Supply Department был указан этот email.</p>'
              . '<p style="margin:0;">Подтвердите адрес, чтобы можно было восстанавливать пароль кабинета по email. Ссылка действительна <strong>24 часа</strong>.</p>';

    $html = renderMailHtml([
        'title'   => 'Подтвердите email',
        'preview' => 'Подтвердите email для ресторана №' . $restNum,
        'intro'   => 'Здравствуйте!',
        'body'    => $bodyHtml,
        'cta'     => ['text' => 'Подтвердить email', 'url' => $verifyUrl],
        'footer'  => 'Если вы не указывали этот email в кабинете ресторана — просто проигнорируйте письмо.',
    ]);

    $sendResult = sendEmail($email, 'Подтверждение email — Supply Department', $html, true);
    if (!$sendResult['success']) {
        error_log('[ro_set_email] mail failed: ' . ($sendResult['error'] ?? 'unknown'));
        // Сам email сохранён, токен есть — пользователь увидит «письмо отправлено».
        // Если SMTP реально сломан — он сможет нажать «отправить снова» в модалке.
    }

    roRespond(['success' => true, 'sent_to' => $email]);
}

if ($roAction === 'verify-email' && $method === 'POST') {
    $token = trim((string)($body['token'] ?? ''));
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }

    $stmt = $pdo->prepare("SELECT id, user_id, email, used_at, (expires_at < NOW()) AS is_expired FROM ro_email_verification_tokens WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }
    if ($row['used_at'] !== null) {
        roRespond(['valid' => false, 'reason' => 'used']);
    }
    if ((int)$row['is_expired'] === 1) {
        roRespond(['valid' => false, 'reason' => 'expired']);
    }

    // Подтверждаем — но только если email пользователя совпадает с email в токене.
    // Если ресторан успел сменить email после отправки этой ссылки — старая ссылка не сработает.
    $check = $pdo->prepare("SELECT email FROM ro_users WHERE id = ? LIMIT 1");
    $check->execute([(int)$row['user_id']]);
    $current = $check->fetchColumn();
    if ($current !== $row['email']) {
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ro_users SET email_verified_at = NOW() WHERE id = ?")->execute([(int)$row['user_id']]);
        $pdo->prepare("UPDATE ro_email_verification_tokens SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ro_verify_email] failed: ' . $e->getMessage());
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }

    roRespond(['valid' => true, 'email' => $row['email']]);
}

// ════════════════════════════════════════════════════════════════════
// Сброс пароля ресторана по email (этап B).
// Работает только для ro_users с email_verified_at IS NOT NULL.
// Telegram-сброс остаётся рядом как fallback.
// ════════════════════════════════════════════════════════════════════

if ($roAction === 'request-password-reset-by-email' && $method === 'POST') {
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        roRespond(['error' => 'Введите корректный email'], 400);
    }
    $email = mb_strtolower($email);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

    // Тихий троттлинг — не палим лимиты.
    //   - не более 5 запросов с одного IP за 10 минут;
    //   - не более 1 запроса на email за 60 секунд.
    try {
        $ipStmt = $pdo->prepare("SELECT COUNT(*) FROM ro_password_reset_logs WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
        $ipStmt->execute([$clientIp]);
        $ipCount = (int)$ipStmt->fetchColumn();

        $emStmt = $pdo->prepare("SELECT COUNT(*) FROM ro_password_reset_logs WHERE email = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
        $emStmt->execute([$email]);
        $emCount = (int)$emStmt->fetchColumn();

        if ($ipCount >= 5 || $emCount >= 1) {
            $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'rate_limited')")
                ->execute([$email, $clientIp]);
            roRespond(['success' => true]);
        }
    } catch (Throwable $e) {}

    // Ищем учётку с подтверждённым email. Если нет/не подтверждена — всё равно success.
    $userStmt = $pdo->prepare("SELECT id, restaurant_number, legal_entity_group, email, email_verified_at FROM ro_users WHERE email = ? AND is_active = 1 LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if (!$user) {
        try {
            $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'not_found')")
                ->execute([$email, $clientIp]);
        } catch (Throwable $e) {}
        roRespond(['success' => true]);
    }
    if (empty($user['email_verified_at'])) {
        try {
            $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'unverified')")
                ->execute([$email, $clientIp]);
        } catch (Throwable $e) {}
        // Не палим, что email есть, но не подтверждён.
        roRespond(['success' => true]);
    }

    $token = bin2hex(random_bytes(32));
    $userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        $pdo->prepare("INSERT INTO ro_password_reset_tokens (user_id, email, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?, ?)")
            ->execute([(int)$user['id'], $email, $token, $clientIp, $userAgent]);
    } catch (Throwable $e) {
        error_log('[ro_pwd_reset] insert token failed: ' . $e->getMessage());
        roRespond(['success' => true]);
    }

    require_once __DIR__ . '/mail_send.php';
    require_once __DIR__ . '/mail_templates.php';

    $siteUrl  = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
    $resetUrl = $siteUrl . '/restaurant/reset-password-by-email?token=' . $token;
    $restNum  = function_exists('formatRestaurantNumber')
        ? formatRestaurantNumber($user['restaurant_number'])
        : (string)$user['restaurant_number'];

    $bodyHtml = '<p style="margin:0 0 12px;">Был получен запрос на сброс пароля кабинета ресторана №<strong>' . htmlspecialchars($restNum, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
              . '<p style="margin:0;">Нажмите кнопку ниже, чтобы задать новый пароль. Ссылка действительна <strong>30 минут</strong>.</p>';

    $html = renderMailHtml([
        'title'   => 'Сброс пароля',
        'preview' => 'Ссылка для сброса пароля действительна 30 минут',
        'intro'   => 'Здравствуйте!',
        'body'    => $bodyHtml,
        'cta'     => ['text' => 'Сбросить пароль', 'url' => $resetUrl],
        'footer'  => 'Если вы не запрашивали сброс пароля — просто проигнорируйте это письмо, ваш текущий пароль останется без изменений.',
    ]);

    $sendResult = sendEmail($email, 'Сброс пароля кабинета ресторана — Supply Department', $html, true);

    try {
        $logRes = $sendResult['success'] ? 'sent' : 'not_found';
        $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, ?)")
            ->execute([$email, $clientIp, $logRes]);
    } catch (Throwable $e) {}

    if (!$sendResult['success']) {
        error_log('[ro_pwd_reset] email send failed: ' . ($sendResult['error'] ?? 'unknown'));
    }
    roRespond(['success' => true]);
}

if ($roAction === 'verify-password-reset-by-email' && $method === 'POST') {
    $token = trim((string)($body['token'] ?? ''));
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }
    $stmt = $pdo->prepare("SELECT t.id, t.user_id, t.email, t.used_at, (t.expires_at < NOW()) AS is_expired, ru.restaurant_number, ru.legal_entity_group FROM ro_password_reset_tokens t JOIN ro_users ru ON ru.id = t.user_id WHERE t.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        roRespond(['valid' => false, 'reason' => 'invalid']);
    }
    if ($row['used_at'] !== null) {
        roRespond(['valid' => false, 'reason' => 'used']);
    }
    if ((int)$row['is_expired'] === 1) {
        roRespond(['valid' => false, 'reason' => 'expired']);
    }

    $restNum = function_exists('formatRestaurantNumber')
        ? formatRestaurantNumber($row['restaurant_number'])
        : (string)$row['restaurant_number'];

    // Маскированный email — чтобы пользователь убедился, что сбрасывает свой пароль.
    $parts = explode('@', $row['email']);
    $local = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $maskedLocal = mb_strlen($local) <= 2 ? $local : mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 2)) . mb_substr($local, -1);

    roRespond([
        'valid' => true,
        'restaurant_label' => '№' . $restNum,
        'email' => $maskedLocal . '@' . $domain,
    ]);
}

if ($roAction === 'reset-password-by-email' && $method === 'POST') {
    $token = trim((string)($body['token'] ?? ''));
    $newPassword = (string)($body['new_password'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        roRespond(['error' => 'Неверный токен'], 400);
    }
    if (mb_strlen($newPassword) < 8) {
        roRespond(['error' => 'Пароль должен быть не менее 8 символов'], 400);
    }

    $stmt = $pdo->prepare("SELECT t.id, t.user_id, t.email, t.used_at, (t.expires_at < NOW()) AS is_expired, ru.restaurant_number, ru.legal_entity_group FROM ro_password_reset_tokens t JOIN ro_users ru ON ru.id = t.user_id WHERE t.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        try { $pdo->prepare("INSERT INTO ro_password_reset_logs (ip_address, result) VALUES (?, 'token_invalid')")->execute([$clientIp]); } catch (Throwable $e) {}
        roRespond(['error' => 'Ссылка недействительна'], 400);
    }
    if ($row['used_at'] !== null) {
        try { $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'token_used')")->execute([$row['email'], $clientIp]); } catch (Throwable $e) {}
        roRespond(['error' => 'Ссылка уже была использована'], 400);
    }
    if ((int)$row['is_expired'] === 1) {
        try { $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'token_expired')")->execute([$row['email'], $clientIp]); } catch (Throwable $e) {}
        roRespond(['error' => 'Срок действия ссылки истёк. Запросите новую.'], 400);
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE ro_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?")
            ->execute([$hash, (int)$row['user_id']]);
        $pdo->prepare("UPDATE ro_password_reset_tokens SET used_at = NOW() WHERE id = ?")
            ->execute([(int)$row['id']]);
        // Инвалидируем остальные неиспользованные токены этого юзера.
        $pdo->prepare("UPDATE ro_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([(int)$row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ro_pwd_reset] reset failed: ' . $e->getMessage());
        roRespond(['error' => 'Не удалось сменить пароль, попробуйте ещё раз'], 500);
    }

    // Гасим все активные сессии ресторана — после сброса все устройства должны перелогиниться.
    try {
        roRevokeAllSessionsForRestaurant($pdo, (int)$row['restaurant_number'], (string)$row['legal_entity_group']);
    } catch (Throwable $e) {}

    try {
        $pdo->prepare("INSERT INTO ro_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'reset_ok')")
            ->execute([$row['email'], $clientIp]);
    } catch (Throwable $e) {}

    roRespond(['success' => true]);
}

// --- Товары для формы ---
if ($roAction === 'products' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    roRequireRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $rest['legal_entity_group'] ?? 'BK_VM');

    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $le = $rest['legal_entity'];

    $products = [];

    if ($search) {
        // Поиск по всем товарам
        $like = "%{$search}%";
        $s = $pdo->prepare("SELECT sku, name, category, qty_per_box, multiplicity FROM products WHERE legal_entity = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 50");
        $s->execute([$le, $like, $like]);
        $products = $s->fetchAll();
    } else {
        // Сначала из шаблона
        $tplQuery = "SELECT t.sku, t.product_name as name, t.category, t.sort_order, p.qty_per_box,
                COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) AS multiplicity
            FROM ro_templates t
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ? AND p.is_active = 1
            WHERE t.legal_entity = ? AND t.is_active = 1";
        $params = [$le, $le];
        if ($category) {
            $tplQuery .= " AND t.category = ?";
            $params[] = $category;
        }
        $tplQuery .= " ORDER BY t.sort_order, t.product_name";
        $s = $pdo->prepare($tplQuery);
        $s->execute($params);
        $products = $s->fetchAll();

        // Если шаблон пуст — берём из stock_malling (уникальные товары)
        if (empty($products)) {
            $smQuery = "SELECT DISTINCT p.name, p.sku, p.category, p.qty_per_box, p.multiplicity
                FROM stock_malling sm
                JOIN products p ON p.legal_entity = ? AND p.is_active = 1
                  AND (sm.product_name LIKE CONCAT(p.sku, ' %') OR p.name = sm.product_name OR p.sku = sm.product_name)
                WHERE 1=1";
            $params = [$le];
            if ($category) {
                $smQuery .= " AND p.category = ?";
                $params[] = $category;
            }
            $smQuery .= " ORDER BY p.category, p.name LIMIT 500";
            $s = $pdo->prepare($smQuery);
            $s->execute($params);
            $products = $s->fetchAll();
        }
    }

    roRespond(['products' => $products]);
}

// --- Сканер товаров (BETA): поиск товара по GTIN + аналоги + остаток склада ---
if ($roAction === 'scan-product' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $gtin = trim((string)($_GET['gtin'] ?? ''));
    if ($gtin === '') roRespond(['error' => 'Не указан штрихкод'], 400);

    $le = $rest['legal_entity'];
    $group = getEntityGroup($le);

    // Ищем товар по GTIN среди всех юрлиц группы (товары — справочник, общий для группы)
    $where = ["p.gtin = ?", "p.is_active = 1"];
    $params = [$gtin];
    applyEntityTextFilter($group, $where, $params, 'p.legal_entity');
    $sql = "SELECT p.sku, p.name, p.gtin, p.unit_of_measure, p.qty_per_box, p.multiplicity, p.category,
                   p.analog_group, p.legal_entity, p.supplier
            FROM products p
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (p.legal_entity = ?) DESC, p.name
            LIMIT 5";
    $params[] = $le;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $found = $s->fetchAll();

    if (empty($found)) {
        roRespond(['found' => false, 'gtin' => $gtin]);
    }

    // Берём первый (приоритет — юрлицо ресторана)
    $main = $found[0];
    $multipleMatches = count($found) > 1 ? array_slice($found, 1) : [];

    // Остаток склада для основного товара
    $stockMain = roGetStockForSku($pdo, $main['sku'], $main['legal_entity']);

    // Аналоги: все товары той же analog_group (включая основной), фильтр по группе юрлиц.
    // Показываем и активные, и снятые с ассортимента (с пометкой is_active=0).
    $analogs = [];
    if (!empty($main['analog_group'])) {
        $aWhere = ["p.analog_group = ?"];
        $aParams = [$main['analog_group']];
        applyEntityTextFilter($group, $aWhere, $aParams, 'p.legal_entity');
        $aSql = "SELECT p.sku, p.name, p.gtin, p.unit_of_measure, p.qty_per_box, p.multiplicity, p.legal_entity, p.supplier, p.is_active
                 FROM products p
                 WHERE " . implode(' AND ', $aWhere) . "
                 ORDER BY (p.sku = ?) DESC, p.is_active DESC, p.name
                 LIMIT 30";
        $aParams[] = $main['sku'];
        $a = $pdo->prepare($aSql);
        $a->execute($aParams);
        foreach ($a->fetchAll() as $row) {
            $row['stock_warehouse'] = roGetStockForSku($pdo, $row['sku'], $row['legal_entity']);
            $row['is_main'] = ($row['sku'] === $main['sku']) ? 1 : 0;
            $row['is_active'] = (int)$row['is_active'];
            $row['multiplicity'] = (int)$row['multiplicity'];
            $analogs[] = $row;
        }
    }

    roRespond([
        'found' => true,
        'product' => [
            'sku' => $main['sku'],
            'name' => $main['name'],
            'gtin' => $main['gtin'],
            'unit_of_measure' => $main['unit_of_measure'],
            'qty_per_box' => $main['qty_per_box'],
            'multiplicity' => (int)$main['multiplicity'],
            'category' => $main['category'],
            'analog_group' => $main['analog_group'],
            'supplier' => $main['supplier'],
            'legal_entity' => $main['legal_entity'],
            'stock_warehouse' => $stockMain,
        ],
        'analogs' => $analogs,
        'multiple_matches' => array_map(function($r) {
            return ['sku' => $r['sku'], 'name' => $r['name'], 'legal_entity' => $r['legal_entity']];
        }, $multipleMatches),
    ]);
}

// --- Сканер: сообщить о ненайденном штрихкоде (BETA) ---
if ($roAction === 'report-missing-gtin' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    // Поддерживаем оба формата: JSON и multipart/form-data (с фото)
    $isMultipart = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false);
    $src = $isMultipart ? $_POST : ($body ?: []);

    $gtin = trim((string)($src['gtin'] ?? ''));
    if ($gtin === '') roRespond(['error' => 'Не указан штрихкод'], 400);
    if (strlen($gtin) > 64) roRespond(['error' => 'Слишком длинный штрихкод'], 400);

    $reporterName = trim((string)($src['name'] ?? ''));
    if (strlen($reporterName) > 500) $reporterName = substr($reporterName, 0, 500);

    $reporterComment = trim((string)($src['comment'] ?? ''));
    if (strlen($reporterComment) > 1000) $reporterComment = substr($reporterComment, 0, 1000);

    $restNumber = (int)$rest['restaurant_number'];
    $group = $rest['legal_entity_group'] ?: 'BK_VM';

    // Обработка загруженного фото (опционально)
    $photoRelPath = null; // относительный путь, что сохраним в БД
    $photoAbsPath = null; // абсолютный путь для sendPhoto
    if ($isMultipart && !empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $maxSize = 6 * 1024 * 1024; // 6 МБ
        if ($file['size'] > $maxSize) {
            roRespond(['error' => 'Фото слишком большое (максимум 6 МБ)'], 400);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            roRespond(['error' => 'Допустимы только JPEG, PNG или WebP'], 400);
        }
        $ext = $allowed[$mime];
        $subdir = date('Y/m');
        $uploadBase = __DIR__ . '/../uploads/scan_unknown';
        $dir = $uploadBase . '/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            error_log('[ro/report-missing-gtin] Не удалось создать папку: ' . $dir);
            roRespond(['error' => 'Не удалось сохранить фото'], 500);
        }
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        $absPath = $dir . '/' . $fname;
        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            roRespond(['error' => 'Не удалось сохранить фото'], 500);
        }
        @chmod($absPath, 0644);
        $photoRelPath = $subdir . '/' . $fname;
        $photoAbsPath = $absPath;
    }

    // 1) Сохраняем в БД (UPSERT по gtin+restaurant_number)
    // Новые поля (reporter_name/comment/photo) перезаписываем при каждом рапорте.
    $dbOk = false;
    try {
        $s = $pdo->prepare("
            INSERT INTO ro_scan_unknown
                (gtin, restaurant_number, legal_entity_group, reporter_name, reporter_comment, reporter_photo_path)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                seen_count = seen_count + 1,
                last_seen = CURRENT_TIMESTAMP,
                status = 'new',
                reporter_name = COALESCE(NULLIF(VALUES(reporter_name), ''), reporter_name),
                reporter_comment = COALESCE(NULLIF(VALUES(reporter_comment), ''), reporter_comment),
                reporter_photo_path = COALESCE(VALUES(reporter_photo_path), reporter_photo_path)
        ");
        $s->execute([
            $gtin,
            $restNumber,
            $group,
            $reporterName !== '' ? $reporterName : null,
            $reporterComment !== '' ? $reporterComment : null,
            $photoRelPath,
        ]);
        $dbOk = true;
    } catch (Exception $e) {
        error_log('[ro/report-missing-gtin] DB error: ' . $e->getMessage());
    }

    // 2) Telegram-уведомление подписчикам из ro_scan_unknown_subscribers
    // Если таблица пустая — никому не шлём.
    $tgSent = 0; $tgTotal = 0;
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if ($botToken) {
        try {
            $a = $pdo->query("
                SELECT u.telegram_chat_id
                FROM ro_scan_unknown_subscribers s
                JOIN users u ON u.name = s.user_name
                WHERE u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != ''
            ");
            $chatIds = $a->fetchAll(PDO::FETCH_COLUMN);
            $tgTotal = count($chatIds);
            $restLabel = formatRestaurantNumber($restNumber, $group);

            // Базовый URL для ссылки в уведомлении
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $appUrl = $_ENV['APP_PUBLIC_URL'] ?? getenv('APP_PUBLIC_URL') ?: '';
            if (!$appUrl && $host) $appUrl = "{$scheme}://{$host}";
            $link = $appUrl ? rtrim($appUrl, '/') . '/restaurant-unknown-barcodes' : '';

            $text = "🔎 <b>Сканер: товар не найден</b>\n"
                  . "Штрихкод: <code>" . htmlspecialchars($gtin, ENT_QUOTES, 'UTF-8') . "</code>\n"
                  . "Ресторан: {$restLabel} ({$group})";
            if ($reporterName !== '') {
                $text .= "\nНазвание: <b>" . htmlspecialchars($reporterName, ENT_QUOTES, 'UTF-8') . "</b>";
            }
            if ($reporterComment !== '') {
                $text .= "\nКомментарий: " . htmlspecialchars($reporterComment, ENT_QUOTES, 'UTF-8');
            }
            if ($link) {
                $text .= "\n🔗 <a href=\"" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "\">Разобрать</a>";
            }
            foreach ($chatIds as $chatId) {
                // Если есть фото — шлём sendPhoto с caption, иначе sendMessage
                if ($photoAbsPath && is_file($photoAbsPath)) {
                    $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";
                    $cfile = new CURLFile($photoAbsPath);
                    $payload = [
                        'chat_id' => $chatId,
                        'photo' => $cfile,
                        'caption' => $text,
                        'parse_mode' => 'HTML',
                    ];
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                } else {
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
                }
                $resp = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp !== false && $http === 200) $tgSent++;
            }
        } catch (Exception $e) {
            error_log('[ro/report-missing-gtin] TG error: ' . $e->getMessage());
        }
    }

    // Считаем успехом, если хоть один канал сработал
    if (!$dbOk && $tgSent === 0) {
        roRespond(['error' => 'Не удалось сохранить сообщение'], 500);
    }
    roRespond([
        'success' => true,
        'db_saved' => $dbOk,
        'telegram_sent' => $tgSent,
        'telegram_total' => $tgTotal,
    ]);
}

// --- Мой заказ на дату ---
if ($roAction === 'my-order' && $method === 'GET' && $roParam) {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    roRequireRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $rest['legal_entity_group'] ?? 'BK_VM');

    $date = $roParam;
    $session = roGetActiveSession($pdo, $rest['legal_entity_group'] ?? 'BK_VM');
    if (!$session) roRespond(['order' => null]);

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $s = $pdo->prepare("
        SELECT id, status, submitted_at, updated_at, updated_by, comment
        FROM ro_orders
        WHERE restaurant_number = ?
          AND delivery_date = ?
          AND legal_entity_group = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $s->execute([$rest['restaurant_number'], $date, $group]);
    $order = $s->fetch();

    if (!$order) roRespond(['order' => null]);

    $items = $pdo->prepare("
        SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
               COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) AS multiplicity
        FROM ro_order_items oi
        LEFT JOIN ro_templates t
            ON t.legal_entity = ?
           AND t.category = oi.category
           AND t.sku = oi.sku
           AND t.is_active = 1
        LEFT JOIN products p ON p.id = (
            SELECT p2.id
            FROM products p2
            WHERE p2.sku = oi.sku AND p2.legal_entity = ?
            ORDER BY p2.is_active DESC, p2.id ASC
            LIMIT 1
        )
        WHERE oi.order_id = ?
        ORDER BY oi.category, oi.product_name
    ");
    $items->execute([$rest['legal_entity'], $rest['legal_entity'], $order['id']]);

    roRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'updated_at' => $order['updated_at'],
            'updated_by' => $order['updated_by'],
            'comment' => $order['comment'] ?? null,
            'items' => $items->fetchAll(),
        ],
    ]);
}

// --- Мои заказы (история) ---
if ($roAction === 'my-orders' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    roRequireRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $rest['legal_entity_group'] ?? 'BK_VM');

    $limit = min((int)($_GET['limit'] ?? 20), 50);
    // Один ресторан = одно юрлицо. История показывается только по своему юрлицу
    // (как в all-history ниже).
    $restEntity = $rest['legal_entity'] ?? '';
    if (!$restEntity) roRespond(['error' => 'У ресторана не задано юр. лицо'], 400);
    $s = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.updated_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
        FROM ro_orders o
        WHERE o.restaurant_number = ? AND o.legal_entity = ?
        ORDER BY o.delivery_date DESC
        LIMIT {$limit}
    ");
    $s->execute([$rest['restaurant_number'], $restEntity]);
    roRespond(['orders' => $s->fetchAll()]);
}

// --- Объединённая история (все источники) ---
if ($roAction === 'all-history' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $rn = $rest['restaurant_number'];
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    // Один ресторан = одно юрлицо. История показывается только по своему юрлицу.
    $restEntity = $rest['legal_entity'] ?? '';
    if (!$restEntity) roRespond(['error' => 'У ресторана не задано юр. лицо'], 400);
    $limit = min((int)($_GET['limit'] ?? 30), 100);

    // Курсор: подгружаем заказы старше указанной даты (для «Загрузить ещё»)
    $beforeDate = trim((string)($_GET['before_date'] ?? ''));
    $hasBefore = ($beforeDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate));
    $allOrders = [];

    // 1. Основная поставка (ro_orders) — показываем в истории только если модуль включён
    if (roRestaurantOrdersEnabled($pdo, $restEntity, $group)) {
        $sql1 = "
            SELECT o.id, o.delivery_date, o.status, o.submitted_at,
                   (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty,
                   (SELECT SUM(oi.quantity * COALESCE(pp.price, 0))
                      FROM ro_order_items oi
                      LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'deposit'
                      WHERE oi.order_id = o.id) as total_deposit
            FROM ro_orders o WHERE o.restaurant_number = ? AND o.legal_entity = ?
        ";
        $params1 = [$rn, $restEntity];
        if ($hasBefore) { $sql1 .= " AND o.delivery_date < ?"; $params1[] = $beforeDate; }
        $sql1 .= " ORDER BY o.delivery_date DESC LIMIT {$limit}";
        $s1 = $pdo->prepare($sql1);
        $s1->execute($params1);
        foreach ($s1->fetchAll() as $r) {
            $r['source'] = 'delivery';
            $r['source_name'] = 'Основная поставка';
            $allOrders[] = $r;
        }
    }

    // 2. Заявки поставщикам (so_orders)
    $sql2 = "
        SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.supplier_id,
               s.short_name as supplier_name,
               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
        FROM so_orders o
        LEFT JOIN suppliers s ON s.id = o.supplier_id
        WHERE o.restaurant_number = ? AND o.legal_entity = ?
    ";
    $params2 = [$rn, $restEntity];
    if ($hasBefore) { $sql2 .= " AND o.delivery_date < ?"; $params2[] = $beforeDate; }
    $sql2 .= " ORDER BY o.delivery_date DESC LIMIT {$limit}";
    $s2 = $pdo->prepare($sql2);
    $s2->execute($params2);
    foreach ($s2->fetchAll() as $r) {
        $r['source'] = 'supplier';
        $r['source_name'] = $r['supplier_name'] ?: 'Поставщик';
        unset($r['supplier_name']);
        $allOrders[] = $r;
    }

    // Сортировка по дате доставки
    usort($allOrders, function($a, $b) {
        return strcmp($b['delivery_date'], $a['delivery_date']);
    });
    // has_more = после среза остались хвосты хотя бы в одной из таблиц
    $hasMore = (count($allOrders) > $limit);
    $allOrders = array_slice($allOrders, 0, $limit);
    roRespond(['orders' => $allOrders, 'has_more' => $hasMore]);
}

// --- Детали заказа из истории ресторана ---
if ($roAction === 'history-order' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $source = trim((string)($_GET['source'] ?? ''));
    $id = trim((string)($_GET['id'] ?? ''));
    if ($source === '' || $id === '') roRespond(['error' => 'Не указан заказ'], 400);

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    // Один ресторан = одно юрлицо. Доступ только к заказам своего юрлица.
    $restEntity = $rest['legal_entity'] ?? '';
    if (!$restEntity) roRespond(['error' => 'У ресторана не задано юр. лицо'], 400);

    if ($source === 'delivery') {
        roRequireRestaurantOrdersEnabled($pdo, $restEntity, $group);
        $s = $pdo->prepare("
            SELECT id, delivery_date, status, submitted_at, updated_at, updated_by, comment, legal_entity
            FROM ro_orders
            WHERE id = ? AND restaurant_number = ? AND legal_entity = ?
            LIMIT 1
        ");
        $s->execute([(int)$id, $rest['restaurant_number'], $restEntity]);
        $order = $s->fetch();
        if (!$order) roRespond(['error' => 'Заказ не найден'], 404);

        $items = $pdo->prepare("
            SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment
            FROM ro_order_items oi
            WHERE oi.order_id = ?
            ORDER BY oi.category, oi.product_name
        ");
        $items->execute([$order['id']]);

        roRespond(['order' => [
            'id' => (int)$order['id'],
            'source' => 'delivery',
            'source_name' => 'Основная поставка',
            'delivery_date' => $order['delivery_date'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'updated_at' => $order['updated_at'],
            'updated_by' => $order['updated_by'],
            'comment' => $order['comment'] ?? null,
            'items' => $items->fetchAll(),
        ]]);
    }

    if ($source === 'supplier') {
        $s = $pdo->prepare("
            SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.updated_at, o.supplier_id,
                   s.short_name AS supplier_name
            FROM so_orders o
            LEFT JOIN suppliers s ON s.id = o.supplier_id
            WHERE o.id = ? AND o.restaurant_number = ? AND o.legal_entity = ?
            LIMIT 1
        ");
        $s->execute([(int)$id, $rest['restaurant_number'], $restEntity]);
        $order = $s->fetch();
        if (!$order) roRespond(['error' => 'Заказ не найден'], 404);

        $items = $pdo->prepare("
            SELECT sku, product_name, quantity,
                   admin_qty,
                   COALESCE(admin_qty, quantity) AS effective_qty
            FROM so_order_items
            WHERE order_id = ? AND COALESCE(admin_qty, quantity) > 0
            ORDER BY product_name
        ");
        $items->execute([$order['id']]);

        roRespond(['order' => [
            'id' => (int)$order['id'],
            'source' => 'supplier',
            'source_name' => $order['supplier_name'] ?: 'Поставщик',
            'delivery_date' => $order['delivery_date'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'updated_at' => $order['updated_at'],
            'updated_by' => null,
            'comment' => null,
            'items' => $items->fetchAll(),
        ]]);
    }

    roRespond(['error' => 'Неизвестный источник заказа'], 400);
}

// --- Отправка заказа ---
if ($roAction === 'submit-order' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    roRequireRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $rest['legal_entity_group'] ?? 'BK_VM');

    $deliveryDate = $body['delivery_date'] ?? '';
    $items = $body['items'] ?? [];
    $comment = $body['comment'] ?? null;

    if (!$deliveryDate) roRespond(['error' => 'Не указана дата доставки'], 400);
    if (empty($items)) roRespond(['error' => 'Заказ пуст'], 400);

    $session = roGetActiveSession($pdo, $rest['legal_entity_group'] ?? 'BK_VM');
    if (!$session) roRespond(['error' => 'Нет активной сессии приёма заявок'], 400);

    if (!roRestaurantHasDeliveryDate($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM', $deliveryDate)) {
        roRespond(['error' => 'На эту дату у ресторана не запланирована поставка'], 400);
    }

    // Проверяем дедлайн
    $dlStatus = roGetDeadlineStatus($pdo, $session['id'], $deliveryDate);
    if ($dlStatus['status'] === 'closed' || $dlStatus['status'] === 'not_open') {
        roRespond(['error' => 'Приём заявок на эту дату закрыт'], 403);
    }

    $aggregated = roAggregateOrderItems($items);
    if (empty($aggregated)) roRespond(['error' => 'Заказ пуст'], 400);
    roRespondMultiplicityError(roFindMultiplicityViolations($pdo, $rest['legal_entity'], $aggregated));

    // Проверяем: есть ли уже заказ?
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $existingOrder = $pdo->prepare("
        SELECT id, status, submitted_at
        FROM ro_orders
        WHERE restaurant_number = ?
          AND delivery_date = ?
          AND legal_entity_group = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $existingOrder->execute([$rest['restaurant_number'], $deliveryDate, $group]);
    $existing = $existingOrder->fetch();

    // Запоминаем старые позиции для diff в журнале
    $oldItemsAudit = [];
    if ($existing) {
        $oldSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
        $oldSt->execute([$existing['id']]);
        foreach ($oldSt->fetchAll() as $oi) {
            $oldItemsAudit[$oi['sku']] = ['name' => $oi['product_name'], 'qty' => floatval($oi['quantity'])];
        }
    }

    $pdo->beginTransaction();
    try {
        if ($existing) {
            // Обновляем — но проверяем, можно ли ещё редактировать
            if (!roCanEdit($pdo, $session['id'], $deliveryDate)) {
                $pdo->rollBack();
                roRespond(['error' => 'Время редактирования заказа истекло. Обратитесь в отдел закупок'], 403);
            }

            $orderId = $existing['id'];
            // Удаляем старые позиции
            $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
            // Обновляем статус и комментарий
            $pdo->prepare("UPDATE ro_orders SET status = 'submitted', updated_at = NOW(), updated_by = ?, comment = ? WHERE id = ?")
                ->execute(["Ресторан {$rest['restaurant_number']}", $comment, $orderId]);
        } else {
            // Создаём новый заказ. legal_entity всегда вычисляем заново через
            // roGetLegalEntity — это единый источник истины (в т.ч. ресторан 3 = Воглия Матта).
            // Не опираемся на ro_users.legal_entity: там могут быть старые/некорректные значения.
            $le = roGetLegalEntity($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? null);
            $grp = $rest['legal_entity_group'] ?? 'BK_VM';
            $pdo->prepare("INSERT INTO ro_orders (session_id, restaurant_number, delivery_date, status, submitted_at, updated_by, legal_entity, legal_entity_group, comment) VALUES (?, ?, ?, 'submitted', NOW(), ?, ?, ?, ?)")
                ->execute([$session['id'], $rest['restaurant_number'], $deliveryDate, "Ресторан {$rest['restaurant_number']}", $le, $grp, $comment]);
            $orderId = $pdo->lastInsertId();
        }

        // Вставляем позиции (UNIQUE KEY на order_id+sku гарантирует отсутствие дублей)
        $insertItem = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
        $totalQty = 0;
        $totalItems = 0;
        foreach ($aggregated as $item) {
            $insertItem->execute([
                $orderId,
                $item['sku'],
                $item['product_name'],
                $item['category'],
                $item['quantity'],
                $item['comment'],
            ]);
            $totalQty += $item['quantity'];
            $totalItems++;
        }

        // ═══ Журнал: создание/обновление заявки рестораном (внутри транзакции) ═══
        // Аудит пишется до commit(), чтобы данные заказа и записи журнала
        // фиксировались атомарно. Если процесс упадёт между commit и аудитом,
        // журнал теперь не потеряется.
        $newItemsAudit = [];
        foreach ($aggregated as $sku => $it) {
            $newItemsAudit[$sku] = ['name' => $it['product_name'] ?? '', 'qty' => floatval($it['quantity'] ?? 0)];
        }
        $actorNameRo = "Ресторан {$rest['restaurant_number']}";
        if (!$existing) {
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $rest['restaurant_number'],
                'delivery_date' => $deliveryDate,
                'action' => 'order_created',
                'actor_name' => $actorNameRo,
                'actor_type' => 'restaurant',
                'new_value' => $totalItems . ' поз. / ' . $totalQty . ' кор.',
                'details' => [
                    'total_items' => $totalItems,
                    'total_qty' => $totalQty,
                    'comment' => $comment,
                ],
            ]);
        } else {
            foreach ($newItemsAudit as $sku => $ni) {
                if (!isset($oldItemsAudit[$sku])) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $rest['restaurant_number'],
                        'delivery_date' => $deliveryDate,
                        'action' => 'item_added',
                        'actor_name' => $actorNameRo,
                        'actor_type' => 'restaurant',
                        'sku' => $sku,
                        'product_name' => $ni['name'],
                        'new_value' => (string)$ni['qty'],
                    ]);
                }
            }
            foreach ($newItemsAudit as $sku => $ni) {
                if (isset($oldItemsAudit[$sku]) && abs($oldItemsAudit[$sku]['qty'] - $ni['qty']) > 0.001) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $rest['restaurant_number'],
                        'delivery_date' => $deliveryDate,
                        'action' => 'item_changed',
                        'actor_name' => $actorNameRo,
                        'actor_type' => 'restaurant',
                        'sku' => $sku,
                        'product_name' => $ni['name'],
                        'old_value' => (string)$oldItemsAudit[$sku]['qty'],
                        'new_value' => (string)$ni['qty'],
                    ]);
                }
            }
            foreach ($oldItemsAudit as $sku => $oi) {
                if (!isset($newItemsAudit[$sku])) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $rest['restaurant_number'],
                        'delivery_date' => $deliveryDate,
                        'action' => 'item_deleted',
                        'actor_name' => $actorNameRo,
                        'actor_type' => 'restaurant',
                        'sku' => $sku,
                        'product_name' => $oi['name'],
                        'old_value' => (string)$oi['qty'],
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        roRespond(['error' => 'Ошибка сохранения заказа'], 500);
    }

    // Уведомление в Telegram о принятой/обновлённой заявке
    try {
        $isNew = !$existing;
        $deliveryDateFmt = (new DateTime($deliveryDate))->format('d.m.Y');

        // Группируем позиции по категориям
        $byCat = [];
        foreach ($aggregated as $it) {
            $q = floatval($it['quantity'] ?? 0);
            if ($q <= 0) continue;
            $cat = $it['category'] ?? 'Сухой';
            if (!isset($byCat[$cat])) $byCat[$cat] = [];
            $byCat[$cat][] = [
                'sku' => $it['sku'] ?? '',
                'name' => $it['product_name'] ?? '',
                'qty' => $q,
            ];
        }

        $fmtQty = function($q) {
            $s = number_format((float)$q, 1, '.', '');
            return rtrim(rtrim($s, '0'), '.');
        };
        $esc = function($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        $catIcons = ['Сухой' => '📦', 'Холод' => '🧊', 'Мороз' => '❄️'];

        $title = $isNew ? '✅ <b>Заявка отправлена</b>' : '✏️ <b>Заявка обновлена</b>';
        $restaurantLabel = roFormatRestaurantTelegramLabel(
            $rest['restaurant_number'],
            $rest['city'] ?? '',
            $rest['address'] ?? '',
            $rest['legal_entity_group'] ?? null
        );
        $lines = [];
        $lines[] = $title;
        $lines[] = '';
        $lines[] = "🏪 <b>Ресторан:</b> " . $esc($restaurantLabel);
        $lines[] = "📅 <b>Доставка:</b> {$deliveryDateFmt}";
        $lines[] = "📋 <b>Позиций:</b> {$totalItems}   📦 <b>Всего:</b> " . $fmtQty($totalQty) . " кор.";

        foreach (['Сухой', 'Холод', 'Мороз'] as $cat) {
            if (empty($byCat[$cat])) continue;
            $catItems = $byCat[$cat];
            $catQty = array_sum(array_column($catItems, 'qty'));
            $icon = $catIcons[$cat] ?? '•';
            $lines[] = '';
            $lines[] = "{$icon} <b>{$cat}</b> — " . count($catItems) . " поз., " . $fmtQty($catQty) . " кор.";
            foreach ($catItems as $ci) {
                $sku = $esc($ci['sku']);
                $name = $esc($ci['name']);
                $lines[] = "• <code>{$sku}</code> {$name} — <b>" . $fmtQty($ci['qty']) . "</b>";
            }
        }
        if ($comment !== null && $comment !== '') {
            $lines[] = '';
            $lines[] = "💬 <i>" . $esc($comment) . "</i>";
        }

        $msg = implode("\n", $lines);
        // Лимит Telegram ~4096 символов
        if (mb_strlen($msg) > 3900) {
            $msg = mb_substr($msg, 0, 3900) . "\n\n…(сообщение обрезано)";
        }

        roNotifyRestaurant($pdo, $rest['restaurant_number'], $msg, $rest['legal_entity_group'] ?? 'BK_VM');
    } catch (Exception $e) {
        // Уведомление не критично — игнорируем ошибку
    }

    roRespond([
        'success' => true,
        'order_id' => (int)$orderId,
        'total_items' => $totalItems,
        'total_qty' => $totalQty,
    ]);
}

// --- Повторить предыдущий заказ ---
if ($roAction === 'repeat-order' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    roRequireRestaurantOrdersEnabled($pdo, $rest['legal_entity'] ?? null, $rest['legal_entity_group'] ?? 'BK_VM');

    $sourceOrderId = $body['source_order_id'] ?? null;
    $deliveryDate = $body['delivery_date'] ?? '';

    if (!$sourceOrderId || !$deliveryDate) roRespond(['error' => 'Не указан исходный заказ или дата'], 400);

    // Получаем позиции исходного заказа
    $s = $pdo->prepare("
        SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
               COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) AS multiplicity
        FROM ro_order_items oi
        JOIN ro_orders o ON o.id = oi.order_id
        LEFT JOIN ro_templates t
            ON t.legal_entity = o.legal_entity
           AND t.category = oi.category
           AND t.sku = oi.sku
           AND t.is_active = 1
        LEFT JOIN products p ON p.id = (
            SELECT p2.id
            FROM products p2
            WHERE p2.sku = oi.sku AND p2.legal_entity = o.legal_entity
            ORDER BY p2.is_active DESC, p2.id ASC
            LIMIT 1
        )
        WHERE o.id = ? AND o.restaurant_number = ?
    ");
    $s->execute([$sourceOrderId, $rest['restaurant_number']]);
    $items = $s->fetchAll();

    if (empty($items)) roRespond(['error' => 'Исходный заказ не найден или пуст'], 404);

    roRespond(['items' => $items]);
}

// ═══════════════════════════════════════════════
// Маршруты для отдела закупок (требуется сессия основного приложения)
// ═══════════════════════════════════════════════

if (strpos($roAction, 'admin') === 0) {
    // Проверяем авторизацию отдела закупок
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) {
        if (!checkApiKey($pdo)) roRespond(['error' => 'Unauthorized'], 401);
    }

    // RBAC: проверяем доступ к модулю restaurant-orders
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $roRequiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : $ACCESS_LEVELS['edit'];
            $roUserLevel = $ACCESS_LEVELS[$perms['restaurant-orders'] ?? 'none'] ?? 0;
            if ($roUserLevel < $roRequiredLevel) {
                roRespond(['error' => 'Недостаточно прав для модуля «Заказы ресторанов»'], 403);
            }
        }
    }

    $adminAction = $roParts[2] ?? '';
    $adminParam = $roParts[3] ?? null;

    // --- Усиленный RBAC для чувствительных admin-операций ---
    // Базовый гейт выше пропускает любого пользователя с restaurant-orders ≥ view/edit.
    // Но управление учётками ресторанов (создание, сброс пароля) и публикация
    // важных сообщений в кабинеты ресторанов требуют более строгой роли.
    $userRoleForSensitive = $sessionUser['role'] ?? '';
    $isSensitiveUsers = ($adminAction === 'users' && $method !== 'GET');
    $isSensitiveCabinetPosts = ($adminAction === 'cabinet-posts' && in_array($method, ['POST', 'PATCH', 'DELETE'], true));
    if ($isSensitiveUsers && $userRoleForSensitive !== 'admin') {
        roRespond(['error' => 'Управление учётками ресторанов доступно только администраторам'], 403);
    }
    if ($isSensitiveCabinetPosts && !in_array($userRoleForSensitive, ['admin', 'manager'], true)) {
        roRespond(['error' => 'Публикация сообщений в кабинеты ресторанов доступна только администраторам и менеджерам'], 403);
    }

    // --- Настройки модуля для выбранной группы юрлиц ---
    if ($adminAction === 'module-settings' && $method === 'GET') {
        $legalEntity = roNormalizeRestaurantOrdersLegalEntity($_GET['legal_entity'] ?? null, $_GET['legal_entity_group'] ?? 'BK_VM');
        $entityGroup = getEntityGroup($legalEntity);
        $entityGroup = roNormalizeLegalEntityGroup($entityGroup);
        roEnsureGroupAccess($sessionUser, $entityGroup);

        roRespond([
            'legal_entity' => $legalEntity,
            'legal_entity_group' => $entityGroup,
            'restaurant_orders_enabled' => roRestaurantOrdersEnabled($pdo, $legalEntity, $entityGroup),
        ]);
    }

    if ($adminAction === 'module-settings' && $method === 'POST') {
        $legalEntity = roNormalizeRestaurantOrdersLegalEntity($body['legal_entity'] ?? null, $body['legal_entity_group'] ?? 'BK_VM');
        $entityGroup = getEntityGroup($legalEntity);
        $entityGroup = roNormalizeLegalEntityGroup($entityGroup);
        roEnsureGroupAccess($sessionUser, $entityGroup);

        $enabled = !empty($body['restaurant_orders_enabled']) ? 1 : 0;
        $updatedBy = $sessionUser['name'] ?? null;
        $s = $pdo->prepare("
            INSERT INTO ro_module_settings (legal_entity, legal_entity_group, restaurant_orders_enabled, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              legal_entity_group = VALUES(legal_entity_group),
              restaurant_orders_enabled = VALUES(restaurant_orders_enabled),
              updated_by = VALUES(updated_by),
              updated_at = CURRENT_TIMESTAMP
        ");
        $s->execute([$legalEntity, $entityGroup, $enabled, $updatedBy]);

        roRespond([
            'success' => true,
            'legal_entity' => $legalEntity,
            'legal_entity_group' => $entityGroup,
            'restaurant_orders_enabled' => $enabled === 1,
        ]);
    }

    // --- Настройщик кабинета ресторанов: важная информация ---
    if ($adminAction === 'cabinet-posts' && $method === 'GET') {
        $where = ["p.deleted_at IS NULL"];
        $params = [];
        $allowedGroups = roGetSessionUserGroups($sessionUser);
        if (($sessionUser['role'] ?? '') !== 'admin') {
            if (!$allowedGroups) {
                $where[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
                $where[] = "(p.target_mode = 'all' OR p.target_group IN ($ph) OR EXISTS (
                    SELECT 1 FROM ro_cabinet_post_restaurants rcp
                    WHERE rcp.post_id = p.id AND rcp.legal_entity_group IN ($ph)
                ))";
                $params = array_merge($params, $allowedGroups, $allowedGroups);
            }
        }
        $group = $_GET['group'] ?? '';
        if ($group === 'BK_VM' || $group === 'PS') {
            roEnsureGroupAccess($sessionUser, $group);
            $where[] = "(p.target_mode = 'all' OR p.target_group = ? OR EXISTS (
                SELECT 1 FROM ro_cabinet_post_restaurants rcp
                WHERE rcp.post_id = p.id AND rcp.legal_entity_group = ?
            ))";
            $params[] = $group;
            $params[] = $group;
        }
        $sql = "
            SELECT p.*,
                   (SELECT COUNT(*) FROM ro_cabinet_post_files f WHERE f.post_id = p.id) AS file_count,
                   (SELECT COUNT(*) FROM ro_cabinet_post_reads rr WHERE rr.post_id = p.id) AS read_count
            FROM ro_cabinet_posts p
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
            LIMIT 100
        ";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $posts = $s->fetchAll();
        roCabinetAttachFiles($pdo, $posts);
        if ($posts) {
            $ids = array_map(fn($p) => (int)$p['id'], $posts);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $t = $pdo->prepare("
                SELECT post_id, restaurant_number, legal_entity_group
                FROM ro_cabinet_post_restaurants
                WHERE post_id IN ($ph)
                ORDER BY legal_entity_group, restaurant_number
            ");
            $t->execute($ids);
            $targets = [];
            foreach ($t->fetchAll() as $row) {
                $targets[(int)$row['post_id']][] = [
                    'restaurant_number' => (int)$row['restaurant_number'],
                    'legal_entity_group' => $row['legal_entity_group'],
                ];
            }
            foreach ($posts as &$post) {
                $post['restaurants'] = $targets[(int)$post['id']] ?? [];
            }
            unset($post);
        }
        roRespond(['posts' => $posts]);
    }

    if ($adminAction === 'cabinet-posts' && $method === 'POST') {
        $payload = $_POST ?: $body;
        $title = trim((string)($payload['title'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));
        $targetMode = trim((string)($payload['target_mode'] ?? 'all'));
        $targetGroup = trim((string)($payload['target_group'] ?? ''));
        $isPublished = !isset($payload['is_published']) || filter_var($payload['is_published'], FILTER_VALIDATE_BOOLEAN);
        $showPopup = !isset($payload['show_popup']) || filter_var($payload['show_popup'], FILTER_VALIDATE_BOOLEAN);
        $notifyTelegram = !empty($payload['notify_telegram']);
        if ($title === '') $title = 'Важная информация';
        if ($message === '') roRespond(['error' => 'Введите текст сообщения'], 400);
        if (!in_array($targetMode, ['all', 'group', 'restaurants'], true)) $targetMode = 'all';
        if ($targetMode === 'group') {
            $targetGroup = roNormalizeLegalEntityGroup($targetGroup ?: 'BK_VM');
            roEnsureGroupAccess($sessionUser, $targetGroup);
        } elseif ($targetMode === 'restaurants') {
            $targetGroup = null;
        } else {
            $targetGroup = null;
        }

        $targets = roCabinetParseRestaurantTargets($payload['restaurants'] ?? []);
        if ($targetMode === 'restaurants' && !$targets) {
            roRespond(['error' => 'Выберите рестораны-получатели'], 400);
        }
        foreach ($targets as $target) {
            roEnsureRestaurantAccess($pdo, $sessionUser, $target['number'], $target['group']);
        }
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("
                INSERT INTO ro_cabinet_posts
                  (title, message, target_mode, target_group, is_published, show_popup, published_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $s->execute([
                mb_substr($title, 0, 255),
                $message,
                $targetMode,
                $targetGroup,
                $isPublished ? 1 : 0,
                $showPopup ? 1 : 0,
                $isPublished ? date('Y-m-d H:i:s') : null,
                $sessionUser['name'] ?? 'Отдел закупок',
            ]);
            $postId = (int)$pdo->lastInsertId();
            if ($targetMode === 'restaurants') {
                $ins = $pdo->prepare("
                    INSERT INTO ro_cabinet_post_restaurants (post_id, restaurant_number, legal_entity_group)
                    VALUES (?, ?, ?)
                ");
                foreach ($targets as $target) {
                    $ins->execute([$postId, $target['number'], $target['group']]);
                }
            }
            $savedFiles = roCabinetSaveUploadedFiles($pdo, $postId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('ro cabinet-post create error: ' . $e->getMessage());
            roRespond(['error' => 'Не удалось сохранить сообщение'], 500);
        }
        $telegramSent = 0;
        if ($notifyTelegram && $isPublished) {
            try {
                $telegramSent = roCabinetSendTelegramPost($pdo, $title, $message, $targetMode, $targetGroup, $targets, $savedFiles ?? []);
            } catch (Throwable $e) {
                error_log('ro cabinet-post telegram notify error: ' . $e->getMessage());
            }
        }
        roRespond(['success' => true, 'id' => $postId, 'telegram_sent' => $telegramSent]);
    }

    if ($adminAction === 'cabinet-posts' && $adminParam && $method === 'PATCH') {
        $postId = (int)$adminParam;
        $s = $pdo->prepare("SELECT * FROM ro_cabinet_posts WHERE id = ? AND deleted_at IS NULL");
        $s->execute([$postId]);
        $post = $s->fetch();
        if (!$post) roRespond(['error' => 'Сообщение не найдено'], 404);
        if (($post['target_mode'] ?? '') === 'group' && !empty($post['target_group'])) {
            roEnsureGroupAccess($sessionUser, $post['target_group']);
        }
        $fields = [];
        $params = [];
        if (array_key_exists('is_published', $body)) {
            $published = !empty($body['is_published']) ? 1 : 0;
            $fields[] = 'is_published = ?';
            $params[] = $published;
            if ($published && empty($post['published_at'])) {
                $fields[] = 'published_at = NOW()';
            }
        }
        if (array_key_exists('show_popup', $body)) {
            $fields[] = 'show_popup = ?';
            $params[] = !empty($body['show_popup']) ? 1 : 0;
        }
        if (!$fields) roRespond(['success' => true]);
        $params[] = $postId;
        $pdo->prepare("UPDATE ro_cabinet_posts SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        roRespond(['success' => true]);
    }

    if ($adminAction === 'cabinet-posts' && $adminParam && $method === 'DELETE') {
        $postId = (int)$adminParam;
        $s = $pdo->prepare("SELECT * FROM ro_cabinet_posts WHERE id = ? AND deleted_at IS NULL");
        $s->execute([$postId]);
        $post = $s->fetch();
        if (!$post) roRespond(['error' => 'Сообщение не найдено'], 404);
        if (($post['target_mode'] ?? '') === 'group' && !empty($post['target_group'])) {
            roEnsureGroupAccess($sessionUser, $post['target_group']);
        }
        $pdo->prepare("UPDATE ro_cabinet_posts SET deleted_at = NOW(), is_published = 0 WHERE id = ?")->execute([$postId]);
        roRespond(['success' => true]);
    }

    // --- Статус заявок ---
    if ($adminAction === 'status' && $method === 'GET') {
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        } else {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }

        $session = roGetSessionForDate($pdo, $entityGroup, $date);
        if (!$session) roRespond(['session' => null, 'orders' => []]);

        $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $date);

        // Все активные рестораны группы юрлиц с основной поставкой на этот день.
        // Дни только с dough_time для теста Пицца Стар не считаем ожидаемой заявкой.
        // JOIN с ro_orders также привязан к legal_entity_group, чтобы заказ
        // БК-ресторана с номером 1 не показался в списке ПС-ресторанов.
        $dow = (int)(new DateTime($date))->format('N');
        $rests = $pdo->prepare("
            SELECT r.number, r.region, r.city, r.address, r.legal_entity_group,
                   ds.delivery_time,
                   o.id as order_id, o.status as order_status, o.submitted_at, o.comment as order_comment,
                   o.updated_at, o.updated_by,
                   (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
            FROM restaurants r
            LEFT JOIN delivery_schedule ds
                ON ds.restaurant_id = r.id
                AND ds.day_of_week = ?
                AND TRIM(COALESCE(ds.delivery_time, '')) <> ''
            LEFT JOIN ro_orders o
                ON o.restaurant_number = r.number
                AND o.delivery_date = ?
                AND o.legal_entity_group = r.legal_entity_group
            WHERE r.active = 1 AND r.legal_entity_group = ?
              AND (ds.restaurant_id IS NOT NULL OR o.id IS NOT NULL)
            ORDER BY r.region, r.number
        ");
        $rests->execute([$dow, $date, $entityGroup]);
        $restaurants = $rests->fetchAll();

        // Подгружаем вес и паллеты для всех заказов
        $orderIds = array_values(array_filter(array_column($restaurants, 'order_id')));
        $weightData = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $ws = $pdo->prepare("
                SELECT oi.order_id, oi.category,
                       SUM(oi.quantity * COALESCE(p.weight_brutto, 0)) as total_weight,
                       SUM(CASE WHEN p.boxes_per_pallet > 0
                           THEN (CASE WHEN COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) > 1
                                 THEN (oi.quantity / COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1)) / p.boxes_per_pallet
                                 ELSE oi.quantity / p.boxes_per_pallet END)
                           ELSE 0 END) as raw_pallets
                FROM ro_order_items oi
                JOIN ro_orders o ON o.id = oi.order_id
                LEFT JOIN ro_templates t
                    ON t.legal_entity = o.legal_entity
                   AND t.category = oi.category
                   AND t.sku = oi.sku
                   AND t.is_active = 1
            LEFT JOIN products p ON p.id = (
                SELECT p2.id
                FROM products p2
                WHERE p2.sku = oi.sku AND p2.legal_entity = o.legal_entity
                ORDER BY p2.is_active DESC, p2.id ASC
                LIMIT 1
            )
            WHERE oi.order_id IN ({$ph})
            GROUP BY oi.order_id, oi.category
        ");
            $ws->execute($orderIds);
            foreach ($ws->fetchAll() as $row) {
                $oid = $row['order_id'];
                if (!isset($weightData[$oid])) {
                    $weightData[$oid] = ['total_weight' => 0, 'pallets' => 0];
                }
                $weightData[$oid]['total_weight'] += (float)$row['total_weight'];
                // Округление: дробная часть ≤ 0.2 → вниз, > 0.2 → вверх.
                // Если товар есть (raw > 0) — минимум 1 паллета.
                $rawP = (float)$row['raw_pallets'];
                if ($rawP > 0) {
                    $frac = $rawP - floor($rawP);
                    $rounded = ($frac > 0.2) ? ceil($rawP) : floor($rawP);
                    if ($rounded < 1) $rounded = 1;
                    $weightData[$oid]['pallets'] += $rounded;
                }
            }
        }
        // Добавляем к каждому ресторану
        foreach ($restaurants as &$r) {
            $oid = $r['order_id'];
            $r['total_weight'] = $oid && isset($weightData[$oid]) ? round($weightData[$oid]['total_weight']) : null;
            $r['pallets'] = $oid && isset($weightData[$oid]) ? $weightData[$oid]['pallets'] : null;
        }
        unset($r);

        // Считаем статистику
        $total = count($restaurants);
        $submitted = 0;
        foreach ($restaurants as $r) {
            if ($r['order_status'] === 'submitted' || $r['order_status'] === 'edited' || $r['order_status'] === 'locked') {
                $submitted++;
            }
        }

        roRespond([
            'session' => [
                'id' => (int)$session['id'],
                'week_start' => $session['week_start'],
                'week_end' => $session['week_end'],
                'legal_entity_group' => $session['legal_entity_group'] ?? $entityGroup,
            ],
            'date' => $date,
            'deadline_status' => $deadlineStatus,
            'restaurants' => $restaurants,
            'stats' => [
                'total' => $total,
                'submitted' => $submitted,
                'pending' => $total - $submitted,
            ],
        ]);
    }

    // --- Импорт заказов из Excel 1С УТ ---
    if ($adminAction === 'import-ut' && $method === 'POST') {
        $action = $body['action'] ?? ($_POST['action'] ?? 'preview');
        if ($action === 'confirm') {
            $payload = $body['payload'] ?? [];
            $addMissingTemplates = !empty($body['add_missing_templates']);
            $overwriteMode = $body['overwrite_mode'] ?? 'none';
            $overwriteRestaurants = $body['overwrite_restaurants'] ?? [];
            roRespond(roCommitUtImport($pdo, $payload, $sessionUser, $addMissingTemplates, $overwriteMode, $overwriteRestaurants));
        }

        if (empty($_FILES['file'])) roRespond(['error' => 'Файл не загружен'], 400);
        $selectedDate = $_POST['delivery_date'] ?? '';
        $legalEntity = $_POST['legal_entity'] ?? null;
        $preview = roBuildUtImportPreview($pdo, $_FILES['file']['tmp_name'], $selectedDate, $sessionUser, $legalEntity);
        roRespond(['success' => true, 'preview' => $preview]);
    }

    // --- Детали заказа ---
    if ($adminAction === 'order' && $method === 'GET' && $adminParam) {
        $s = $pdo->prepare("SELECT o.*, r.city, r.address, r.region FROM ro_orders o LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = o.legal_entity_group WHERE o.id = ?");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) roRespond(['error' => 'Заказ не найден'], 404);
        // Проверка доступа к юр. лицу заказа: отдел закупок одной группы не должен видеть чужие заказы
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $order['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $items = $pdo->prepare("SELECT oi.*, p.weight_netto, p.weight_brutto, p.external_code, p.gtin, p.boxes_per_pallet, COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) as multiplicity, COALESCE(p.is_traceable, 0) as is_traceable,
                   (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.legal_entity_group = ? AND pp.price_type = 'deposit' ORDER BY pp.updated_at DESC LIMIT 1) AS deposit_price
            FROM ro_order_items oi
            LEFT JOIN ro_templates t
                ON t.legal_entity = ?
               AND t.category = oi.category
               AND t.sku = oi.sku
               AND t.is_active = 1
            LEFT JOIN products p ON p.id = (
                SELECT p2.id
                FROM products p2
                WHERE p2.sku = oi.sku AND p2.legal_entity = ?
                ORDER BY p2.is_active DESC, p2.id ASC
                LIMIT 1
            )
            WHERE oi.order_id = ? ORDER BY oi.category, oi.product_name");
        // Первый параметр — group для product_prices (цены живут на группе),
        // остальные — конкретное юрлицо для ro_templates и products.
        $items->execute([$order['legal_entity_group'] ?? getEntityGroup($order['legal_entity']), $order['legal_entity'], $order['legal_entity'], $order['id']]);

        $order['items'] = $items->fetchAll();
        roRespond(['order' => $order]);
    }

    // --- Редактирование заказа отделом закупок ---
    if ($adminAction === 'order' && $method === 'PATCH' && $adminParam) {
        $orderId = (int)$adminParam;
        $items = $body['items'] ?? null;
        $status = $body['status'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? null;

        // Запоминаем старые позиции/состояние для сравнения и аудита
        $oldItems = [];
        $oldOrderSt = $pdo->prepare("SELECT restaurant_number, delivery_date, status, legal_entity FROM ro_orders WHERE id = ?");
        $oldOrderSt->execute([$orderId]);
        $oldOrder = $oldOrderSt->fetch() ?: [];
        // Проверка доступа к юр. лицу заказа
        if ($sessionUser && $oldOrder && !checkLegalEntityAccess($sessionUser, $oldOrder['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
        if ($items !== null) {
            $oldSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
            $oldSt->execute([$orderId]);
            foreach ($oldSt->fetchAll() as $oi) {
                $oldItems[$oi['sku']] = ['name' => $oi['product_name'], 'qty' => floatval($oi['quantity'])];
            }
        }

        $aggregated = $items !== null ? roAggregateOrderItems($items) : [];

        $pdo->beginTransaction();
        try {
            if ($items !== null) {
                // Обновляем позиции с агрегацией по SKU (на случай, если фронт
                // прислал один и тот же товар несколькими строками).
                $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
                $insert = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($aggregated as $ag) {
                    $insert->execute([$orderId, $ag['sku'], $ag['product_name'], $ag['category'], $ag['quantity'], $ag['comment']]);
                }
            }

            if ($deliveryDate) {
                $pdo->prepare("UPDATE ro_orders SET delivery_date = ? WHERE id = ?")
                    ->execute([$deliveryDate, $orderId]);
            }

            $updatedBy = resolveActorName($pdo, $sessionUser);
            $newStatus = $status ?: 'edited';
            $pdo->prepare("UPDATE ro_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?")
                ->execute([$newStatus, $updatedBy, $orderId]);

            // ═══ Журнал: правка заказа отделом закупок (внутри транзакции) ═══
            // Аудит пишется до commit(), чтобы изменения заказа и журнал
            // фиксировались атомарно.
            $actorName = $updatedBy;
            if ($items !== null) {
                $newItemsAudit = [];
                foreach ($aggregated as $sku => $it) {
                    $newItemsAudit[$sku] = ['name' => $it['product_name'] ?? '', 'qty' => floatval($it['quantity'] ?? 0)];
                }
                foreach ($newItemsAudit as $sku => $ni) {
                    if (!isset($oldItems[$sku])) {
                        roLogAudit($pdo, [
                            'order_id' => $orderId,
                            'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                            'delivery_date' => $oldOrder['delivery_date'] ?? null,
                            'action' => 'item_added',
                            'actor_name' => $actorName,
                            'actor_type' => 'admin',
                            'sku' => $sku,
                            'product_name' => $ni['name'],
                            'new_value' => (string)$ni['qty'],
                        ]);
                    }
                }
                foreach ($newItemsAudit as $sku => $ni) {
                    if (isset($oldItems[$sku]) && abs($oldItems[$sku]['qty'] - $ni['qty']) > 0.001) {
                        roLogAudit($pdo, [
                            'order_id' => $orderId,
                            'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                            'delivery_date' => $oldOrder['delivery_date'] ?? null,
                            'action' => 'item_changed',
                            'actor_name' => $actorName,
                            'actor_type' => 'admin',
                            'sku' => $sku,
                            'product_name' => $ni['name'],
                            'old_value' => (string)$oldItems[$sku]['qty'],
                            'new_value' => (string)$ni['qty'],
                        ]);
                    }
                }
                foreach ($oldItems as $sku => $oi2) {
                    if (!isset($newItemsAudit[$sku])) {
                        roLogAudit($pdo, [
                            'order_id' => $orderId,
                            'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                            'delivery_date' => $oldOrder['delivery_date'] ?? null,
                            'action' => 'item_deleted',
                            'actor_name' => $actorName,
                            'actor_type' => 'admin',
                            'sku' => $sku,
                            'product_name' => $oi2['name'],
                            'old_value' => (string)$oi2['qty'],
                        ]);
                    }
                }
            }
            if ($status && isset($oldOrder['status']) && $oldOrder['status'] !== $status) {
                roLogAudit($pdo, [
                    'order_id' => $orderId,
                    'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                    'delivery_date' => $oldOrder['delivery_date'] ?? null,
                    'action' => 'status_changed',
                    'actor_name' => $actorName,
                    'actor_type' => 'admin',
                    'old_value' => $oldOrder['status'],
                    'new_value' => $status,
                ]);
            }
            if ($deliveryDate && isset($oldOrder['delivery_date']) && $oldOrder['delivery_date'] !== $deliveryDate) {
                roLogAudit($pdo, [
                    'order_id' => $orderId,
                    'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                    'delivery_date' => $deliveryDate,
                    'action' => 'delivery_date_changed',
                    'actor_name' => $actorName,
                    'actor_type' => 'admin',
                    'old_value' => $oldOrder['delivery_date'],
                    'new_value' => $deliveryDate,
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            roRespond(['error' => 'Ошибка сохранения'], 500);
        }

        // Уведомляем ресторан в Telegram с деталями изменений
        $orderInfo = $pdo->prepare("SELECT restaurant_number, delivery_date FROM ro_orders WHERE id = ?");
        $orderInfo->execute([$orderId]);
        $oi = $orderInfo->fetch();
        if ($oi) {
            $dayNames = [0=>'Воскресенье',1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота'];
            $dow = (int)date('w', strtotime($oi['delivery_date']));
            $dayName = $dayNames[$dow] ?? '';
            $dateStr = $dayName . ', ' . date('d.m', strtotime($oi['delivery_date']));
            $restNum = $oi['restaurant_number'];

            $msg = "📝 Ресторан {$restNum} — заказ на {$dateStr}\n";
            $msg .= "Изменён: {$updatedBy}\n";

            // Формируем список изменений
            if ($items !== null) {
                $newItems = [];
                foreach (roAggregateOrderItems($items) as $sku => $item) {
                    $newItems[$sku] = ['name' => $item['product_name'] ?? '', 'qty' => floatval($item['quantity'] ?? 0)];
                }

                $changes = [];
                // Добавленные
                foreach ($newItems as $sku => $ni) {
                    if (!isset($oldItems[$sku])) {
                        $changes[] = "  ➕ {$ni['name']} — {$ni['qty']} кор.";
                    }
                }
                // Изменённые
                foreach ($newItems as $sku => $ni) {
                    if (isset($oldItems[$sku]) && abs($oldItems[$sku]['qty'] - $ni['qty']) > 0.001) {
                        $oldQ = $oldItems[$sku]['qty'];
                        $diff = $ni['qty'] - $oldQ;
                        $arrow = $diff > 0 ? '↑' : '↓';
                        $changes[] = "  ✏️ {$ni['name']}: {$oldQ} → {$ni['qty']} ({$arrow}" . abs($diff) . ")";
                    }
                }
                // Удалённые
                foreach ($oldItems as $sku => $oi2) {
                    if (!isset($newItems[$sku])) {
                        $changes[] = "  ❌ {$oi2['name']} — убрано";
                    }
                }

                if (!empty($changes)) {
                    $msg .= "\nИзменения:\n" . implode("\n", $changes);
                }

                // Итого
                $totalItems = count($newItems);
                $totalQty = array_sum(array_column($newItems, 'qty'));
                $msg .= "\n\nИтого: {$totalItems} поз., {$totalQty} кор.";
            }

            if ($deliveryDate) {
                $newDow = (int)date('w', strtotime($deliveryDate));
                $newDayName = $dayNames[$newDow] ?? '';
                $newDateStr = $newDayName . ', ' . date('d.m', strtotime($deliveryDate));
                $msg .= "\n📅 Дата доставки изменена на {$newDateStr}";
            }

            roNotifyRestaurant($pdo, $restNum, $msg, getEntityGroup($oldOrder['legal_entity'] ?? '') === 'PS' ? 'PS' : 'BK_VM');
        }

        roRespond(['success' => true]);
    }

    // --- Удаление заказа отделом закупок ---
    if ($adminAction === 'order' && $method === 'DELETE' && $adminParam) {
        $orderId = (int)$adminParam;
        // Сохраняем инфо для уведомления и журнала до удаления
        $orderInfo = $pdo->prepare("SELECT restaurant_number, delivery_date, legal_entity FROM ro_orders WHERE id = ?");
        $orderInfo->execute([$orderId]);
        $oi = $orderInfo->fetch();
        if (!$oi) roRespond(['error' => 'Заказ не найден'], 404);
        // Проверка доступа к юр. лицу заказа
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $oi['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        // Запоминаем позиции для журнала
        $delItemsSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
        $delItemsSt->execute([$orderId]);
        $delItems = $delItemsSt->fetchAll();

        $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM ro_orders WHERE id = ?")->execute([$orderId]);

        // ═══ Журнал: удаление заказа целиком ═══
        if ($oi) {
            $actorName = resolveActorName($pdo, $sessionUser);
            $totalQty = array_sum(array_map(fn($r) => floatval($r['quantity']), $delItems));
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $oi['restaurant_number'],
                'delivery_date' => $oi['delivery_date'],
                'action' => 'order_deleted',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => count($delItems) . ' поз. / ' . $totalQty . ' кор.',
                'details' => ['items' => $delItems],
            ]);
        }

        // Уведомляем ресторан
        if ($oi) {
            $dayNames = [0=>'Воскресенье',1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота'];
            $dow = (int)date('w', strtotime($oi['delivery_date']));
            $dayName = $dayNames[$dow] ?? '';
            $dateStr = $dayName . ', ' . date('d.m', strtotime($oi['delivery_date']));
            $restNum = $oi['restaurant_number'];
            $by = resolveActorName($pdo, $sessionUser);
            roNotifyRestaurant($pdo, $restNum,
                "❌ Ресторан {$restNum} — заказ на {$dateStr} удалён ({$by}). Если это ошибка — свяжитесь с нами.",
                getEntityGroup($oi['legal_entity'] ?? '') === 'PS' ? 'PS' : 'BK_VM');
        }

        roRespond(['success' => true]);
    }

    // --- Удаление отдельной позиции из заказа ---
    if ($adminAction === 'item' && $method === 'DELETE' && $adminParam) {
        $itemId = (int)$adminParam;
        // Проверяем существование (сначала по id)
        $check = $pdo->prepare("SELECT oi.id, oi.sku, oi.product_name, oi.quantity, o.restaurant_number, o.delivery_date, o.id as order_id, o.legal_entity
            FROM ro_order_items oi JOIN ro_orders o ON o.id = oi.order_id WHERE oi.id = ?");
        $check->execute([$itemId]);
        $item = $check->fetch();

        // Фоллбэк: заказ мог быть пересохранён (все позиции пересоздаются с новыми id).
        // Ищем по паре (order_id, sku) — её передаём в query-параметрах для устойчивости.
        if (!$item) {
            $fbOrderId = $_GET['order_id'] ?? null;
            $fbSku = $_GET['sku'] ?? null;
            if ($fbOrderId && $fbSku) {
                $fb = $pdo->prepare("SELECT oi.id, oi.sku, oi.product_name, oi.quantity, o.restaurant_number, o.delivery_date, o.id as order_id, o.legal_entity
                    FROM ro_order_items oi JOIN ro_orders o ON o.id = oi.order_id
                    WHERE oi.order_id = ? AND oi.sku = ?");
                $fb->execute([$fbOrderId, $fbSku]);
                $item = $fb->fetch();
            }
        }

        if (!$item) roRespond(['error' => 'Позиция не найдена'], 404);
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $item['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $pdo->prepare("DELETE FROM ro_order_items WHERE id = ?")->execute([$item['id']]);

        // ═══ Журнал: удаление одной позиции отделом закупок ═══
        $actorName = resolveActorName($pdo, $sessionUser);
        roLogAudit($pdo, [
            'order_id' => $item['order_id'],
            'restaurant_number' => $item['restaurant_number'],
            'delivery_date' => $item['delivery_date'],
            'action' => 'item_deleted',
            'actor_name' => $actorName,
            'actor_type' => 'admin',
            'sku' => $item['sku'],
            'product_name' => $item['product_name'],
            'old_value' => (string)$item['quantity'],
        ]);

        // Если в заказе больше нет позиций — удаляем сам заказ
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM ro_order_items WHERE order_id = ?");
        $remaining->execute([$item['order_id']]);
        $orderDeleted = false;
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare("DELETE FROM ro_orders WHERE id = ?")->execute([$item['order_id']]);
            $orderDeleted = true;
            roLogAudit($pdo, [
                'order_id' => $item['order_id'],
                'restaurant_number' => $item['restaurant_number'],
                'delivery_date' => $item['delivery_date'],
                'action' => 'order_deleted',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => 'последняя позиция удалена',
            ]);
        }

        roRespond(['success' => true, 'order_deleted' => $orderDeleted]);
    }

    // --- Управление сессией ---
    if ($adminAction === 'session' && $method === 'POST') {
        $action = $body['action'] ?? 'create';
        $entityGroup = $body['legal_entity_group'] ?? null;
        if (!$entityGroup) {
            $legalEntity = $body['legal_entity'] ?? null;
            $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        }
        if (!$entityGroup) {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
        }
        roEnsureGroupAccess($sessionUser, $entityGroup);

        if ($action === 'create') {
            $session = roGetActiveSession($pdo, $entityGroup);
            roRespond(['success' => true, 'session_id' => (int)($session['id'] ?? 0), 'session' => $session, 'created' => false]);
        }

        if ($action === 'close') {
            $sessionId = $body['session_id'] ?? null;
            if ($sessionId) {
                $session = roGetSessionById($pdo, $sessionId);
                if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
                roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');
                if (($session['created_by'] ?? '') !== 'permanent') {
                    $pdo->prepare("UPDATE ro_sessions SET status = 'closed' WHERE id = ?")->execute([$sessionId]);
                }
            }
            roRespond(['success' => true]);
        }

        if ($action === 'auto') {
            $session = roGetActiveSession($pdo, $entityGroup);
            roRespond(['success' => true, 'session' => $session, 'created' => false]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Открыть / закрыть приём на дату ---
    if ($adminAction === 'toggle-date' && $method === 'POST') {
        $sessionId = $body['session_id'] ?? null;
        $date = $body['delivery_date'] ?? '';
        $isOpen = isset($body['is_open']) ? ($body['is_open'] ? 1 : 0) : 1;
        $createdBy = resolveActorName($pdo, $sessionUser);

        if (!$sessionId || !$date) roRespond(['error' => 'Не указана сессия или дата'], 400);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        // Если открываем дату за пределами сессии — расширяем сессию
        if ($isOpen) {
            if ($date < $session['week_start']) {
                $pdo->prepare("UPDATE ro_sessions SET week_start = ? WHERE id = ?")->execute([$date, $sessionId]);
            }
            if ($date > $session['week_end']) {
                $pdo->prepare("UPDATE ro_sessions SET week_end = ? WHERE id = ?")->execute([$date, $sessionId]);
            }
        }

        $pdo->prepare("INSERT INTO ro_deadline_overrides (session_id, delivery_date, is_open, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), created_by = VALUES(created_by)")
            ->execute([$sessionId, $date, $isOpen, $createdBy]);

        roRespond(['success' => true, 'is_open' => (bool)$isOpen]);
    }

    // --- Список открытых дат сессии ---
    if ($adminAction === 'open-dates' && $method === 'GET') {
        $sessionId = $_GET['session_id'] ?? null;
        if (!$sessionId) {
            $legalEntity = $_GET['legal_entity'] ?? null;
            $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
            if (!$entityGroup) {
                $allowedGroups = roGetSessionUserGroups($sessionUser);
                $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            }
            roEnsureGroupAccess($sessionUser, $entityGroup);
            $session = roGetActiveSession($pdo, $entityGroup);
            $sessionId = $session ? $session['id'] : null;
        }
        if (!$sessionId) roRespond(['dates' => []]);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['dates' => []]);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        $s = $pdo->prepare("SELECT delivery_date, is_open, soft_deadline, hard_deadline FROM ro_deadline_overrides WHERE session_id = ? ORDER BY delivery_date");
        $s->execute([$sessionId]);
        roRespond(['dates' => $s->fetchAll()]);
    }

    // --- Продление дедлайна ---
    if ($adminAction === 'extend-deadline' && $method === 'POST') {
        $sessionId = $body['session_id'] ?? null;
        $date = $body['delivery_date'] ?? '';
        $softDeadline = $body['soft_deadline'] ?? '14:00:00';
        $hardDeadline = $body['hard_deadline'] ?? '16:00:00';
        $createdBy = resolveActorName($pdo, $sessionUser);

        if (!$sessionId || !$date) roRespond(['error' => 'Не указана сессия или дата'], 400);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        $pdo->prepare("INSERT INTO ro_deadline_overrides (session_id, delivery_date, is_open, soft_deadline, hard_deadline, created_by) VALUES (?, ?, 1, ?, ?, ?) ON DUPLICATE KEY UPDATE soft_deadline = VALUES(soft_deadline), hard_deadline = VALUES(hard_deadline), created_by = VALUES(created_by)")
            ->execute([$sessionId, $date, $softDeadline, $hardDeadline, $createdBy]);

        roRespond(['success' => true]);
    }

    // --- Шаблоны ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $le = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        $category = $_GET['category'] ?? null;
        roEnsureGroupAccess($sessionUser, getEntityGroup($le));

        $q = "SELECT t.*, COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) as multiplicity
            FROM ro_templates t
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ? AND p.is_active = 1
            WHERE t.legal_entity = ?";
        $params = [$le, $le];
        if ($category) { $q .= " AND t.category = ?"; $params[] = $category; }
        $q .= " ORDER BY t.category, t.sort_order, t.product_name";
        $s = $pdo->prepare($q);
        $s->execute($params);
        roRespond(['templates' => $s->fetchAll()]);
    }

    if ($adminAction === 'templates' && $method === 'POST') {
        $action = $body['action'] ?? 'save';

        if ($action === 'save') {
            $items = $body['items'] ?? [];
            $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
            $category = $body['category'] ?? '';
            roEnsureGroupAccess($sessionUser, getEntityGroup($le));

            if (!$category) roRespond(['error' => 'Не указана категория'], 400);

            // Дедупликация по SKU (или по имени, если SKU нет) внутри категории.
            // uq_ro_tpl уникален по (legal_entity, category, sku) — дубли в payload
            // ронят весь INSERT и шаблон не сохраняется. Молча отбрасываем повторы.
            $deduped = [];
            $skipped = 0;
            $seenSku = [];
            $seenName = [];
            foreach ($items as $item) {
                $sku = trim((string)($item['sku'] ?? ''));
                $name = trim((string)($item['product_name'] ?? ''));
                if ($sku !== '') {
                    if (isset($seenSku[$sku])) { $skipped++; continue; }
                    $seenSku[$sku] = true;
                } else {
                    $nameKey = mb_strtolower($name);
                    if ($nameKey !== '' && isset($seenName[$nameKey])) { $skipped++; continue; }
                    if ($nameKey !== '') $seenName[$nameKey] = true;
                }
                $deduped[] = $item;
            }

            // Удаляем старые для этой категории + юрлица
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);

            $insert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, multiplicity, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($deduped as $i => $item) {
                $mult = intval($item['multiplicity'] ?? 0);
                $insert->execute([
                    $le,
                    $category,
                    $item['sku'] ?? '',
                    $item['product_name'] ?? '',
                    $mult > 0 ? $mult : 1,
                    $i,
                ]);
            }
            roRespond(['success' => true, 'count' => count($deduped), 'skipped_duplicates' => $skipped]);
        }

        if ($action === 'import-from-stock') {
            // Импорт из ro_stock_balances (вкладка «Остатки склада»).
            // Берём последнюю загруженную дату остатков для данного юр. лица
            // и только те позиции, у которых есть остаток (quantity > 0),
            // а категория товара совпадает с выбранной.
            $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
            $category = $body['category'] ?? '';
            roEnsureGroupAccess($sessionUser, getEntityGroup($le));

            // Последняя дата остатков для юрлица
            $dateStmt = $pdo->prepare("SELECT MAX(balance_date) FROM ro_stock_balances WHERE legal_entity = ?");
            $dateStmt->execute([$le]);
            $latestDate = $dateStmt->fetchColumn();
            if (!$latestDate) {
                roRespond(['error' => 'Нет данных об остатках склада для «' . $le . '». Сначала загрузите файл остатков на вкладке «Остатки склада».'], 400);
            }

            $s = $pdo->prepare("
                SELECT DISTINCT p.sku, p.name AS product_name, COALESCE(p.multiplicity, 1) AS multiplicity
                FROM ro_stock_balances sb
                JOIN products p ON p.sku = sb.sku AND p.legal_entity = sb.legal_entity AND p.is_active = 1
                WHERE sb.legal_entity = ?
                  AND sb.balance_date = ?
                  AND sb.quantity > 0
                  AND p.category = ?
                ORDER BY p.name
            ");
            $s->execute([$le, $latestDate, $category]);
            $products = $s->fetchAll();

            // Сохраняем как шаблон
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);
            $insert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, multiplicity, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $mult = intval($p['multiplicity'] ?? 0);
                $insert->execute([
                    $le,
                    $category,
                    $p['sku'],
                    $p['product_name'],
                    $mult > 0 ? $mult : 1,
                    $i,
                ]);
            }

            roRespond(['success' => true, 'count' => count($products), 'items' => $products, 'balance_date' => $latestDate]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Управление учётками ресторанов ---
    // Источник истины — справочник ресторанов. ro_users только хранит пароль/сессию.
    if ($adminAction === 'users' && $method === 'GET') {
        // В выборку включаем legal_entity_group ресторана — нужно, чтобы
        // подставить правильное юрлицо (особенно для Пицца Стар, где у ресторана
        // может совпадать номер с БК).
        $usersWhere = ["r.active = 1"];
        $usersParams = [];
        roApplyAllowedGroupsSql($sessionUser, $usersWhere, $usersParams, "r.legal_entity_group");
        $s = $pdo->prepare("
            SELECT
                r.number AS restaurant_number,
                r.legal_entity_group,
                r.region,
                r.city,
                r.address,
                ru.id,
                ru.legal_entity,
                ru.is_active,
                ru.last_login_at,
                ru.password_changed_at,
                ru.telegram_chat_id,
                ru.email,
                ru.email_verified_at,
                CASE WHEN ru.password_hash IS NULL OR ru.password_hash = '' THEN 0 ELSE 1 END AS has_password
            FROM restaurants r
            LEFT JOIN ro_users ru
                   ON ru.restaurant_number = r.number
                  AND ru.legal_entity_group COLLATE utf8mb4_general_ci = r.legal_entity_group
            WHERE " . implode(' AND ', $usersWhere) . "
            ORDER BY r.legal_entity_group, r.number
        ");
        $s->execute($usersParams);
        $rows = $s->fetchAll();
        // Подставим юрлицо для тех, у кого ещё нет учётки
        foreach ($rows as &$row) {
            if (empty($row['legal_entity'])) {
                $row['legal_entity'] = roGetLegalEntity($pdo, $row['restaurant_number'], $row['legal_entity_group']);
            }
            $row['is_active'] = (int)($row['is_active'] ?? 1);
            $row['has_password'] = (int)$row['has_password'];
        }
        unset($row);
        roRespond(['users' => $rows]);
    }

    if ($adminAction === 'users' && $method === 'POST') {
        $action = $body['action'] ?? 'create';

        if ($action === 'create') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);
            if (mb_strlen($password) < 8) roRespond(['error' => 'Пароль слишком короткий (минимум 8 символов)'], 400);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);

            $le = roGetLegalEntity($pdo, $restNum, $restGroup);
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO ro_users (restaurant_number, legal_entity_group, password_hash, legal_entity, password_changed_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1, password_changed_at = NOW()")
                ->execute([$restNum, $restGroup, $hash, $le]);
            roLogAudit($pdo, [
                'action'            => 'password_changed',
                'actor_type'        => 'admin',
                'restaurant_number' => $restNum,
                'actor_name'        => resolveActorName($pdo, $sessionUser),
            ]);

            roRespond(['success' => true, 'restaurant_number' => $restNum, 'legal_entity_group' => $restGroup]);
        }

        if ($action === 'create-bulk') {
            // Назначить пароль для ресторанов
            // mode = 'missing' (по умолчанию) — только тем, у кого ещё нет пароля
            // mode = 'all' — всем подряд (затирая существующие пароли)
            $password = $body['password'] ?? '';
            $mode = ($body['mode'] ?? 'missing') === 'all' ? 'all' : 'missing';
            if (!$password) roRespond(['error' => 'Не указан пароль'], 400);
            if (mb_strlen($password) < 8) roRespond(['error' => 'Пароль слишком короткий (минимум 8 символов)'], 400);

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $restsWhere = ["active = 1"];
            $restsParams = [];
            roApplyAllowedGroupsSql($sessionUser, $restsWhere, $restsParams, 'legal_entity_group');
            $restsSql = "SELECT number, legal_entity_group FROM restaurants WHERE " . implode(' AND ', $restsWhere) . " ORDER BY legal_entity_group, number";
            $rests = $pdo->prepare($restsSql);
            $rests->execute($restsParams);
            $changed = 0;
            $bulkActorName = resolveActorName($pdo, $sessionUser);
            $insert = $pdo->prepare("INSERT INTO ro_users (restaurant_number, legal_entity_group, password_hash, legal_entity, password_changed_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1, password_changed_at = NOW()");
            $check = $pdo->prepare("SELECT password_hash FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ?");
            foreach ($rests->fetchAll() as $r) {
                if ($mode === 'missing') {
                    $check->execute([$r['number'], $r['legal_entity_group']]);
                    $existing = $check->fetchColumn();
                    if ($existing) continue;
                }
                $le = roGetLegalEntity($pdo, $r['number'], $r['legal_entity_group']);
                $insert->execute([$r['number'], $r['legal_entity_group'], $hash, $le]);
                roLogAudit($pdo, [
                    'action'            => 'password_changed',
                    'actor_type'        => 'admin',
                    'restaurant_number' => (int)$r['number'],
                    'actor_name'        => $bulkActorName,
                ]);
                $changed++;
            }
            roRespond(['success' => true, 'created' => $changed, 'mode' => $mode]);
        }

        if ($action === 'toggle') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $active = (int)($body['is_active'] ?? 1);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);
            $pdo->prepare("UPDATE ro_users SET is_active = ? WHERE restaurant_number = ? AND legal_entity_group = ?")->execute([$active, $restNum, $restGroup]);
            // Если деактивируем — гасим все живые сессии, иначе ресторан останется
            // внутри по уже выданным токенам.
            if ($active === 0) {
                roRevokeAllSessionsForRestaurant($pdo, $restNum, $restGroup);
            }
            roRespond(['success' => true]);
        }

        if ($action === 'set-email') {
            // Закупщик задаёт/меняет email ресторана. После — отправляем
            // ресторану письмо для подтверждения адреса.
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $email = trim((string)($body['email'] ?? ''));
            if (!$restNum) roRespond(['error' => 'Не указан номер ресторана'], 400);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                roRespond(['error' => 'Введите корректный email'], 400);
            }
            if (mb_strlen($email) > 255) roRespond(['error' => 'Слишком длинный email'], 400);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);

            $email = $email === '' ? null : mb_strtolower($email);

            $userRow = $pdo->prepare("SELECT id, email FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? LIMIT 1");
            $userRow->execute([$restNum, $restGroup]);
            $userRowData = $userRow->fetch();
            if (!$userRowData) {
                roRespond(['error' => 'У ресторана нет учётной записи. Сначала создайте пароль.'], 404);
            }

            // Запись/очистка. При смене email сбрасываем verified.
            if ($email === null) {
                $pdo->prepare("UPDATE ro_users SET email = NULL, email_verified_at = NULL WHERE id = ?")->execute([(int)$userRowData['id']]);
                $pdo->prepare("UPDATE ro_email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([(int)$userRowData['id']]);
                roRespond(['success' => true, 'cleared' => true]);
            }

            $changed = ($userRowData['email'] !== $email);
            if ($changed) {
                $pdo->prepare("UPDATE ro_users SET email = ?, email_verified_at = NULL WHERE id = ?")->execute([$email, (int)$userRowData['id']]);
                $pdo->prepare("UPDATE ro_email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([(int)$userRowData['id']]);
            }

            // Отправляем письмо подтверждения (даже если адрес не сменился —
            // закупщик мог нажать кнопку «повторно отправить»).
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO ro_email_verification_tokens (user_id, email, token, expires_at, ip_address) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)")
                ->execute([(int)$userRowData['id'], $email, $token, $_SERVER['REMOTE_ADDR'] ?? null]);

            require_once __DIR__ . '/mail_send.php';
            require_once __DIR__ . '/mail_templates.php';
            $siteUrl   = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
            $verifyUrl = $siteUrl . '/restaurant/verify-email?token=' . $token;
            $restDisp  = function_exists('formatRestaurantNumber') ? formatRestaurantNumber($restNum) : (string)$restNum;
            $bodyHtml = '<p style="margin:0 0 12px;">Закупщик указал этот email для кабинета ресторана №<strong>' . htmlspecialchars($restDisp, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                      . '<p style="margin:0;">Подтвердите адрес, чтобы можно было восстанавливать пароль кабинета по email. Ссылка действительна <strong>24 часа</strong>.</p>';
            $html = renderMailHtml([
                'title'   => 'Подтвердите email',
                'preview' => 'Подтвердите email для ресторана №' . $restDisp,
                'intro'   => 'Здравствуйте!',
                'body'    => $bodyHtml,
                'cta'     => ['text' => 'Подтвердить email', 'url' => $verifyUrl],
                'footer'  => 'Если этот email указан по ошибке — обратитесь к закупщику.',
            ]);
            $sendResult = sendEmail($email, 'Подтверждение email — Supply Department', $html, true);
            if (!$sendResult['success']) {
                error_log('[admin set-email] mail failed: ' . ($sendResult['error'] ?? 'unknown'));
            }

            roRespond(['success' => true, 'email' => $email, 'sent' => !!$sendResult['success']]);
        }

        if ($action === 'reset-password') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);
            if (mb_strlen($password) < 8) roRespond(['error' => 'Пароль слишком короткий (минимум 8 символов)'], 400);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE ro_users SET password_hash = ?, password_changed_at = NOW() WHERE restaurant_number = ? AND legal_entity_group = ?")->execute([$hash, $restNum, $restGroup]);
            // После сброса пароля закупщиком гасим ВСЕ сессии — мы не знаем, кто
            // в моменте внутри, и пароль уже не его.
            roRevokeAllSessionsForRestaurant($pdo, $restNum, $restGroup);
            roLogAudit($pdo, [
                'action'            => 'password_changed',
                'actor_type'        => 'admin',
                'restaurant_number' => $restNum,
                'actor_name'        => resolveActorName($pdo, $sessionUser),
            ]);
            roRespond(['success' => true]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Универсальный отчёт ---
    if ($adminAction === 'report' && $method === 'GET') {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+7 days'));
        $legalEntity = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        $entityGroup = getEntityGroup($legalEntity);
        roEnsureGroupAccess($sessionUser, $entityGroup);
        $category = $_GET['category'] ?? '';
        $status = $_GET['status'] ?? '';
        $restaurants = $_GET['restaurants'] ?? ''; // comma-separated

        $orderGroupExpr = "o.legal_entity_group";
        $where = ["o.delivery_date BETWEEN ? AND ?", "o.status != 'draft'", "{$orderGroupExpr} = ?"];
        $params = [$dateFrom, $dateTo, $entityGroup];

        if ($status) {
            $where[] = "o.status = ?";
            $params[] = $status;
        }
        if ($restaurants) {
            $restNums = array_map('intval', explode(',', $restaurants));
            $ph = implode(',', array_fill(0, count($restNums), '?'));
            $where[] = "o.restaurant_number IN ({$ph})";
            $params = array_merge($params, $restNums);
        }

        $sql = "SELECT o.id, o.restaurant_number, o.delivery_date, o.status, o.session_id,
                       {$orderGroupExpr} AS legal_entity_group,
                       r.city, r.address
                FROM ro_orders o
                LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = o.legal_entity_group
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.delivery_date, o.restaurant_number";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $orders = $s->fetchAll();

        $orderIds = array_column($orders, 'id');
        $items = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $catWhere = '';
            $catParams = $orderIds;
            if ($category) {
                $catWhere = " AND oi.category = ?";
                $catParams[] = $category;
            }
            $st = $pdo->prepare("SELECT oi.id, oi.order_id, oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
                       p.weight_brutto,
                       (SELECT pp.price FROM product_prices pp JOIN ro_orders o2 ON o2.id = oi.order_id
                           WHERE pp.sku = oi.sku AND pp.legal_entity_group = o2.legal_entity_group AND pp.price_type = 'deposit'
                           ORDER BY pp.updated_at DESC LIMIT 1) AS deposit_price
                FROM ro_order_items oi
                LEFT JOIN products p ON p.sku = oi.sku
                WHERE oi.order_id IN ({$ph}){$catWhere} ORDER BY oi.category, oi.product_name");
            $st->execute($catParams);
            $items = $st->fetchAll();
        }

        // Список ресторанов для фильтра
        $restWhere = ["o.status != 'draft'", "{$orderGroupExpr} = ?"];
        $restParams = [$entityGroup];
        $restSql = "SELECT DISTINCT o.restaurant_number FROM ro_orders o WHERE " . implode(' AND ', $restWhere) . " ORDER BY o.restaurant_number";
        $restStmt = $pdo->prepare($restSql);
        $restStmt->execute($restParams);
        $restList = $restStmt->fetchAll(PDO::FETCH_COLUMN);

        // Список сессий
        if (roSessionsSupportGroups($pdo)) {
            $sessionsWhere = ["legal_entity_group = ?"];
            $sessionsParams = [$entityGroup];
            $sessionsSql = "SELECT id, week_start, week_end, status, legal_entity_group FROM ro_sessions";
            if (!empty($sessionsWhere)) $sessionsSql .= " WHERE " . implode(' AND ', $sessionsWhere);
            $sessionsSql .= " ORDER BY id DESC LIMIT 20";
            $sessStmt = $pdo->prepare($sessionsSql);
            $sessStmt->execute($sessionsParams);
            $sessions = $sessStmt->fetchAll();
        } else {
            $sessions = $pdo->query("SELECT id, week_start, week_end, status, 'BK_VM' AS legal_entity_group FROM ro_sessions ORDER BY id DESC LIMIT 20")->fetchAll();
        }

        roRespond([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'orders' => $orders,
            'items' => $items,
            'restaurant_list' => $restList,
            'sessions' => $sessions,
        ]);
    }

    // --- Выгрузка заказов ---
    if ($adminAction === 'export' && $method === 'GET') {
        $format = $adminParam ?? 'summary'; // summary, per-restaurant, all, ctt-json
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        } else {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }
        $session = roGetSessionForDate($pdo, $entityGroup, $date);
        if (!$session) roRespond(['error' => 'Нет активной сессии'], 400);

        // Получаем все заказы на дату
        $dow = (int)(new DateTime($date))->format('N');
        $ordersSql = "
            SELECT o.id, o.restaurant_number, o.status, o.submitted_at, o.legal_entity,
                   r.region, r.city, r.address,
                   ds.delivery_time
            FROM ro_orders o
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = o.legal_entity_group
            LEFT JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ?
            WHERE o.delivery_date = ? AND o.status != 'draft'
              AND o.legal_entity_group = ?
        ";
        $ordersParams = [$dow, $date, $entityGroup];
        if ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            if (empty($allowedGroups)) roRespond(['error' => 'Нет доступа к данным этого юрлица'], 403);
            $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
            $ordersSql .= " AND o.legal_entity_group IN ({$ph})";
            $ordersParams = array_merge($ordersParams, $allowedGroups);
        }
        $ordersSql .= " ORDER BY o.restaurant_number";
        $orders = $pdo->prepare($ordersSql);
        $orders->execute($ordersParams);
        $ordersList = $orders->fetchAll();

        // Все позиции
        $orderIds = array_column($ordersList, 'id');
        $allItems = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $items = $pdo->prepare("SELECT oi.*, o.restaurant_number, p.weight_netto, p.weight_brutto, p.external_code, p.gtin, p.boxes_per_pallet, COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) as multiplicity, COALESCE(p.is_traceable, 0) as is_traceable,
                       (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.price_type = 'deposit'
                          AND pp.legal_entity_group = o.legal_entity_group
                          ORDER BY (pp.legal_entity = o.legal_entity) DESC, pp.updated_at DESC LIMIT 1) AS deposit_price,
                       (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.price_type = 'purchase'
                          AND pp.legal_entity_group = o.legal_entity_group
                          ORDER BY (pp.legal_entity = o.legal_entity) DESC, pp.updated_at DESC LIMIT 1) AS purchase_price
                FROM ro_order_items oi
                JOIN ro_orders o ON o.id = oi.order_id
                LEFT JOIN ro_templates t ON t.id = (
                    SELECT t2.id FROM ro_templates t2
                    WHERE t2.sku = oi.sku
                      AND t2.category = oi.category
                      AND t2.is_active = 1
                      AND t2.legal_entity_group = o.legal_entity_group
                    ORDER BY (t2.legal_entity = o.legal_entity) DESC
                    LIMIT 1
                )
                LEFT JOIN products p ON p.id = (
                    SELECT p2.id
                    FROM products p2
                    WHERE p2.sku = oi.sku
                      AND p2.legal_entity_group = o.legal_entity_group
                    ORDER BY (p2.legal_entity = o.legal_entity) DESC, p2.is_active DESC, p2.id ASC
                    LIMIT 1
                )
                WHERE oi.order_id IN ({$ph}) AND oi.quantity > 0 ORDER BY oi.category, oi.product_name");
            $items->execute($orderIds);
            $allItems = $items->fetchAll();
        }

        if ($format === 'ctt-json') {
            $cttPrefix = roGetCttPrefixByGroup($entityGroup);
            $ordersById = [];
            foreach ($ordersList as $order) {
                $ordersById[(int)$order['id']] = $order;
            }

            $cttItems = [];
            $skippedMissingGtin = 0;
            $missingDepositPrice = 0;
            foreach ($allItems as $item) {
                $gtin = trim((string)($item['gtin'] ?? ''));
                if ($gtin === '') {
                    $skippedMissingGtin++;
                    continue;
                }
                $order = $ordersById[(int)$item['order_id']] ?? null;
                if (!$order) continue;
                $restaurantNumber = (int)$order['restaurant_number'];
                $depositPrice = round((float)($item['deposit_price'] ?? 0), 2);
                if ($depositPrice <= 0) {
                    $missingDepositPrice++;
                }
                $quantity = (float)($item['quantity'] ?? 0);
                $positionWeightBrutto = (float)($item['weight_brutto'] ?? 0) * $quantity;
                $cttItems[] = [
                    'o' => $cttPrefix . '-' . $restaurantNumber,
                    'r' => roFormatCttRestaurantLabel($restaurantNumber, $order['city'] ?? '', $order['address'] ?? ''),
                    's' => trim((string)($item['category'] ?? '')),
                    'g' => $gtin,
                    'n' => trim((string)($item['product_name'] ?? '')),
                    'q' => (string)(0 + $quantity),
                    'w' => roFormatCttWeight($positionWeightBrutto),
                    'p' => $depositPrice,
                ];
            }

            usort($cttItems, static function ($a, $b) {
                return [$a['o'], $a['s'], $a['n']] <=> [$b['o'], $b['s'], $b['n']];
            });

            roRespond([
                'date' => $date,
                'format' => $format,
                'filename' => 'data-' . strtolower($cttPrefix) . '-' . $date . '.json',
                'items' => $cttItems,
                'skipped_missing_gtin' => $skippedMissingGtin,
                'missing_deposit_price' => $missingDepositPrice,
            ]);
        }

        roRespond([
            'date' => $date,
            'orders' => $ordersList,
            'items' => $allItems,
            'format' => $format,
        ]);
    }

    // --- Поиск товаров (для шаблонов) ---
    if ($adminAction === 'products' && $method === 'GET') {
        $search = $_GET['search'] ?? '';
        $le = $_GET['legal_entity'] ?? '';
        if ($le) roEnsureGroupAccess($sessionUser, getEntityGroup($le));
        if (!$search || strlen($search) < 2 || !$le) roRespond(['products' => []]);
        $like = "%{$search}%";
        $s = $pdo->prepare("SELECT sku, name, category, qty_per_box, multiplicity FROM products WHERE legal_entity = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 50");
        $s->execute([$le, $like, $like]);
        roRespond(['products' => $s->fetchAll()]);
    }

    // --- Список всех сессий ---
    if ($adminAction === 'sessions' && $method === 'GET') {
        if (roSessionsSupportGroups($pdo)) {
            $where = [];
            $params = [];
            roApplyAllowedGroupsSql($sessionUser, $where, $params, 'legal_entity_group');
            $sql = "SELECT * FROM ro_sessions";
            if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY week_start DESC LIMIT 20";
            $s = $pdo->prepare($sql);
            $s->execute($params);
            roRespond(['sessions' => $s->fetchAll()]);
        }
        roRespond(['sessions' => $pdo->query("SELECT *, 'BK_VM' AS legal_entity_group FROM ro_sessions ORDER BY week_start DESC LIMIT 20")->fetchAll()]);
    }

    // ═══ Журнал изменений (общий + по заказу) ═══

    // --- Общий журнал с фильтрами ---
    if ($adminAction === 'audit' && $method === 'GET' && !$adminParam) {
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $restaurant = $_GET['restaurant'] ?? '';
        $actor = $_GET['actor'] ?? '';
        $action = $_GET['action'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        $limit = min((int)($_GET['limit'] ?? 200), 1000);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }

        $where = ['1=1'];
        $params = [];
        // Фильтр по дате поставки — то, что обычно ищут («все события по заказам на дату X»).
        if ($dateFrom) { $where[] = 'al.delivery_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'al.delivery_date <= ?'; $params[] = $dateTo; }
        if ($restaurant !== '') { $where[] = 'al.restaurant_number = ?'; $params[] = (int)$restaurant; }
        if ($actor !== '')      { $where[] = 'al.actor_name LIKE ?';     $params[] = '%' . $actor . '%'; }
        if ($action !== '')     { $where[] = 'al.action = ?';            $params[] = $action; }
        if ($search !== '')     {
            $where[] = '(al.sku LIKE ? OR al.product_name LIKE ? OR al.old_value LIKE ? OR al.new_value LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        // Фильтр по группе юрлиц: событие относится либо к заказу этой группы,
        // либо к ресторану этой группы (для событий без order_id).
        if ($entityGroup) {
            $where[] = "((o.legal_entity IS NOT NULL AND o.legal_entity_group = ?) OR (al.order_id IS NULL AND r.legal_entity_group = ?))";
            $params[] = $entityGroup;
            $params[] = $entityGroup;
        } elseif ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            if (empty($allowedGroups)) {
                $where[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
                $where[] = "(
                    (o.legal_entity IS NOT NULL AND o.legal_entity_group IN ({$ph}))
                    OR
                    (al.order_id IS NULL AND r.legal_entity_group IN ({$ph}))
                )";
                foreach ($allowedGroups as $group) $params[] = $group;
                foreach ($allowedGroups as $group) $params[] = $group;
            }
        }

        $sql = "SELECT al.id, al.order_id, al.restaurant_number, al.delivery_date, al.action, al.actor_name, al.actor_type,
                       al.sku, al.product_name, al.old_value, al.new_value, al.details, al.created_at
                FROM ro_audit_log al
                LEFT JOIN ro_orders o ON o.id = al.order_id
                LEFT JOIN restaurants r ON r.number = al.restaurant_number AND r.active = 1
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll();

        // Total count для пагинации
        $countSql = "SELECT COUNT(*) FROM ro_audit_log al
                     LEFT JOIN ro_orders o ON o.id = al.order_id
                     LEFT JOIN restaurants r ON r.number = al.restaurant_number AND r.active = 1
                     WHERE " . implode(' AND ', $where);
        $cs = $pdo->prepare($countSql);
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        roRespond(['events' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    // --- История одного заказа (по order_id или по restaurant+date) ---
    if ($adminAction === 'audit' && $method === 'GET' && $adminParam) {
        $orderId = (int)$adminParam;
        // Пытаемся взять restaurant_number + delivery_date этого заказа
        // (если он ещё существует), чтобы подтянуть события с null-order_id от удаления
        $meta = $pdo->prepare("SELECT restaurant_number, delivery_date, legal_entity FROM ro_orders WHERE id = ?");
        $meta->execute([$orderId]);
        $m = $meta->fetch();
        if ($m && $sessionUser && !checkLegalEntityAccess($sessionUser, $m['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к этому заказу'], 403);
        } elseif (!$m && $sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $auditMeta = $pdo->prepare("SELECT restaurant_number FROM ro_audit_log WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $auditMeta->execute([$orderId]);
            $auditRestaurant = $auditMeta->fetchColumn();
            if ($auditRestaurant) {
                roEnsureRestaurantAccess($pdo, $sessionUser, $auditRestaurant);
            }
        }

        $sql = "SELECT id, order_id, restaurant_number, delivery_date, action, actor_name, actor_type,
                       sku, product_name, old_value, new_value, details, created_at
                FROM ro_audit_log
                WHERE order_id = ?";
        $params = [$orderId];
        if ($m) {
            $sql .= " OR (restaurant_number = ? AND delivery_date = ?)";
            $params[] = $m['restaurant_number'];
            $params[] = $m['delivery_date'];
        }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 2000";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        roRespond(['events' => $s->fetchAll()]);
    }

    // ═══ Остатки склада ═══

    // --- Загрузка остатков из Excel ---
    if ($adminAction === 'stock-upload' && $method === 'POST') {
        if (empty($_FILES['file'])) roRespond(['error' => 'Файл не загружен'], 400);
        $balanceDate = $_POST['balance_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $balanceDate)) roRespond(['error' => 'Неверный формат даты'], 400);
        $allowedEntities = roGetAllowedLegalEntities($sessionUser);
        $isFullAdmin = !$sessionUser || (($sessionUser['role'] ?? '') === 'admin');
        if (!$isFullAdmin && empty($allowedEntities)) {
            roRespond(['error' => 'Нет доступа к загрузке остатков'], 403);
        }

        require_once __DIR__ . '/../lib/SimpleXLSX.php';
        $filePath = $_FILES['file']['tmp_name'];
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if (!$xlsx) roRespond(['error' => 'Не удалось прочитать Excel файл'], 400);

        // Загружаем все товары из БД для сопоставления (включая неактивные/«невидимые» карточки).
        // ORDER BY is_active DESC: активные идут первыми и занимают ключ, неактивные заполняют
        // только те ключи, которых у активных нет — это исключает перетирание активных карточек.
        $productsStmt = $pdo->query("SELECT sku, external_code, name FROM products ORDER BY is_active DESC, id ASC");
        $productsBySku = [];
        $productsByExtCode = [];
        foreach ($productsStmt->fetchAll() as $p) {
            $sku = roNormalizeStockProductCode($p['sku'] ?? '');
            if ($sku !== '' && !isset($productsBySku[$sku])) {
                $productsBySku[$sku] = $p;
            }
            $ext = roNormalizeStockProductCode($p['external_code'] ?? '');
            if ($ext !== '' && !isset($productsByExtCode[$ext])) {
                $productsByExtCode[$ext] = $p;
            }
        }

        $matched = 0;
        $skipped = 0;
        $rows = [];
        $unmatchedMap = []; // ключ: extCode|sku → ['external_code','sku','name','qty','warehouse','legal_entity']

        foreach ($xlsx->sheetNames() as $sheetIdx => $sheetName) {
            // Определяем тип склада (пропускаем листы с примерами заказов)
            if (mb_stripos($sheetName, 'Пример') !== false) continue;
            $warehouse = '';
            if (mb_stripos($sheetName, 'П6') !== false) $warehouse = 'Сухой';
            elseif (mb_stripos($sheetName, 'П1') !== false) $warehouse = 'Холод+Мороз';
            else continue;

            $sheetRows = $xlsx->rows($sheetIdx);
            if (empty($sheetRows)) continue;

            // Ищем заголовок
            $headerRow = -1;
            $colProduct = -1;
            $colOwner = -1;
            $colQty = -1;
            for ($r = 0; $r < min(10, count($sheetRows)); $r++) {
                foreach ($sheetRows[$r] as $c => $val) {
                    $v = mb_strtolower(trim((string)$val));
                    if ($v === '') continue;
                    // «Товар» (точное совпадение — не путать с «Владелец товара»)
                    if ($v === 'товар' || $v === 'номенклатура' || $v === 'наименование') $colProduct = $c;
                    if (mb_strpos($v, 'владелец') !== false) $colOwner = $c;
                    // Колонка количества: «Итог», «Итого», «Кол-во», «Количество», «Кол-во штук», «Остаток»
                    if (preg_match('/^итог|^кол[-\s]?во|^количеств|^остаток|штук/u', $v)) $colQty = $c;
                }
                if ($colProduct >= 0 && $colQty >= 0) { $headerRow = $r; break; }
            }
            if ($headerRow < 0) continue;

            for ($r = $headerRow + 1; $r < count($sheetRows); $r++) {
                $productStr = trim((string)($sheetRows[$r][$colProduct] ?? ''));
                $qty = (float)($sheetRows[$r][$colQty] ?? 0);
                if (!$productStr || $qty <= 0) continue;

                // Определяем юрлицо по владельцу
                $ownerStr = mb_strtolower(trim((string)($sheetRows[$r][$colOwner] ?? '')));
                $legalEntity = '';
                if (mb_strpos($ownerStr, 'воглия') !== false) {
                    $legalEntity = 'ООО "Воглия Матта"';
                } elseif (mb_strpos($ownerStr, 'бургер') !== false) {
                    $legalEntity = 'ООО "Бургер БК"';
                } elseif (mb_strpos($ownerStr, 'пицца стар') !== false || mb_strpos($ownerStr, 'додо') !== false) {
                    $legalEntity = 'ООО "Пицца Стар"';
                } else {
                    continue; // пропускаем ДоДо, Сбарро и т.д.
                }
                if (!$isFullAdmin && !in_array($legalEntity, $allowedEntities, true)) {
                    continue;
                }

                // Парсим обычный формат: "внешний_код - SKU Название".
                // Если формат другой, берём первый код из строки и пробуем найти его как артикул или внешний код.
                $extCode = '';
                $sku = '';
                $excelName = $productStr;
                if (preg_match('/^(\S+)\s*-\s*(\S+)\s+(.+)$/', $productStr, $m)) {
                    $extCode = roNormalizeStockProductCode($m[1]);
                    $sku = roNormalizeStockProductCode($m[2]);
                    $excelName = trim($m[3]);
                } elseif (preg_match('/^\s*(\S+)\s+(.+)$/u', $productStr, $m)) {
                    $sku = roNormalizeStockProductCode($m[1]);
                    $extCode = $sku;
                    $excelName = trim($m[2]);
                } else {
                    $sku = roNormalizeStockProductCode($productStr);
                    $extCode = $sku;
                }

                // Сопоставляем: сначала по SKU, потом по внешнему коду
                $foundProduct = $productsBySku[$sku] ?? $productsByExtCode[$extCode] ?? null;
                if ($foundProduct) {
                    $fSku = $foundProduct['sku'];
                    $key = $fSku . '|' . $legalEntity;
                    if (isset($rows[$key])) {
                        $rows[$key][2] += $qty;
                    } else {
                        $rows[$key] = [$fSku, $foundProduct['name'], $qty, $warehouse, $legalEntity, $balanceDate];
                    }
                    $matched++;
                } else {
                    $skipped++;
                    $umKey = $extCode . '|' . $sku . '|' . $legalEntity;
                    if (isset($unmatchedMap[$umKey])) {
                        $unmatchedMap[$umKey]['qty'] += $qty;
                    } else {
                        $unmatchedMap[$umKey] = [
                            'external_code' => $extCode,
                            'sku' => $sku,
                            'name' => $excelName,
                            'qty' => $qty,
                            'warehouse' => $warehouse,
                            'legal_entity' => $legalEntity,
                        ];
                    }
                }
            }
        }

        // Вставляем в БД
        if (!empty($rows)) {
            if ($isFullAdmin) {
                $pdo->prepare("DELETE FROM ro_stock_balances WHERE balance_date = ?")->execute([$balanceDate]);
            } else {
                $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
                $deleteParams = array_merge([$balanceDate], $allowedEntities);
                $pdo->prepare("DELETE FROM ro_stock_balances WHERE balance_date = ? AND legal_entity IN ({$ph})")->execute($deleteParams);
            }
            $stmt = $pdo->prepare("INSERT INTO ro_stock_balances (sku, product_name, quantity, warehouse, legal_entity, balance_date) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
        }

        $unmatched = array_values($unmatchedMap);
        usort($unmatched, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        roRespond([
            'success' => true,
            'matched' => $matched,
            'skipped' => $skipped,
            'date' => $balanceDate,
            'unmatched' => $unmatched,
        ]);
    }

    // --- Остатки с учётом заказов ---
    if ($adminAction === 'stock-balances' && $method === 'GET') {
        $balanceDate = $_GET['date'] ?? '';
        $deliveryDate = $_GET['delivery_date'] ?? '';
        $legalEntity = $_GET['legal_entity'] ?? '';
        $orderMode = $_GET['order_mode'] ?? 'until';
        $orderDatesRaw = trim((string)($_GET['order_dates'] ?? ''));
        if (!$balanceDate || !$deliveryDate) roRespond(['error' => 'Не указаны даты'], 400);
        if ($legalEntity) {
            roEnsureGroupAccess($sessionUser, getEntityGroup($legalEntity));
        }

        // Остатки на дату (с фильтром по юрлицу если указано)
        if ($legalEntity) {
            $s = $pdo->prepare("SELECT sku, product_name, quantity, warehouse, legal_entity FROM ro_stock_balances WHERE balance_date = ? AND legal_entity = ? ORDER BY warehouse, product_name");
            $s->execute([$balanceDate, $legalEntity]);
        } else {
            $balanceWhere = ['balance_date = ?'];
            $balanceParams = [$balanceDate];
            if ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
                $allowedEntities = roGetAllowedLegalEntities($sessionUser);
                if (empty($allowedEntities)) {
                    roRespond(['items' => [], 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
                }
                $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
                $balanceWhere[] = "legal_entity IN ({$ph})";
                foreach ($allowedEntities as $entity) $balanceParams[] = $entity;
            }
            $sql = "SELECT sku, product_name, quantity, warehouse, legal_entity
                    FROM ro_stock_balances
                    WHERE " . implode(' AND ', $balanceWhere) . "
                    ORDER BY legal_entity, warehouse, product_name";
            $s = $pdo->prepare($sql);
            $s->execute($balanceParams);
        }
        $balanceRowsRaw = $s->fetchAll();

        // Склеиваем остатки по SKU + юрлицу, чтобы одна позиция не распадалась
        // на несколько строк из-за нескольких складов или дублей в загрузке.
        $balancesMap = [];
        foreach ($balanceRowsRaw as $row) {
            $sku = roNormalizeSku($row['sku'] ?? '');
            $legalEntityRow = trim((string)($row['legal_entity'] ?? ''));
            if ($sku === '' || $legalEntityRow === '') continue;
            $key = $sku . '|' . $legalEntityRow;
            if (!isset($balancesMap[$key])) {
                $balancesMap[$key] = [
                    'sku' => $sku,
                    'product_name' => trim((string)($row['product_name'] ?? '')) ?: $sku,
                    'quantity' => 0,
                    'warehouses' => [],
                    'legal_entity' => $legalEntityRow,
                ];
            }
            $balancesMap[$key]['quantity'] += (float)($row['quantity'] ?? 0);
            $warehouse = trim((string)($row['warehouse'] ?? ''));
            if ($warehouse !== '') {
                $balancesMap[$key]['warehouses'][$warehouse] = true;
            }
            if (
                ($balancesMap[$key]['product_name'] === '' || $balancesMap[$key]['product_name'] === $sku)
                && !empty($row['product_name'])
            ) {
                $balancesMap[$key]['product_name'] = trim((string)$row['product_name']);
            }
        }

        $balances = [];
        foreach ($balancesMap as $row) {
            $warehouses = array_keys($row['warehouses']);
            sort($warehouses, SORT_NATURAL | SORT_FLAG_CASE);
            $hasWarehouseConflict = count($warehouses) > 1;
            $balances[] = [
                'sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'warehouse' => $hasWarehouseConflict ? 'Ошибка склада' : ($warehouses[0] ?? ''),
                'warehouse_error' => $hasWarehouseConflict,
                'warehouse_error_details' => $hasWarehouseConflict ? implode(', ', $warehouses) : '',
                'legal_entity' => $row['legal_entity'],
            ];
        }
        usort($balances, function ($a, $b) {
            return [$a['legal_entity'], $a['warehouse'], $a['product_name'], $a['sku']]
                <=> [$b['legal_entity'], $b['warehouse'], $b['product_name'], $b['sku']];
        });

        // Карта поставщиков: sku|legal_entity -> supplier (точное совпадение, активные карточки приоритетнее).
        // Fallback ищем строго внутри той же группы юрлиц (BK_VM / PS), чтобы ВМ не подтягивала
        // данные из ПС и наоборот — это нарушение правила групп.
        $supplierMap = [];
        $supplierByGroup = [];
        $balanceSkus = array_values(array_unique(array_column($balances, 'sku')));
        if (!empty($balanceSkus)) {
            $ph = implode(',', array_fill(0, count($balanceSkus), '?'));
            $qs = $pdo->prepare("SELECT sku, supplier, legal_entity FROM products WHERE sku IN ($ph) ORDER BY is_active DESC, id ASC");
            $qs->execute($balanceSkus);
            foreach ($qs->fetchAll() as $row) {
                $normalizedSku = roNormalizeSku($row['sku'] ?? '');
                if ($normalizedSku === '') continue;
                if (empty($row['supplier'])) continue;
                $mk = $normalizedSku . '|' . $row['legal_entity'];
                if (!isset($supplierMap[$mk])) {
                    $supplierMap[$mk] = $row['supplier'];
                }
                $gk = $normalizedSku . '|' . getEntityGroup($row['legal_entity']);
                if (!isset($supplierByGroup[$gk])) {
                    $supplierByGroup[$gk] = $row['supplier'];
                }
            }
        }

        // Суммарные заказы для колонки «Заказано».
        // По умолчанию берём период от даты остатков+1 до выбранной доставки.
        // В режиме selected считаем только явно отмеченные пользователем даты.
        $ordersWhere = ["o.status IN ('submitted','edited','locked')"];
        $ordersParams = [];
        if ($orderMode === 'selected') {
            $selectedDates = [];
            foreach (array_filter(array_map('trim', explode(',', $orderDatesRaw))) as $d) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
                if ($d <= $balanceDate || $d > $deliveryDate) continue;
                $selectedDates[$d] = true;
            }
            $selectedDates = array_keys($selectedDates);
            sort($selectedDates);
            if (empty($selectedDates)) {
                $ordersWhere[] = '1 = 0';
            } else {
                $ph = implode(',', array_fill(0, count($selectedDates), '?'));
                $ordersWhere[] = "o.delivery_date IN ({$ph})";
                foreach ($selectedDates as $d) $ordersParams[] = $d;
            }
        } else {
            $ordersWhere[] = 'o.delivery_date > ?';
            $ordersWhere[] = 'o.delivery_date <= ?';
            $ordersParams[] = $balanceDate;
            $ordersParams[] = $deliveryDate;
        }
        if ($legalEntity) {
            $ordersWhere[] = 'o.legal_entity = ?';
            $ordersParams[] = $legalEntity;
        } elseif ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedEntities = roGetAllowedLegalEntities($sessionUser);
            if (empty($allowedEntities)) {
                roRespond(['items' => [], 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
            }
            $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
            $ordersWhere[] = "o.legal_entity IN ({$ph})";
            foreach ($allowedEntities as $entity) $ordersParams[] = $entity;
        }
        $s2 = $pdo->prepare("
            SELECT oi.sku,
                   o.legal_entity AS real_legal_entity,
                   SUM(oi.quantity) as total_ordered
            FROM ro_order_items oi
            JOIN ro_orders o ON o.id = oi.order_id
            WHERE " . implode(' AND ', $ordersWhere) . "
            GROUP BY oi.sku, real_legal_entity
        ");
        $s2->execute($ordersParams);
        $orders = [];
        foreach ($s2->fetchAll() as $row) {
            $normalizedSku = roNormalizeSku($row['sku'] ?? '');
            if ($normalizedSku === '') continue;
            $key = $normalizedSku . '|' . $row['real_legal_entity'];
            if (!isset($orders[$key])) $orders[$key] = 0;
            $orders[$key] += (float)$row['total_ordered'];
        }

        $items = [];
        $seenSkus = [];
        foreach ($balances as $b) {
            $stockQty = (float)$b['quantity'];
            $le = $b['legal_entity'];
            $normalizedSku = roNormalizeSku($b['sku'] ?? '');
            if ($normalizedSku === '') continue;
            $key = $normalizedSku . '|' . $le;
            $orderedQty = $orders[$key] ?? 0;
            $seenSkus[$key] = true;
            $supplier = $supplierMap[$key] ?? $supplierByGroup[$normalizedSku . '|' . getEntityGroup($le)] ?? '';
            $items[] = [
                'sku' => $normalizedSku,
                'product_name' => $b['product_name'],
                'supplier' => $supplier,
                'warehouse' => $b['warehouse'],
                'warehouse_error' => !empty($b['warehouse_error']),
                'warehouse_error_details' => $b['warehouse_error_details'] ?? '',
                'legal_entity' => $le,
                'stock_qty' => $stockQty,
                'ordered_qty' => $orderedQty,
                'remaining' => $stockQty - $orderedQty,
            ];
        }

        // Товары, которые заказаны, но которых нет в остатках
        foreach ($orders as $key => $orderedQty) {
            if (isset($seenSkus[$key])) continue;
            list($sku, $le) = explode('|', $key);
            if ($legalEntity && $le !== $legalEntity) continue;
            // Получаем название товара из БД. Точная карточка по юрлицу приоритетнее;
            // если её нет — ищем внутри той же группы юрлиц (BK_VM / PS), чтобы не подтянуть карточку чужой группы.
            $leGroup = getEntityGroup($le);
            $entitiesInGroup = getEntitiesInGroup($leGroup);
            $grpPh = implode(',', array_fill(0, count($entitiesInGroup), '?'));
            $ps = $pdo->prepare("SELECT name, category, supplier FROM products WHERE sku = ? AND legal_entity IN ($grpPh) ORDER BY (legal_entity = ?) DESC, is_active DESC, id ASC LIMIT 1");
            $ps->execute(array_merge([$sku], $entitiesInGroup, [$le]));
            $prod = $ps->fetch();
            $prodName = $prod ? $prod['name'] : $sku;
            $warehouse = '';
            if ($prod) {
                $cat = $prod['category'] ?? '';
                if ($cat === 'Мороз' || $cat === 'Холод') $warehouse = 'Холод+Мороз';
                else $warehouse = 'Сухой';
            }
            $items[] = [
                'sku' => $sku,
                'product_name' => $prodName,
                'supplier' => $prod['supplier'] ?? '',
                'warehouse' => $warehouse,
                'warehouse_error' => false,
                'warehouse_error_details' => '',
                'legal_entity' => $le,
                'stock_qty' => 0,
                'ordered_qty' => $orderedQty,
                'remaining' => -$orderedQty,
            ];
        }

        roRespond(['items' => $items, 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
    }

    // --- Доступные даты остатков ---
    if ($adminAction === 'stock-dates' && $method === 'GET') {
        $s = $pdo->query("SELECT DISTINCT balance_date FROM ro_stock_balances ORDER BY balance_date DESC LIMIT 30");
        $dates = array_column($s->fetchAll(), 'balance_date');
        roRespond(['dates' => $dates]);
    }

    // --- Неизвестные штрихкоды: список ---
    if ($adminAction === 'scan-unknown' && $method === 'GET' && !$adminParam) {
        $status = $_GET['status'] ?? 'new';
        $group = strtoupper(trim((string)($_GET['group'] ?? '')));
        $search = trim((string)($_GET['search'] ?? ''));

        $where = [];
        $params = [];
        if ($status && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($group === 'BK_VM' || $group === 'PS') {
            $where[] = 'legal_entity_group = ?';
            $params[] = $group;
        }
        if ($search !== '') {
            $where[] = '(gtin LIKE ? OR restaurant_number LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT id, gtin, restaurant_number, legal_entity_group, seen_count,
                       first_seen, last_seen, status, notes,
                       reporter_name, reporter_comment,
                       CASE WHEN reporter_photo_path IS NOT NULL AND reporter_photo_path <> ''
                            THEN 1 ELSE 0 END AS has_photo
                FROM ro_scan_unknown";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY last_seen DESC LIMIT 500';

        $s = $pdo->prepare($sql);
        $s->execute($params);
        roRespond(['items' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // --- Неизвестные штрихкоды: счётчик новых (для бейджа) ---
    if ($adminAction === 'scan-unknown-count-new' && $method === 'GET') {
        $s = $pdo->query("SELECT COUNT(*) FROM ro_scan_unknown WHERE status = 'new'");
        $count = (int)$s->fetchColumn();
        roRespond(['count' => $count]);
    }

    // --- Неизвестные штрихкоды: отдача фото ---
    if ($adminAction === 'scan-unknown' && $method === 'GET' && $adminParam && ($roParts[4] ?? '') === 'photo') {
        $id = (int)$adminParam;
        if ($id <= 0) roRespond(['error' => 'Bad id'], 400);

        $s = $pdo->prepare("SELECT reporter_photo_path FROM ro_scan_unknown WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row || empty($row['reporter_photo_path'])) {
            roRespond(['error' => 'Фото не найдено'], 404);
        }

        $uploadBase = __DIR__ . '/../uploads/scan_unknown';
        $absPath = realpath($uploadBase . '/' . $row['reporter_photo_path']);
        $baseReal = realpath($uploadBase);
        // Защита от path traversal: реальный путь должен быть внутри uploadBase
        if (!$absPath || !$baseReal || strpos($absPath, $baseReal) !== 0 || !is_file($absPath)) {
            roRespond(['error' => 'Файл недоступен'], 404);
        }

        $mime = mime_content_type($absPath) ?: 'application/octet-stream';
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($absPath));
        header('Cache-Control: private, max-age=3600');
        readfile($absPath);
        exit;
    }

    // --- Неизвестные штрихкоды: сменить статус ---
    if ($adminAction === 'scan-unknown' && $method === 'POST' && $adminParam) {
        $id = (int)$adminParam;
        $op = $roParts[4] ?? '';
        if ($id <= 0) roRespond(['error' => 'Bad id'], 400);

        if ($op === 'status') {
            $newStatus = (string)($body['status'] ?? '');
            if (!in_array($newStatus, ['new', 'resolved', 'ignored'], true)) {
                roRespond(['error' => 'Недопустимый статус'], 400);
            }

            // Сначала читаем текущую строку для уведомления
            $curSt = $pdo->prepare("SELECT status, gtin, restaurant_number, legal_entity_group, reporter_name FROM ro_scan_unknown WHERE id = ?");
            $curSt->execute([$id]);
            $row = $curSt->fetch();
            if (!$row) roRespond(['error' => 'Запись не найдена'], 404);

            $oldStatus = $row['status'];

            $s = $pdo->prepare("UPDATE ro_scan_unknown SET status = ? WHERE id = ?");
            $s->execute([$newStatus, $id]);

            // Уведомляем ресторан, только если переводим в resolved из другого статуса
            $notified = false;
            if ($newStatus === 'resolved' && $oldStatus !== 'resolved') {
                $gtinSafe = htmlspecialchars($row['gtin'], ENT_QUOTES, 'UTF-8');
                $reporter = trim((string)($row['reporter_name'] ?? ''));
                $message = "✅ <b>Штрихкод добавлен в базу</b>\n"
                         . "Код: <code>{$gtinSafe}</code>";
                if ($reporter !== '') {
                    $message .= "\nТовар: " . htmlspecialchars($reporter, ENT_QUOTES, 'UTF-8');
                }
                $message .= "\n\nСпасибо за сигнал! Теперь при сканировании товар будет находиться.";
                try {
                    roNotifyRestaurant(
                        $pdo,
                        (int)$row['restaurant_number'],
                        $message,
                        $row['legal_entity_group']
                    );
                    $notified = true;
                } catch (Exception $e) {
                    error_log('[scan-unknown status] notify error: ' . $e->getMessage());
                }
            }

            roRespond(['success' => true, 'notified' => $notified]);
        }

        if ($op === 'notes') {
            $notes = trim((string)($body['notes'] ?? ''));
            if (strlen($notes) > 500) $notes = substr($notes, 0, 500);
            $s = $pdo->prepare("UPDATE ro_scan_unknown SET notes = ? WHERE id = ?");
            $s->execute([$notes === '' ? null : $notes, $id]);
            roRespond(['success' => true]);
        }

        roRespond(['error' => 'Unknown op'], 400);
    }

    // --- Подписчики на уведомления: список + кандидаты ---
    if ($adminAction === 'scan-unknown-subscribers' && $method === 'GET') {
        global $ROLE_TEMPLATES;

        $cur = $pdo->query("SELECT user_name FROM ro_scan_unknown_subscribers ORDER BY user_name");
        $current = $cur->fetchAll(PDO::FETCH_COLUMN);

        // Кандидаты: пользователи с привязанным Telegram + доступом к модулю restaurant-orders
        $cand = $pdo->query("
            SELECT name, role, display_role, telegram_chat_id, permissions
            FROM users
            WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''
            ORDER BY name
        ");
        $rows = $cand->fetchAll(PDO::FETCH_ASSOC);

        $candidates = [];
        foreach ($rows as $r) {
            $perms = resolvePermissions($r['role'] ?? 'user', $r['permissions'] ?? null, $ROLE_TEMPLATES);
            $level = $perms['restaurant-orders'] ?? 'none';
            if ($level && $level !== 'none') {
                $candidates[] = [
                    'name' => $r['name'],
                    'role' => $r['role'],
                    'display_role' => $r['display_role'],
                    'access_level' => $level,
                ];
            }
        }

        roRespond(['subscribers' => $current, 'candidates' => $candidates]);
    }

    // --- Подписчики на уведомления: сохранить ---
    if ($adminAction === 'scan-unknown-subscribers' && $method === 'POST') {
        $list = $body['subscribers'] ?? [];
        if (!is_array($list)) $list = [];

        $names = [];
        foreach ($list as $n) {
            $n = trim((string)$n);
            if ($n !== '') $names[$n] = true;
        }
        $names = array_keys($names);

        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM ro_scan_unknown_subscribers");
            if (!empty($names)) {
                $ph = implode(',', array_fill(0, count($names), '?'));
                $valid = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
                $valid->execute($names);
                $validNames = $valid->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($validNames)) {
                    $ins = $pdo->prepare("INSERT INTO ro_scan_unknown_subscribers (user_name) VALUES (?)");
                    foreach ($validNames as $n) $ins->execute([$n]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('ro scan-unknown subscribers save error: ' . $e->getMessage());
            roRespond(['error' => 'Не удалось сохранить подписчиков. Попробуйте ещё раз.'], 500);
        }

        $cur = $pdo->query("SELECT user_name FROM ro_scan_unknown_subscribers ORDER BY user_name");
        roRespond(['success' => true, 'subscribers' => $cur->fetchAll(PDO::FETCH_COLUMN)]);
    }

    roRespond(['error' => 'Not found'], 404);
}

// Если дошли сюда — неизвестный маршрут
roRespond(['error' => 'Not found'], 404);
