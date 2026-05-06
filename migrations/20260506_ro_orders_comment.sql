-- Добавляем колонку comment в ro_orders.
--
-- Колонка фактически появилась на проде ALTER'ом, но миграции под неё в
-- репозитории не было. На чистой базе модуль ломался бы при попытке записать
-- комментарий заказа. ADD COLUMN IF NOT EXISTS — идемпотентно.

ALTER TABLE ro_orders
  ADD COLUMN IF NOT EXISTS comment VARCHAR(500) DEFAULT NULL AFTER legal_entity;
