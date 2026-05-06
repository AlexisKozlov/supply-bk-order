-- Догоняющая миграция: legal_entity и расширенный UNIQUE для ro_stock_balances.
--
-- В исходной миграции 20260409 колонки legal_entity не было, а UNIQUE стоял по
-- (sku, balance_date). В реальной БД это поправили ALTER'ом, но в репозитории
-- остался старый текст — на чистой базе остатки одного юрлица молча затирали бы
-- остатки другого.
--
-- Эта миграция приводит схему чистой БД к проду:
--   1) добавляет legal_entity (если её нет);
--   2) если ещё активен старый UNIQUE по (sku, balance_date) — удаляет;
--   3) создаёт новый UNIQUE по (sku, legal_entity, balance_date).
-- Идемпотентно: при повторном запуске ничего не делает.

ALTER TABLE ro_stock_balances
  ADD COLUMN IF NOT EXISTS legal_entity VARCHAR(100) NOT NULL DEFAULT '' AFTER warehouse;

-- Удалить старый UNIQUE без legal_entity, если он остался.
SET @has_old_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_stock_balances'
    AND index_name = 'uq_ro_stock_sku_date'
);
SET @sql := IF(@has_old_uq > 0, 'ALTER TABLE ro_stock_balances DROP INDEX uq_ro_stock_sku_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Создать корректный UNIQUE c учётом юрлица, если ещё нет.
SET @has_new_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_stock_balances'
    AND index_name = 'uq_ro_stock_sku_le_date'
);
SET @sql := IF(@has_new_uq = 0, 'ALTER TABLE ro_stock_balances ADD UNIQUE KEY uq_ro_stock_sku_le_date (sku, legal_entity, balance_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
