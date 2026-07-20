<?php
/**
 * Письма ресторанам о сборе остатков.
 *
 * Поводы (kind):
 *   start        — сбор только что создан;
 *   reminder_24h — за сутки до дедлайна, тем кто ещё не сдал;
 *   reminder_2h  — за 2 часа до дедлайна, тем кто ещё не сдал;
 *   manual       — закупщик нажал «Напомнить».
 *
 * Отправляем с ящика info@ (account 'info'). Почта ресторанов — @burger-king.by,
 * там стоит MailCleaner: заголовки List-Unsubscribe, которые дефолтный ящик
 * добавляет к системным письмам, он считает признаком рассылки и кладёт письмо
 * в спам. У аккаунта 'info' этих заголовков нет.
 *
 * Письма шлём НЕ в веб-запросе: одна отправка ~0.8 сек, ресторанов под 60.
 * Реальную рассылку делает api/cron_stock_collection_mail.php, веб только
 * запускает его в фоне (scMailSpawn) или ждёт планового запуска крона.
 */

require_once __DIR__ . '/mail_send.php';
require_once __DIR__ . '/mail_templates.php';

/** Все поддерживаемые поводы письма (совпадают с ENUM stock_collection_mail_log.kind). */
function scMailKinds(): array {
    return ['start', 'reminder_24h', 'reminder_2h', 'manual'];
}

/**
 * Дедлайн в человеческом виде: «21.07.2026 в 10:00».
 * Время наивное, в минском поясе — как его ввёл закупщик и как хранит MySQL.
 */
function scMailFormatDeadline(?string $deadlineAt): string {
    if (!$deadlineAt) return '';
    $ts = strtotime($deadlineAt);
    if (!$ts) return '';
    return date('d.m.Y', $ts) . ' в ' . date('H:i', $ts);
}

