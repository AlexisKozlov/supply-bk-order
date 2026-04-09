-- Разделяем настройки уведомлений на 5 отдельных
-- notify_reminders → notify_veg_reminders (напоминания о заявках на овощи)
-- notify_new_sessions → notify_veg_sessions (новые сессии овощей)
-- notify_confirmations остаётся (подтверждения подачи)
-- Добавляем:
--   notify_stock_reminders (напоминания о сборе остатков)
--   notify_stock_sessions (новые сборы остатков)

ALTER TABLE `veg_telegram_subs`
  ADD COLUMN `notify_veg_reminders` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Напоминания о заявках на овощи' AFTER `notify_new_sessions`,
  ADD COLUMN `notify_veg_sessions` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Уведомления о новых сессиях овощей' AFTER `notify_veg_reminders`,
  ADD COLUMN `notify_stock_reminders` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Напоминания о сборе остатков' AFTER `notify_veg_sessions`,
  ADD COLUMN `notify_stock_sessions` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Уведомления о новых сборах остатков' AFTER `notify_stock_reminders`;

-- Перенос данных из старых колонок
UPDATE `veg_telegram_subs` SET
  notify_veg_reminders = notify_reminders,
  notify_veg_sessions = notify_new_sessions,
  notify_stock_reminders = notify_reminders,
  notify_stock_sessions = notify_new_sessions;

-- Удаляем старые колонки
ALTER TABLE `veg_telegram_subs`
  DROP COLUMN `notify_reminders`,
  DROP COLUMN `notify_new_sessions`;
