-- Добавляет флаг обязательного срока годности для каждой позиции сбора.

ALTER TABLE stock_collection_products
  ADD COLUMN need_expiry TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Нужен срок годности для позиции' AFTER unit;
