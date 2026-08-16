<?php
// Единый источник данных о юридических лицах.
// Группы: BK_VM — «Бургер БК» + «Воглия Матта», PS — «Пицца Стар»

function getEntityGroup($legalEntity) {
    if (!$legalEntity) return 'BK_VM';
    return (strpos($legalEntity, 'Пицца Стар') !== false) ? 'PS' : 'BK_VM';
}

// Полное юрлицо ресторана по его номеру + группе.
// Источник истины — колонка restaurants.legal_entity (миграция 20260506).
// Хардкод оставлен как fallback на случай нового ресторана, для которого
// колонка ещё не заполнена.
function roGetLegalEntity($pdo, $restaurantNumber, $group = null) {
    $num = (int)$restaurantNumber;
    if ($group === null) {
        $s = $pdo->prepare("SELECT legal_entity, legal_entity_group FROM restaurants WHERE number = ? AND active = 1 LIMIT 1");
        $s->execute([$num]);
        $row = $s->fetch();
        if ($row) {
            if (!empty($row['legal_entity'])) return $row['legal_entity'];
            $group = $row['legal_entity_group'] ?: 'BK_VM';
        } else {
            $group = 'BK_VM';
        }
    } else {
        // Группа известна — берём legal_entity по паре (number, group).
        $s = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE number = ? AND legal_entity_group = ? AND active = 1 LIMIT 1");
        $s->execute([$num, $group]);
        $le = $s->fetchColumn();
        if (!empty($le)) return $le;
    }
    // Fallback (для несуществующих/новых ресторанов).
    if ($group === 'PS') return 'ООО "Пицца Стар"';
    if ($num === 3) return 'ООО "Воглия Матта"';
    return 'ООО "Бургер БК"';
}

// Возвращает список полных названий юрлиц, входящих в группу ('BK_VM' | 'PS').
// Нужно для таблиц с данными (analysis_data, stock_collections, orders и т.п.),
// где хранится textовая колонка legal_entity без колонки legal_entity_group.
function getEntitiesInGroup($group) {
    if ($group === 'PS') return ['ООО "Пицца Стар"'];
    return ['ООО "Бургер БК"', 'ООО "Воглия Матта"'];
}

/**
 * Подзапрос «взять одну карточку товара из общего справочника products».
 *
 * products и suppliers — ОБЩИЙ справочник группы: физически он лежит под
 * главным юрлицом (у BK_VM это «Бургер БК», 183 активных товара). Если
 * джойнить его по одному юрлицу, «Воглия Матта» остаётся ни с чем: у неё в
 * products всего 6 строк и все неактивные. Товар приезжает пустым — без веса,
 * паллет и кратности, и в сводке по дню у ресторана №3 стояло 0 паллет при
 * полном заказе на 47 позиций.
 *
 * Отдаёт id одной карточки: сначала активные, при равенстве — карточка своего
 * юрлица. Одна строка на артикул, поэтому JOIN не размножает позиции заказа.
 *
 * $skuExpr       — выражение с артикулом из внешнего запроса, например 'oi.sku'
 * $ownEntityExpr — выражение со «своим» юрлицом: 'o.legal_entity' или '?'.
 *                  Для '?' параметр дописывает вызывающий код СРАЗУ после
 *                  вызова — в SQL он идёт следом за списком юрлиц группы.
 * $params        — массив параметров запроса, функция дописывает юрлица группы
 * $activeOnly    — брать только активные карточки. Ставить там, где так было
 *                  и раньше: снятый с каталога товар не должен вдруг начать
 *                  подсказывать фасовку и кратность там, где их не показывали.
 */
function productsPickIdSql(string $skuExpr, string $group, string $ownEntityExpr, array &$params, bool $activeOnly = false): string {
    $entities = getEntitiesInGroup($group);
    $ph = implode(',', array_fill(0, count($entities), '?'));
    foreach ($entities as $e) $params[] = $e;
    $activeSql = $activeOnly ? ' AND p2.is_active = 1' : '';
    return "(SELECT p2.id
               FROM products p2
              WHERE p2.sku = {$skuExpr} AND p2.legal_entity IN ({$ph}){$activeSql}
              ORDER BY p2.is_active DESC, ({$ownEntityExpr} = p2.legal_entity) DESC, p2.id ASC
              LIMIT 1)";
}

/**
 * То же, что productsPickIdSql, но когда юрлицо приходит КОЛОНКОЙ и в одном
 * запросе могут смешаться разные группы (телеграм-бот, дашборд, аналитика:
 * `... a.legal_entity ...` без внешнего фильтра). Параметров не требует.
 *
 * Заменяет старое условие `p.legal_entity = a.legal_entity`: справочник общий
 * на группу, у «Воглии Матты» своих карточек в products почти нет (6 штук, все
 * архивные), и все её строки приезжали без названия и фасовки.
 *
 * Группа считается тем же правилом, что и getEntityGroup() выше.
 */
