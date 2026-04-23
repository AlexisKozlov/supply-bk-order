DROP TABLE IF EXISTS `ro_module_settings`;

CREATE TABLE `ro_module_settings` (
  `legal_entity` VARCHAR(100) NOT NULL,
  `legal_entity_group` VARCHAR(20) NOT NULL,
  `restaurant_orders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`legal_entity`),
  KEY `idx_ro_module_settings_group` (`legal_entity_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ro_module_settings` (`legal_entity`, `legal_entity_group`, `restaurant_orders_enabled`)
VALUES
  ('ООО "Бургер БК"', 'BK_VM', 1),
  ('ООО "Воглия Матта"', 'BK_VM', 1),
  ('ООО "Пицца Стар"', 'PS', 1)
ON DUPLICATE KEY UPDATE
  `legal_entity_group` = VALUES(`legal_entity_group`);
