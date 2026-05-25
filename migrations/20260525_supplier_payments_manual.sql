-- Разрешаем ручное создание оплаты без привязки к заказу.
-- /payments получает кнопку «+ Добавить вручную» для случаев когда заказ
-- забыли провести через портал.
--
-- order_id раньше был NOT NULL — теперь NULL допустим.

ALTER TABLE supplier_payments MODIFY COLUMN order_id CHAR(36) NULL;
