-- Добавляем поле sort_order для сохранения порядка позиций в заказе
ALTER TABLE `order_items` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `received_qty`;
