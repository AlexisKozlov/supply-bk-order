-- Маркетинговые активности
CREATE TABLE IF NOT EXISTS marketing_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'promo',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  date_from DATE DEFAULT NULL,
  date_to DATE DEFAULT NULL,
  legal_entity VARCHAR(100) NOT NULL,
  restaurant_count INT DEFAULT NULL,
  note TEXT DEFAULT NULL,
  created_by VARCHAR(100) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_le (legal_entity),
  INDEX idx_status (status),
  INDEX idx_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Товары/ингредиенты внутри активности
CREATE TABLE IF NOT EXISTS marketing_activity_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  activity_id INT NOT NULL,
  product_id INT DEFAULT NULL,
  sku VARCHAR(50) DEFAULT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  calc_method VARCHAR(20) NOT NULL DEFAULT 'auv',
  auv DECIMAL(10,4) DEFAULT NULL,
  total_volume DECIMAL(12,2) DEFAULT NULL,
  fixed_qty DECIMAL(12,2) DEFAULT NULL,
  unit VARCHAR(20) DEFAULT 'шт',
  sort_order INT DEFAULT 0,
  note TEXT DEFAULT NULL,
  FOREIGN KEY (activity_id) REFERENCES marketing_activities(id) ON DELETE CASCADE,
  INDEX idx_activity (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Файлы/вложения к активности
CREATE TABLE IF NOT EXISTS marketing_activity_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  activity_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (activity_id) REFERENCES marketing_activities(id) ON DELETE CASCADE,
  INDEX idx_activity (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
