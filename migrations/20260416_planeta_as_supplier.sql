-- ═══════════════════════════════════════════════════════════════════
-- Планета Ресторанов → универсальный модуль заявок поставщикам
--
-- Было: отдельная система veg_*, сессионная, со своими таблицами.
-- Стало: обычный поставщик в suppliers с so_enabled=1.
--
-- Что переносим:
--   veg_delivery_days   → so_supplier_schedules
--   veg_deadline_rules  → so_deadline_rules
--   товары последней сессии → so_templates
--   настройки приёма    → so_supplier_settings
--
-- Старые таблицы veg_* НЕ удаляем — нужны для истории заявок.
-- ═══════════════════════════════════════════════════════════════════

-- Таблица so_deadline_rules (если вдруг ещё не создана миграцией)
CREATE TABLE IF NOT EXISTS so_deadline_rules (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id CHAR(36)     NOT NULL COMMENT 'FK → suppliers.id',
  delivery_dow TINYINT     NOT NULL COMMENT '1=ПН … 7=ВС — день поставки',
  deadline_dow TINYINT     NOT NULL COMMENT '1=ПН … 7=ВС — день дедлайна',
  deadline_time TIME       NOT NULL DEFAULT '14:00:00',
  UNIQUE KEY uq_dl_rule (supplier_id, delivery_dow)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заводим поставщика
-- UUID задаём явно, чтобы он не менялся при повторном запуске
INSERT INTO suppliers
  (id, short_name, full_name, legal_entity, legal_entity_group, is_active, so_enabled)
VALUES
  ('bbbbbbbb-0000-4000-a000-000000000001',
   'Планета Ресторанов',
   'Планета Ресторанов',
   'ООО "Бургер БК"',
   'BK_VM',
   1, 1)
ON DUPLICATE KEY UPDATE
  so_enabled = 1,
  is_active  = 1;

-- Настройки приёма (дедлайн по умолчанию 12:00 — как было в veg)
INSERT INTO so_supplier_settings
  (supplier_id, is_accepting_orders, default_deadline_time)
VALUES
  ('bbbbbbbb-0000-4000-a000-000000000001', 1, '12:00:00')
ON DUPLICATE KEY UPDATE
  is_accepting_orders   = 1,
  default_deadline_time = '12:00:00';

-- Правила дедлайнов: копируем из veg_deadline_rules
-- veg_deadline_rules не имеет supplier_id — правила были глобальными
INSERT INTO so_deadline_rules
  (supplier_id, delivery_dow, deadline_dow, deadline_time)
SELECT
  'bbbbbbbb-0000-4000-a000-000000000001',
  delivery_dow,
  deadline_dow,
  deadline_time
FROM veg_deadline_rules
ON DUPLICATE KEY UPDATE
  deadline_dow  = VALUES(deadline_dow),
  deadline_time = VALUES(deadline_time);

-- График доставки: переносим из veg_delivery_days
-- veg_delivery_days.restaurant_number → restaurants.id (нужен FK)
-- order_day = день перед доставкой (если ПН — то ВС предыдущей нед.)
INSERT INTO so_supplier_schedules
  (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
SELECT
  'bbbbbbbb-0000-4000-a000-000000000001',
  r.id,
  CASE WHEN vdd.day_of_week = 1 THEN 7 ELSE vdd.day_of_week - 1 END,
  vdd.day_of_week,
  1,
  NOW(),
  'migration'
FROM veg_delivery_days vdd
JOIN restaurants r
  ON r.number = CAST(vdd.restaurant_number AS UNSIGNED)
  AND r.active = 1
  AND r.legal_entity_group = 'BK_VM'
ON DUPLICATE KEY UPDATE
  delivery_day = VALUES(delivery_day),
  is_active    = 1,
  updated_at   = NOW(),
  updated_by   = 'migration';

-- Шаблоны товаров: из последней сессии (активной или самой свежей)
-- SKU генерируем как PLAN-NNN по номеру товара в сессии
-- Вставляем для обоих юрлиц BK_VM (БК и ВМ)
INSERT INTO so_templates
  (supplier_id, legal_entity, product_id, sku, product_name, sort_order, multiplicity, min_qty, is_active)
SELECT
  'bbbbbbbb-0000-4000-a000-000000000001',
  le.legal_entity,
  NULL,
  CONCAT('PLAN-', LPAD(vsp.id, 3, '0')),
  vsp.product_name,
  vsp.sort_order,
  vsp.multiplicity,
  NULL,
  1
FROM veg_session_products vsp
CROSS JOIN (
  SELECT 'ООО "Бургер БК"'   AS legal_entity
  UNION ALL
  SELECT 'ООО "Воглия Матта"'
) le
WHERE vsp.session_id = (
  SELECT id FROM veg_sessions
  ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id DESC
  LIMIT 1
)
ON DUPLICATE KEY UPDATE
  product_name = VALUES(product_name),
  sort_order   = VALUES(sort_order),
  multiplicity = VALUES(multiplicity),
  is_active    = 1;
