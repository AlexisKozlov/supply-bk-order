ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS broadcast_group VARCHAR(64) DEFAULT NULL AFTER created_by;

CREATE INDEX IF NOT EXISTS idx_notifications_type_created_at
  ON notifications (type, created_at);

CREATE INDEX IF NOT EXISTS idx_notifications_broadcast_group
  ON notifications (broadcast_group);

CREATE TABLE IF NOT EXISTS admin_broadcast_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  broadcast_group VARCHAR(64) NOT NULL,
  sender VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  target_staff_cabinet TINYINT(1) NOT NULL DEFAULT 0,
  target_restaurant_cabinet TINYINT(1) NOT NULL DEFAULT 0,
  target_staff_telegram TINYINT(1) NOT NULL DEFAULT 0,
  target_restaurant_telegram TINYINT(1) NOT NULL DEFAULT 0,
  staff_telegram_sent INT NOT NULL DEFAULT 0,
  restaurant_telegram_sent INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_broadcast_group (broadcast_group),
  KEY idx_admin_broadcast_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