/** Сбор + количество позиций. null, если сбора нет. */
function scMailLoadCollection(PDO $pdo, int $collectionId): ?array {
    $st = $pdo->prepare("
        SELECT sc.id, sc.name, sc.status, sc.legal_entity_group, sc.deadline_at, sc.created_at,
               (SELECT COUNT(*) FROM stock_collection_products p WHERE p.collection_id = sc.id) AS products_count
        FROM stock_collections sc WHERE sc.id = ?
    ");
    $st->execute([$collectionId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Кому ещё нужно отправить письмо этого повода.
 *
 * Берём активные кабинеты ресторанов группы сбора, у которых указан email.
 * Подтверждение адреса не требуем: письмо не содержит ничего секретного, а
 * подтверждённых адресов сильно меньше, чем реально работающих.
 *
 * Исключаем тех, кто уже сдал остатки по этому сбору, и тех, кому письмо
 * этого повода уже уходило. Для 'manual' повтор разрешён — закупщик жмёт
 * кнопку осознанно.
 */
function scMailRecipients(PDO $pdo, array $coll, string $kind, int $minGapHours = 0): array {
    $params = [$coll['legal_entity_group'], (int)$coll['id']];
    $skipLogged = '';
    if ($kind !== 'manual') {
        $skipLogged = "
          AND NOT EXISTS (
            SELECT 1 FROM stock_collection_mail_log ml
            WHERE ml.collection_id = ? AND ml.restaurant_number = u.restaurant_number
              AND ml.kind = ? AND ml.success = 1
          )";
        $params[] = (int)$coll['id'];
        $params[] = $kind;
    }

    // Пауза после предыдущего письма по этому же сбору — чтобы напоминание не
    // пришло следом за письмом о старте.
    $skipRecent = '';
    if ($minGapHours > 0) {
        // Часы подставляем в текст запроса: значение всегда наше целое число,
        // а MySQL не принимает плейсхолдер в INTERVAL при нативных prepare.
        $skipRecent = "
          AND NOT EXISTS (
            SELECT 1 FROM stock_collection_mail_log ml2
            WHERE ml2.collection_id = ? AND ml2.restaurant_number = u.restaurant_number
              AND ml2.sent_at > NOW() - INTERVAL " . (int)$minGapHours . " HOUR
          )";
        $params[] = (int)$coll['id'];
    }

    $st = $pdo->prepare("
        SELECT u.restaurant_number, u.email
        FROM ro_users u
        JOIN restaurants r ON r.number = u.restaurant_number AND r.legal_entity_group = u.legal_entity_group
        WHERE u.legal_entity_group = ?
          AND u.is_active = 1
          AND u.email IS NOT NULL AND u.email <> ''
          AND NOT EXISTS (
            SELECT 1 FROM stock_collection_data scd
            WHERE scd.collection_id = ? AND scd.restaurant_number = u.restaurant_number
          )
          {$skipLogged}
          {$skipRecent}
        ORDER BY CAST(u.restaurant_number AS UNSIGNED)
    ");
    $st->execute($params);
    return $st->fetchAll();
}

/** Сколько писем этого повода уйдёт прямо сейчас (для ответа в интерфейсе). */
function scMailPendingCount(PDO $pdo, int $collectionId, string $kind): int {
    $coll = scMailLoadCollection($pdo, $collectionId);
    if (!$coll || $coll['status'] !== 'active') return 0;
    return count(scMailRecipients($pdo, $coll, $kind));
}

/** Тема и HTML письма для конкретного ресторана. */
function scMailBuild(array $coll, string $kind, string $restaurantNumber): array {
    $siteUrl  = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
    $link     = $siteUrl . '/restaurant/stock/' . (int)$coll['id'];
    $name     = (string)$coll['name'];
    $deadline = scMailFormatDeadline($coll['deadline_at'] ?? null);
    $count    = (int)$coll['products_count'];
    $restNum  = function_exists('formatRestaurantNumber')
        ? formatRestaurantNumber((int)$restaurantNumber)
        : (string)$restaurantNumber;

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    switch ($kind) {
        case 'reminder_24h':
            $subject = 'Остатки по сбору «' . $name . '»' . ($deadline ? ' — срок ' . $deadline : '');
            $intro   = 'Остатки по вашему ресторану пока не сданы.';
            break;
        case 'reminder_2h':
            $subject = 'Последние часы: остатки по сбору «' . $name . '»';
            $intro   = 'Срок сдачи остатков истекает совсем скоро.';
            break;
        case 'manual':
            $subject = 'Напоминание: остатки по сбору «' . $name . '»';
            $intro   = 'Остатки по вашему ресторану пока не сданы.';
            break;
        case 'start':
        default:
            $subject = 'Начат сбор остатков: ' . $name;
            $intro   = 'Отдел закупок начал сбор остатков.';
            break;
    }

    // ВАЖНО: минимальный HTML, без брендированного шаблона (renderMailHtml)
    // и без inline-стилей. Адреса ресторанов — на @burger-king.by, их шлюз
    // MailCleaner на тяжёлой вёрстке ставит в теме «{Spam?}» и уносит письмо
    // в спам. На простом HTML запускается Spamc и голосует «ham».
    // Plain text не подходит: без HTML Spamc вообще не стартует.
    $html  = '<html><body>';
    $html .= '<p>' . $esc($intro) . '</p>';
    $html .= '<p>Ресторан №' . $esc($restNum) . '</p>';
    $html .= '<p>Сбор: ' . $esc($name) . '</p>';
    $html .= '<p>Позиций к заполнению: ' . $count . '</p>';
    if ($deadline !== '') {
        $html .= '<p>Заполнить до ' . $esc($deadline) . '</p>';
    }
    $html .= '<p>Заполнить можно в кабинете ресторана: ' . $esc($link) . '</p>';
    $html .= '<p>Либо в Telegram-боте отдела закупок.</p>';
    $html .= '<p>Отдел закупок</p>';
    $html .= '</body></html>';

    return ['subject' => $subject, 'html' => $html];
}

/**
 * Разослать письма. Вызывать только из CLI (крон) — блокирует надолго.
 * Возвращает ['sent' => N, 'failed' => M].
 */
function scSendCollectionEmails(PDO $pdo, int $collectionId, string $kind, int $minGapHours = 0): array {
    $result = ['sent' => 0, 'failed' => 0];
    if (!in_array($kind, scMailKinds(), true)) return $result;

    $coll = scMailLoadCollection($pdo, $collectionId);
    if (!$coll || $coll['status'] !== 'active') return $result;

    $recipients = scMailRecipients($pdo, $coll, $kind, $minGapHours);
    if (!$recipients) return $result;

    $log = $pdo->prepare("
        INSERT INTO stock_collection_mail_log (collection_id, restaurant_number, kind, email, success, error_message, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE email = VALUES(email), success = VALUES(success),
                                error_message = VALUES(error_message), sent_at = NOW()
    ");

    foreach ($recipients as $r) {
        $mail = scMailBuild($coll, $kind, (string)$r['restaurant_number']);
        try {
            $res = sendEmail($r['email'], $mail['subject'], $mail['html'], true, ['account' => 'info']);
        } catch (Throwable $e) {
            $res = ['success' => false, 'error' => $e->getMessage()];
        }
        $ok = !empty($res['success']);
        $ok ? $result['sent']++ : $result['failed']++;
        if (!$ok) {
            error_log('[scSendCollectionEmails] rest=' . $r['restaurant_number'] . ' kind=' . $kind
                . ' failed: ' . ($res['error'] ?? 'unknown'));
        }
        $log->execute([
            (int)$coll['id'], (string)$r['restaurant_number'], $kind, (string)$r['email'],
            $ok ? 1 : 0, $ok ? null : mb_substr((string)($res['error'] ?? ''), 0, 500),
        ]);
        // Пауза между письмами — не упереться в лимит почтового хостинга.
        usleep(400000);
    }

    return $result;
}

/**
 * Запустить рассылку фоном, чтобы не держать веб-запрос.
 * Если запустить не удалось — не страшно: плановый крон (каждые 5 минут)
 * разошлёт то же самое по своим правилам. Исключение — 'manual': его крон
 * сам не делает, поэтому о неудаче сообщаем вызывающему коду.
 */
function scMailSpawn(int $collectionId, string $kind): bool {
    if (!function_exists('exec')) return false;
    if (!in_array($kind, scMailKinds(), true)) return false;
    $script = __DIR__ . '/../cron_stock_collection_mail.php';
    if (!is_file($script)) return false;
    $cmd = 'php ' . escapeshellarg($script)
         . ' --collection=' . escapeshellarg((string)$collectionId)
         . ' --kind=' . escapeshellarg($kind)
         . ' > /dev/null 2>&1 &';
    @exec($cmd, $out, $rc);
    return $rc === 0;
}