function productsPickIdByEntityColSql(string $skuExpr, string $entityColExpr): string {
    $groupSql = "(CASE WHEN {$entityColExpr} LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END)";
    return "(SELECT p2.id
               FROM products p2
              WHERE p2.sku = {$skuExpr} AND p2.legal_entity_group = {$groupSql}
              ORDER BY p2.is_active DESC, ({$entityColExpr} = p2.legal_entity) DESC, p2.id ASC
              LIMIT 1)";
}

// Применяет фильтр "legal_entity IN (список полных названий группы)" к SQL-запросу.
// Для таблиц с данными (в которых нет legal_entity_group). $group — 'BK_VM' | 'PS'.
function applyEntityTextFilter($group, &$where, &$params, $column = 'legal_entity') {
    $entities = getEntitiesInGroup($group);
    $phs = implode(',', array_fill(0, count($entities), '?'));
    $col = (strpos($column, '.') !== false) ? $column : "`$column`";
    $where[] = "$col IN ({$phs})";
    foreach ($entities as $e) $params[] = $e;
}

// Красивое отображение номера ресторана в интерфейсе.
// PS-рестораны в БД хранятся в диапазоне 1001+, но показываются как 'PS01', 'PS02'.
// Для BK_VM — обычное число. Если группа не задана — определяем по значению (1000+ = PS).
function formatRestaurantNumber($number, $group = null) {
    $n = (int)$number;
    if ($n <= 0) return '';
    if ($group === null) $group = ($n >= 1000) ? 'PS' : 'BK_VM';
    if ($group === 'PS') {
        return 'PS' . str_pad((string)($n - 1000), 2, '0', STR_PAD_LEFT);
    }
    return (string)$n;
}

// Парсер пользовательского ввода в номер ресторана для БД.
// Понимает 'PS01', 'ps1', '1001', '24', ' 24 '. Возвращает ['number' => int, 'group' => string]
// или null, если не распознано.
function parseRestaurantInput($input) {
    if ($input === null) return null;
    $s = trim(strtoupper((string)$input));
    if ($s === '') return null;
    if (preg_match('/^PS[\s\-]?0*(\d{1,3})$/', $s, $m)) {
        $inGroup = (int)$m[1];
        if ($inGroup <= 0) return null;
        return ['number' => 1000 + $inGroup, 'group' => 'PS'];
    }
    if (preg_match('/^0*(\d+)$/', $s, $m)) {
        $n = (int)$m[1];
        if ($n <= 0) return null;
        return ['number' => $n, 'group' => ($n >= 1000) ? 'PS' : 'BK_VM'];
    }
    return null;
}

// Оставлено для обратной совместимости со старым кодом, который мог
// ожидать формат с LIKE-шаблонами. Новый код должен пользоваться
// getEntityGroup() и фильтровать по колонке legal_entity_group.
function getEntityFilter($legalEntity) {
    if (getEntityGroup($legalEntity) === 'PS') {
        return ['like' => ['%Пицца Стар%']];
    }
    return ['like' => ['%Бургер БК%', '%Воглия Матта%']];
}

// Применяет фильтр по группе юрлиц к SQL-запросу.
// Работает с новой колонкой legal_entity_group (точное совпадение, индексируется).
// $column — имя колонки (можно с префиксом таблицы, напр. 'p.legal_entity_group').
function applyEntityGroupFilter($legalEntity, &$where, &$params, $column = 'legal_entity_group') {
    $group = getEntityGroup($legalEntity);
    // Префикс таблицы (напр. `p.legal_entity_group`) оставляем как есть,
    // иначе оборачиваем в бэктики.
    $col = (strpos($column, '.') !== false) ? $column : "`$column`";
    $where[] = "$col = ?";
    $params[] = $group;
}

// Возвращает массив кодов групп ('BK_VM','PS'), к которым у пользователя есть доступ.
function userGroupCodes($sessionUser) {
    if (!$sessionUser) return [];
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
    if (!is_array($userEntities) || empty($userEntities)) return [];
    $groups = [];
    foreach ($userEntities as $le) {
        $g = getEntityGroup($le);
        if ($g) $groups[$g] = true;
    }
    return array_keys($groups);
}

// Проверяет доступ пользователя к группе юрлиц ('BK_VM' | 'PS').
// Используется для таблиц, где область видимости — группа, а не одно
// юрлицо (например, stock_collections). Админ видит всё.
function checkLegalEntityGroupAccess($sessionUser, $group) {
    if (!$sessionUser) return true;
    if (($sessionUser['role'] ?? '') === 'admin') return true;
    if (!$group) return false;
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
    if (!is_array($userEntities) || empty($userEntities)) return false;
    foreach ($userEntities as $le) {
        if (getEntityGroup($le) === $group) return true;
    }
    return false;
}
