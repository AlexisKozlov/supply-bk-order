<?php
/**
 * Уведомления ресторану о правках закупщика в ЗАКРЫТОМ сборе остатков.
 *
 * Закрытый сбор в кабинете не показывается, поэтому о правке ресторан узнать
 * не может. Шлём сообщение в Telegram — но не на каждую ячейку: закупщик
 * правит подряд несколько позиций, и десять сообщений вместо одного никому не
 * нужны. Правки копятся в sc_edit_notifications, отправляет крон
 * cron_sc_edit_notify.php, когда правки прекратились.
 */

/** Через сколько минут тишины после последней правки отправлять сообщение. */
const SC_EDIT_QUIET_MINUTES = 3;

/**
 * Копит одну правку. Если по этому ресторану и сбору уже есть неотправленное
 * уведомление — дописывает изменение в него и сдвигает время последней правки.
 */
function scQueueEditNotification(PDO $pdo, int $collectionId, string $restaurantNumber,
                                 int $productId, float $was, float $now): void {
    try {
        $nameStmt = $pdo->prepare("SELECT product_name FROM stock_collection_products WHERE id = ?");
        $nameStmt->execute([$productId]);
        $productName = (string)$nameStmt->fetchColumn();
        if ($productName === '') $productName = 'Товар #' . $productId;

        $sel = $pdo->prepare("SELECT id, changes FROM sc_edit_notifications
                              WHERE collection_id = ? AND restaurant_number = ? AND sent_at IS NULL
                              LIMIT 1");
        $sel->execute([$collectionId, $restaurantNumber]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        $entry = ['product' => $productName, 'was' => $was, 'now' => $now];

        if ($row) {
            $changes = json_decode((string)$row['changes'], true) ?: [];
            // Повторная правка той же позиции: «было» остаётся исходным,
            // обновляем только «стало» — иначе в сообщении будет цепочка.
            $found = false;
            foreach ($changes as &$ch) {
                if (($ch['product'] ?? '') === $productName) { $ch['now'] = $now; $found = true; break; }
            }
            unset($ch);
            if (!$found) $changes[] = $entry;
            $pdo->prepare("UPDATE sc_edit_notifications SET changes = ?, last_edit_at = NOW() WHERE id = ?")
                ->execute([json_encode($changes, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
        } else {
            $pdo->prepare("INSERT INTO sc_edit_notifications (collection_id, restaurant_number, changes, last_edit_at)
                           VALUES (?, ?, ?, NOW())")
                ->execute([$collectionId, $restaurantNumber, json_encode([$entry], JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {
        // Уведомление — дополнение к правке. Если очередь не сработала,
        // сама правка всё равно должна сохраниться.
        error_log('scQueueEditNotification: ' . $e->getMessage());
    }
}

/** Число без лишних нулей: 21.00 → 21, 2.50 → 2.5 */
function scFmtQty(float $v): string {
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
}

/**
 * Отправляет накопленные уведомления, по которым правки прекратились.
 * @return array{sent:int, skipped:int}
 */
function scSendEditNotifications(PDO $pdo, ?callable $log = null): array {
    $log = $log ?: function () {};
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '';
    $out = ['sent' => 0, 'skipped' => 0];

    $rows = $pdo->prepare("
        SELECT n.id, n.collection_id, n.restaurant_number, n.changes, c.name AS collection_name
        FROM sc_edit_notifications n
        JOIN stock_collections c ON c.id = n.collection_id
        WHERE n.sent_at IS NULL
          AND n.last_edit_at < (NOW() - INTERVAL " . SC_EDIT_QUIET_MINUTES . " MINUTE)
        ORDER BY n.id");
    $rows->execute();
    $pending = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (!$pending) return $out;

    if ($botToken === '') {
        $log('нет TELEGRAM_BOT_TOKEN — уведомления не отправлены');
        return $out;
    }

    $subStmt = $pdo->prepare("
        SELECT DISTINCT chat_id FROM ro_telegram_subs
        WHERE restaurant_number = ?
          AND chat_id IS NOT NULL AND chat_id != ''
          AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))");
    $mark = $pdo->prepare("UPDATE sc_edit_notifications SET sent_at = NOW() WHERE id = ?");

    foreach ($pending as $row) {
        $changes = json_decode((string)$row['changes'], true) ?: [];
        if (!$changes) { $mark->execute([(int)$row['id']]); continue; }

        $subStmt->execute([$row['restaurant_number']]);
        $chatIds = array_column($subStmt->fetchAll(PDO::FETCH_ASSOC), 'chat_id');
        if (!$chatIds) {
            // Бот не привязан — отмечаем отправленным, иначе очередь будет
            // копиться вечно. Ресторан узнает при следующем контакте.
            $mark->execute([(int)$row['id']]);
            $out['skipped']++;
            continue;
        }

        $lines = [];
        foreach ($changes as $ch) {
            $lines[] = '• ' . htmlspecialchars((string)($ch['product'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ': <b>' . scFmtQty((float)($ch['was'] ?? 0)) . '</b> → <b>' . scFmtQty((float)($ch['now'] ?? 0)) . '</b>';
        }
        $text = '✏️ <b>Отдел закупок поправил ваши остатки</b>' . "\n"
              . 'Сбор: ' . htmlspecialchars((string)$row['collection_name'], ENT_QUOTES, 'UTF-8') . "\n\n"
              . implode("\n", $lines) . "\n\n"
              . 'Сбор уже закрыт, поэтому в кабинете его не видно. Если цифры неверные — напишите в отдел закупок.';

        $ok = false;
        foreach ($chatIds as $chatId) {
            if (sendTelegramMessage($botToken, $chatId, $text)) $ok = true;
        }
        if ($ok) {
            $mark->execute([(int)$row['id']]);
            $out['sent']++;
            $log(sprintf('ресторан %s, сбор «%s»: отправлено (%d позиц.)',
                $row['restaurant_number'], $row['collection_name'], count($changes)));
        } else {
            $log(sprintf('ресторан %s: отправка не удалась, попробуем в следующий раз', $row['restaurant_number']));
        }
    }
    return $out;
}
