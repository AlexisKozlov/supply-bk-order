-- Калькулятор паллет
-- Товары — плоский справочник, поставщик — текст при вводе поставки

-- Справочник товаров
CREATE TABLE IF NOT EXISTS `plt_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `entity_group` VARCHAR(20) NOT NULL COMMENT 'bk_vm или ps',
  `name` VARCHAR(500) NOT NULL,
  `sku` VARCHAR(50) DEFAULT NULL COMMENT 'Артикул',
  `storage_type` ENUM('cold','frozen') NOT NULL DEFAULT 'cold',
  `boxes_per_pallet` INT NOT NULL DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_plt_products_eg` (`entity_group`),
  INDEX `idx_plt_products_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Поставки
CREATE TABLE IF NOT EXISTS `plt_deliveries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `legal_entity` VARCHAR(100) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `supplier_name` VARCHAR(255) NOT NULL,
  `total_cold` INT NOT NULL DEFAULT 0,
  `total_frozen` INT NOT NULL DEFAULT 0,
  `note` TEXT DEFAULT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plt_deliveries_le_date` (`legal_entity`, `delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции поставки
CREATE TABLE IF NOT EXISTS `plt_delivery_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `delivery_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `product_name` VARCHAR(500) NOT NULL,
  `boxes_per_pallet` INT NOT NULL,
  `storage_type` ENUM('cold','frozen') NOT NULL,
  `boxes` INT NOT NULL DEFAULT 0,
  `pallets` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`delivery_id`) REFERENCES `plt_deliveries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `plt_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Остатки на складе
CREATE TABLE IF NOT EXISTS `plt_daily_stock` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `legal_entity` VARCHAR(100) NOT NULL,
  `stock_date` DATE NOT NULL,
  `cold_pallets` INT NOT NULL DEFAULT 0,
  `frozen_pallets` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_stock_le_date` (`legal_entity`, `stock_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сводная таблица
CREATE TABLE IF NOT EXISTS `plt_summary` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `legal_entity` VARCHAR(100) NOT NULL,
  `entry_date` DATE NOT NULL,
  `supplier_name` VARCHAR(255) NOT NULL,
  `cold_pallets` INT NOT NULL DEFAULT 0,
  `frozen_pallets` INT NOT NULL DEFAULT 0,
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `delivery_id` INT DEFAULT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plt_summary_le_date` (`legal_entity`, `entry_date`),
  INDEX `idx_plt_summary_delivery` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
