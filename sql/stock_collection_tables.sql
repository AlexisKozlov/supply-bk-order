-- Таблицы для сбора остатков ресторанов (отдельно от дефицита)

-- Сессия сбора: один сбор = несколько товаров
CREATE TABLE IF NOT EXISTS `stock_collections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `legal_entity` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL COMMENT 'Название сбора, напр. "Сбор 05.03"',
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  INDEX `idx_le` (`legal_entity`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Товары в сессии сбора
CREATE TABLE IF NOT EXISTS `stock_collection_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `collection_id` INT NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `product_sku` VARCHAR(50) DEFAULT NULL,
  `unit` ENUM('boxes','pieces') NOT NULL DEFAULT 'pieces' COMMENT 'В чём собираем остатки',
  `sort_order` INT NOT NULL DEFAULT 0,
  INDEX `idx_collection` (`collection_id`),
  FOREIGN KEY (`collection_id`) REFERENCES `stock_collections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Данные остатков от ресторанов
CREATE TABLE IF NOT EXISTS `stock_collection_data` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `collection_id` INT NOT NULL,
  `product_id` INT NOT NULL COMMENT 'stock_collection_products.id',
  `restaurant_number` VARCHAR(20) NOT NULL,
  `stock` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `source` ENUM('form','file','manual') NOT NULL DEFAULT 'form',
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_collection` (`collection_id`),
  INDEX `idx_product` (`product_id`),
  FOREIGN KEY (`collection_id`) REFERENCES `stock_collections`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `stock_collection_products`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_prod_rest` (`product_id`, `restaurant_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Токены для публичной формы (привязаны к сессии сбора)
CREATE TABLE IF NOT EXISTS `stock_collection_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `collection_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  FOREIGN KEY (`collection_id`) REFERENCES `stock_collections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
