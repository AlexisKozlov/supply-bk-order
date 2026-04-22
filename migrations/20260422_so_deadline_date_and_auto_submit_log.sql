-- Заявки поставщикам: точная дата разового дедлайна и журнал авто-подачи.

ALTER TABLE so_deadline_overrides
  MODIFY COLUMN deadline_time TIME NULL DEFAULT NULL;

ALTER TABLE so_deadline_overrides
  ADD COLUMN IF NOT EXISTS deadline_date DATE NULL AFTER delivery_date;

CREATE TABLE IF NOT EXISTS so_auto_submit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id CHAR(36) NOT NULL,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  delivery_date DATE NOT NULL,
  source_order_id INT UNSIGNED NOT NULL,
  new_order_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_so_auto_submit (supplier_id, restaurant_number, delivery_date),
  KEY idx_so_auto_submit_source (source_order_id),
  KEY idx_so_auto_submit_new (new_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
