-- Файлы КП (коммерческих предложений) привязанные к тендерам
CREATE TABLE IF NOT EXISTS tender_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tender_id INT NOT NULL,
  supplier VARCHAR(255) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tender_id) REFERENCES tenders(id) ON DELETE CASCADE,
  INDEX idx_tender_supplier (tender_id, supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
