<?php
/**
 * API возврата кег.
 * Подключается из index.php. Переменные ($pdo, $endpoint, $subpoint, $method, $body, $parts) через global.
 *
 * Маршруты:
 *   GET    keg-catalog              — список кег
 *   GET    keg-returns              — список заявок
 *   POST   keg-returns              — создать DRAFT
 *   GET    keg-returns/{id}         — детально
 *   PATCH  keg-returns/{id}         — обновить
 *   POST   keg-returns/{id}/submit       — DRAFT → SUBMITTED
 *   POST   keg-returns/{id}/cancel       — → CANCELLED
 *   POST   keg-returns/{id}/replace-bso  — заменить БСО (10:00–15:00, со списком в историю)
 *   GET    keg-returns/{id}/excel        — скачать Excel ТТН
 */

if ($endpoint !== 'keg-returns' && $endpoint !== 'keg-catalog') return;

// Принимаем токен из query-параметра только для эндпоинтов скачивания
// (Excel/печать/шаблон) — там окно открывается через window.open() и
// заголовки слать нельзя. На остальных маршрутах query-token игнорируется,
// потому что попадает в access-логи nginx и Referer.
$krQueryTokenAllowed = false;
if (!empty($parts[2]) && in_array($parts[2], ['excel', 'print'], true)) {
    $krQueryTokenAllowed = true;
} elseif (!empty($parts[1]) && in_array($parts[1], ['import-template', 'import-template.xlsx'], true)) {
    $krQueryTokenAllowed = true;
}

