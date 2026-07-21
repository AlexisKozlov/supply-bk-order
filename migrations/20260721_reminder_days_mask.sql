-- Маска дней доставки, по которым слать напоминание о заявке.
-- Бит (delivery_dow-1): 1=Пн ... 7=Вс. NULL = все дни (как было).
ALTER TABLE `restaurant_reminder_subscriptions`
  ADD COLUMN `reminder_days` SMALLINT DEFAULT NULL
  COMMENT 'Битовая маска дней доставки для напоминаний (NULL=все)' AFTER `telegram_enabled`;
