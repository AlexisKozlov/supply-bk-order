-- Таблицы для модуля «Распределение новинок»

-- Сессия распределения
CREATE TABLE IF NOT EXISTS `dist_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL COMMENT 'Название, напр. "Новинки март 2026"',
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Товары в сессии (ссылка на справочник products)
CREATE TABLE IF NOT EXISTS `dist_session_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `product_id` CHAR(36) NOT NULL COMMENT 'products.id из справочника',
  `default_qty` DECIMAL(10,2) NOT NULL DEFAULT 1 COMMENT 'Кол-во по умолчанию',
  `unit` VARCHAR(20) NOT NULL DEFAULT 'кор' COMMENT 'кор/шт/кг',
  `sort_order` INT NOT NULL DEFAULT 0,
  INDEX `idx_session` (`session_id`),
  FOREIGN KEY (`session_id`) REFERENCES `dist_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Записи распределения: ресторан + товар + кол-во + отгружено
CREATE TABLE IF NOT EXISTS `dist_entries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_product_id` INT NOT NULL,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `qty` VARCHAR(100) DEFAULT NULL COMMENT 'Значение (число или текст, NULL = default_qty)',
  `shipped` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Отгружено',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_sp_rest` (`session_product_id`, `restaurant_number`),
  INDEX `idx_session_product` (`session_product_id`),
  FOREIGN KEY (`session_product_id`) REFERENCES `dist_session_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