if ($krQueryTokenAllowed) {
    if (!empty($_GET['token']) && empty($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        $_SERVER['HTTP_X_SESSION_TOKEN'] = $_GET['token'];
    }
    if (!empty($_GET['ro_token']) && empty($_SERVER['HTTP_X_RO_TOKEN'])) {
        $_SERVER['HTTP_X_RO_TOKEN'] = $_GET['ro_token'];
    }
    // Снижаем ущерб от попадания токена в логи: убираем из суперглобала и
    // запрещаем кеш/реферер на этот ответ.
    unset($_GET['token'], $_GET['ro_token']);
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Referrer-Policy: no-referrer');
}

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;

// ═══ Хелперы ═══

function krRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/**
 * Проверка прав портала на модуль «Заказы ресторанов» (вкл. возврат кег).
 * minLevel: view | edit | full.
 */
function krRequirePortalAccess(?array $portalUser, string $minLevel = 'view'): void {
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    if (!$portalUser) krRespond(['error' => 'Требуется авторизация портала'], 401);
    $perms = resolvePermissions($portalUser['role'] ?? 'user', $portalUser['permissions'] ?? null, $ROLE_TEMPLATES);
    $actual = $ACCESS_LEVELS[$perms['keg-returns'] ?? 'none'] ?? 0;
    $required = $ACCESS_LEVELS[$minLevel] ?? 0;
    if ($actual < $required) krRespond(['error' => 'Недостаточно прав'], 403);
}

/**
 * Проверка доступа к группе юрлиц (BK_VM / PS) для пользователя портала.
 * Админ — всегда ок. Остальным — только если их legal_entities содержат
 * хотя бы одно юрлицо этой группы.
 */
function krRequireGroupAccess(?array $portalUser, ?string $group): void {
    if (!$portalUser) krRespond(['error' => 'Требуется авторизация'], 401);
    if (($portalUser['role'] ?? '') === 'admin') return;
    $allowed = roGetSessionUserGroups($portalUser);
    if (!$group || !in_array($group, $allowed, true)) {
        krRespond(['error' => 'Нет доступа к данным этого юрлица'], 403);
    }
}

/**
 * Возвращает DateTime (10:00 Europe/Minsk) последнего рабочего дня перед return_date.
 */
function kegCalcDeadline(string $returnDate): DateTime {
    $tz = new DateTimeZone('Europe/Minsk');
    $d = new DateTime($returnDate, $tz);
    $d->setTime(10, 0, 0);
    do {
        $d->modify('-1 day');
    } while (in_array((int)$d->format('N'), [6, 7]));
    return $d;
}

/**
 * Cutoff (15:00 того же рабочего дня, что и deadline 10:00) — последний момент,
 * когда ресторан ещё может заменить испорченный БСО через спец-эндпоинт.
 * После cutoff менять БСО нельзя (заявки уходят лог-провайдеру финально).
 */
function kegCalcCutoff(string $returnDate): DateTime {
    $d = kegCalcDeadline($returnDate);
    $d->setTime(15, 0, 0);
    return $d;
}

/**
 * Сессия ресторана через cookie ro_session или legacy X-RO-Token.
 * Сессии живут в ro_user_sessions (мультисессии, см. helpers.php).
 */
function krGetRestaurantSession($pdo) {
    $user = roReadActiveSessionRow($pdo);
    if (!$user) return null;
    $rest = krGetRestaurantByNumber($pdo, $user['restaurant_number'], $user['legal_entity_group'] ?? null);
    $user['restaurant_id'] = isset($rest['id']) ? (int)$rest['id'] : null;
    $user['pickup_address'] = $rest['pickup_address'] ?? null;
    if (empty($user['legal_entity_group']) && !empty($rest['legal_entity_group'])) {
        $user['legal_entity_group'] = $rest['legal_entity_group'];
    }
    return $user;
}

function krGetRestaurantByNumber($pdo, $restaurantNumber, $group = null) {
    $g = ($group && in_array(strtoupper($group), ['BK_VM', 'PS'])) ? strtoupper($group) : 'BK_VM';
    $s = $pdo->prepare("
        SELECT id, number, region, city, address, pickup_address, pickup_weekdays, legal_entity_group
        FROM restaurants
        WHERE number = ? AND active = 1 AND legal_entity_group = ?
        LIMIT 1
    ");
    $s->execute([(int)$restaurantNumber, $g]);
    return $s->fetch() ?: null;
}

function krGetReturnWithItems($pdo, $id) {
    $s = $pdo->prepare("
        SELECT kr.*,
               r.number AS restaurant_number, r.city AS restaurant_city,
               r.address AS restaurant_address, r.pickup_address,
               r.legal_entity_group AS restaurant_leg,
               r.pickup_weekdays AS restaurant_pickup_weekdays
        FROM keg_returns kr
        JOIN restaurants r ON r.id = kr.restaurant_id
        WHERE kr.id = ?
    ");
    $s->execute([(int)$id]);
    $row = $s->fetch();
    if (!$row) return null;

    $items = $pdo->prepare("
        SELECT kri.id, kri.keg_code, kri.quantity,
               COALESCE((SELECT p.name FROM products p
                          WHERE p.sku = kri.keg_code AND p.legal_entity_group = ?
                          ORDER BY p.is_active DESC, p.id ASC LIMIT 1),
                        kc.name) AS keg_name
        FROM keg_return_items kri
        JOIN keg_catalog kc ON kc.code = kri.keg_code
        WHERE kri.request_id = ?
        ORDER BY kc.sort_order, kri.keg_code
    ");
    $items->execute([$row['legal_entity_group'] ?? 'BK_VM', (int)$id]);
    $row['items'] = $items->fetchAll();

    // История замен БСО (старые номера → текущий). Используется в карточке
    // заявки на ресторане и в модалке закупок.
    try {
        $hStmt = $pdo->prepare("
            SELECT id, old_series, old_number, new_series, new_number,
                   reason, changed_by_ru_user_id, changed_by_user, changed_at
            FROM keg_return_bso_history
            WHERE request_id = ?
            ORDER BY changed_at, id
        ");
        $hStmt->execute([(int)$id]);
        $row['bso_history'] = $hStmt->fetchAll();
    } catch (Throwable $e) {
        // если миграция ещё не применена — пустой массив
        $row['bso_history'] = [];
    }

    // Реквизиты юрлица-отправителя.
    // BK_VM: ресторан №3 → ВМ, остальные → БК (см. api/includes/legal_entities.php).
    $row['legal_entity_code'] = krLegalCodeForRestaurant((int)$row['restaurant_number'], $row['restaurant_leg'] ?? 'BK_VM');
    $led = $pdo->prepare("SELECT * FROM legal_entity_details WHERE legal_entity_code = ?");
    $led->execute([$row['legal_entity_code']]);
    $row['legal_entity_details'] = $led->fetch() ?: null;

    krAddDeadline($row);

    return $row;
}

function krLegalCodeForRestaurant(int $restaurantNumber, string $group): string {
    if ($group === 'PS') return 'PS';
    return $restaurantNumber === 3 ? 'VM' : 'BK';
}

/**
 * Отправляет TG-уведомление всем подписчикам ресторана.
 */
/**
 * Отправляет ресторану полное уведомление о маршрутизации:
 * текстовое сообщение + PDF-файл ТТН.
 */
function krNotifyRouted(PDO $pdo, array $row): void {
    $bsoStr  = trim(($row['bso_series'] ?? '') . ' ' . ($row['bso_number'] ?? ''));
    $bsoDate = !empty($row['return_date']) ? date('d.m.Y', strtotime($row['return_date'])) : '';
    $vehicle = trim((string)($row['vehicle'] ?? '')) ?: '—';
    $driver  = trim((string)($row['driver']  ?? '')) ?: '—';
    $link    = 'https://supply-department.online/restaurant/keg-returns?id=' . (int)$row['id'];
    // По новой логике ТТН печатается заранее, до маршрутизации — у ресторана
    // на руках уже бланк, не хватает только машины и водителя. Сообщаем эти
    // два поля прямо в первой строке, чтобы их можно было сразу вписать.
    $tgMsg   = '✅ Заявка №' . $bsoStr . ' от ' . $bsoDate . ' маршрутизирована.' . "\n"
             . 'Машина: ' . $vehicle . "\n"
             . 'Водитель: ' . $driver . "\n"
             . 'Впишите эти данные в уже распечатанную ТТН и возьмите подпись водителя.' . "\n"
             . 'Открыть заявку: ' . $link;
    try {
        krNotifyRestaurant($pdo, (int)$row['restaurant_id'], $tgMsg);
    } catch (Throwable $e) {
        error_log('krNotifyRouted text failed for #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
    // Web Push ресторану (рядом с Telegram).
    try {
        if (!function_exists('pushSendToRestaurant')) {
            require_once __DIR__ . '/push_send.php';
        }
        $rNum = $pdo->prepare("SELECT number, legal_entity_group FROM restaurants WHERE id = ?");
        $rNum->execute([(int)$row['restaurant_id']]);
        $rr = $rNum->fetch();
        if ($rr && (int)$rr['number'] > 0) {
            pushSendToRestaurant($pdo, (int)$rr['number'], (string)($rr['legal_entity_group'] ?: 'BK_VM'), [
                'title' => '✅ Маршрутизация: ' . $bsoStr,
                'body'  => 'Машина: ' . $vehicle . ' · Водитель: ' . $driver . '. Впишите в распечатанную ТТН.',
                'url'   => '/restaurant/keg-returns?id=' . (int)$row['id'],
                'tag'   => 'keg-routed-' . (int)$row['id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('krNotifyRouted push failed for #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
    // PDF best-effort
    try {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) return;
        $rNum = $pdo->prepare("SELECT number FROM restaurants WHERE id = ?");
        $rNum->execute([(int)$row['restaurant_id']]);
        $rNumber = (int)$rNum->fetchColumn();
        if (!$rNumber) return;
        $subStmt = $pdo->prepare("
            SELECT DISTINCT chat_id FROM ro_telegram_subs
            WHERE restaurant_number = ?
              AND chat_id IS NOT NULL AND chat_id != ''
              AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
        ");
        $subStmt->execute([$rNumber]);
        $chatIds = array_column($subStmt->fetchAll(), 'chat_id');
        if (!$chatIds) return;
        $pdfContent = krGeneratePdf($row);
        if ($pdfContent === false) return;
        $pdfName = 'ТТН ' . ($row['bso_series'] ?: 'X') . ' ' . ($row['bso_number'] ?: '0') . '.pdf';
        foreach ($chatIds as $chatId) {
            sendTelegramDocument($botToken, $chatId, $pdfName, $pdfContent, 'ТТН №' . $bsoStr);
        }
    } catch (Throwable $e) {
        error_log('krNotifyRouted PDF failed for #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
}

/**
 * Уведомление ресторана об отмене маршрутизации (ROUTED → SUBMITTED).
 * Шлём текстовое сообщение в Telegram и web-push. PDF не дёргаем — он был
 * актуален только под маршрутизированный статус.
 */
function krNotifyUnrouted(PDO $pdo, array $row): void {
    $bsoStr  = trim(($row['bso_series'] ?? '') . ' ' . ($row['bso_number'] ?? ''));
    $bsoDate = !empty($row['return_date']) ? date('d.m.Y', strtotime($row['return_date'])) : '';
    $link    = 'https://supply-department.online/restaurant/keg-returns?id=' . (int)$row['id'];
    $tgMsg   = '↩️ Маршрутизация по ТТН №' . $bsoStr . ' от ' . $bsoDate . ' отменена.' . "\n"
             . 'Ожидайте нового назначения водителя и машины.' . "\n"
             . 'Открыть: ' . $link;
    try {
        krNotifyRestaurant($pdo, (int)$row['restaurant_id'], $tgMsg);
    } catch (Throwable $e) {
        error_log('krNotifyUnrouted text failed for #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
    try {
        if (!function_exists('pushSendToRestaurant')) {
            require_once __DIR__ . '/push_send.php';
        }
        $rNum = $pdo->prepare("SELECT number, legal_entity_group FROM restaurants WHERE id = ?");
        $rNum->execute([(int)$row['restaurant_id']]);
        $rr = $rNum->fetch();
        if ($rr && (int)$rr['number'] > 0) {
            pushSendToRestaurant($pdo, (int)$rr['number'], (string)($rr['legal_entity_group'] ?: 'BK_VM'), [
                'title' => '↩️ Маршрутизация отменена',
                'body'  => 'ТТН ' . $bsoStr . ' от ' . $bsoDate . '. Ожидайте нового назначения водителя и машины.',
                'url'   => '/restaurant/keg-returns?id=' . (int)$row['id'],
                'tag'   => 'keg-unrouted-' . (int)$row['id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('krNotifyUnrouted push failed for #' . (int)$row['id'] . ': ' . $e->getMessage());
    }
}

function krNotifyRestaurant(PDO $pdo, int $restaurantId, string $text) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;
    $r = $pdo->prepare("SELECT number FROM restaurants WHERE id = ?");
    $r->execute([$restaurantId]);
    $number = (int)$r->fetchColumn();
    if (!$number) return;
    $s = $pdo->prepare("
        SELECT DISTINCT chat_id FROM ro_telegram_subs
        WHERE restaurant_number = ?
          AND chat_id IS NOT NULL AND chat_id != ''
          AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
    ");
    $s->execute([$number]);
    $chatIds = array_column($s->fetchAll(), 'chat_id');
    if (!$chatIds) {
        error_log("krNotifyRestaurant: no chat_ids for restaurant #$number");
        return;
    }
    foreach ($chatIds as $chatId) {
        sendTelegramMessage($botToken, $chatId, $text);
    }
}

/**
 * Письмо бухгалтерии при переводе заявки в статус «Не сдана».
 * Адреса берём из settings (key='keg_not_returned_emails', через запятую).
 * Если адреса не заданы — тихо ничего не делаем.
 */
function krNotifyAccountingNotReturned(PDO $pdo, ?array $row, string $by): void {
    if (!$row) return;
    $emails = (string)($pdo->query("SELECT value FROM settings WHERE `key` = 'keg_not_returned_emails'")->fetchColumn() ?: '');
    $to = preg_split('/[\s,;]+/', trim($emails), -1, PREG_SPLIT_NO_EMPTY);
    if (!$to) return;

    require_once __DIR__ . '/mail_send.php';
    require_once __DIR__ . '/mail_templates.php';
    if (!function_exists('sendEmail') || !function_exists('renderMailHtml')) return;

    $num  = (string)($row['restaurant_number'] ?? '');
    $date = !empty($row['return_date']) ? date('d.m.Y', strtotime($row['return_date'])) : '';
    $bso  = trim(($row['bso_series'] ?? '') . ' ' . ($row['bso_number'] ?? ''));
    $city = trim((string)($row['restaurant_city'] ?? ''));
    $kegs = 0;
    foreach (($row['items'] ?? []) as $it) $kegs += (int)($it['quantity'] ?? 0);

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Строки таблицы с реквизитами заявки
    $rowsHtml = '';
    $addRow = function ($label, $val) use (&$rowsHtml, $esc) {
        if ($val === '' || $val === null) return;
        $rowsHtml .= '<tr>'
            . '<td style="padding:7px 16px 7px 0;color:#8B7355;font-size:14px;white-space:nowrap;vertical-align:top;">' . $esc($label) . '</td>'
            . '<td style="padding:7px 0;color:#2C1A12;font-size:14px;font-weight:600;">' . $esc($val) . '</td>'
            . '</tr>';
    };
    $addRow('Ресторан', '№' . $num . ($city ? ' · ' . $city : ''));
    $addRow('Дата возврата', $date);
    $addRow('ТТН', $bso);
    $addRow('Кег в заявке', $kegs ? (string)$kegs : '');
    $addRow('Причина', trim((string)($row['not_returned_reason'] ?? '')));
    $addRow('Отметил', $by);

    $body = '<p style="margin:0 0 18px;font-size:15px;color:#2C1A12;line-height:1.55;">'
          . 'Ресторан <b>№' . $esc($num) . '</b> не сдал кеги по маршрутизированной заявке на возврат. '
          . 'Заявка переведена в статус «Не сдана».</p>'
          . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#FBF7F0;border:1px solid #ECE3D6;border-radius:12px;">'
          . '<tr><td style="padding:6px 18px;">'
          . '<table role="presentation" cellspacing="0" cellpadding="0" border="0">' . $rowsHtml . '</table>'
          . '</td></tr></table>';

    $subject = "Кеги не сданы — ресторан №{$num}" . ($date ? " ({$date})" : '');
    $html = renderMailHtml([
        'title'   => 'Кеги не сданы',
        'preview' => 'Ресторан №' . $num . ' не сдал кеги' . ($date ? " ({$date})" : ''),
        'body'    => $body,
        'footer'  => 'Письмо сформировано автоматически порталом отдела закупок.',
    ]);
    try { sendEmail($to, $subject, $html, true, ['account' => 'default']); }
    catch (Throwable $e) { error_log('krNotifyAccountingNotReturned: ' . $e->getMessage()); }
}

/**
 * Валидация серии и номера БСО.
 * Серия: ровно 2 заглавные кириллические буквы.
 * Номер: ровно 7 цифр.
 */
/**
 * Проверяет, включён ли модуль возврата кег для конкретного ресторана.
 * Возвращает массив [enabled, reason]: enabled=true, если оба флага активны
 * (на ресторане и на юрлице).
 */
function krGetReturnsEnabledStatus(PDO $pdo, int $restaurantId, string $legalEntityGroup): array {
    // Глобальный флаг (по юрлицу). Используем legal_entity_group через
    // соответствующее юрлицо: для BK_VM берём «ООО "Бургер БК"».
    $globalEnabled = true;
    try {
        $legalEntity = ($legalEntityGroup === 'PS') ? 'ООО "Пицца Стар"' : 'ООО "Бургер БК"';
        $s = $pdo->prepare("SELECT keg_returns_enabled FROM ro_module_settings WHERE legal_entity = ? LIMIT 1");
        $s->execute([$legalEntity]);
        $row = $s->fetch();
        if ($row && (int)$row['keg_returns_enabled'] !== 1) $globalEnabled = false;
    } catch (Throwable $e) {
        // если миграция ещё не применена — считаем включённым
    }
    if (!$globalEnabled) {
        return ['enabled' => false, 'reason' => 'Возврат кег временно отключён отделом закупок'];
    }
    $restEnabled = true;
    try {
        $s = $pdo->prepare("SELECT keg_returns_enabled FROM restaurants WHERE id = ?");
        $s->execute([$restaurantId]);
        $val = $s->fetchColumn();
        if ($val !== false && (int)$val !== 1) $restEnabled = false;
    } catch (Throwable $e) { /* колонка ещё не добавлена */ }
    if (!$restEnabled) {
        return ['enabled' => false, 'reason' => 'Возврат кег для этого ресторана отключён'];
    }
    return ['enabled' => true, 'reason' => null];
}

function krStatusLabel(?string $status): string {
    return [
        'DRAFT'        => 'Черновик',
        'SUBMITTED'    => 'Отправлена',
        'ROUTED'       => 'Маршрутизирована',
        'CANCELLED'    => 'Отменена',
        'NOT_RETURNED' => 'Не сдана',
    ][(string)$status] ?? 'неизвестно';
}

function krValidateBso(?string $series, ?string $number): ?string {
    $s = trim((string)$series);
    $n = trim((string)$number);
    if ($s === '' && $n === '') return null; // допустимо в DRAFT
    if (!preg_match('/^[А-ЯЁ]{2}$/u', $s)) return 'Серия БСО — две заглавные кириллические буквы';
    if (!preg_match('/^\d{7}$/', $n)) return 'Номер БСО — ровно 7 цифр';
    return null;
}

/**
 * Валидация дня недели: return_date должна попадать в pickup_weekdays маски.
 * Маска: бит 0 = Пн, бит 6 = Вс.
 */
function krValidateReturnDate(?string $date, int $weekdaysMask): ?string {
    if (!$date) return 'Укажите дату возврата';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Неверный формат даты';
    if ($weekdaysMask === 0) return null; // не задано — не блокируем
    $tz = new DateTimeZone('Europe/Minsk');
    $d = new DateTime($date, $tz);
    $weekday = (int)$d->format('N') - 1; // 0=Пн, 6=Вс
    if (!($weekdaysMask & (1 << $weekday))) {
        $names = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
        $allowed = [];
        for ($i = 0; $i < 7; $i++) if ($weekdaysMask & (1 << $i)) $allowed[] = $names[$i];
        return 'Дата возврата должна быть: ' . implode(', ', $allowed);
    }
    return null;
}

/**
 * Возвращает pickup_weekdays ресторана по restaurant_id.
 */
function krGetPickupWeekdays(PDO $pdo, int $restaurantId): int {
    $s = $pdo->prepare("SELECT pickup_weekdays FROM restaurants WHERE id = ?");
    $s->execute([$restaurantId]);
    return (int)($s->fetchColumn() ?: 0);
}

/**
 * Добавляет к строке заявки поля deadline_iso, cutoff_iso и can_replace_bso.
 * can_replace_bso = true, если сейчас интервал [deadline 10:00, cutoff 15:00)
 * и статус ∈ {SUBMITTED, ROUTED}. До deadline ресторан правит БСО обычным
 * редактированием (PATCH), после cutoff — нельзя совсем.
 */
function krAddDeadline(array &$row): void {
    if (!empty($row['return_date'])) {
        $deadline = kegCalcDeadline($row['return_date']);
        $cutoff   = kegCalcCutoff($row['return_date']);
        $row['deadline_iso'] = $deadline->format(DateTime::ATOM);
        $row['cutoff_iso']   = $cutoff->format(DateTime::ATOM);
        $now = new DateTime('now', new DateTimeZone('Europe/Minsk'));
        $statusOk = in_array($row['status'] ?? '', ['SUBMITTED', 'ROUTED'], true);
        $row['can_replace_bso'] = $statusOk && $now >= $deadline && $now < $cutoff;
    } else {
        $row['deadline_iso'] = null;
        $row['cutoff_iso']   = null;
        $row['can_replace_bso'] = false;
    }
}

/**
 * Нормализует адрес для матчинга.
 */
function krNormalizeAddress(string $addr): string {
    $addr = mb_strtolower(trim($addr));
    $addr = preg_replace('/\s+/', ' ', $addr);
    $addr = rtrim($addr, '.');
    return $addr;
}

/**
 * Адрес → массив значимых токенов. Используется в нечётком матчинге импорта
 * маршрутизации. Цель — сравнивать адреса, в которых одно и то же место
 * написано по-разному: «г. Минск, пл. Свободы, Дом 17 ресторан» и
 * «Минск, свободы, 17 (немига)» должны дать пересекающиеся токены.
 */
function krAddressTokens(string $addr): array {
    $a = mb_strtolower(trim($addr), 'UTF-8');
    $a = str_replace('ё', 'е', $a);
    // Содержимое в круглых скобках выкидываем — обычно это пояснения вроде
    // «(Немига)», «(Простор)», «(Аэропорт)». Они мешают сравнению.
    $a = preg_replace('/\([^)]*\)/u', ' ', $a);
    // Любая пунктуация и спецсимволы → пробел. Оставляем только буквы и цифры.
    $a = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $a);
    $tokens = preg_split('/\s+/u', trim($a), -1, PREG_SPLIT_NO_EMPTY);
    // Стоп-слова — типовые адресные префиксы и шум, ни о чём не говорящий
    // конкретному адресу. «д» (дом) и «к» (корпус) короткие — отсеялись бы
    // фильтром длины ниже, но оставляю явно для читаемости списка.
    $stop = [
        'г','город','гор',
        'ул','улица',
        'пр','прт','просп','проспект',
        'пл','площадь',
        'пер','переулок',
        'тр','тракт',
        'мкад',
        'д','дом','корп','корпус','к',
        'оф','офис','пом','помещение',
        'ресторан','рест','номер','сектор',
    ];
    return array_values(array_filter($tokens, function($t) use ($stop) {
        if (in_array($t, $stop, true)) return false;
        // Одиночные буквы — шум («т.о.», «дом 15 В /Г»). А вот одиночную цифру
        // выкидывать нельзя: это номер дома («Кирова, 2», «Привокзальная, 7»).
        if (mb_strlen($t, 'UTF-8') <= 1 && !preg_match('/\d/u', $t)) return false;
        return true;
    }));
}

/**
 * Похожесть двух адресов как Jaccard-индекс по их токенам + бонус за
 * совпавший токен с цифрой (это почти всегда номер дома). 0.0 — совсем
 * разные, 1.0 — все значимые слова и номер совпали.
 *
 * Жёсткие правила несовпадения (моментальный 0.0):
 *  — у обеих сторон есть город из списка БК, и города разные;
 *  — у обеих сторон есть номер дома (токен с цифрой), и номера разные.
 * Это защищает от ложных матчей по совпадению только улицы.
 */
function krKnownCities(): array {
    return ['минск','гродно','брест','витебск','гомель','могилев',
        'бобруйск','лида','мозырь','пинск','полоцк','солигорск','барановичи',
        'жлобин','жодино','молодечно'];
}

/**
 * Номера домов в адресе: числовая часть каждого токена с цифрой.
 * «Дом 56\1 Г» → ['56','1'], «19-1, 6 сектор» → ['19','1','6'].
 */
function krAddressHouses(string $addr): array {
    $out = [];
    foreach (krAddressTokens($addr) as $t) {
        if (preg_match('/\d+/u', $t, $m)) $out[] = $m[0];
    }
    return array_values(array_unique($out));
}

/**
 * Название улицы: значимые слова без цифр и без городов.
 * «г. Минск, ул. П. Мстиславца, Дом 11 ресторан» → ['мстиславца'].
 */
function krAddressStreet(string $addr): array {
    $tok = array_filter(krAddressTokens($addr), fn($t) => !preg_match('/\d/u', $t));
    return array_values(array_diff($tok, krKnownCities()));
}

/**
 * Строгое совпадение адресов. Нужно для импорта маршрутизации: файл логистов
 * содержит точки всех юрлиц (в том числе Пиццы Стар), и «похожего» адреса мало —
 * чужую точку нельзя навесить на наш ресторан. Раньше хватало половины общих
 * слов, из-за чего «ул. Притыцкого, 19А» цеплялось к ресторану №57
 * «Аэровокзальная, 19-1»: совпадала только цифра 19.
 *
 * Совпадением считаем только: тот же номер дома И та же улица, при этом города
 * (если известны с обеих сторон) не должны различаться.
 */
function krAddressStrictMatch(string $a, string $b): bool {
    $cities = krKnownCities();
    $aCity = array_intersect(krAddressTokens($a), $cities);
    $bCity = array_intersect(krAddressTokens($b), $cities);
    if (!empty($aCity) && !empty($bCity) && empty(array_intersect($aCity, $bCity))) return false;

    $aHouse = krAddressHouses($a);
    $bHouse = krAddressHouses($b);
    if (empty($aHouse) || empty($bHouse)) return false;
    if (empty(array_intersect($aHouse, $bHouse))) return false;

    $aStreet = krAddressStreet($a);
    $bStreet = krAddressStreet($b);
    if (empty($aStreet) || empty($bStreet)) return false;
    return !empty(array_intersect($aStreet, $bStreet));
}

function krAddressMatchScore(string $a, string $b): float {
    $aTok = krAddressTokens($a);
    $bTok = krAddressTokens($b);
    if (empty($aTok) || empty($bTok)) return 0.0;
    $aSet = array_values(array_unique($aTok));
    $bSet = array_values(array_unique($bTok));

    // Города из active BK_VM рестораны. Если у обеих сторон город есть и
    // они разные — это разные адреса, что бы там по словам не пересекалось.
    $cities = krKnownCities();
    $aCities = array_intersect($aSet, $cities);
    $bCities = array_intersect($bSet, $cities);
    if (!empty($aCities) && !empty($bCities) && empty(array_intersect($aCities, $bCities))) {
        return 0.0;
    }

    // Номер дома — токен с цифрой. Если у обеих сторон есть, и не пересекаются,
    // это разные дома (одна улица не считается «тот же адрес»).
    $aDigits = array_filter($aSet, fn($t) => (bool)preg_match('/\d/u', $t));
    $bDigits = array_filter($bSet, fn($t) => (bool)preg_match('/\d/u', $t));
    $digitInter = array_intersect($aDigits, $bDigits);
    if (!empty($aDigits) && !empty($bDigits) && empty($digitInter)) {
        return 0.0;
    }

    $inter = array_values(array_intersect($aSet, $bSet));
    $union = array_values(array_unique(array_merge($aSet, $bSet)));
    $jaccard = count($union) > 0 ? count($inter) / count($union) : 0.0;
    if (!empty($digitInter)) $jaccard += 0.2;
    return min(1.0, $jaccard);
}

function krInsertItems($pdo, $requestId, array $items) {
    $stmt = $pdo->prepare("
        INSERT INTO keg_return_items (request_id, keg_code, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    ");
    foreach ($items as $item) {
        $stmt->execute([(int)$requestId, trim($item['keg_code']), (int)$item['quantity']]);
    }
}

function krValidateItems(array $items): ?string {
    if (empty($items)) return 'Укажите хотя бы одну позицию кег';
    foreach ($items as $i => $item) {
        if (empty($item['keg_code'])) return "Позиция #" . ($i + 1) . ": не указан код кеги";
        if (!isset($item['quantity']) || (int)$item['quantity'] <= 0) return "Позиция #" . ($i + 1) . ": quantity должен быть > 0";
    }
    return null;
}

// ═══ Авторизация (общая для keg-catalog и keg-returns) ═══

// Определяем режим: ресторан или портал.
// Хотя бы одна авторизация обязательна для всех keg-* эндпоинтов,
// включая справочник кег (раньше был открыт анонимно).
$krRestSession = krGetRestaurantSession($pdo);
$krPortalUser  = getSessionUser($pdo);
if (!$krRestSession && !$krPortalUser) {
    krRespond(['error' => 'Нет авторизации'], 401);
}
// Staff-сессия приоритетнее ресторанной. Если у админа в браузере осталась
// cookie ro_session от теста кабинета — он всё равно работает как сотрудник.
// Иначе админ получал урезанный список заявок без полей restaurant_*.
$isRestaurant = (bool)$krRestSession && !$krPortalUser;

// ═══ Роутинг keg-catalog ═══

if ($endpoint === 'keg-catalog') {
    if ($method !== 'GET') krRespond(['error' => 'Метод не поддерживается'], 405);
    $catGroup = $krRestSession['legal_entity_group']
        ?? (isset($_GET['legal_entity_group']) ? trim($_GET['legal_entity_group']) : 'BK_VM');
    if (!in_array($catGroup, ['BK_VM', 'PS'], true)) $catGroup = 'BK_VM';
    $stmt = $pdo->prepare("
        SELECT kc.code,
               COALESCE((SELECT p.name FROM products p
                          WHERE p.sku = kc.code AND p.legal_entity_group = ?
                          ORDER BY p.is_active DESC, p.id ASC LIMIT 1),
                        kc.name) AS name,
               kc.photo_url, kc.sort_order
        FROM keg_catalog kc
        WHERE kc.active = 1
        ORDER BY kc.sort_order, kc.code
    ");
    $stmt->execute([$catGroup]);
    krRespond($stmt->fetchAll());
}

// ═══ Роутинг keg-returns ═══

// parts: [0]=keg-returns, [1]=id или null, [2]=action или null
$krId = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$krAction = $parts[2] ?? null;

// Проверяем, что parts[1] не содержит нечислового субэндпоинта (import-routing и т.п.)
$krSubSlug = (!$krId && isset($parts[1]) && $parts[1] !== '') ? $parts[1] : null;

// ── GET /keg-returns/export ── xlsx-выгрузка списка для портала
if ($method === 'GET' && $krSubSlug === 'export') {
    if ($isRestaurant) krRespond(['error' => 'Только для портала'], 403);
    krRequirePortalAccess($krPortalUser, 'view');
    $filterGroup = isset($_GET['legal_entity_group']) ? trim($_GET['legal_entity_group']) : 'BK_VM';
    if (!in_array($filterGroup, ['BK_VM', 'PS'])) $filterGroup = 'BK_VM';
    krRequireGroupAccess($krPortalUser, $filterGroup);

    // Доп. фильтры из query-параметров — повторяют фильтры списка на странице,
    // чтобы экспортилось ровно то, что пользователь видит.
    $filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
    if ($filterStatus !== '' && !in_array($filterStatus, ['SUBMITTED', 'ROUTED', 'CANCELLED', 'NOT_RETURNED'], true)) $filterStatus = '';
    $filterRestaurantId = isset($_GET['restaurant_id']) && $_GET['restaurant_id'] !== '' ? (int)$_GET['restaurant_id'] : 0;
    $filterFrom = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
    $filterTo   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : '';
    // Юрлицо внутри группы BK_VM: BK = все рестораны кроме №3, VM = ресторан №3
    // (см. krLegalCodeForRestaurant). Для группы PS не применяется.
    $filterEntity = isset($_GET['legal_entity']) ? trim($_GET['legal_entity']) : '';
    if ($filterGroup !== 'BK_VM' || !in_array($filterEntity, ['BK', 'VM'], true)) $filterEntity = '';

    $where = ["kr.legal_entity_group = ?", "kr.status != 'DRAFT'"];
    $params = [$filterGroup];
    if ($filterStatus !== '')        { $where[] = "kr.status = ?";       $params[] = $filterStatus; }
    if ($filterRestaurantId > 0)     { $where[] = "kr.restaurant_id = ?"; $params[] = $filterRestaurantId; }
    if ($filterFrom !== '')          { $where[] = "kr.return_date >= ?"; $params[] = $filterFrom; }
    if ($filterTo !== '')            { $where[] = "kr.return_date <= ?"; $params[] = $filterTo; }
    if ($filterEntity === 'VM')      { $where[] = "r.number = 3"; }
    elseif ($filterEntity === 'BK')  { $where[] = "r.number <> 3"; }

    // Одна строка = одна позиция кеги в заявке. Заявка с N разными кегами
    // даст N строк. Так видны количества каждой кеги, а не только сумма.
    // Внешний код берём из products.external_code (мап по sku=keg_catalog.code).
    // keg_catalog.code — это артикул кеги, а не внешний код.
    $sql = "
        SELECT kr.id AS request_id,
               kr.return_date, kr.status, kr.bso_series, kr.bso_number,
               kr.vehicle, kr.driver, kr.sender_position_name,
               kr.created_at, kr.routed_at,
               r.number AS restaurant_number, r.city AS restaurant_city,
               r.address AS restaurant_address, r.pickup_address,
               kri.keg_code AS sku, kri.quantity,
               COALESCE((SELECT p2.name
                          FROM products p2
                          WHERE p2.sku = kri.keg_code AND p2.legal_entity_group = kr.legal_entity_group
                          ORDER BY p2.is_active DESC, p2.id ASC
                          LIMIT 1),
                        kc.name) AS keg_name,
               (SELECT p.external_code
                  FROM products p
                  WHERE p.sku = kri.keg_code AND p.legal_entity_group = kr.legal_entity_group
                  ORDER BY p.is_active DESC, p.id ASC
                  LIMIT 1) AS external_code
        FROM keg_returns kr
        JOIN restaurants r ON r.id = kr.restaurant_id
        LEFT JOIN keg_return_items kri ON kri.request_id = kr.id
        LEFT JOIN keg_catalog kc ON kc.code = kri.keg_code
        WHERE " . implode(' AND ', $where) . "
        ORDER BY kr.return_date DESC, kr.id DESC, kri.keg_code
    ";
    $rows = $pdo->prepare($sql);
    $rows->execute($params);
    $list = $rows->fetchAll();

    $statusLabels = ['DRAFT'=>'Черновик','SUBMITTED'=>'Отправлена','ROUTED'=>'Маршрутизирована','CANCELLED'=>'Отменена','NOT_RETURNED'=>'Не сдана'];

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    $headers = [
        'Дата возврата (ТТН)', 'Ресторан', 'Адрес погрузки',
        'Серия БСО', 'Номер БСО', 'Статус',
        'Артикул и наименование', 'Внешний код кеги', 'Количество',
        'Водитель', 'Машина', 'Сдал грузоотправитель',
        'Создана', 'Маршрутизирована',
    ];

    // Заполняет один лист заголовками, строками и оформлением.
    $fillSheet = function ($sh, array $list) use ($headers, $statusLabels) {
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sh->setCellValue($col . '1', $h);
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = 'A1:' . $lastCol . '1';
        $sh->getStyle($headerRange)->getFont()->setBold(true)->setSize(11);
        $sh->getStyle($headerRange)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F4A261');
        $sh->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sh->getStyle($headerRange)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sh->getRowDimension(1)->setRowHeight(34);

        $r = 2;
        foreach ($list as $row) {
            $restName = '№' . (int)$row['restaurant_number'] . ' ' . trim(($row['restaurant_city'] ?? '') . ($row['restaurant_address'] ? ', ' . $row['restaurant_address'] : ''));
            $sh->setCellValue('A' . $r, $row['return_date'] ? date('d.m.Y', strtotime($row['return_date'])) : '');
            $sh->setCellValue('B' . $r, $restName);
            $sh->setCellValue('C' . $r, $row['pickup_address'] ?? '');
            $sh->setCellValue('D' . $r, $row['bso_series'] ?? '');
            $sh->setCellValue('E' . $r, $row['bso_number'] ?? '');
            $sh->setCellValue('F' . $r, $statusLabels[$row['status']] ?? $row['status']);
            // Артикул + Наименование одной строкой (если кеги нет — оставляем пустым)
            $sku = trim((string)($row['sku'] ?? ''));
            $kegName = trim((string)($row['keg_name'] ?? ''));
            $skuName = $sku !== '' && $kegName !== '' ? ($sku . ' ' . $kegName) : ($sku !== '' ? $sku : $kegName);
            $sh->setCellValue('G' . $r, $skuName);
            $sh->setCellValue('H' . $r, $row['external_code'] ?? '');
            $sh->setCellValue('I' . $r, $row['quantity'] !== null ? (int)$row['quantity'] : '');
            $sh->setCellValue('J' . $r, $row['driver'] ?? '');
            $sh->setCellValue('K' . $r, $row['vehicle'] ?? '');
            $sh->setCellValue('L' . $r, $row['sender_position_name'] ?? '');
            $sh->setCellValue('M' . $r, $row['created_at'] ? date('d.m.Y H:i', strtotime($row['created_at'])) : '');
            $sh->setCellValue('N' . $r, $row['routed_at'] ? date('d.m.Y H:i', strtotime($row['routed_at'])) : '');
            // Заливка по статусу
            $color = ['SUBMITTED' => 'FFF3E0', 'ROUTED' => 'E8F5E9', 'CANCELLED' => 'FCE4EC', 'NOT_RETURNED' => 'FDE0DC'][$row['status']] ?? 'FFFFFF';
            $sh->getStyle('A' . $r . ':' . $lastCol . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            // Внешний код кеги — текстовый формат, чтобы Excel не превращал «900000123» в число с потерей ведущих нулей.
            $sh->getStyle('H' . $r)->getNumberFormat()->setFormatCode('@');
            $r++;
        }
        // Автоширина колонок
        foreach (range('A', $lastCol) as $col) {
            $sh->getColumnDimension($col)->setAutoSize(true);
        }
        // Границы по всему диапазону
        if ($r > 2) {
            $sh->getStyle('A1:' . $lastCol . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        }
        $sh->freezePane('A2');
    };

    // Один лист. Юрлицо выбирается на портале (БК или ВМ) и приходит отдельным
    // параметром — выгрузка получается отдельным файлом под каждое юрлицо.
    $entityName = $filterEntity === 'VM' ? 'Воглия Матта'
        : ($filterEntity === 'BK' ? 'Бургер БК'
        : ($filterGroup === 'PS' ? 'Пицца Стар' : 'Возврат кег'));
    $sh = $ss->getActiveSheet();
    $sh->setTitle(mb_substr($entityName, 0, 31));
    $fillSheet($sh, $list);

    $fnameTag = $filterEntity !== '' ? $filterEntity : $filterGroup;
    $fname = 'keg-returns_' . $fnameTag . '_' . date('Ymd_Hi') . '.xlsx';
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $w = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
    $w->save('php://output');
    exit;
}

// ── GET /keg-returns/restaurant-info ──
if ($method === 'GET' && $krSubSlug === 'restaurant-info') {
    if (!$isRestaurant) krRespond(['error' => 'Только для ресторана'], 403);
    $r = krGetRestaurantByNumber($pdo, $krRestSession['restaurant_number'], $krRestSession['legal_entity_group'] ?? null);
    if (!$r) krRespond(['error' => 'Ресторан не найден'], 404);
    $infoStmt = $pdo->prepare("SELECT default_vehicle, default_driver FROM restaurants WHERE id = ?");
    $infoStmt->execute([$r['id']]);
    $extra = $infoStmt->fetch() ?: [];
    $status = krGetReturnsEnabledStatus($pdo, (int)$r['id'], $r['legal_entity_group'] ?? 'BK_VM');
    krRespond([
        'restaurant_id'    => (int)$r['id'],
        'restaurant_number'=> (int)$r['number'],
        'pickup_address'   => $r['pickup_address'] ?? '',
        'pickup_weekdays'  => (int)($r['pickup_weekdays'] ?? 0),
        'default_vehicle'  => $extra['default_vehicle'] ?? '',
        'default_driver'   => $extra['default_driver'] ?? '',
        'keg_returns_enabled' => $status['enabled'],
        'keg_returns_disabled_reason' => $status['enabled'] ? null : $status['reason'],
    ]);
}

// ── GET /keg-returns ──
if ($method === 'GET' && $krId === null && $krAction === null && $krSubSlug === null) {
    if ($isRestaurant) {
        $restId = (int)$krRestSession['restaurant_id'];
        $rows = $pdo->prepare("
            SELECT kr.id, kr.return_date, kr.status, kr.bso_series, kr.bso_number,
                   kr.vehicle, kr.driver, kr.sender_position_name, kr.created_at, kr.submitted_at,
                   (SELECT SUM(quantity) FROM keg_return_items WHERE request_id = kr.id) AS total_kegs
            FROM keg_returns kr
            WHERE kr.restaurant_id = ?
            ORDER BY kr.return_date DESC, kr.id DESC
        ");
        $rows->execute([$restId]);
    } else {
        // Portal: фильтр по статусу (не DRAFT) в рамках группы юрлиц пользователя
        krRequirePortalAccess($krPortalUser, 'view');
        $filterGroup = isset($_GET['legal_entity_group']) ? trim($_GET['legal_entity_group']) : 'BK_VM';
        if (!in_array($filterGroup, ['BK_VM', 'PS'])) $filterGroup = 'BK_VM';
        krRequireGroupAccess($krPortalUser, $filterGroup);
        $rows = $pdo->prepare("
            SELECT kr.id, kr.restaurant_id, kr.return_date, kr.status, kr.bso_series, kr.bso_number,
                   kr.vehicle, kr.driver, kr.sender_position_name, kr.created_at, kr.submitted_at, kr.updated_at,
                   r.number AS restaurant_number, r.city AS restaurant_city,
                   r.address AS restaurant_address, r.pickup_address,
                   (SELECT SUM(quantity) FROM keg_return_items WHERE request_id = kr.id) AS total_kegs,
                   (SELECT COUNT(*) FROM keg_return_bso_history WHERE request_id = kr.id) AS bso_replaced_count
            FROM keg_returns kr
            JOIN restaurants r ON r.id = kr.restaurant_id
            WHERE kr.legal_entity_group = ? AND kr.status != 'DRAFT'
            ORDER BY kr.return_date DESC, kr.id DESC
        ");
        $rows->execute([$filterGroup]);
        $list = $rows->fetchAll();
        foreach ($list as &$item) {
            krAddDeadline($item);
        }
        unset($item);
        krRespond($list);
    }
    // Ресторан: просто список без deadline_iso
    krRespond($rows->fetchAll());
}

// ── POST /keg-returns ── создать DRAFT
if ($method === 'POST' && $krId === null && $krAction === null && $krSubSlug === null) {
    $returnDate = trim($body['return_date'] ?? '');
    if (!$returnDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        krRespond(['error' => 'Укажите дату возврата'], 400);
    }

    $items = $body['items'] ?? [];
    $itemsError = krValidateItems($items);
    if ($itemsError) krRespond(['error' => $itemsError], 400);

    if ($isRestaurant) {
        $restaurantId = (int)$krRestSession['restaurant_id'];
        $legalEntityGroup = $krRestSession['legal_entity_group'] ?? 'BK_VM';
        $createdByChatId = null; // не храним chat_id в этой таблице, только restaurant_id
        $createdByUser = null;
    } else {
        krRequirePortalAccess($krPortalUser, 'edit');
        $restaurantId = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;
        if (!$restaurantId) krRespond(['error' => 'Не указан ресторан'], 400);
        // Группа берётся из самого ресторана, чтобы пользователь не мог
        // создать заявку для чужого юрлица передачей произвольного id.
        $rGroupStmt = $pdo->prepare("SELECT legal_entity_group FROM restaurants WHERE id = ? AND active = 1");
        $rGroupStmt->execute([$restaurantId]);
        $legalEntityGroup = $rGroupStmt->fetchColumn();
        if (!$legalEntityGroup) krRespond(['error' => 'Ресторан не найден'], 404);
        krRequireGroupAccess($krPortalUser, $legalEntityGroup);
        $createdByChatId = null;
        $createdByUser = $krPortalUser['name'] ?? null;
    }

    if ($legalEntityGroup !== 'BK_VM') krRespond(['error' => 'Возврат пока доступен только для Бургер БК и Воглия Матта'], 400);

    // Проверяем, что возврат кег включён для этого ресторана и юрлица
    if ($isRestaurant) {
        $kStatus = krGetReturnsEnabledStatus($pdo, $restaurantId, $legalEntityGroup);
        if (!$kStatus['enabled']) krRespond(['error' => $kStatus['reason']], 403);
    }

    $bsoSeries = trim($body['bso_series'] ?? '') ?: null;
    $bsoNumber = trim($body['bso_number'] ?? '') ?: null;
    $senderPositionName = trim($body['sender_position_name'] ?? '');

    // Валидация формата БСО
    $bsoErr = krValidateBso($bsoSeries, $bsoNumber);
    if ($bsoErr) krRespond(['error' => $bsoErr], 422);

    // Валидация дня недели (только для ресторана с заданной маской)
    if ($isRestaurant) {
        $wdMask = krGetPickupWeekdays($pdo, $restaurantId);
        $wdErr = krValidateReturnDate($returnDate, $wdMask);
        if ($wdErr) krRespond(['error' => $wdErr], 422);
    }

    // Проверка уникальности BSO (только если оба заполнены; NULL != NULL в MySQL — дубль невозможен)
    if ($bsoSeries !== null && $bsoNumber !== null) {
        $chk = $pdo->prepare("SELECT id FROM keg_returns WHERE restaurant_id = ? AND bso_series = ? AND bso_number = ?");
        $chk->execute([$restaurantId, $bsoSeries, $bsoNumber]);
        if ($chk->fetch()) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);
    }

    // Подхватываем дефолтные водителя/машину ресторана, если они есть
    $defQ = $pdo->prepare("SELECT default_vehicle, default_driver FROM restaurants WHERE id = ?");
    $defQ->execute([$restaurantId]);
    $def = $defQ->fetch() ?: [];
    $defaultVehicle = !empty($def['default_vehicle']) ? $def['default_vehicle'] : null;
    $defaultDriver  = !empty($def['default_driver'])  ? $def['default_driver']  : null;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO keg_returns (restaurant_id, legal_entity_group, return_date, bso_series, bso_number,
                                     vehicle, driver, sender_position_name, status, created_by_chat_id, created_by_user)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?)
        ");
        $stmt->execute([$restaurantId, $legalEntityGroup, $returnDate, $bsoSeries, $bsoNumber,
                        $defaultVehicle, $defaultDriver, $senderPositionName, $createdByChatId, $createdByUser]);
        $newId = (int)$pdo->lastInsertId();
        krInsertItems($pdo, $newId, $items);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);
        error_log('keg_returns INSERT: ' . $e->getMessage());
        krRespond(['error' => 'Ошибка создания заявки'], 500);
    }

    $row = krGetReturnWithItems($pdo, $newId);
    krRespond($row, 201);
}

// ── GET /keg-returns/{id} ──
if ($method === 'GET' && $krId && $krAction === null) {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);
    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'view');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }
    krRespond($row);
}

// ── PATCH /keg-returns/{id} ──
if ($method === 'PATCH' && $krId && $krAction === null) {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
        if (!in_array($row['status'], ['DRAFT', 'SUBMITTED'])) {
            krRespond(['error' => 'Нельзя редактировать в статусе «' . krStatusLabel($row['status']) . '»'], 422);
        }
        // Проверка дедлайна
        $deadline = kegCalcDeadline($row['return_date']);
        $now = new DateTime('now', new DateTimeZone('Europe/Minsk'));
        if ($now > $deadline) {
            krRespond(['error' => 'Дедлайн истёк, редактирование недоступно'], 422);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'edit');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
        if ($row['status'] === 'CANCELLED') {
            krRespond(['error' => 'Нельзя редактировать отменённую заявку'], 422);
        }
    }

    // Валидация BSO при PATCH если переданы
    if (array_key_exists('bso_series', $body) || array_key_exists('bso_number', $body)) {
        $newSeries4check = array_key_exists('bso_series', $body) ? (trim($body['bso_series']) ?: null) : $row['bso_series'];
        $newNumber4check = array_key_exists('bso_number', $body) ? (trim($body['bso_number']) ?: null) : $row['bso_number'];
        $bsoErr2 = krValidateBso($newSeries4check, $newNumber4check);
        if ($bsoErr2) krRespond(['error' => $bsoErr2], 422);
    }

    // Валидация дня недели при изменении return_date
    if (array_key_exists('return_date', $body)) {
        $newDate4check = trim($body['return_date']);
        $wdMask2 = krGetPickupWeekdays($pdo, (int)$row['restaurant_id']);
        $wdErr2 = krValidateReturnDate($newDate4check, $wdMask2);
        if ($wdErr2) krRespond(['error' => $wdErr2], 422);
    }

    // Собираем обновляемые поля
    $allowed = ['return_date', 'vehicle', 'driver', 'sender_position_name', 'bso_series', 'bso_number'];
    $sets = [];
    $vals = [];
    $bsoFields = ['bso_series', 'bso_number'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "$f = ?";
            $v = is_string($body[$f]) ? trim($body[$f]) : $body[$f];
            $vals[] = (in_array($f, $bsoFields) && $v === '') ? null : $v;
        }
    }

    // Маршрутизация — только по явному флагу _route в теле запроса.
    // Раньше PATCH сам переводил SUBMITTED→ROUTED при заполненных машине и водителе,
    // из-за чего «Сохранить» в портальной модалке незаметно маршрутизировал заявку.
    // Теперь «Сохранить» и «Маршрутизировать» — это две разные кнопки на фронте.
    $krWillRoute = false;
    if (!$isRestaurant && !empty($body['_route'])) {
        if ($row['status'] !== 'SUBMITTED') {
            krRespond(['error' => 'Маршрутизировать можно только заявку в статусе «Отправлена»'], 422);
        }
        $newVehicle = isset($body['vehicle']) ? trim($body['vehicle']) : ($row['vehicle'] ?? '');
        $newDriver  = isset($body['driver'])  ? trim($body['driver'])  : ($row['driver'] ?? '');
        if ($newVehicle === '' || $newDriver === '') {
            krRespond(['error' => 'Для маршрутизации заполните машину и водителя'], 422);
        }
        $krWillRoute = true;
        $sets[] = "status = ?";
        $vals[] = 'ROUTED';
        $sets[] = "routed_at = NOW()";
    }

    // BSO уникальность при смене
    if (isset($body['bso_series']) || isset($body['bso_number'])) {
        $newSeries = isset($body['bso_series']) ? (trim($body['bso_series']) ?: null) : $row['bso_series'];
        $newNumber = isset($body['bso_number']) ? (trim($body['bso_number']) ?: null) : $row['bso_number'];
        if ($newSeries !== null && $newNumber !== null) {
            $chk = $pdo->prepare("SELECT id FROM keg_returns WHERE restaurant_id = ? AND bso_series = ? AND bso_number = ? AND id != ?");
            $chk->execute([(int)$row['restaurant_id'], $newSeries, $newNumber, $krId]);
            if ($chk->fetch()) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);
        }
    }

    $krActuallyRouted = false;
    if (!empty($sets)) {
        // Если переходим в ROUTED — дополнительно ограничиваем WHERE status='SUBMITTED',
        // чтобы защититься от гонки двух параллельных PATCH-запросов.
        $whereClause = $krWillRoute ? "WHERE id = ? AND status = 'SUBMITTED'" : "WHERE id = ?";
        $vals[] = $krId;
        try {
            $stmt = $pdo->prepare("UPDATE keg_returns SET " . implode(', ', $sets) . " $whereClause");
            $stmt->execute($vals);
            // rowCount = 0 при ROUTED-переходе означает, что кто-то опередил (уже не SUBMITTED).
            if ($krWillRoute && $stmt->rowCount() > 0) {
                $krActuallyRouted = true;
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);
            error_log('keg_returns PATCH: ' . $e->getMessage());
            krRespond(['error' => 'Ошибка обновления'], 500);
        }
    }

    // Обновляем items если переданы
    if (isset($body['items'])) {
        $itemsError = krValidateItems($body['items']);
        if ($itemsError) krRespond(['error' => $itemsError], 400);
        // Удаляем старые, вставляем новые
        $pdo->prepare("DELETE FROM keg_return_items WHERE request_id = ?")->execute([$krId]);
        krInsertItems($pdo, $krId, $body['items']);
    }

    $rowAfter = krGetReturnWithItems($pdo, $krId);
    // Шлём уведомление только если именно МЫ переключили статус (rowCount > 0).
    // $krActuallyRouted=false означает, что кто-то опередил — уведомление уже ушло от него.
    if ($rowAfter && $krActuallyRouted) {
        krNotifyRouted($pdo, $rowAfter);
    }
    krRespond($rowAfter);
}

// ── POST /keg-returns/{id}/submit ──
if ($method === 'POST' && $krId && $krAction === 'submit') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'edit');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }
    if ($row['status'] !== 'DRAFT') {
        krRespond(['error' => 'Отправить можно только черновик'], 422);
    }

    // Проверка дедлайна — только для ресторана. Закупщик (портал) может
    // отправлять заявку и после дедлайна (предупреждение показывается на фронте).
    if ($isRestaurant) {
        $submitDeadline = kegCalcDeadline($row['return_date']);
        $submitNow = new DateTime('now', new DateTimeZone('Europe/Minsk'));
        if ($submitNow >= $submitDeadline) {
            krRespond(['error' => 'Дедлайн прошёл, отправка недоступна'], 422);
        }
    }

    // Валидация обязательных полей при submit
    if (trim($row['sender_position_name']) === '') {
        krRespond(['error' => 'Укажите поле "Сдал грузоотправитель"'], 422);
    }
    if (empty($row['bso_series']) || empty($row['bso_number'])) {
        krRespond(['error' => 'Укажите серию и номер БСО'], 422);
    }
    // Валидация формата БСО при submit
    $bsoErrSubmit = krValidateBso($row['bso_series'], $row['bso_number']);
    if ($bsoErrSubmit) krRespond(['error' => $bsoErrSubmit], 422);

    if (empty($row['items'])) {
        krRespond(['error' => 'Добавьте хотя бы одну позицию кег'], 422);
    }

    $pdo->prepare("UPDATE keg_returns SET status = 'SUBMITTED', submitted_at = NOW() WHERE id = ?")->execute([$krId]);
    $row = krGetReturnWithItems($pdo, $krId);

    // TG-уведомление ресторану
    $bsoStr = trim(($row['bso_series'] ?? '') . ' ' . ($row['bso_number'] ?? ''));
    $submitDate = date('d.m.Y', strtotime($row['return_date']));
    $tgSubmit = '🍺 Возвратная ТТН №' . $bsoStr . ' от ' . $submitDate . ' сформирована. Ожидайте маршрутизацию.';
    krNotifyRestaurant($pdo, (int)$row['restaurant_id'], $tgSubmit);

    krRespond($row);
}

// ── DELETE /keg-returns/{id} ── (закупщик — любую в своей группе, ресторан — только свой DRAFT)
if ($method === 'DELETE' && $krId && $krAction === null) {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);
    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
        if ($row['status'] !== 'DRAFT') {
            krRespond(['error' => 'Ресторан может удалять только черновик'], 422);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'full');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }
    $pdo->prepare("DELETE FROM keg_returns WHERE id = ?")->execute([$krId]);
    krRespond(['success' => true]);
}

// ── POST /keg-returns/{id}/cancel ──
if ($method === 'POST' && $krId && $krAction === 'cancel') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    if (!$isRestaurant) {
        krRequirePortalAccess($krPortalUser, 'edit');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
        if ($row['status'] === 'CANCELLED') {
            krRespond(['error' => 'Заявка уже отменена'], 422);
        }
        if ($row['status'] === 'ROUTED') {
            krRespond(['error' => 'Маршрутизированную заявку отменить нельзя — обратитесь в отдел закупок'], 422);
        }
        // DRAFT — отменяется всегда; SUBMITTED — только до дедлайна.
        if ($row['status'] === 'SUBMITTED' && !empty($row['return_date'])) {
            $deadline = kegCalcDeadline($row['return_date']);
            $now = new DateTime('now', new DateTimeZone('Europe/Minsk'));
            if ($now > $deadline) {
                krRespond(['error' => 'Дедлайн прошёл — отмена недоступна'], 422);
            }
        }
    }

    $pdo->prepare("UPDATE keg_returns SET status = 'CANCELLED', cancelled_at = NOW() WHERE id = ?")->execute([$krId]);
    $row = krGetReturnWithItems($pdo, $krId);
    krRespond($row);
}

// ── POST /keg-returns/{id}/unroute ── откат маршрутизации (ROUTED → SUBMITTED)
// Доступен только закупке. Машина/водитель остаются, чтобы при повторной
// маршрутизации не вбивать заново. Ресторану летит уведомление (TG + push),
// что заявка снова в статусе «Отправлена».
if ($method === 'POST' && $krId && $krAction === 'unroute') {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);
    krRequirePortalAccess($krPortalUser, 'edit');
    krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);

    if ($row['status'] !== 'ROUTED') {
        krRespond(['error' => 'Отменить маршрутизацию можно только для заявки в статусе «Маршрутизирована»'], 422);
    }

    // Защита от гонки: переключаем только если ещё ROUTED. Машину и водителя
    // обнуляем — при повторной маршрутизации логисты пришлют новые данные,
    // а старые могут ввести в заблуждение (логист может назначить другого).
    $stmt = $pdo->prepare("
        UPDATE keg_returns
        SET status = 'SUBMITTED', routed_at = NULL, vehicle = NULL, driver = NULL
        WHERE id = ? AND status = 'ROUTED'
    ");
    $stmt->execute([$krId]);
    if ($stmt->rowCount() === 0) {
        krRespond(['error' => 'Статус заявки уже изменился, обновите страницу'], 409);
    }

    $rowAfter = krGetReturnWithItems($pdo, $krId);
    if ($rowAfter) krNotifyUnrouted($pdo, $rowAfter);
    krRespond($rowAfter);
}

// ── GET/POST /keg-returns/not-returned-emails ── адреса бухгалтерии для писем «Не сдана»
if ($krSubSlug === 'not-returned-emails' && $method === 'GET') {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    krRequirePortalAccess($krPortalUser, 'view');
    $v = $pdo->query("SELECT value FROM settings WHERE `key` = 'keg_not_returned_emails'")->fetchColumn();
    krRespond(['emails' => (string)($v ?: '')]);
}
if ($krSubSlug === 'not-returned-emails' && $method === 'POST') {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    krRequirePortalAccess($krPortalUser, 'full');
    $raw = trim((string)($body['emails'] ?? ''));
    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $valid = [];
    foreach ($parts as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) krRespond(['error' => 'Неверный email: ' . $e], 422);
        $valid[] = $e;
    }
    $joined = implode(', ', $valid);
    $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('keg_not_returned_emails', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([$joined]);
    krRespond(['emails' => $joined]);
}

// ── POST /keg-returns/{id}/not-returned ── ресторан/закупка отмечают «Не сдана»
// Ресторан: свою маршрутизированную заявку, только со следующего дня после даты
// возврата. Закупка: любую маршрутизированную в своей группе. При переводе шлём
// письмо бухгалтерии (settings key='keg_not_returned_emails').
if ($method === 'POST' && $krId && $krAction === 'not-returned') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    $reason = trim((string)($body['reason'] ?? ''));
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
        if ($row['status'] !== 'ROUTED') {
            krRespond(['error' => 'Отметить «не сдана» можно только по маршрутизированной заявке'], 422);
        }
        $today = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('Y-m-d');
        if (empty($row['return_date']) || $today <= $row['return_date']) {
            krRespond(['error' => 'Отметить «не сдана» можно только со следующего дня после даты возврата'], 422);
        }
        // Ресторан обязан указать причину.
        if ($reason === '') {
            krRespond(['error' => 'Укажите причину, почему кеги не сданы'], 422);
        }
        $by = 'ro:' . ($krRestSession['restaurant_number'] ?? '');
    } else {
        krRequirePortalAccess($krPortalUser, 'edit');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
        if ($row['status'] !== 'ROUTED') {
            krRespond(['error' => 'Поставить статус «не сдана» можно только по маршрутизированной заявке'], 422);
        }
        $by = $krPortalUser['name'] ?? 'закупка';
    }

    $stmt = $pdo->prepare("UPDATE keg_returns SET status = 'NOT_RETURNED', not_returned_at = NOW(), not_returned_by = ?, not_returned_reason = ? WHERE id = ? AND status = 'ROUTED'");
    $stmt->execute([$by, ($reason !== '' ? $reason : null), $krId]);
    if ($stmt->rowCount() === 0) {
        krRespond(['error' => 'Статус заявки уже изменился, обновите страницу'], 409);
    }

    $rowAfter = krGetReturnWithItems($pdo, $krId);
    krNotifyAccountingNotReturned($pdo, $rowAfter, $by);
    krRespond($rowAfter);
}

// ── POST /keg-returns/{id}/revert-not-returned ── откат «Не сдана» → ROUTED (только закупка)
if ($method === 'POST' && $krId && $krAction === 'revert-not-returned') {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);
    krRequirePortalAccess($krPortalUser, 'edit');
    krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    if ($row['status'] !== 'NOT_RETURNED') {
        krRespond(['error' => 'Вернуть можно только заявку в статусе «Не сдана»'], 422);
    }
    $stmt = $pdo->prepare("UPDATE keg_returns SET status = 'ROUTED', not_returned_at = NULL, not_returned_by = NULL, not_returned_reason = NULL WHERE id = ? AND status = 'NOT_RETURNED'");
    $stmt->execute([$krId]);
    if ($stmt->rowCount() === 0) {
        krRespond(['error' => 'Статус заявки уже изменился, обновите страницу'], 409);
    }
    krRespond(krGetReturnWithItems($pdo, $krId));
}

// ── POST /keg-returns/{id}/replace-bso ──
// Замена номера БСО, если ресторан испортил бланк. Доступно только в окне
// [deadline 10:00, cutoff 15:00) и только в статусе SUBMITTED/ROUTED.
// До 10:00 — ресторан правит БСО обычным PATCH. После 15:00 — никаких изменений.
if ($method === 'POST' && $krId && $krAction === 'replace-bso') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'edit');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }

    if (!in_array($row['status'], ['SUBMITTED', 'ROUTED'], true)) {
        krRespond(['error' => 'Замена БСО доступна только для отправленных и маршрутизированных заявок'], 422);
    }
    if (empty($row['return_date'])) {
        krRespond(['error' => 'У заявки не указана дата возврата'], 422);
    }

    $deadline = kegCalcDeadline($row['return_date']);
    $cutoff   = kegCalcCutoff($row['return_date']);
    $now      = new DateTime('now', new DateTimeZone('Europe/Minsk'));
    if ($now < $deadline) {
        krRespond(['error' => 'До дедлайна 10:00 правьте БСО обычным редактированием'], 422);
    }
    if ($now >= $cutoff) {
        krRespond(['error' => 'Окно замены БСО закрыто (после 15:00). Свяжитесь с отделом закупок'], 422);
    }

    $newSeries = trim((string)($body['new_series'] ?? ''));
    $newNumber = trim((string)($body['new_number'] ?? ''));
    $reason    = trim((string)($body['reason'] ?? ''));

    if ($newSeries === '' || $newNumber === '') {
        krRespond(['error' => 'Укажите серию и номер нового БСО'], 422);
    }
    $bsoErr = krValidateBso($newSeries, $newNumber);
    if ($bsoErr) krRespond(['error' => $bsoErr], 422);
    if ($reason === '') krRespond(['error' => 'Укажите причину замены'], 422);
    if (mb_strlen($reason) > 255) krRespond(['error' => 'Причина: не более 255 символов'], 422);

    if ($newSeries === ($row['bso_series'] ?? '') && $newNumber === ($row['bso_number'] ?? '')) {
        krRespond(['error' => 'Новый БСО совпадает с текущим'], 422);
    }

    // Уникальность БСО среди заявок этого же ресторана
    $chk = $pdo->prepare("SELECT id FROM keg_returns WHERE restaurant_id = ? AND bso_series = ? AND bso_number = ? AND id != ?");
    $chk->execute([(int)$row['restaurant_id'], $newSeries, $newNumber, $krId]);
    if ($chk->fetch()) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);

    $changedByChat = $isRestaurant ? (int)($krRestSession['id'] ?? 0) : null;
    $changedByUser = !$isRestaurant ? ($krPortalUser['name'] ?? null) : null;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO keg_return_bso_history
                (request_id, old_series, old_number, new_series, new_number, reason, changed_by_ru_user_id, changed_by_user)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $krId,
            $row['bso_series'] ?: null,
            $row['bso_number'] ?: null,
            $newSeries,
            $newNumber,
            $reason,
            $changedByChat,
            $changedByUser,
        ]);
        $pdo->prepare("UPDATE keg_returns SET bso_series = ?, bso_number = ? WHERE id = ?")
            ->execute([$newSeries, $newNumber, $krId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == 23000) krRespond(['error' => 'Заявка с таким БСО уже существует'], 422);
        error_log('keg_returns replace-bso: ' . $e->getMessage());
        krRespond(['error' => 'Ошибка замены БСО'], 500);
    }

    $rowAfter = krGetReturnWithItems($pdo, $krId);
    krRespond($rowAfter);
}

