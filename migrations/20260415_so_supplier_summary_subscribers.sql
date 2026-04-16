CREATE TABLE IF NOT EXISTS so_supplier_summary_subscribers (
  supplier_id CHAR(36) NOT NULL COMMENT 'FK -> suppliers.id',
  user_name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (supplier_id, user_name),
  KEY idx_so_summary_subscribers_user (user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO so_supplier_summary_subscribers (supplier_id, user_name, created_by)
SELECT s.id, ts.user_name, 'migration'
FROM suppliers s
JOIN telegram_settings ts ON ts.so_deadline_summary = 1
JOIN users u ON u.name = ts.user_name
WHERE s.is_active = 1
  AND s.so_enabled = 1;
