-- Добавляем колонку legal_entity_group в ro_orders.
--
-- Зачем: в коде в 12+ SQL-запросах стоит выражение
--   CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END
-- Если юрлицо когда-нибудь переименуют (например, добавят дефис), все эти
-- фильтры тихо перестанут работать правильно. Делаем явную колонку, по которой
-- можно фильтровать напрямую (и индекс, чтобы было быстро).
--
-- Заполняем существующие записи по правилу: PS — если в legal_entity встречается
-- «Пицца Стар», иначе BK_VM (Бургер БК + Воглия Матта).

ALTER TABLE ro_orders
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

UPDATE ro_orders
SET legal_entity_group = CASE
  WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
  ELSE 'BK_VM'
END
WHERE legal_entity_group = 'BK_VM' AND legal_entity LIKE '%Пицца Стар%';

SET @has_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_orders'
    AND index_name = 'idx_ro_orders_group_date'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE ro_orders ADD KEY idx_ro_orders_group_date (legal_entity_group, delivery_date)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
