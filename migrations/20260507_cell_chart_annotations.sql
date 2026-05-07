-- Аннотации (события) для графика загрузки ячеек.
-- Видны всем пользователям, помечают важные даты на оси графика.
CREATE TABLE IF NOT EXISTS cell_chart_annotations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_date DATE NOT NULL,
  label VARCHAR(255) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#E76F51',
  created_by VARCHAR(100) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cell_annot_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
