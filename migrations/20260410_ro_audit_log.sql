-- ═══════════════════════════════════════════════════════════
-- Журнал изменений для модуля «Заказы ресторанов»
-- Фиксирует все действия: создание, обновление, удаление заказов/позиций,
-- смена статуса, «поставка не нужна». Используется для страницы «Журнал».
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ro_audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned DEFAULT NULL COMMENT 'ID заказа (null если заказ уже удалён)',
  `restaurant_number` smallint(5) unsigned DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'order_created/order_updated/order_skipped/order_deleted/item_added/item_changed/item_deleted/status_changed/delivery_date_changed',
  `actor_name` varchar(255) DEFAULT NULL COMMENT 'Имя сотрудника или метка «Ресторан N»',
  `actor_type` enum('restaurant','admin','bot','system') NOT NULL DEFAULT 'system',
  `actor_ip` varchar(45) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `details` longtext DEFAULT NULL COMMENT 'JSON: diff позиций, комментарий и т.п.',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_restaurant_date` (`restaurant_number`, `delivery_date`),
  KEY `idx_created` (`created_at`),
  KEY `idx_action` (`action`),
  KEY `idx_actor` (`actor_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
