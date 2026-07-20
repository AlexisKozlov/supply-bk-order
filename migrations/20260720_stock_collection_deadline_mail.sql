-- Сбор остатков: дедлайн заполнения + журнал писем ресторанам.
--
-- 1. stock_collections.deadline_at — «заполнить до» (дата и время).
--    Информационный: после дедлайна ресторан всё ещё может сдать остатки,
--    просто везде видно, что срок вышел. У старых сборов остаётся NULL.
-- 2. stock_collection_mail_log — что и кому уже отправили по email.
--    Нужен, чтобы автонапоминания не ушли одному ресторану дважды.

ALTER TABLE `stock_collections`
  ADD COLUMN `deadline_at` DATETIME DEFAULT NULL COMMENT 'Заполнить до (информационный дедлайн)' AFTER `status`;

CREATE TABLE IF NOT EXISTS `stock_collection_mail_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `collection_id` INT NOT NULL,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `kind` ENUM('start','reminder_24h','reminder_2h','manual') NOT NULL COMMENT 'Повод письма',
  `email` VARCHAR(255) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_coll_rest_kind` (`collection_id`, `restaurant_number`, `kind`),
  INDEX `idx_collection` (`collection_id`),
  FOREIGN KEY (`collection_id`) REFERENCES `stock_collections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Журнал писем ресторанам о сборе остатков';
