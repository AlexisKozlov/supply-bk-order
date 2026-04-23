-- Корректировки заказов ресторанов
CREATE TABLE IF NOT EXISTS order_corrections (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  restaurant_chat_id BIGINT NOT NULL COMMENT 'chat_id подавшего заявку',
  submitter_name    VARCHAR(255) DEFAULT NULL COMMENT 'Имя/username подавшего',
  delivery_date     DATE NOT NULL,
  action            ENUM('add', 'remove') NOT NULL,
  product_sku       VARCHAR(50) NOT NULL,
  product_name      VARCHAR(255) NOT NULL,
  quantity          DECIMAL(10,2) NOT NULL,
  unit_of_measure   VARCHAR(20) NOT NULL DEFAULT 'кор.',
  comment           TEXT DEFAULT NULL,
  status            ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewer_chat_id  BIGINT DEFAULT NULL,
  reviewer_name     VARCHAR(100) DEFAULT NULL,
  review_comment    TEXT DEFAULT NULL,
  reviewed_at       DATETIME DEFAULT NULL,
  notify_messages   TEXT DEFAULT NULL COMMENT 'JSON: batch_ids + message_ids для обновления',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_corr_rest_date (restaurant_number, delivery_date),
  INDEX idx_corr_status (status),
  INDEX idx_corr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Настройка уведомлений о корректировках для отдела закупок
ALTER TABLE telegram_settings
  ADD COLUMN IF NOT EXISTS correction_notifications TINYINT(1) NOT NULL DEFAULT 0;
