-- Этап 1 апгрейда «Заявки поставщикам»: отправка сводки поставщику по email.
-- 1) Флаг авто-отправки письма в дедлайн (по поставщику, по умолчанию выкл).
-- 2) Журнал отправок писем (аудит, без уникальных ключей).
-- 3) Таблица-сторож для защиты авто-отправки от дублей (аналог so_auto_submit_log).

ALTER TABLE `so_supplier_settings`
  ADD COLUMN `auto_email_summary` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Слать сводку поставщику на email автоматически в дедлайн';

CREATE TABLE IF NOT EXISTS `so_email_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `legal_entity` VARCHAR(255) NULL DEFAULT NULL,
  `recipients` TEXT NULL,
  `cc_recipients` TEXT NULL,
  `subject` VARCHAR(255) NULL,
  `restaurants_count` INT NOT NULL DEFAULT 0,
  `items_count` INT NOT NULL DEFAULT 0,
  `trigger_type` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `sender_user_name` VARCHAR(255) NULL,
  `ip_address` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_supplier_date` (`supplier_id`, `delivery_date`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `so_email_auto_log` (
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_supplier_date` (`supplier_id`, `delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
