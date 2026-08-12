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

// Автор изменения, когда напоминания включила привязка к боту.
const RR_AUTO_BOT_BY = 'auto:bot';

// Три карточки напоминаний: подписка + её таблица Telegram-получателей.
const RR_SUB_TABLES = [
    ['restaurant_reminder_subscriptions',      'restaurant_reminder_tg_subscribers'],
    ['restaurant_main_delivery_subscriptions', 'restaurant_main_delivery_tg_subscribers'],
    ['restaurant_keg_return_subscriptions',    'restaurant_keg_return_tg_subscribers'],
];

/**
 * «Эту настройку выключал не отдел закупок».
 *
 * Выключение рестораном (кабинет 'ro:*', бот 'tg:*') и автоматические строки
 * можно включать обратно. Выключение закупкой (там в updated_by стоит имя
 * сотрудника) — осознанное решение, его не трогаем.
 */
function rrNotSupplyOffSql(string $alias = 'sub'): string {
    return "($alias.updated_by IS NULL"
         . " OR $alias.updated_by LIKE 'ro:%'"
         . " OR $alias.updated_by LIKE 'tg:%'"
         . " OR $alias.updated_by LIKE 'auto:%')";
}

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
        WHERE sub.updated_by LIKE 'auto:%'
          $where
        ON DUPLICATE KEY UPDATE is_active = 1
    ")->execute($params);

    // Убрать тех, кто отвязался или больше не относится к этому ресторану.
    $pdo->prepare("
        DELETE t FROM $tgTable t
        JOIN $subTable sub ON sub.id = t.subscription_id
        JOIN restaurants r ON r.id = sub.restaurant_id
        WHERE sub.updated_by LIKE 'auto:%'
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
 * Включить ресторану все напоминания и дублирование в Telegram.
 *
 * Зовётся, когда сотрудник ресторана привязал бота: с этого момента ресторану
 * есть куда слать, значит напоминания должны работать и дублироваться в чат.
 *
 * Что делает:
 *   1. создаёт недостающие подписки (дефолт «включено»);
 *   2. включает то, что выключал сам ресторан, — но НЕ трогает выключенное
 *      отделом закупок;
 *   3. снимает «глушилки» заявок, поставленные рестораном (глушилки закупок
 *      остаются — их ставят осознанно, например поставщик к точке не ездит);
 *   4. добавляет в получатели привязанные чаты.
 *
 * $onlyTgSubId — добавить только этого человека (id строки ro_telegram_subs).
 * Так ручной выбор получателей в ресторане не сбрасывается: приходит новый
 * сотрудник — добавляется он один. NULL — добавить всех привязанных.
 *
 * Возвращает счётчики: ['enabled' => …, 'unmuted' => …, 'recipients' => …].
 */
function rrEnableRemindersForRestaurant(PDO $pdo, int $restaurantId, ?int $onlyTgSubId = null, string $actor = RR_AUTO_BOT_BY): array {
    $res = ['enabled' => 0, 'unmuted' => 0, 'recipients' => 0];
    if ($restaurantId <= 0) return $res;
    try {
        rrEnsureReminderDefaults($pdo, $restaurantId);

        // ── 1. Включаем выключенное самим рестораном ──
        foreach (RR_SUB_TABLES as [$subTable, $tgTable]) {
            $st = $pdo->prepare("
                UPDATE $subTable sub
                   SET sub.is_enabled = 1,
                       sub.portal_enabled = 1,
                       sub.telegram_enabled = 1,
                       sub.updated_at = NOW(),
                       sub.updated_by = ?
                 WHERE sub.restaurant_id = ?
                   AND (sub.is_enabled = 0 OR sub.telegram_enabled = 0)
                   AND " . rrNotSupplyOffSql('sub') . "
            ");
            $st->execute([$actor, $restaurantId]);
            $res['enabled'] += $st->rowCount();
        }

        // ── 2. Снимаем глушилки, поставленные самим рестораном ──
        $mute = $pdo->prepare("DELETE FROM so_reminder_mutes WHERE restaurant_id = ? AND created_by LIKE 'ro:%'");
        $mute->execute([$restaurantId]);
        $res['unmuted'] = $mute->rowCount();

        // ── 3. Получатели Telegram ──
        foreach (RR_SUB_TABLES as [$subTable, $tgTable]) {
            $onlyOne = $onlyTgSubId ? ' AND ts.id = ?' : '';
            $params = [$restaurantId];
            if ($onlyTgSubId) $params[] = $onlyTgSubId;
            $ins = $pdo->prepare("
                INSERT INTO $tgTable (subscription_id, ro_tg_sub_id, is_active)
                SELECT sub.id, ts.id, 1
                  FROM $subTable sub
                  JOIN restaurants r ON r.id = sub.restaurant_id
                  JOIN ro_telegram_subs ts
                    ON ts.restaurant_number = r.number
                   AND ts.legal_entity_group = r.legal_entity_group
                   AND ts.verified_at IS NOT NULL
                   AND ts.chat_id IS NOT NULL
                 WHERE sub.restaurant_id = ?
                   AND sub.is_enabled = 1
                   AND sub.telegram_enabled = 1
                   $onlyOne
                ON DUPLICATE KEY UPDATE is_active = 1
            ");
            $ins->execute($params);
            $res['recipients'] += $ins->rowCount();
        }
    } catch (Exception $e) {
        // Привязка к боту не должна падать из-за напоминаний.
        error_log('rrEnableRemindersForRestaurant failed (restaurant ' . $restaurantId . '): ' . $e->getMessage());
    }
    return $res;
}

/**
 * id ресторана по номеру и группе юрлиц — привязка бота знает только их.
 */
function rrRestaurantIdByNumber(PDO $pdo, int $restaurantNumber, string $group): ?int {
    try {
        $st = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $st->execute([$restaurantNumber, $group]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Exception $e) {
        error_log('rrRestaurantIdByNumber failed: ' . $e->getMessage());
        return null;
    }
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