// ═══ Числа прописью ═══

function numberToWordsRu(int $n): string {
    if ($n === 0) return 'Ноль';
    if ($n < 0) return 'Минус ' . numberToWordsRu(-$n);
    $units  = ['', 'Один', 'Два', 'Три', 'Четыре', 'Пять', 'Шесть', 'Семь', 'Восемь', 'Девять'];
    $unitsF = ['', 'Одна', 'Две', 'Три', 'Четыре', 'Пять', 'Шесть', 'Семь', 'Восемь', 'Девять'];
    $teens  = ['Десять', 'Одиннадцать', 'Двенадцать', 'Тринадцать', 'Четырнадцать', 'Пятнадцать',
               'Шестнадцать', 'Семнадцать', 'Восемнадцать', 'Девятнадцать'];
    $tens     = ['', '', 'Двадцать', 'Тридцать', 'Сорок', 'Пятьдесят', 'Шестьдесят', 'Семьдесят', 'Восемьдесят', 'Девяносто'];
    $hundreds = ['', 'Сто', 'Двести', 'Триста', 'Четыреста', 'Пятьсот', 'Шестьсот', 'Семьсот', 'Восемьсот', 'Девятьсот'];
    $result = [];
    if ($n >= 1000000) {
        $m = (int)($n / 1000000);
        $lm = $m % 10; $ltm = $m % 100;
        $word = ($ltm >= 10 && $ltm < 20) ? 'миллионов' : (($lm === 1) ? 'миллион' : (($lm >= 2 && $lm <= 4) ? 'миллиона' : 'миллионов'));
        $result[] = numberToWordsRu($m) . ' ' . $word;
        $n %= 1000000;
    }
    if ($n >= 1000) {
        $t = (int)($n / 1000);
        $tStr = '';
        $h = (int)($t / 100); if ($h) $tStr .= $hundreds[$h] . ' ';
        $tt = $t % 100;
        if ($tt >= 10 && $tt < 20) { $tStr .= $teens[$tt - 10]; }
        else {
            $td = (int)($tt / 10); $u = $tt % 10;
            if ($td) $tStr .= $tens[$td] . ($u ? ' ' : '');
            if ($u) $tStr .= $unitsF[$u];
        }
        $tStr = trim($tStr);
        $ld = $t % 10; $ltd = $t % 100;
        $word = ($ltd >= 10 && $ltd < 20) ? 'тысяч' : (($ld === 1) ? 'тысяча' : (($ld >= 2 && $ld <= 4) ? 'тысячи' : 'тысяч'));
        $result[] = $tStr . ' ' . $word;
        $n %= 1000;
    }
    if ($n > 0) {
        $h = (int)($n / 100); if ($h) $result[] = $hundreds[$h];
        $tt = $n % 100;
        if ($tt >= 10 && $tt < 20) { $result[] = $teens[$tt - 10]; }
        else {
            $td = (int)($tt / 10); $u = $tt % 10;
            if ($td) $result[] = $tens[$td];
            if ($u)  $result[] = $units[$u];
        }
    }
    $raw = trim(implode(' ', $result));
    // Первая буква большая, остальное — строчные (через mb_strtolower для кириллицы)
    return mb_strtoupper(mb_substr($raw, 0, 1, 'UTF-8'), 'UTF-8') . mb_strtolower(mb_substr($raw, 1, null, 'UTF-8'), 'UTF-8');
}

