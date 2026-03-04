-- Таблица для хранения данных из stock_mailing (сроки годности)
CREATE TABLE IF NOT EXISTS `stock_malling` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer` VARCHAR(255) DEFAULT NULL COMMENT 'Заказчик',
  `warehouse` VARCHAR(255) DEFAULT NULL COMMENT 'Склад',
  `product_name` VARCHAR(500) NOT NULL COMMENT 'Наименование товара',
  `production_date` DATE DEFAULT NULL COMMENT 'Дата производства',
  `expiry_date` DATE DEFAULT NULL COMMENT 'Годен до',
  `block_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Причина блокировки',
  `expiry_status` VARCHAR(50) DEFAULT NULL COMMENT 'Статус годности (Годен/Просрочен)',
  `quantity` DECIMAL(12,2) DEFAULT 0 COMMENT 'Остаток',
  `uploaded_at` DATETIME DEFAULT NOW() COMMENT 'Дата загрузки',
  `uploaded_by` VARCHAR(100) DEFAULT NULL COMMENT 'Кто загрузил',
  INDEX idx_customer (`customer`),
  INDEX idx_expiry_date (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
