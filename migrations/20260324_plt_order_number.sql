-- Добавляем номер заказа в поставки (опционально)
ALTER TABLE `plt_deliveries`
  ADD COLUMN `order_number` VARCHAR(100) DEFAULT NULL AFTER `supplier_name`;
