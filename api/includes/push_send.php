<?php
/**
 * Хелпер отправки Web Push уведомлений из крона.
 * Использует minishlink/web-push (composer).
 *
 * Использование:
 *   $count = pushSendToRestaurant($pdo, $restaurantNumber, $legalEntityGroup, [
 *       'title' => '⏰ Напоминание',
 *       'body'  => 'Сегодня до 14:00 подайте заявку...',
 *       'url'   => '/restaurant/reminders',
 *       'tag'   => 'reminder-<supplier>-<date>',  // не показывать дубликаты
 *   ]);
 *
 * Возвращает количество УСПЕШНО доставленных push-сообщений.
 * Endpoint-ы, помеченные браузером как expired/invalid, удаляются автоматически.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function pushSendToRestaurant(PDO $pdo, int $restaurantNumber, string $legalEntityGroup, array $payload): int {
    $vapidPublic = $_ENV['VAPID_PUBLIC'] ?? '';
    $vapidPrivate = $_ENV['VAPID_PRIVATE'] ?? '';
    $vapidSubject = $_ENV['VAPID_SUBJECT'] ?? 'mailto:support@example.com';
    if (!$vapidPublic || !$vapidPrivate) return 0;

    // Отключённому ресторану push не шлём — как и в Telegram.
    $stmt = $pdo->prepare("
        SELECT p.id, p.endpoint, p.p256dh, p.auth
        FROM push_subscriptions p
        JOIN restaurants r ON r.number = p.restaurant_number
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = p.legal_entity_group COLLATE utf8mb4_unicode_ci
             AND r.active = 1
        WHERE p.restaurant_number = ? AND p.legal_entity_group = ?
    ");
    $stmt->execute([$restaurantNumber, $legalEntityGroup]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    try {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $vapidSubject,
                'publicKey'  => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ], [], 10);
    } catch (\Throwable $e) {
        error_log('[push_send] init error: ' . $e->getMessage());
        return 0;
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $byEndpoint = [];
    foreach ($rows as $r) {
        $sub = Subscription::create([
            'endpoint'        => $r['endpoint'],
            'publicKey'       => $r['p256dh'],
            'authToken'       => $r['auth'],
            'contentEncoding' => 'aesgcm',
        ]);
        $webPush->queueNotification($sub, $payloadJson);
        $byEndpoint[$r['endpoint']] = (int)$r['id'];
    }

    $ok = 0;
    foreach ($webPush->flush() as $report) {
        $endpointStr = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $ok++;
            // Обновим last_used_at
            if (isset($byEndpoint[$endpointStr])) {
                try {
                    $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")
                        ->execute([$byEndpoint[$endpointStr]]);
                } catch (\Throwable $e) { /* ignore */ }
            }
        } else if ($report->isSubscriptionExpired()) {
            // Подписка устарела — удаляем
            if (isset($byEndpoint[$endpointStr])) {
                try {
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")
                        ->execute([$byEndpoint[$endpointStr]]);
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
    }
    return $ok;
}

/**
 * Отправка push сотруднику портала (не ресторану).
 *
 * Подписки сотрудников лежат в той же таблице, но привязаны к user_id, а не
 * к номеру ресторана: их ставит /api/push/subscribe, когда в сессии
 * staff-пользователь. Отдельная функция нужна, потому что
 * pushSendToRestaurant джойнит restaurants и такие подписки отбрасывает.
 *
 * @return int сколько устройств получило уведомление
 */
function pushSendToUser(PDO $pdo, int $userId, array $payload): int {
    $vapidPublic  = $_ENV['VAPID_PUBLIC'] ?? '';
    $vapidPrivate = $_ENV['VAPID_PRIVATE'] ?? '';
    $vapidSubject = $_ENV['VAPID_SUBJECT'] ?? 'mailto:support@example.com';
    if (!$vapidPublic || !$vapidPrivate || $userId <= 0) return 0;

    $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    try {
        $webPush = new WebPush([
            'VAPID' => ['subject' => $vapidSubject, 'publicKey' => $vapidPublic, 'privateKey' => $vapidPrivate],
        ]);
    } catch (\Throwable $e) {
        error_log('pushSendToUser: ' . $e->getMessage());
        return 0;
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $byEndpoint = [];
    foreach ($rows as $r) {
        $sub = Subscription::create([
            'endpoint'        => $r['endpoint'],
            'publicKey'       => $r['p256dh'],
            'authToken'       => $r['auth'],
            'contentEncoding' => 'aesgcm',
        ]);
        $webPush->queueNotification($sub, $payloadJson);
        $byEndpoint[$r['endpoint']] = (int)$r['id'];
    }

    $ok = 0;
    foreach ($webPush->flush() as $report) {
        $endpointStr = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $ok++;
            if (isset($byEndpoint[$endpointStr])) {
                try { $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")->execute([$byEndpoint[$endpointStr]]); }
                catch (\Throwable $e) { /* ignore */ }
            }
        } elseif ($report->isSubscriptionExpired()) {
            if (isset($byEndpoint[$endpointStr])) {
                try { $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$byEndpoint[$endpointStr]]); }
                catch (\Throwable $e) { /* ignore */ }
            }
        }
    }
    return $ok;
}

/**
 * Отправка на ОДНУ подписку — используется для проверочного уведомления
 * сразу после включения: человек должен увидеть, что всё работает, а не
 * гадать по серой кнопке.
 *
 * @return bool доставлено или нет. Протухшая подписка удаляется.
 */
function pushSendToEndpoint(PDO $pdo, string $endpoint, array $payload): bool {
    $vapidPublic = $_ENV['VAPID_PUBLIC'] ?? '';
    $vapidPrivate = $_ENV['VAPID_PRIVATE'] ?? '';
    $vapidSubject = $_ENV['VAPID_SUBJECT'] ?? 'mailto:support@example.com';
    if (!$vapidPublic || !$vapidPrivate || !$endpoint) return false;

    $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE endpoint = ?");
    $stmt->execute([$endpoint]);
    $row = $stmt->fetch();
    if (!$row) return false;

    try {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $vapidSubject,
                'publicKey'  => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ], [], 10);
    } catch (\Throwable $e) {
        error_log('[push_send] init error: ' . $e->getMessage());
        return false;
    }

    $sub = Subscription::create([
        'endpoint'        => $row['endpoint'],
        'publicKey'       => $row['p256dh'],
        'authToken'       => $row['auth'],
        'contentEncoding' => 'aesgcm',
    ]);
    $report = $webPush->sendOneNotification($sub, json_encode($payload, JSON_UNESCAPED_UNICODE));
    if ($report->isSuccess()) {
        try {
            $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        } catch (\Throwable $e) { /* ignore */ }
        return true;
    }
    if ($report->isSubscriptionExpired()) {
        try { $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$row['id']]); } catch (\Throwable $e) { /* ignore */ }
    } else {
        error_log('[push_send] test send failed: ' . $report->getReason());
    }
    return false;
}
