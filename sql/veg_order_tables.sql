-- Таблицы для модуля «Заказ овощей»

-- Сессия (неделя): один сбор заявок
CREATE TABLE IF NOT EXISTS `veg_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL COMMENT 'Название сессии, напр. "Неделя 11 (10-16 марта)"',
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Товары в сессии
CREATE TABLE IF NOT EXISTS `veg_session_products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `unit` ENUM('kg','pcs') NOT NULL DEFAULT 'kg' COMMENT 'кг или шт',
  `multiplicity` DECIMAL(10,2) DEFAULT NULL COMMENT 'Кратность заказа (напр. 6 кг)',
  `sort_order` INT NOT NULL DEFAULT 0,
  INDEX `idx_session` (`session_id`),
  FOREIGN KEY (`session_id`) REFERENCES `veg_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Токены для публичной ссылки (7 дней)
CREATE TABLE IF NOT EXISTS `veg_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  FOREIGN KEY (`session_id`) REFERENCES `veg_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- График доставки овощей по ресторанам (дни недели: 1=пн, ..., 7=вс)
CREATE TABLE IF NOT EXISTS `veg_delivery_days` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `day_of_week` TINYINT NOT NULL COMMENT '1=пн, 2=вт, 3=ср, 4=чт, 5=пт, 6=сб, 7=вс',
  UNIQUE KEY `uniq_rest_day` (`restaurant_number`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Заявки: ресторан + товар + дата доставки + количество
CREATE TABLE IF NOT EXISTS `veg_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `product_id` INT NOT NULL COMMENT 'veg_session_products.id',
  `restaurant_number` VARCHAR(20) NOT NULL,
  `delivery_date` DATE NOT NULL COMMENT 'Дата доставки',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `admin_note` VARCHAR(500) DEFAULT NULL COMMENT 'Пометка админа',
  `admin_qty` DECIMAL(12,2) DEFAULT NULL COMMENT 'Скорректированное количество',
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_session` (`session_id`),
  INDEX `idx_product` (`product_id`),
  INDEX `idx_restaurant` (`restaurant_number`),
  FOREIGN KEY (`session_id`) REFERENCES `veg_sessions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `veg_session_products`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_order` (`session_id`, `product_id`, `restaurant_number`, `delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пометки администратора для поставщика (по ресторану на сессию)
CREATE TABLE IF NOT EXISTS `veg_restaurant_notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `restaurant_number` VARCHAR(20) NOT NULL,
  `note` VARCHAR(500) DEFAULT NULL COMMENT 'Пометка для поставщика, напр. "Вторник 16:00-19:00"',
  UNIQUE KEY `uniq_session_rest` (`session_id`, `restaurant_number`),
  FOREIGN KEY (`session_id`) REFERENCES `veg_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Правила дедлайнов (день доставки → день и время дедлайна)
CREATE TABLE IF NOT EXISTS `veg_deadline_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `delivery_dow` TINYINT NOT NULL COMMENT '1=пн..7=вс — день доставки',
  `deadline_dow` TINYINT NOT NULL COMMENT '1=пн..7=вс — день дедлайна',
  `deadline_time` TIME NOT NULL DEFAULT '12:00:00' COMMENT 'Время дедлайна',
  UNIQUE KEY `uniq_delivery` (`delivery_dow`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Начальные правила: пн→пт 12:00, вт→пт 12:00, ср→пн 12:00, пт→ср 12:00, сб→ср 12:00
INSERT IGNORE INTO veg_deadline_rules (delivery_dow, deadline_dow, deadline_time) VALUES
  (1, 5, '12:00:00'), (2, 5, '12:00:00'), (3, 1, '12:00:00'),
  (5, 3, '12:00:00'), (6, 3, '12:00:00');
