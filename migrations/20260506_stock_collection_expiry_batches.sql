-- Добавляем срок годности и поддержку нескольких партий на один товар.
--
-- Теперь одна позиция сбора может хранить несколько строк:
-- одна строка = одна партия с собственной датой годности и количеством.

ALTER TABLE stock_collection_data
  ADD COLUMN expiry_date DATE DEFAULT NULL COMMENT 'Срок годности партии' AFTER restaurant_number;

ALTER TABLE stock_collection_data
  DROP INDEX uniq_prod_rest;

ALTER TABLE stock_collection_data
  ADD INDEX idx_collection_product_rest_expiry (collection_id, product_id, restaurant_number, expiry_date);
