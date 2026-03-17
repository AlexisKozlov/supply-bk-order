-- Добавить привязку позиции тендера к товару из справочника (по SKU)
ALTER TABLE `tender_items`
  ADD COLUMN `sku` VARCHAR(50) DEFAULT NULL COMMENT 'Артикул из справочника товаров' AFTER `name`;
