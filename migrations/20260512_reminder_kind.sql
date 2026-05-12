-- Замена sentinel UUID '00000000-...' на явный дискриминатор reminder_kind.
--
-- Контекст:
-- В прошлой реализации для напоминаний об основной поставке использовался
-- "магический" supplier_id = '00000000-0000-0000-0000-000000000000' и offset
-- 1_000_000_000 для subscription_id в reminder_runs. Это «техдолг» — теперь
-- заменяем на явное поле reminder_kind ENUM.
--
-- После миграции:
--   reminder_kind='supplier'       — обычный поставщик, supplier_id заполнен реальным UUID
--   reminder_kind='main_delivery'  — основная поставка, supplier_id = '' (пусто)
--   subscription_id в reminder_runs для main delivery теперь хранится «как есть»
--   (id из restaurant_main_delivery_subscriptions), без offset.

-- 1) reminder_acknowledgements
ALTER TABLE reminder_acknowledgements
  ADD COLUMN reminder_kind ENUM('supplier','main_delivery') NOT NULL DEFAULT 'supplier' AFTER restaurant_id;

UPDATE reminder_acknowledgements
SET reminder_kind = 'main_delivery', supplier_id = ''
WHERE supplier_id = '00000000-0000-0000-0000-000000000000';

ALTER TABLE reminder_acknowledgements
  DROP INDEX uniq_ack,
  ADD UNIQUE KEY uniq_ack (restaurant_id, reminder_kind, supplier_id, target_date, order_day);

-- 2) reminder_runs
ALTER TABLE reminder_runs
  ADD COLUMN reminder_kind ENUM('supplier','main_delivery') NOT NULL DEFAULT 'supplier' AFTER subscription_id;

-- Сначала обновляем индекс (чтобы добавить reminder_kind в UNIQUE),
-- иначе следующий UPDATE упадёт на дубле с supplier-строкой.
ALTER TABLE reminder_runs
  DROP INDEX uniq_run,
  ADD UNIQUE KEY uniq_run (subscription_id, reminder_kind, target_date, order_day, run_hour, channel, recipient);

UPDATE reminder_runs
SET reminder_kind = 'main_delivery', subscription_id = subscription_id - 1000000000
WHERE subscription_id >= 1000000000;
