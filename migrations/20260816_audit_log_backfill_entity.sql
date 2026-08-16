-- Журнал действий: восстановление юрлица у старых записей.
-- Запускать ПОСЛЕ 20260816_audit_log_legal_entity.sql.
--
-- До: 1073 записи из 5422 с юрлицом (20%).
-- После: 4932 с юрлицом, 5035 с юрлицом или группой (93%).
-- Остаток — записи об уже удалённых объектах и глобальные действия
-- (правка учёток сотрудников, курс валюты, загрузка машин: у неё юрлица нет
-- в принципе, рейс везёт заказы обеих компаний).

-- 1. Из самой записи журнала: многие вызовы клали юрлицо в details.
UPDATE audit_log a
   SET a.legal_entity = JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.legal_entity'))
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '')
   AND JSON_VALID(a.details)
   AND JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.legal_entity')) LIKE 'ООО%';

-- 2-9. По связанному объекту.
UPDATE audit_log a JOIN so_orders o ON o.id = a.entity_id
   SET a.legal_entity = o.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type = 'supplier_order' AND o.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN orders o ON o.id = a.entity_id
   SET a.legal_entity = o.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type IN ('order','orders') AND o.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN plans p ON p.id = a.entity_id
   SET a.legal_entity = p.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type IN ('plan','plans') AND p.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN products p ON p.id = a.entity_id
   SET a.legal_entity = p.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type IN ('product','products') AND p.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN suppliers s ON s.id = a.entity_id
   SET a.legal_entity = s.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type IN ('supplier','suppliers') AND s.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN stock_collections c ON c.id = a.entity_id
   SET a.legal_entity = c.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type = 'stock_collection' AND c.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN tenders t ON t.id = a.entity_id
   SET a.legal_entity = t.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type = 'tender' AND t.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN meeting_protocols m ON m.id = a.entity_id
   SET a.legal_entity = m.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.entity_type = 'protocol' AND m.legal_entity IS NOT NULL;

-- 10. Напоминания ресторанов: entity_id — это restaurants.id.
UPDATE audit_log a JOIN restaurants r ON r.id = a.entity_id
   SET a.legal_entity = r.legal_entity
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '')
   AND a.entity_type IN ('restaurant_reminder_subscriptions','restaurant_main_delivery_subscriptions','restaurant_keg_return_subscriptions')
   AND r.legal_entity IS NOT NULL;

-- 11. Группа из деталей — для сущностей, общих на БК+ВМ.
UPDATE audit_log a
   SET a.legal_entity_group = JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.legal_entity_group'))
 WHERE (a.legal_entity IS NULL OR a.legal_entity = '') AND a.legal_entity_group IS NULL
   AND JSON_VALID(a.details)
   AND JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.legal_entity_group')) IN ('BK_VM','PS');

-- 12. Корректировки — по самой корректировке. entity_id может быть списком
-- «100,101», поэтому берём только чисто числовые.
UPDATE audit_log a JOIN order_corrections c ON c.id = CAST(a.entity_id AS UNSIGNED)
   SET a.legal_entity_group = c.legal_entity_group
 WHERE a.legal_entity_group IS NULL AND (a.legal_entity IS NULL OR a.legal_entity = '')
   AND a.entity_type IN ('correction','order_corrections')
   AND a.entity_id REGEXP '^[0-9]+$' AND c.legal_entity_group IS NOT NULL;

-- 13. По ресторану: автор вида «ro:38» / «tg:38» или номер в деталях.
UPDATE audit_log a JOIN restaurants r ON r.number = CAST(SUBSTRING(a.user_name, 4) AS UNSIGNED)
   SET a.legal_entity = r.legal_entity, a.legal_entity_group = r.legal_entity_group
 WHERE a.legal_entity_group IS NULL AND a.user_name REGEXP '^(ro|tg):[0-9]+$' AND r.legal_entity IS NOT NULL;

UPDATE audit_log a JOIN restaurants r ON r.number = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.restaurant_number')) AS UNSIGNED)
   SET a.legal_entity = r.legal_entity, a.legal_entity_group = r.legal_entity_group
 WHERE a.legal_entity_group IS NULL AND JSON_VALID(a.details)
   AND JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.restaurant_number')) REGEXP '^[0-9]+$'
   AND r.legal_entity IS NOT NULL;

-- 14. Группа из юрлица (триггер срабатывает только на новых записях).
UPDATE audit_log
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END
 WHERE legal_entity IS NOT NULL AND legal_entity <> '';
