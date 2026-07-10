-- Выделение отдельных RBAC-модулей: keg-returns, reconciliation, tit-requests.
-- Раньше они «висели» на родителях (restaurant-orders / analysis / order).
-- В шаблонах ролей дочерние наследуют уровень родителя автоматически (helpers.php).
-- Здесь переносим ИНДИВИДУАЛЬНЫЕ переопределения пользователей: если у юзера в
-- permissions задан родитель — копируем тот же уровень в дочерний (если ещё не задан),
-- чтобы фактический доступ не изменился (напр. restaurant-orders=none → keg-returns=none).
-- permissions хранится как JSON-текст.

UPDATE users
SET permissions = JSON_SET(permissions, '$."keg-returns"', JSON_UNQUOTE(JSON_EXTRACT(permissions, '$."restaurant-orders"')))
WHERE permissions IS NOT NULL AND permissions <> '' AND permissions <> '{}'
  AND JSON_CONTAINS_PATH(permissions, 'one', '$."restaurant-orders"')
  AND NOT JSON_CONTAINS_PATH(permissions, 'one', '$."keg-returns"');

UPDATE users
SET permissions = JSON_SET(permissions, '$."reconciliation"', JSON_UNQUOTE(JSON_EXTRACT(permissions, '$."analysis"')))
WHERE permissions IS NOT NULL AND permissions <> '' AND permissions <> '{}'
  AND JSON_CONTAINS_PATH(permissions, 'one', '$."analysis"')
  AND NOT JSON_CONTAINS_PATH(permissions, 'one', '$."reconciliation"');

UPDATE users
SET permissions = JSON_SET(permissions, '$."tit-requests"', JSON_UNQUOTE(JSON_EXTRACT(permissions, '$."order"')))
WHERE permissions IS NOT NULL AND permissions <> '' AND permissions <> '{}'
  AND JSON_CONTAINS_PATH(permissions, 'one', '$."order"')
  AND NOT JSON_CONTAINS_PATH(permissions, 'one', '$."tit-requests"');
