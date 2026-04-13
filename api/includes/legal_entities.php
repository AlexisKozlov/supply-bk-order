<?php
// Единый источник данных о юридических лицах.
// Группы: BK_VM — «Бургер БК» + «Воглия Матта», PS — «Пицца Стар»

function getEntityGroup($legalEntity) {
    if (!$legalEntity) return 'BK_VM';
    return (strpos($legalEntity, 'Пицца Стар') !== false) ? 'PS' : 'BK_VM';
}

// Возвращает список полных названий юрлиц, входящих в группу ('BK_VM' | 'PS').
// Нужно для таблиц с данными (analysis_data, stock_collections, orders и т.п.),
// где хранится textовая колонка legal_entity без колонки legal_entity_group.
function getEntitiesInGroup($group) {
    if ($group === 'PS') return ['ООО "Пицца Стар"'];
    return ['ООО "Бургер БК"', 'ООО "Воглия Матта"'];
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
