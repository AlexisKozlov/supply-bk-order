CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL DEFAULT 'order',
  title VARCHAR(255) NOT NULL,
  message TEXT,
  entity_type VARCHAR(50),
  entity_id VARCHAR(64),
  legal_entity VARCHAR(100),
  created_by VARCHAR(100),
  read_by JSON DEFAULT ('[]'),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
