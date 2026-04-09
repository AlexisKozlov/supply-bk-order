-- Добавляем данные Telegram-профиля подписчиков ресторанов
ALTER TABLE `veg_telegram_subs`
  ADD COLUMN `first_name` VARCHAR(255) DEFAULT NULL COMMENT 'Имя в Telegram' AFTER `restaurant_number`,
  ADD COLUMN `username` VARCHAR(255) DEFAULT NULL COMMENT '@username в Telegram' AFTER `first_name`;
