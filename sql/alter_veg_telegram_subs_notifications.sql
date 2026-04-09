-- Настройки уведомлений для ресторанов
ALTER TABLE `veg_telegram_subs`
  ADD COLUMN `notify_reminders` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Напоминания о заявках и остатках' AFTER `username`,
  ADD COLUMN `notify_confirmations` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Подтверждения подачи заявок' AFTER `notify_reminders`,
  ADD COLUMN `notify_new_sessions` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Уведомления о новых сессиях/сборах' AFTER `notify_confirmations`;
