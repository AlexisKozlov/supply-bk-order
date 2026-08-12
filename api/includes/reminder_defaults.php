<?php
/**
 * Дефолт напоминаний ресторана — ВКЛЮЧЕНО.
 *
 * Раньше карточки «локальный поставщик», «основная поставка» и «возврат кег»
 * были opt-in: пока ресторан сам не включит, напоминания молчали. Теперь
 * наоборот — включено сразу, вместе с дублированием в Telegram всем, кто
 * привязал бота. Выключить по-прежнему можно вручную (переключатель в кабинете
 * или «Напом.» у закупок) — выключение уважается и автоматикой не возвращается.
 *
 * Подписки материализуются строками, а не считаются «на лету»: журнал отправок
 * reminder_runs, баннер «Сегодня» и выбор получателей завязаны на id подписки.
 * Недостающие строки создаёт этот файл — его зовёт крон напоминаний (каждые
 * 5 минут) и вкладка «Напоминания» при открытии.
 *
 * updated_by = RR_AUTO_DEFAULT_BY означает «строку создали автоматически,
 * человек её не трогал». Только у таких строк список Telegram-получателей
 * досинхронизируется: новый сотрудник привязал бота — сразу начинает получать.
 * Как только настройку меняет человек (кабинет 'ro:*', бот 'tg:*', закупки),
 * автосинхронизация этой строки прекращается.
 */

const RR_AUTO_DEFAULT_BY = 'auto:default';

/**
 * Создаёт недостающие подписки-«по умолчанию» и досинхронизирует получателей.
 * $restaurantId — ограничить одним рестораном (id из таблицы restaurants).
 */
function rrEnsureReminderDefaults(PDO $pdo, ?int $restaurantId = null): void {
    try {
        // ── Локальные поставщики: строка на пару (ресторан, поставщик) ──
        $where = $restaurantId ? ' AND ss.restaurant_id = ?' : '';
        $params = $restaurantId ? [$restaurantId] : [];
        // INSERT IGNORE: строка уже есть — значит, настройку кто-то трогал,
        // её не переписываем (в том числе осознанное «выключено»).
        $pdo->prepare("
            INSERT IGNORE INTO restaurant_reminder_subscriptions
                (restaurant_id, supplier_id, is_enabled, portal_enabled, telegram_enabled, cron_managed, updated_by)
            SELECT DISTINCT ss.restaurant_id, ss.supplier_id, 1, 1, 1, 0, '" . RR_AUTO_DEFAULT_BY . "'
            FROM supplier_schedules ss
            JOIN suppliers s ON s.id = ss.supplier_id
            JOIN restaurants r ON r.id = ss.restaurant_id
            WHERE ss.is_active = 1 AND s.is_active = 1 AND s.so_enabled = 0 AND r.active = 1
              $where
        ")->execute($params);

        // ── Основная поставка: строка на ресторан, у которого задан дедлайн ──
        $where = $restaurantId ? ' AND ds.restaurant_id = ?' : '';
        $pdo->prepare("
            INSERT IGNORE INTO restaurant_main_delivery_subscriptions
                (restaurant_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
            SELECT DISTINCT ds.restaurant_id, 1, 1, 1, '" . RR_AUTO_DEFAULT_BY . "'
            FROM delivery_schedule ds
            JOIN restaurants r ON r.id = ds.restaurant_id
            WHERE ds.order_day IS NOT NULL AND ds.order_deadline IS NOT NULL AND r.active = 1
              $where
        ")->execute($params);

        // ── Возврат кег: строка на ресторан с графиком вывоза ──
        $where = $restaurantId ? ' AND r.id = ?' : '';
        $pdo->prepare("
            INSERT IGNORE INTO restaurant_keg_return_subscriptions
                (restaurant_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
            SELECT r.id, 1, 1, 1, '" . RR_AUTO_DEFAULT_BY . "'
            FROM restaurants r
            WHERE r.active = 1 AND r.pickup_weekdays > 0
              $where
        ")->execute($params);

        // ── Получатели Telegram у нетронутых строк = все, кто привязал бота ──
        rrSyncAutoRecipients($pdo, 'restaurant_reminder_subscriptions', 'restaurant_reminder_tg_subscribers', $restaurantId);
        rrSyncAutoRecipients($pdo, 'restaurant_main_delivery_subscriptions', 'restaurant_main_delivery_tg_subscribers', $restaurantId);
        rrSyncAutoRecipients($pdo, 'restaurant_keg_return_subscriptions', 'restaurant_keg_return_tg_subscribers', $restaurantId);
    } catch (Exception $e) {
        // Дефолты не должны ронять ни крон, ни открытие вкладки.
        error_log('rrEnsureReminderDefaults failed: ' . $e->getMessage());
    }
}

/**
 * Досинхронизация получателей Telegram у автоматических (нетронутых) подписок.
 * Имена таблиц — только из кода этого модуля, снаружи не приходят.
 */
function rrSyncAutoRecipients(PDO $pdo, string $subTable, string $tgTable, ?int $restaurantId = null): void {
    $where = $restaurantId ? ' AND sub.restaurant_id = ?' : '';
    $params = $restaurantId ? [$restaurantId] : [];

    // Добавить тех, кто привязал бота уже после создания подписки.
    $pdo->prepare("
        INSERT INTO $tgTable (subscription_id, ro_tg_sub_id, is_active)
        SELECT sub.id, ts.id, 1
        FROM $subTable sub
        JOIN restaurants r ON r.id = sub.restaurant_id
        JOIN ro_telegram_subs ts
          ON ts.restaurant_number = r.number
         AND ts.legal_entity_group = r.legal_entity_group
         AND ts.verified_at IS NOT NULL
         AND ts.chat_id IS NOT NULL
        WHERE sub.updated_by = '" . RR_AUTO_DEFAULT_BY . "'
          $where
        ON DUPLICATE KEY UPDATE is_active = 1
    ")->execute($params);

    // Убрать тех, кто отвязался или больше не относится к этому ресторану.
    $pdo->prepare("
        DELETE t FROM $tgTable t
        JOIN $subTable sub ON sub.id = t.subscription_id
        JOIN restaurants r ON r.id = sub.restaurant_id
        WHERE sub.updated_by = '" . RR_AUTO_DEFAULT_BY . "'
          AND NOT EXISTS (
              SELECT 1 FROM ro_telegram_subs ts
              WHERE ts.id = t.ro_tg_sub_id
                AND ts.verified_at IS NOT NULL
                AND ts.chat_id IS NOT NULL
                AND ts.restaurant_number = r.number
                AND ts.legal_entity_group = r.legal_entity_group
          )
          $where
    ")->execute($params);
}

/**
 * Отметить получателями всех, кто привязал бота в этом ресторане.
 * Вызывается в момент включения напоминаний — чтобы человек сразу видел
 * галочку «Дублировать в Telegram» и отмеченных получателей.
 */
function rrSelectAllTgRecipients(PDO $pdo, string $tgTable, int $subId, int $restaurantPk): void {
    $pdo->prepare("
        INSERT INTO $tgTable (subscription_id, ro_tg_sub_id, is_active)
        SELECT ?, ts.id, 1
        FROM ro_telegram_subs ts
        JOIN restaurants r ON r.id = ?
        WHERE ts.restaurant_number = r.number
          AND ts.legal_entity_group = r.legal_entity_group
          AND ts.verified_at IS NOT NULL
          AND ts.chat_id IS NOT NULL
        ON DUPLICATE KEY UPDATE is_active = 1
    ")->execute([$subId, $restaurantPk]);
}
