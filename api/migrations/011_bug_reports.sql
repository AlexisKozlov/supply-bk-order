-- Система обратной связи: багрепорты и ответы

CREATE TABLE IF NOT EXISTS bug_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  screenshots JSON DEFAULT NULL COMMENT 'Массив путей к скриншотам',
  action_log TEXT DEFAULT NULL COMMENT 'Лог последних действий пользователя',
  page_url VARCHAR(500) DEFAULT NULL,
  status ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  created_by VARCHAR(100) NOT NULL,
  legal_entity VARCHAR(255) DEFAULT NULL,
  browser_info VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_br_status (status),
  INDEX idx_br_created_by (created_by),
  INDEX idx_br_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bug_report_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  message TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_brr_report (report_id),
  FOREIGN KEY (report_id) REFERENCES bug_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
