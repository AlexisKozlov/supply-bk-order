-- Отключение напоминаний о подаче заявок для пары «поставщик + ресторан».
--
-- Есть запись — напоминания по заявкам этому ресторану у этого поставщика
-- ВЫКЛЮЧЕНЫ (по всем каналам: Telegram и пуш). Нет записи — включены, как
-- и было. Нужно, когда ресторан заказывает часто и не хочет ежедневных
-- напоминаний «заявка не подана».

CREATE TABLE IF NOT EXISTS `so_reminder_mutes` (
  `supplier_id` CHAR(36) NOT NULL,
  `restaurant_id` INT NOT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`supplier_id`, `restaurant_id`),
  INDEX `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Заглушённые напоминания о заявках: пара поставщик+ресторан';