function rublesToWordsRu(int $rub, int $kop): string {
    return numberToWordsRu($rub) . ' руб. ' . sprintf('%02d', $kop) . ' коп.';
}

// ═══ Общий хелпер заполнения шаблона ТТН ═══

/**
 * Загружает шаблон ТТН и заполняет его данными заявки.
 * Возвращает готовый Spreadsheet для дальнейшего сохранения (Xlsx или PDF).
 *
 * Координаты по дампу строк 36-64:
 *   A = Наименование, O = Ед.изм, R = Кол-во, U = Цена, Y = Стоимость без НДС,
 *   AD = Ставка НДС, AG = Сумма НДС, AL = Стоимость с НДС,
 *   AQ = Кол-во грузовых мест, AT = Масса груза.
 *   Итого прописью: H45, H47, H49, AN49. Цифры: AW46, AW48.
 *   Товар к перевозке принял (водитель): AN51.
 */
function krFillTemplate(array $row): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $led = $row['legal_entity_details'] ?? null;
    $returnDate = $row['return_date'];
    $items = $row['items'] ?? [];
    $restaurantNumber = (int)($row['restaurant_number'] ?? 0);

    // Дата
    $months = ['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая',
               '06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября',
               '11'=>'ноября','12'=>'декабря'];
    [$y, $m, $d] = explode('-', $returnDate);
    $dateFormatted = (int)$d . ' ' . ($months[$m] ?? $m) . ' ' . $y . ' г.';
    $dateShort = sprintf('%02d.%02d.%04d', (int)$d, (int)$m, (int)$y);

    // Реквизиты юрлица
    $shortName = $led ? ($led['full_name'] ?? '') : '';
    $base = $led ? ($led['full_name'] . ', ' . $led['address']) : '';
    $senderName   = $base ? ($base . ' (Ресторан №' . $restaurantNumber . ')') : '';
    $receiverName = $base ? ($base . ' (хранение)') : '';
    $unp = $led ? ($led['unp'] ?? '') : '';

    $pickupAddress  = !empty($row['pickup_address']) ? $row['pickup_address'] : ($row['restaurant_city'] . ', ' . $row['restaurant_address']);
    $dropoffAddress = 'Минский район, Луговослободский с/с, М4 18 км, Склад №6 ТЛК "Прилесье"';

    $vehicle = $row['vehicle'] ?? '';
    $driver  = $row['driver'] ?? '';
    $senderPositionName = $row['sender_position_name'] ?? '';

    // Используем единый шаблон БК и подставляем данные юрлица (Воглия Матта / Бургер БК)
    // динамически из legal_entity_details: full_name, address, unp.
    $templatePath = __DIR__ . '/../../ТТН1.xls';
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load($templatePath);
    $sheet = $spreadsheet->getActiveSheet();

    // Дата — D18
    $sheet->getCell('D18')->setValue($dateFormatted);

    // УНП грузоотправителя — T4, грузополучателя — AB4
    if ($unp) {
        $sheet->getCell('T4')->setValue($unp);
        $sheet->getCell('AB4')->setValue($unp);
    }

    // Грузоотправитель — H26, грузополучатель — H28
    if ($senderName)   $sheet->getCell('H26')->setValue($senderName);
    if ($receiverName) $sheet->getCell('H28')->setValue($receiverName);

    // Пункт погрузки — AC30, разгрузки — AV30
    $sheet->getCell('AC30')->setValue($pickupAddress);
    $sheet->getCell('AV30')->setValue($dropoffAddress);

    // Автомобиль — E20 (рядом с label), водитель — E22 (рядом с label)
    // E21 и E23 — подсказки «(марка, регистрационный знак)» и «(фамилия и инициалы)», не трогаем
    if ($vehicle) $sheet->getCell('E20')->setValue($vehicle);
    if ($driver)  $sheet->getCell('E22')->setValue($driver);
    $sheet->getStyle('E20')->getFont()->setSize(12);
    $sheet->getStyle('E22')->getFont()->setSize(12);

    // К путевому листу №: всегда «б/н» (label в AO20, значение справа от него)
    $sheet->getCell('BB20')->setValue('б/н');
    $sheet->getStyle('BB20')->getFont()->setSize(12);

    // Исполнитель погрузки/разгрузки — E63, E64 (только юрлицо, без адреса)
    if ($shortName) {
        $sheet->getCell('E63')->setValue($shortName);
        $sheet->getCell('E64')->setValue($shortName);
    }

    // Дата операций погрузки/разгрузки
    $sheet->getCell('U63')->setValue($dateShort);
    $sheet->getCell('Y63')->setValue($dateShort);
    $sheet->getCell('U64')->setValue($dateShort);
    $sheet->getCell('Y64')->setValue($dateShort);

    // Товар к перевозке принял (водитель) — AN51; AN52 — подпись «водитель»
    if ($driver) {
        $sheet->getCell('AN51')->setValue('водитель ' . $driver);
        $sheet->getStyle('AN51')->getFont()->setSize(12);
        $sheet->getCell('AN52')->setValue('(водитель)');
    }

    // ── Товарный раздел ──
    // Только непустые позиции
    $nonEmpty = array_values(array_filter($items, fn($it) => (int)$it['quantity'] > 0));
    $price = 300;         // руб за кегу
    $weightPerKegT = 0.0093; // тонн за кегу
    $itemRows = [40, 41];
    $totalQty    = 0;
    $totalSum    = 0;
    $totalWeight = 0.0;

    for ($i = 0; $i < count($itemRows); $i++) {
        $r = $itemRows[$i];
        if (isset($nonEmpty[$i])) {
            $it  = $nonEmpty[$i];
            $qty = (int)$it['quantity'];
            $name = $it['keg_code'] . ' ' . $it['keg_name'];
            $sum = $qty * $price;
            $weightT  = round($qty * 0.0093, 3); // в тоннах для табличной колонки
            $weightKg = $qty * 9.3;              // в кг для прописи итога
            $sheet->getCell('A'  . $r)->setValue($name);
            $sheet->getCell('O'  . $r)->setValue('шт');
            $sheet->getCell('R'  . $r)->setValue($qty);
            $sheet->getCell('U'  . $r)->setValue(number_format($price, 2, ',', ' '));
            $sheet->getCell('Y'  . $r)->setValue(number_format($sum,   2, ',', ' '));
            $sheet->getCell('AD' . $r)->setValue('-');
            $sheet->getCell('AG' . $r)->setValue('-');
            $sheet->getCell('AL' . $r)->setValue(number_format($sum,   2, ',', ' '));
            $sheet->getCell('AQ' . $r)->setValue($qty);
            $sheet->getCell('AT' . $r)->setValue(number_format($weightT, 3, ',', ' '));
            $totalQty       += $qty;
            $totalSum       += $sum;
            $totalWeight    += $weightT;
            $totalWeightKg  = isset($totalWeightKg) ? $totalWeightKg + $weightKg : $weightKg;
        } else {
            foreach (['A','O','R','U','Y','AD','AG','AL','AQ','AT'] as $col) {
                $sheet->getCell($col . $r)->setValue('');
            }
        }
    }

    // Высота строк товарного раздела — auto, перенос текста
    $sheet->getRowDimension(40)->setRowHeight(28);
    $sheet->getRowDimension(41)->setRowHeight(28);
    $sheet->getStyle('A40:A41')->getAlignment()->setWrapText(true);

    // Центрирование числовых колонок товарного раздела (строки 40, 41, 42)
    $numericCols = ['R', 'U', 'Y', 'AD', 'AG', 'AL', 'AQ', 'AT'];
    foreach ([40, 41, 42] as $nr) {
        foreach ($numericCols as $nc) {
            $sheet->getStyle($nc . $nr)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                ->setIndent(1);
        }
    }

    // Если только одна позиция — скрываем строку 41
    if (count($nonEmpty) === 1) {
        $sheet->getRowDimension(41)->setVisible(false);
    }

    // ИТОГО — строка 42 (масса в тоннах с 4 знаками)
    $sheet->getCell('Y42') ->setValue(number_format($totalSum,    2, ',', ' '));
    $sheet->getCell('AL42')->setValue(number_format($totalSum,    2, ',', ' '));
    $sheet->getCell('AQ42')->setValue($totalQty);
    $sheet->getCell('AT42')->setValue(number_format($totalWeight, 3, ',', ' '));

    // Сводные значения прописью и цифрами (строки 45-50)
    $totalSumInt = (int)floor($totalSum);
    $totalSumKop = (int)round(($totalSum - $totalSumInt) * 100);
    // Берём кг из показанного значения тонн (round до 3 знаков * 1000),
    // чтобы прописью совпадало с цифрой массы в таблице.
    $totalWeightKgInt = (int)round($totalWeight * 1000);

    $sheet->getCell('H45')->setValue('Ноль руб. 00 коп.');
    $sheet->getCell('AW46')->setValue('0,00');
    $sheet->getCell('H47')->setValue(rublesToWordsRu($totalSumInt, $totalSumKop));
    $sheet->getCell('AW48')->setValue(number_format($totalSum, 2, ',', ' '));
    $sheet->getCell('H49')->setValue(numberToWordsRu($totalWeightKgInt) . ' кг.');
    $sheet->getCell('AN49')->setValue(numberToWordsRu($totalQty));

    // Сдал грузоотправитель — H51, H53. Всегда перезаписываем, чтобы убрать
    // дефолтный текст из шаблона при пустом значении.
    $sheet->getCell('H51')->setValue($senderPositionName ?: '');
    $sheet->getCell('H53')->setValue($senderPositionName ?: '');

    // Page setup: A4, книжная, fit-to-width, узкие поля
    $ps = $sheet->getPageSetup();
    $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ps->setFitToPage(true);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(0);
    $ps->setPrintArea('A3:BO81');
    $ps->setHorizontalCentered(false);
    $ps->setVerticalCentered(false);
    // Поля: верх 1.5 см, низ 1 см, лево 2 см (сдвиг вправо +1 см), право 1 см.
    $sheet->getPageMargins()
        ->setTop(0.59)->setRight(0.3937)->setLeft(0.787)->setBottom(0.3937)
        ->setHeader(0)->setFooter(0);

    // Отступ после строки УНП (строка 4): высота строки 5 = ~3.5 см (≈99 pt).
    $sheet->getRowDimension(5)->setRowHeight(99);

    return $spreadsheet;
}

