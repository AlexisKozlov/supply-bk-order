<?php
// Единый источник данных о юридических лицах.
// Группы: BK_VM — «Бургер БК» + «Воглия Матта», PS — «Пицца Стар»

function getEntityGroup($legalEntity) {
    if (!$legalEntity) return 'BK_VM';
    return (strpos($legalEntity, 'Пицца Стар') !== false) ? 'PS' : 'BK_VM';
}

function getEntityFilter($legalEntity) {
    if (getEntityGroup($legalEntity) === 'PS') {
        return ['like' => ['%Пицца Стар%']];
    }
    return ['like' => ['%Бургер БК%', '%Воглия Матта%']];
}

function applyEntityGroupFilter($legalEntity, &$where, &$params, $column = 'legal_entity') {
    $filter = getEntityFilter($legalEntity);
    if (count($filter['like']) === 1) {
        $where[] = "`$column` LIKE ?";
        $params[] = $filter['like'][0];
    } else {
        $clauses = [];
        foreach ($filter['like'] as $pattern) {
            $clauses[] = "`$column` LIKE ?";
            $params[] = $pattern;
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
}
