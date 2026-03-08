-- Тендеры: основная таблица
CREATE TABLE IF NOT EXISTS `tenders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL COMMENT 'Название тендера',
  `description` TEXT DEFAULT NULL COMMENT 'Описание / требования',
  `legal_entity` VARCHAR(100) NOT NULL,
  `status` ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
  `deadline` DATE DEFAULT NULL COMMENT 'Крайний срок подачи предложений',
  `winner_supplier` VARCHAR(255) DEFAULT NULL COMMENT 'Выбранный победитель',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tenders_le` (`legal_entity`),
  INDEX `idx_tenders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции тендера (что закупаем)
CREATE TABLE IF NOT EXISTS `tender_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tender_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL COMMENT 'Название товара / услуги',
  `quantity` DECIMAL(12,2) DEFAULT NULL COMMENT 'Количество',
  `unit` VARCHAR(50) DEFAULT NULL COMMENT 'Единица (шт, кг, упак и т.д.)',
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(`id`) ON DELETE CASCADE,
  INDEX `idx_tender_items_tender` (`tender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Предложения поставщиков
CREATE TABLE IF NOT EXISTS `tender_offers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tender_id` INT NOT NULL,
  `supplier` VARCHAR(255) NOT NULL COMMENT 'Название поставщика',
  `note` TEXT DEFAULT NULL COMMENT 'Примечание к предложению',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(`id`) ON DELETE CASCADE,
  INDEX `idx_tender_offers_tender` (`tender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Цены предложения по каждой позиции
CREATE TABLE IF NOT EXISTS `tender_offer_prices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `offer_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `price` DECIMAL(12,2) DEFAULT NULL,
  FOREIGN KEY (`offer_id`) REFERENCES `tender_offers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `tender_items`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_offer_item` (`offer_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
