-- ═══════════════════════════════════════════════════════════
-- Заявки поставщикам: переход на постоянный режим приёма
-- Вместо еженедельных сессий — переключатель «Приём вкл/выкл»
-- у каждого поставщика
-- ═══════════════════════════════════════════════════════════

-- 1. Таблица настроек поставщика
CREATE TABLE IF NOT EXISTS `so_supplier_settings` (
  `supplier_id` char(36) NOT NULL,
  `is_accepting_orders` tinyint(1) NOT NULL DEFAULT 1,
  `default_deadline_time` time NOT NULL DEFAULT '14:00:00',
  `pause_message` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Заполняем настройки для всех поставщиков с активным расписанием
INSERT IGNORE INTO `so_supplier_settings` (`supplier_id`, `is_accepting_orders`, `default_deadline_time`)
SELECT DISTINCT supplier_id, 1, '14:00:00'
FROM `so_supplier_schedules`
WHERE is_active = 1;

-- 3. so_orders: делаем session_id необязательным и меняем уникальный ключ
ALTER TABLE `so_orders` DROP FOREIGN KEY `so_orders_ibfk_1`;
ALTER TABLE `so_orders` DROP INDEX `uq_so_order`;
ALTER TABLE `so_orders` MODIFY COLUMN `session_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `so_orders` ADD UNIQUE KEY `uq_so_order_sup` (`supplier_id`, `restaurant_number`, `delivery_date`);

-- 4. so_deadline_overrides: привязываем к supplier_id вместо session_id
ALTER TABLE `so_deadline_overrides` DROP FOREIGN KEY `so_deadline_overrides_ibfk_1`;
ALTER TABLE `so_deadline_overrides` DROP INDEX `uq_so_deadline`;
ALTER TABLE `so_deadline_overrides` ADD COLUMN `supplier_id` char(36) DEFAULT NULL AFTER `session_id`;

UPDATE `so_deadline_overrides` o
  LEFT JOIN `so_sessions` s ON s.id = o.session_id
  SET o.supplier_id = s.supplier_id
  WHERE o.supplier_id IS NULL AND o.session_id IS NOT NULL;

ALTER TABLE `so_deadline_overrides` MODIFY COLUMN `session_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `so_deadline_overrides` ADD UNIQUE KEY `uq_so_deadline_sup` (`supplier_id`, `delivery_date`);
