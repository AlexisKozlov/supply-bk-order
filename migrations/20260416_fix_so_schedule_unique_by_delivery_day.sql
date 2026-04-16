-- Графики поставщиков должны быть уникальны по дню поставки, а не по дню заказа.
-- Иначе изменение дедлайна или дня заказа оставляет "залипшие" строки и кажется,
-- что график не сохраняется.

START TRANSACTION;

-- Оставляем только одну строку на поставщик + ресторан + день поставки.
-- Приоритет: активная, затем более свежая updated_at, затем больший id.
DELETE ss_old
FROM so_supplier_schedules ss_old
JOIN so_supplier_schedules ss_keep
  ON ss_old.supplier_id = ss_keep.supplier_id
 AND ss_old.restaurant_id = ss_keep.restaurant_id
 AND ss_old.delivery_day = ss_keep.delivery_day
 AND ss_old.id <> ss_keep.id
 AND (
      ss_old.is_active < ss_keep.is_active
      OR (
           ss_old.is_active = ss_keep.is_active
           AND COALESCE(ss_old.updated_at, '1000-01-01 00:00:00') < COALESCE(ss_keep.updated_at, '1000-01-01 00:00:00')
         )
      OR (
           ss_old.is_active = ss_keep.is_active
           AND COALESCE(ss_old.updated_at, '1000-01-01 00:00:00') = COALESCE(ss_keep.updated_at, '1000-01-01 00:00:00')
           AND ss_old.id < ss_keep.id
         )
 );

ALTER TABLE so_supplier_schedules
  DROP INDEX uq_ss_schedule,
  ADD UNIQUE KEY uq_ss_schedule_delivery (supplier_id, restaurant_id, delivery_day);

COMMIT;
