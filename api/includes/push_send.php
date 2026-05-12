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

    $stmt = $pdo->prepare("
        SELECT id, endpoint, p256dh, auth
        FROM push_subscriptions
        WHERE restaurant_number = ? AND legal_entity_group = ?
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
