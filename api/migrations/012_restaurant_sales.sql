-- Реализация ресторанов: продажи по группам аналогов и дням

CREATE TABLE IF NOT EXISTS restaurant_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_date DATE NOT NULL,
  analog_group VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Количество проданного (шт/кг/л)',
  restaurant_count INT NOT NULL DEFAULT 0 COMMENT 'Сколько ресторанов продавало в этот день',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_date_group (sale_date, analog_group),
  INDEX idx_rs_group (analog_group),
  INDEX idx_rs_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
