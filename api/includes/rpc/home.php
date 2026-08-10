<?php
/**
 * RPC: цифры для главной страницы.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS.
 *
 * Главная показывает три числа: что не подано, что сегодня приедет и что
 * горит по срокам. Каждое считается только если у человека есть доступ к
 * соответствующему разделу — иначе в ответе просто null, и плашка не
 * рисуется. Так закупщик без доступа к срокам не увидит чужую цифру.
 */

    if ($fn === 'home_stats') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);

        // Юрлица пользователя. Админ без привязок видит всё.
        $entities = $authUser['legal_entities'] ?? '';
        if (is_string($entities)) $entities = json_decode($entities, true) ?: [];
        if (!is_array($entities)) $entities = [];
        $isAdmin = ($authUser['role'] ?? '') === 'admin';

        // Кэш на минуту: главную открывают часто, а цифры меняются медленно.
        $cacheKey = 'home_stats_' . ($authUserName ?: '_') . '_' . implode('|', $entities);
        $cached = cacheGet($cacheKey, 60);
        if ($cached !== null) respond($cached);

        // Проверка доступа без обрыва запроса: requireModuleAccess отвечает
        // 403 и завершает работу, а нам нужно молча пропустить одну цифру.
        $perms = resolvePermissions($authUser['role'] ?? 'user', $authUser['permissions'] ?? null, $ROLE_TEMPLATES);
        $can = function ($module) use ($perms, $ACCESS_LEVELS) {
            return ($ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0) >= ($ACCESS_LEVELS['view'] ?? 1);
        };

        // Условие по юрлицу для таблиц с колонкой legal_entity.
        $leSql = '';
        $leArgs = [];
        if (!$isAdmin || $entities) {
            if (!$entities) respond(['error' => 'У пользователя нет привязанных юрлиц'], 403);
            $leSql = ' AND legal_entity IN (' . implode(',', array_fill(0, count($entities), '?')) . ')';
            $leArgs = array_values($entities);
        }

        $out = ['pending' => null, 'incoming' => null, 'expiring' => null];

        // ── 1. Заявки поставщикам, которые сегодня положено подать.
        // Считаем чёрные дыры: строки со статусом draft на сегодняшнюю дату
        // подачи. Рядом показываем, сколько всего заявок на сегодня — иначе
        // ноль читается как «работы нет», а не как «всё сдано».
        if ($can('supplier-orders')) {
            try {
                $st = $pdo->prepare(
                    "SELECT
                        SUM(status = 'draft') AS pending,
                        COUNT(*)              AS total,
                        COUNT(DISTINCT CASE WHEN status = 'draft' THEN supplier_id END) AS suppliers
                     FROM so_orders
                     WHERE order_date = CURDATE()" . $leSql
                );
                $st->execute($leArgs);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['pending'] = [
                    'value'     => intval($r['pending'] ?? 0),
                    'total'     => intval($r['total'] ?? 0),
                    'suppliers' => intval($r['suppliers'] ?? 0),
                ];
            } catch (Throwable $e) {
                error_log('home_stats pending: ' . $e->getMessage());
            }
        }

        // ── 2. Приходы на сегодня: сколько поставщиков сегодня везёт.
        // Число поставщиков понятнее числа строк: строк столько же, сколько
        // ресторанов в заявке, и «55 приходов» ничего не говорит.
        if ($can('supplier-orders')) {
            try {
                $st = $pdo->prepare(
                    "SELECT COUNT(DISTINCT supplier_id) AS suppliers, COUNT(*) AS orders
                     FROM so_orders
                     WHERE delivery_date = CURDATE()
                       AND status IN ('submitted','locked')" . $leSql
                );
                $st->execute($leArgs);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['incoming'] = [
                    'value'  => intval($r['suppliers'] ?? 0),
                    'orders' => intval($r['orders'] ?? 0),
                ];
            } catch (Throwable $e) {
                error_log('home_stats incoming: ' . $e->getMessage());
            }
        }

        // ── 3. Сроки годности на складе.
        // У stock_malling нет колонки legal_entity: юрлицо лежит в поле
        // customer коротким именем («Бургер БК»), поэтому фильтруем по нему.
        if ($can('shelf-life')) {
            try {
                $shortSql = '';
                $shortArgs = [];
                if ($leSql) {
                    $short = array_map(function ($e) {
                        return trim(str_replace(['ООО', '"', '«', '»'], '', $e));
                    }, $entities);
                    $shortSql = ' AND TRIM(customer) IN (' . implode(',', array_fill(0, count($short), '?')) . ')';
                    $shortArgs = $short;
                }
                $st = $pdo->prepare(
                    "SELECT COUNT(*) AS cnt, COUNT(DISTINCT warehouse) AS zones
                     FROM stock_malling
                     WHERE expiry_date IS NOT NULL
                       AND expiry_date <= CURDATE() + INTERVAL 7 DAY" . $shortSql
                );
                $st->execute($shortArgs);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['expiring'] = [
                    'value' => intval($r['cnt'] ?? 0),
                    'zones' => intval($r['zones'] ?? 0),
                ];
            } catch (Throwable $e) {
                error_log('home_stats expiring: ' . $e->getMessage());
            }
        }

        cacheSet($cacheKey, $out);
        respond($out);
    }
