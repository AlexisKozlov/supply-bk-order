CREATE TABLE IF NOT EXISTS warehouse_cells (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_date DATE NOT NULL,
  legal_entity VARCHAR(100) NOT NULL,
  stock_type ENUM('cold','frozen','dry','shabany') NOT NULL,
  cell_count INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_date_entity_type (report_date, legal_entity, stock_type),
  INDEX idx_wc_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
