-- 2026-05-09: добавление колонки legal_entity_group в таблицы, где её ещё нет.
-- Закрывает производительность JOIN-ов вида
--   ON r.legal_entity_group = (CASE WHEN o.legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END)
-- которые блокируют использование индексов и заставляют MySQL делать
-- LIKE '%...' по varchar на каждой строке. После миграции эти CASE
-- заменяются на простое сравнение o.legal_entity_group = r.legal_entity_group,
-- индексируемое и быстрое.
--
-- Размеры таблиц на момент миграции (всё небольшое):
--   orders=121, so_orders=1072, product_prices=463, ro_templates=294
-- UPDATE проходит мгновенно.

-- ─── orders ─────────────────────────────────────────────────────────
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;
UPDATE orders
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END;
CREATE INDEX IF NOT EXISTS idx_orders_le_group ON orders (legal_entity_group);

-- ─── so_orders ──────────────────────────────────────────────────────
ALTER TABLE so_orders
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;
UPDATE so_orders
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END;
CREATE INDEX IF NOT EXISTS idx_so_orders_le_group ON so_orders (legal_entity_group);

-- ─── product_prices ─────────────────────────────────────────────────
ALTER TABLE product_prices
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;
UPDATE product_prices
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END;
CREATE INDEX IF NOT EXISTS idx_product_prices_le_group ON product_prices (legal_entity_group);

-- ─── ro_templates ───────────────────────────────────────────────────
ALTER TABLE ro_templates
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;
UPDATE ro_templates
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END;
CREATE INDEX IF NOT EXISTS idx_ro_templates_le_group ON ro_templates (legal_entity_group);