/**
 * Рекурсивно удаляет директорию и всё её содержимое.
 */
function krRmDirRec($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? krRmDirRec($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Генерирует PDF из заявки через LibreOffice.
 * Возвращает содержимое PDF-файла в виде строки, или false при ошибке.
 */
// $copies — сколько одинаковых ТТН вернуть в одном PDF (для печати на нескольких
// бланках БСО). По умолчанию 1; при печати ресторан получает 4 одинаковые ТТН.
function krGeneratePdf(array $row, int $copies = 1) {
    $copies = max(1, $copies);
    $spreadsheet = krFillTemplate($row);
    $tmpDir  = sys_get_temp_dir() . '/kegprint_' . uniqid();
    @mkdir($tmpDir);
    $xlsxPath = $tmpDir . '/ttn.xlsx';
    $content = false;
    try {
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($xlsxPath);

        $cmd = 'export HOME=' . escapeshellarg($tmpDir)
            . ' && timeout 30 libreoffice --headless --convert-to pdf --outdir '
            . escapeshellarg($tmpDir) . ' ' . escapeshellarg($xlsxPath) . ' 2>&1';
        $out     = shell_exec($cmd);
        $pdfPath = $tmpDir . '/ttn.pdf';

        if (!file_exists($pdfPath)) {
            error_log('krGeneratePdf failed: ' . $out);
        } elseif ($copies > 1) {
            // Склеиваем N одинаковых копий в один PDF (pdfunite из poppler-utils).
            $mergedPath = $tmpDir . '/ttn_x' . $copies . '.pdf';
            $args = str_repeat(escapeshellarg($pdfPath) . ' ', $copies);
            $mout = shell_exec('pdfunite ' . $args . escapeshellarg($mergedPath) . ' 2>&1');
            if (file_exists($mergedPath)) {
                $content = file_get_contents($mergedPath);
            } else {
                error_log('krGeneratePdf pdfunite failed: ' . $mout);
                $content = file_get_contents($pdfPath); // запас: хотя бы 1 копия
            }
        } else {
            $content = file_get_contents($pdfPath);
        }
    } finally {
        krRmDirRec($tmpDir);
    }
    return $content;
}

// Транслитерация кириллицы в латиницу для ASCII-имени файла (серия ТТН — кириллица).
function krTranslit(string $s): string {
    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z',
        'И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R',
        'С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
        'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    return strtr($s, $map);
}

// Дата ГГГГ-ММ-ДД → «ДД.ММ.ГГГГ» для читаемого имени файла; '' если даты нет.
function krNiceDate(?string $d): string {
    if (!$d) return '';
    $p = explode('-', $d);
    return count($p) === 3 ? ($p[2] . '.' . $p[1] . '.' . $p[0]) : $d;
}

// Заголовок Content-Disposition с именем файла на русском:
// ASCII-фолбэк (транслит) для старых клиентов + RFC 5987 filename* в UTF-8,
// который современные браузеры показывают кириллицей.
function krCDHeader(string $disposition, string $filename): string {
    $ascii = preg_replace('/[^\x20-\x7E]/', '', krTranslit($filename));
    $ascii = str_replace('"', '', $ascii);
    if (trim($ascii) === '') $ascii = 'file';
    return 'Content-Disposition: ' . $disposition
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($filename);
}

// ── GET /keg-returns/{id}/excel ──
if ($method === 'GET' && $krId && $krAction === 'excel') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);

    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'view');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }

    $bsoSeries = $row['bso_series'] ?? '';
    $bsoNumber = $row['bso_number'] ?? '';
    $niceDate  = krNiceDate($row['return_date']);
    $filename  = 'ТТН ' . ($bsoSeries ?: 'X') . ' ' . ($bsoNumber ?: '0') . ($niceDate ? ' от ' . $niceDate : '') . '.xlsx';
    $cdHeader  = krCDHeader('attachment', $filename);

    $spreadsheet = krFillTemplate($row);

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header($cdHeader);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ── GET /keg-returns/{id}/print ── PDF для печати через LibreOffice
if ($method === 'GET' && $krId && $krAction === 'print') {
    $row = krGetReturnWithItems($pdo, $krId);
    if (!$row) krRespond(['error' => 'Не найдено'], 404);
    if ($isRestaurant) {
        if ((int)$row['restaurant_id'] !== (int)$krRestSession['restaurant_id']) {
            krRespond(['error' => 'Нет доступа'], 403);
        }
    } else {
        krRequirePortalAccess($krPortalUser, 'view');
        krRequireGroupAccess($krPortalUser, $row['legal_entity_group'] ?? null);
    }

    $bsoSeries = $row['bso_series'] ?? '';
    $bsoNumber = $row['bso_number'] ?? '';
    $niceDate  = krNiceDate($row['return_date']);
    $filename  = 'ТТН ' . ($bsoSeries ?: 'X') . ' ' . ($bsoNumber ?: '0') . ($niceDate ? ' от ' . $niceDate : '') . '.pdf';

    // 4 одинаковые ТТН — по числу бланков БСО, используемых на один возврат.
    $pdfContent = krGeneratePdf($row, 4);
    if ($pdfContent === false) {
        krRespond(['error' => 'Не удалось сгенерировать PDF'], 500);
    }

    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header(krCDHeader('inline', $filename));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $pdfContent;
    exit;
}

