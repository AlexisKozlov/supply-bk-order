<?php
/**
 * Единая функция расчёта дедлайна приёма заявок поставщикам.
 *
 * Используется тремя разными точками входа (web/api, telegram-бот, cron),
 * поэтому файл выделен отдельно и подключается самостоятельно, а не через
 * supplier_orders.php (там есть early-return по $endpoint).
 *
 * Приоритет расчёта для пары (supplier_id, delivery_date):
 *   1) so_deadline_overrides на конкретную дату:
 *      - is_closed = 1 → день принудительно закрыт;
 *      - иначе deadline_date/deadline_time из override;
 *      - если deadline_date не заполнена, дата берётся из правила дня недели.
 *   2) so_deadline_rules по дню недели доставки (указывает deadline_dow + deadline_time).
 *   3) default_deadline_time из so_supplier_settings, дедлайн = (delivery_date − 1 день).
 *
 * Часовой пояс — Europe/Minsk.
 */

if (!function_exists('soCalculateDeadlineCore')) {

/**
 * Чистая функция без БД. Принимает уже загруженные override- и rule-строки
 * и default-время. Возвращает готовый дедлайн или признак закрытого дня.
 *
 * @param array|null  $override              запись so_deadline_overrides или null
 * @param array|null  $rule                  запись so_deadline_rules для нужного delivery_dow или null
 * @param string      $defaultDeadlineTime   fallback, формат HH:MM[:SS]
 * @param string      $deliveryDate          Y-m-d
 * @param DateTimeZone|null $tz              по умолчанию Europe/Minsk
 * @return array{
 *   is_closed: bool,
 *   forced_closed: bool,
 *   deadline_dt: ?DateTime,
 *   deadline_str: ?string,
 *   deadline_time: ?string
 * }
 */
function soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz = null) {
    $tz = $tz ?: new DateTimeZone('Europe/Minsk');

    // 1. Принудительно закрытый день
    if ($override && !empty($override['is_closed'])) {
        return [
            'is_closed' => true,
            'forced_closed' => true,
            'deadline_dt' => null,
            'deadline_str' => null,
            'deadline_time' => null,
        ];
    }

    // 2. Вычисляем дату и время дедлайна
    $deliveryObj = new DateTime($deliveryDate, $tz);
    if ($override && !empty($override['deadline_time'])) {
        if (!empty($override['deadline_date'])) {
            $deadlineDate = new DateTime($override['deadline_date'], $tz);
        } elseif ($rule && !empty($rule['deadline_time'])) {
            $deadlineDate = soDeadlineDateByRule($deliveryObj, $rule);
        } else {
            $deadlineDate = (clone $deliveryObj)->modify('-1 day');
        }
        $deadlineTime = $override['deadline_time'];
    } elseif ($rule && !empty($rule['deadline_time'])) {
        $deadlineDate = soDeadlineDateByRule($deliveryObj, $rule);
        $deadlineTime = $rule['deadline_time'];
    } else {
        $deadlineDate = (clone $deliveryObj)->modify('-1 day');
        $deadlineTime = $defaultDeadlineTime ?: '14:00:00';
    }

    $deadlineDT = new DateTime($deadlineDate->format('Y-m-d') . ' ' . $deadlineTime, $tz);
    $deadlineStr = $deadlineDate->format('Y-m-d') . ' ' . substr($deadlineTime, 0, 5);

    $now = new DateTime('now', $tz);
    return [
        'is_closed' => $now >= $deadlineDT,
        'forced_closed' => false,
        'deadline_dt' => $deadlineDT,
        'deadline_str' => $deadlineStr,
        'deadline_time' => $deadlineTime,
    ];
}

function soDeadlineDateByRule($deliveryObj, $rule) {
    $deadlineDow = (int)$rule['deadline_dow'];
    $deliveryDow = (int)$deliveryObj->format('N');
    $deadlineDate = clone $deliveryObj;
    $diff = $deliveryDow - $deadlineDow;
    if ($diff <= 0) $diff += 7;
    $deadlineDate->modify("-{$diff} days");
    return $deadlineDate;
}

/**
 * Обёртка с БД-запросами: сама достаёт override / rule / default для пары
 * (supplier_id, delivery_date) и считает через soCalculateDeadlineCore.
 *
 * @return array результат soCalculateDeadlineCore + ключ 'status' ('open'|'closed').
 */
function soCalculateDeadline($pdo, $supplierId, $deliveryDate) {
    // 1. Override по конкретной дате
    try {
        $s = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
        $s->execute([$supplierId, $deliveryDate]);
        $override = $s->fetch() ?: null;
    } catch (PDOException $e) {
        // Миграция is_closed не применена
        $s = $pdo->prepare("SELECT deadline_time FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
        $s->execute([$supplierId, $deliveryDate]);
        $override = $s->fetch() ?: null;
    }

    // 2. Правило по дню недели доставки
    $rule = null;
    if ($supplierId && (!$override || !empty($override['is_closed']) === false)) {
        $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');
        $r = $pdo->prepare("SELECT deadline_dow, deadline_time FROM so_deadline_rules WHERE supplier_id = ? AND delivery_dow = ?");
        $r->execute([$supplierId, $deliveryDow]);
        $rule = $r->fetch() ?: null;
    }

    // 3. Default из настроек поставщика
    $defaultDeadlineTime = '14:00:00';
    if ($supplierId) {
        $st = $pdo->prepare("SELECT default_deadline_time FROM so_supplier_settings WHERE supplier_id = ?");
        $st->execute([$supplierId]);
        $row = $st->fetch();
        if ($row && !empty($row['default_deadline_time'])) {
            $defaultDeadlineTime = $row['default_deadline_time'];
        }
    }

    $res = soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate);
    $res['status'] = $res['is_closed'] ? 'closed' : 'open';
    return $res;
}

} // if (!function_exists)
