-- Добавление ставки НДС в таблицы цен

ALTER TABLE `product_prices`
  ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 20.00
  COMMENT 'Ставка НДС, %';

ALTER TABLE `price_history`
  ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(5,2) DEFAULT NULL
  COMMENT 'Ставка НДС, %';
