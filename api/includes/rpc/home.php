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

        $out = ['pending' => null, 'warehouse' => null, 'unreceived' => null, 'expiring' => null];

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

        // ── 2. Поставки на склад: заказы отдела закупок с сегодняшней датой
        // поставки. Раньше здесь стояли те же so_orders, что и в первой
        // цифре — получалось две цифры про одно и то же. Настоящие приходы
        // на склад лежат в orders: delivery_date — дата поставки,
        // received_at — отметка приёмки.
        if ($can('plan-fact')) {
            try {
                $st = $pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            SUM(received_at IS NOT NULL) AS received,
                            COUNT(DISTINCT supplier) AS suppliers
                     FROM orders
                     WHERE delivery_date = CURDATE()" . $leSql
                );
                $st->execute($leArgs);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $warehouse = [
                    'value'     => intval($r['total'] ?? 0),
                    'received'  => intval($r['received'] ?? 0),
                    'suppliers' => intval($r['suppliers'] ?? 0),
                    'next_date' => null,
                    'next_count' => 0,
                ];
                // Сегодня пусто — подсказываем, когда ближайшая поставка,
                // иначе ноль читается как «ничего не везут вообще».
                if ($warehouse['value'] === 0) {
                    $st = $pdo->prepare(
                        "SELECT delivery_date, COUNT(*) AS cnt
                         FROM orders
                         WHERE delivery_date > CURDATE()" . $leSql . "
                         GROUP BY delivery_date ORDER BY delivery_date LIMIT 1"
                    );
                    $st->execute($leArgs);
                    if ($n = $st->fetch(PDO::FETCH_ASSOC)) {
                        $warehouse['next_date'] = $n['delivery_date'];
                        $warehouse['next_count'] = intval($n['cnt']);
                    }
                }
                $out['warehouse'] = $warehouse;

                // Поставки, которые приехали, но никто не отметил приёмку.
                // Смотрим на две недели назад: более старое — это уже не
                // «забыли отметить», а разбор архива.
                $st = $pdo->prepare(
                    "SELECT COUNT(*) AS cnt FROM orders
                     WHERE received_at IS NULL
                       AND delivery_date < CURDATE()
                       AND delivery_date >= CURDATE() - INTERVAL 14 DAY" . $leSql
                );
                $st->execute($leArgs);
                $out['unreceived'] = ['value' => intval($st->fetchColumn())];
            } catch (Throwable $e) {
                error_log('home_stats warehouse: ' . $e->getMessage());
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
