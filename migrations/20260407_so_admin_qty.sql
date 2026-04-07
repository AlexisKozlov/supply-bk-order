-- Добавляем admin_qty для редактирования количества закупщиком
ALTER TABLE so_order_items ADD COLUMN admin_qty DECIMAL(10,2) DEFAULT NULL AFTER quantity;