// ── GET /keg-returns/import-template.xlsx ── шаблон для логистов
if ($method === 'GET' && ($krSubSlug === 'import-template.xlsx' || $krSubSlug === 'import-template')) {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    krRequirePortalAccess($krPortalUser, 'full');

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // Лист 1: «Маршрутизация» — пустой шаблон с заголовками, как ждёт парсер.
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Маршрутизация');
    $headers = ['Водитель', 'Заказчик', 'Адрес точки', '№ ТТН', 'Машина'];
    foreach ($headers as $i => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1';
        $sh->setCellValue($cell, $h);
        $sh->getStyle($cell)->getFont()->setBold(true)->setSize(11);
        $sh->getStyle($cell)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('502314');
        $sh->getStyle($cell)->getFont()->getColor()->setRGB('FFFFFF');
        $sh->getStyle($cell)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
    // Пара примеров для понимания формата
    $sh->setCellValue('A2', 'Иванов И.И.');
    $sh->setCellValue('B2', 'Бургер БК');
    $sh->setCellValue('C2', 'г. Минск, ул. Притыцкого, 154');
    $sh->setCellValue('D2', 'без №');
    $sh->setCellValue('E2', 'AA1234-5');
    $sh->setCellValue('A3', 'Петров П.П.');
    $sh->setCellValue('B3', 'Воглия Матта');
    $sh->setCellValue('C3', 'г. Минск, пр. Победителей, 65');
    $sh->setCellValue('D3', 'без №');
    $sh->setCellValue('E3', 'BB7654-9');
    $sh->getColumnDimension('A')->setWidth(22);
    $sh->getColumnDimension('B')->setWidth(20);
    $sh->getColumnDimension('C')->setWidth(45);
    $sh->getColumnDimension('D')->setWidth(12);
    $sh->getColumnDimension('E')->setWidth(16);

    // Лист 2: «Адреса ресторанов» — актуальный справочник для копирования.
    $sh2 = $ss->createSheet();
    $sh2->setTitle('Адреса ресторанов');
    $hdr2 = ['№ ресторана', 'Юрлицо', 'Город', 'Адрес', 'Адрес погрузки', 'Дни возврата'];
    foreach ($hdr2 as $i => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1';
        $sh2->setCellValue($cell, $h);
        $sh2->getStyle($cell)->getFont()->setBold(true)->setSize(11);
        $sh2->getStyle($cell)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('6B321F');
        $sh2->getStyle($cell)->getFont()->getColor()->setRGB('FFFFFF');
    }
    $rows = $pdo->query("
        SELECT r.number, r.legal_entity_group, r.city, r.address, r.pickup_address, r.pickup_weekdays
        FROM restaurants r
        WHERE r.active = 1 AND r.legal_entity_group = 'BK_VM'
          AND COALESCE(r.keg_returns_enabled, 1) = 1
        ORDER BY r.number
    ")->fetchAll();
    $weekdayNames = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    $r = 2;
    foreach ($rows as $row) {
        $isVm = ((int)$row['number'] === 3); // см. krLegalCodeForRestaurant
        $legalName = $isVm ? 'Воглия Матта' : 'Бургер БК';
        $mask = (int)($row['pickup_weekdays'] ?? 0);
        $days = [];
        for ($i = 0; $i < 7; $i++) if ($mask & (1 << $i)) $days[] = $weekdayNames[$i];
        // Если pickup_address не задан — собираем из города и адреса ресторана
        // (так же делается в самой ТТН и в таблице портала).
        $pickup = trim((string)($row['pickup_address'] ?? ''));
        if ($pickup === '') {
            $city = trim((string)($row['city'] ?? ''));
            $addr = trim((string)($row['address'] ?? ''));
            $pickup = ($city !== '' && $addr !== '') ? ($city . ', ' . $addr) : ($city !== '' ? $city : $addr);
        }
        $sh2->setCellValue('A' . $r, (int)$row['number']);
        $sh2->setCellValue('B' . $r, $legalName);
        $sh2->setCellValue('C' . $r, $row['city'] ?? '');
        $sh2->setCellValue('D' . $r, $row['address'] ?? '');
        $sh2->setCellValue('E' . $r, $pickup);
        $sh2->setCellValue('F' . $r, $days ? implode(', ', $days) : '—');
        $r++;
    }
    $sh2->getColumnDimension('A')->setWidth(12);
    $sh2->getColumnDimension('B')->setWidth(18);
    $sh2->getColumnDimension('C')->setWidth(16);
    $sh2->getColumnDimension('D')->setWidth(40);
    $sh2->getColumnDimension('E')->setWidth(40);
    $sh2->getColumnDimension('F')->setWidth(28);
    $sh2->freezePane('A2');

    $ss->setActiveSheetIndex(0);

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
    $filename = 'keg_routing_template.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $writer->save('php://output');
    exit;
}

// ── POST /keg-returns/import-routing ──
if ($method === 'POST' && $krSubSlug === 'import-routing') {
    if ($isRestaurant) krRespond(['error' => 'Нет доступа'], 403);
    // Массовая операция по всем ресторанам группы BK_VM — требуется full-доступ
    // и наличие BK_VM в legal_entities пользователя.
    krRequirePortalAccess($krPortalUser, 'full');
    krRequireGroupAccess($krPortalUser, 'BK_VM');

    // При multipart/form-data данные приходят в $_POST, не в $body
    $returnDate = trim($_POST['return_date'] ?? $body['return_date'] ?? '');
    if (!$returnDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        krRespond(['error' => 'Укажите дату возврата'], 400);
    }

    if (empty($_FILES['file'])) {
        krRespond(['error' => 'Файл не выбран'], 400);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Защита от zip-bomb / подмены MIME: сверяем по реальному содержимому,
    // а не по расширению (10 МБ xlsx-«бомба» может развернуться в гигабайты).
    $fileSize = (int)($_FILES['file']['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > 10 * 1024 * 1024) {
        krRespond(['error' => 'Файл должен быть не больше 10 МБ'], 400);
    }
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $realMime = $finfo ? @finfo_file($finfo, $tmpPath) : '';
    if ($finfo) finfo_close($finfo);
    $allowedXlsxMimes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'];
    $allowedXlsMimes  = ['application/vnd.ms-excel', 'application/excel', 'application/octet-stream', 'application/x-ole-storage'];
    if ($ext === 'xlsx' && !in_array($realMime, $allowedXlsxMimes, true)) {
        krRespond(['error' => 'Файл не похож на .xlsx (' . htmlspecialchars($realMime) . ')'], 400);
    }
    if ($ext === 'xls' && !in_array($realMime, $allowedXlsMimes, true)) {
        krRespond(['error' => 'Файл не похож на .xls (' . htmlspecialchars($realMime) . ')'], 400);
    }

    try {
        if ($ext === 'xlsx') {
            $reader = new XlsxReader();
        } elseif ($ext === 'xls') {
            $reader = new XlsReader();
        } else {
            krRespond(['error' => 'Поддерживаются только Excel-файлы (.xlsx или .xls)'], 400);
        }
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        // Жёсткий лимит на число строк/колонок — защита от zip-bomb с миллионами ячеек.
        $highestRow = (int)$sheet->getHighestDataRow();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestRow > 50000 || $highestCol > 100) {
            krRespond(['error' => 'В файле слишком много строк/колонок. Ожидается до 50000 строк и 100 колонок.'], 400);
        }
        $sheetData = $sheet->toArray(null, true, true, false);
    } catch (Exception $e) {
        error_log('keg import-routing read: ' . $e->getMessage());
        error_log('keg-returns import-routing read failed: ' . $e->getMessage());
        krRespond(['error' => 'Не удалось прочитать файл — проверьте формат'], 422);
    }

    // Ищем строку заголовков и индексы нужных колонок в ней.
    // Раньше колонки были захардкожены под наш шаблон (E=машина), но в реальном
    // файле логистов структура может быть другая: колонка E часто это «Вес
    // заявки брутто, кг», а гос. номер сидит под подписью «Гос. номер
    // транспортного средства». Поэтому ищем колонки по тексту заголовка.
    $headerIdx = -1;
    $colDriver = null; $colCustomer = null; $colAddress = null; $colVehicle = null; $colWarehouse = null;
    foreach ($sheetData as $idx => $row2) {
        $tmpDriver = null; $tmpCustomer = null; $tmpAddress = null; $tmpVehicle = null; $tmpWarehouse = null;
        foreach ($row2 as $colIdx => $cell) {
            $c = mb_strtolower(trim((string)$cell), 'UTF-8');
            if ($c === '') continue;
            if ($tmpDriver === null && ($c === 'водитель' || strpos($c, 'фио водителя') !== false)) {
                $tmpDriver = $colIdx;
            } elseif ($tmpWarehouse === null && strpos($c, 'склад') !== false) {
                // «Физический склад отгрузки» — по нему отличаем Прилесье 6 (сухое,
                // кеги) от Прилесья 1 (холод/мороз, другие водители).
                $tmpWarehouse = $colIdx;
            } elseif ($tmpCustomer === null && (strpos($c, 'заказчик') !== false || strpos($c, 'клиент') !== false)) {
                $tmpCustomer = $colIdx;
            } elseif ($tmpAddress === null && strpos($c, 'адрес') !== false) {
                $tmpAddress = $colIdx;
            } elseif ($tmpVehicle === null && (
                    strpos($c, 'гос') !== false && strpos($c, 'номер') !== false
                    || strpos($c, 'гос. номер') !== false
                    || strpos($c, 'госномер') !== false
                    || $c === 'машина'
                    || strpos($c, 'номер машины') !== false
                    || strpos($c, 'номер тс') !== false
                    || strpos($c, 'номер транспорт') !== false
                )) {
                $tmpVehicle = $colIdx;
            }
        }
        // Заголовочная строка должна содержать хотя бы «Водитель» И «Адрес».
        if ($tmpDriver !== null && $tmpAddress !== null) {
            $headerIdx = $idx;
            $colDriver = $tmpDriver;
            $colCustomer = $tmpCustomer;
            $colAddress = $tmpAddress;
            $colVehicle = $tmpVehicle;
            $colWarehouse = $tmpWarehouse;
            break;
        }
    }
    if ($headerIdx < 0) {
        krRespond(['error' => 'Не найдена строка заголовков (нет колонок «Водитель» и «Адрес»)'], 422);
    }
    if ($colVehicle === null) {
        // Fallback на старый формат: «Машина» в колонке E, либо непосредственно
        // после «Адрес точки». Это сохраняет совместимость со старым шаблоном.
        $colVehicle = ($colAddress !== null) ? ($colAddress + 2) : 4;
    }

    // Парсим строки данных с поддержкой объединённых ячеек (значение только в первой строке группы)
    $currentDriver    = null;
    $currentCustomer  = null;
    $currentVehicle   = null;
    $currentWarehouse = null;
    $parsed = [];

    foreach ($sheetData as $idx => $row2) {
        if ($idx <= $headerIdx) continue;
        $driver    = trim((string)($row2[$colDriver] ?? ''));
        $customer  = $colCustomer !== null ? trim((string)($row2[$colCustomer] ?? '')) : '';
        $warehouse = $colWarehouse !== null ? trim((string)($row2[$colWarehouse] ?? '')) : '';
        $address   = trim((string)($row2[$colAddress] ?? ''));
        $vehicle   = trim((string)($row2[$colVehicle] ?? ''));

        if ($driver !== '')    $currentDriver    = $driver;
        if ($customer !== '')  $currentCustomer  = $customer;
        if ($vehicle !== '')   $currentVehicle   = $vehicle;
        if ($warehouse !== '') $currentWarehouse = $warehouse;
        if ($address === '') continue; // продолжение мержа — без адреса

        $parsed[] = [
            'driver'    => $currentDriver,
            'customer'  => $currentCustomer,
            'address'   => $address,
            'vehicle'   => $currentVehicle,
            'warehouse' => $currentWarehouse,
        ];
    }

    // Файл логистов на ОДИН день содержит строки и со склада «Прилесье 6»
    // (сухое — оттуда забирают кеги), и со склада «Прилесье 1» (холод/мороз —
    // другие водители, кег вообще нет). По одному адресу в файле легко оказаться
    // двум строкам с разными водителями (Витебск, Чкалова 35: Прилесье 1 везёт
    // Бибик, Прилесье 6 — Климовец), поэтому для возврата кег берём ТОЛЬКО
    // строки Прилесье 6. Название склада живёт либо в колонке «Физический склад
    // отгрузки», либо (в старых файлах) в колонке «Заказчик».
    // Если склад в файле не указан вовсе — оставляем строки как есть,
    // чтобы не сломать старый формат.
    $whOf = function(array $row) use ($colWarehouse) {
        $src = $colWarehouse !== null ? ($row['warehouse'] ?? '') : ($row['customer'] ?? '');
        return mb_strtolower((string)$src, 'UTF-8');
    };
    $isPrilesye6 = function(array $row) use ($whOf) {
        $c = $whOf($row);
        return strpos($c, 'прилесье 6') !== false || strpos($c, 'прилесье-6') !== false;
    };
    // Склад считаем указанным, только если он реально назван («Прилесье …»).
    // В старом шаблоне в колонке «Заказчик» стоит юрлицо («Бургер БК»), а не склад —
    // такой файл фильтровать по складу нельзя, иначе отсеются все строки.
    $hasWarehouseInfo = false;
    foreach ($parsed as $p) {
        if (strpos($whOf($p), 'прилесье') !== false) { $hasWarehouseInfo = true; break; }
    }
    if ($hasWarehouseInfo) {
        $parsed = array_values(array_filter($parsed, $isPrilesye6));
    }

    // Дедупликация по адресу — оставляем первую строку. По одному адресу в файле
    // могут стоять две точки разных юрлиц (наш ресторан и Пицца Стар), но развозит
    // их один водитель, поэтому лишняя строка ничего не добавляет.
    $seen = [];
    $unique = [];
    foreach ($parsed as $p) {
        $key = krNormalizeAddress($p['address']);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $p;
        }
    }

    // Загружаем все SUBMITTED заявки за return_date (BK_VM)
    $reqStmt = $pdo->prepare("
        SELECT kr.id, kr.status, kr.vehicle, kr.driver, kr.bso_series, kr.bso_number, kr.restaurant_id,
               r.address AS restaurant_address, r.number AS restaurant_number, r.city AS restaurant_city
        FROM keg_returns kr
        JOIN restaurants r ON r.id = kr.restaurant_id
        WHERE kr.return_date = ? AND kr.legal_entity_group = 'BK_VM' AND kr.status = 'SUBMITTED'
        ORDER BY r.number
    ");
    $reqStmt->execute([$returnDate]);
    $requests = $reqStmt->fetchAll();

    // Список «всех доступных» для ручного сопоставления на фронте.
    // Возвращается отдельно от preview, чтобы юзер мог переназначить
    // адрес-несовпадение на нужную заявку через селект.
    $availableRequests = [];
    foreach ($requests as $req) {
        $availableRequests[] = [
            'request_id'         => (int)$req['id'],
            'restaurant_number'  => (int)$req['restaurant_number'],
            'restaurant_city'    => $req['restaurant_city'],
            'restaurant_address' => $req['restaurant_address'],
            'current_driver'     => $req['driver'],
            'current_vehicle'    => $req['vehicle'],
        ];
    }

    $preview = [];
    $commitActions = [];

    // Готовим сравниваемые строки заявок: в БД restaurants.address обычно без города
    // (Минск/Гродно/… живёт в отдельной колонке r.city). Чтобы избежать ложных
    // совпадений вида «Минск Космонавтов 81» ↔ «Гродно Космонавтов 81»,
    // склеиваем «город + адрес» один раз и дальше работаем с этим.
    $requestsFull = [];
    foreach ($requests as $req) {
        $city = trim((string)($req['restaurant_city'] ?? ''));
        $addr = trim((string)($req['restaurant_address'] ?? ''));
        $full = $city !== '' && $addr !== '' ? ($city . ', ' . $addr) : ($city !== '' ? $city : $addr);
        $requestsFull[] = ['req' => $req, 'full' => $full];
    }

    foreach ($unique as $fileRow) {
        // Строгий отбор: улица и номер дома обязаны совпасть, город — не
        // конфликтовать. Адреса чужих юрлиц (Пицца Стар и прочие точки из того же
        // файла) сюда просто не проходят: среди наших ресторанов их нет.
        $scored = [];
        foreach ($requestsFull as $rf) {
            if (!krAddressStrictMatch($fileRow['address'], $rf['full'])) continue;
            $scored[] = ['req' => $rf['req'], 'score' => krAddressMatchScore($fileRow['address'], $rf['full'])];
        }
        usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);

        // Уточняем по заказчику, если он есть в файле (старый формат): «Воглия» — это
        // всегда ресторан №3, «Бургер» — любой другой.
        if (count($scored) > 1) {
            $custLower = mb_strtolower((string)($fileRow['customer'] ?? ''), 'UTF-8');
            if (strpos($custLower, 'воглия') !== false) {
                $byCust = array_filter($scored, fn($s) => (int)$s['req']['restaurant_number'] === 3);
            } elseif (strpos($custLower, 'бургер') !== false || strpos($custLower, 'бк') !== false) {
                $byCust = array_filter($scored, fn($s) => (int)$s['req']['restaurant_number'] !== 3);
            } else {
                $byCust = $scored;
            }
            if (count($byCust) > 0) $scored = array_values($byCust);
        }

        if (empty($scored)) {
            $preview[] = ['row' => $fileRow, 'match' => null, 'warning' => 'не найден'];
            continue;
        }

        // Несколько подходящих ресторанов: назначаем автоматически только явного
        // лидера (заметно более похожий адрес). Если разрыв мал — не гадаем,
        // оставляем ручной выбор в превью.
        if (count($scored) > 1 && ($scored[0]['score'] - $scored[1]['score']) < 0.1) {
            $nums = array_map(fn($s) => '№' . (int)$s['req']['restaurant_number'], $scored);
            $preview[] = [
                'row'     => $fileRow,
                'match'   => null,
                'warning' => 'подходит несколько ресторанов (' . implode(', ', $nums) . ') — выберите вручную',
            ];
            continue;
        }

        $match = $scored[0]['req'];
        $warning = null;

        $preview[] = [
            'row' => $fileRow,
            'match' => [
                'request_id'         => (int)$match['id'],
                'restaurant_number'  => (int)$match['restaurant_number'],
                'restaurant_address' => $match['restaurant_address'],
                'status'             => $match['status'],
                'current_driver'     => $match['driver'],
                'current_vehicle'    => $match['vehicle'],
            ],
            'warning' => $warning,
        ];
        $commitActions[] = ['req' => $match, 'file' => $fileRow, 'warning' => $warning];
    }

    $commitVal = $_POST['commit'] ?? $body['commit'] ?? null;
    $isCommit = ($commitVal === 'true' || $commitVal === true || $commitVal === 1 || $commitVal === '1');

    // Ручные оверрайды от фронта: { "0": 123, "5": null, ... } — индекс строки
    // превью → request_id (или null чтобы «не назначать»). Перекрывают авто-матч.
    $overridesRaw = $_POST['overrides'] ?? $body['overrides'] ?? null;
    $overrides = [];
    if (is_string($overridesRaw) && $overridesRaw !== '') {
        $decoded = json_decode($overridesRaw, true);
        if (is_array($decoded)) $overrides = $decoded;
    } elseif (is_array($overridesRaw)) {
        $overrides = $overridesRaw;
    }

    // Множество SUBMITTED-заявок для валидации оверрайдов (защита от подмены)
    $validRequestIds = [];
    foreach ($requests as $req) $validRequestIds[(int)$req['id']] = $req;

    if ($isCommit) {
        // Собираем итоговый план применения: по каждой строке превью —
        // либо ручной оверрайд, либо авто-матч. Если есть и то и то, оверрайд побеждает.
        $applyPlan = [];
        foreach ($preview as $idx => $pv) {
            $fileRow = $pv['row'];
            $reqId = null;
            if (array_key_exists((string)$idx, $overrides) || array_key_exists($idx, $overrides)) {
                $ov = $overrides[(string)$idx] ?? $overrides[$idx] ?? null;
                if ($ov === null || $ov === '' || $ov === 'null') {
                    continue; // юзер выбрал «не назначать»
                }
                $reqId = (int)$ov;
                if (!isset($validRequestIds[$reqId])) continue; // защита от подмены
            } elseif (!empty($pv['match']['request_id'])) {
                $reqId = (int)$pv['match']['request_id'];
            } else {
                continue;
            }
            $applyPlan[] = ['req_id' => $reqId, 'file' => $fileRow];
        }

        // Защита от двойного назначения одной заявки разным файловым строкам:
        // первая выигрывает, дубли отбрасываем.
        $seenReq = [];
        $applyPlanUnique = [];
        foreach ($applyPlan as $row) {
            if (isset($seenReq[$row['req_id']])) continue;
            $seenReq[$row['req_id']] = true;
            $applyPlanUnique[] = $row;
        }

        $pendingNotifications = [];
        $pdo->beginTransaction();
        try {
            foreach ($applyPlanUnique as $action) {
                $reqId = (int)$action['req_id'];
                $file  = $action['file'];
                $stmt = $pdo->prepare("
                    UPDATE keg_returns SET vehicle = ?, driver = ?, status = 'ROUTED', routed_at = NOW()
                    WHERE id = ? AND status = 'SUBMITTED'
                ");
                $stmt->execute([
                    $file['vehicle'] ?? '',
                    $file['driver']  ?? '',
                    $reqId,
                ]);
                if ($stmt->rowCount() > 0) {
                    $pendingNotifications[] = $reqId;
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('keg_returns import-routing commit: ' . $e->getMessage());
            krRespond(['error' => 'Ошибка при сохранении маршрутизации'], 500);
        }
        // Уведомления отправляем только после успешного commit.
        foreach ($pendingNotifications as $rid) {
            $routedRow = krGetReturnWithItems($pdo, $rid);
            if ($routedRow) krNotifyRouted($pdo, $routedRow);
        }
        krRespond([
            'preview' => $preview,
            'available_requests' => $availableRequests,
            'committed' => count($applyPlanUnique),
        ]);
    }

    krRespond([
        'preview' => $preview,
        'available_requests' => $availableRequests,
    ]);
}

// Если ни один маршрут не сработал
krRespond(['error' => 'Не найдено'], 404);
