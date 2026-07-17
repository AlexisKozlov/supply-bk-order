-- Минимальный заказ у поставщика для модуля «Заявки поставщикам».
--
-- min_order_value — порог минимального заказа (жёсткий блок на подаче заявки).
--                   NULL или 0 = минимума нет, блок не срабатывает.
-- min_order_unit  — единица измерения порога: 'kg' (килограммы) или 'pieces' (штуки).
--                   При заданном value и NULL unit трактуется как 'kg'.
--
-- Обе колонки NULL по умолчанию, чтобы существующие строки поставщиков
-- продолжали работать без минимума и без миграции данных.

ALTER TABLE so_supplier_settings
    ADD COLUMN min_order_value DECIMAL(10,2) NULL DEFAULT NULL,
    ADD COLUMN min_order_unit  VARCHAR(8)    NULL DEFAULT NULL;
