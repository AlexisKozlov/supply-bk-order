-- Таблицы для инструмента распределения дефицитного товара

CREATE TABLE IF NOT EXISTS `deficit_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `legal_entity` VARCHAR(100) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `product_sku` VARCHAR(50) DEFAULT NULL,
  `warehouse_stock` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `next_delivery_date` DATE DEFAULT NULL,
  `growth_factor` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `total_need` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total_allocated` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `restaurant_count` INT NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_le` (`legal_entity`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deficit_results` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `restaurant_id` INT DEFAULT NULL,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `daily_consumption` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `days_to_cover` INT NOT NULL DEFAULT 0,
  `need` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `allocated` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `delivery_day` VARCHAR(20) DEFAULT NULL,
  INDEX `idx_session` (`session_id`),
  FOREIGN KEY (`session_id`) REFERENCES `deficit_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deficit_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `legal_entity` VARCHAR(100) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deficit_restaurant_stock` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token_id` INT NOT NULL,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `stock` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token_id`),
  FOREIGN KEY (`token_id`) REFERENCES `deficit_tokens`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_token_rest` (`token_id`, `restaurant_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
