-- Модуль "Протоколы совещаний"

-- Серии (повторяющиеся совещания)
CREATE TABLE IF NOT EXISTS meeting_protocol_series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Название серии (напр. "Еженедельная планёрка")',
    recurrence ENUM('weekly','biweekly','monthly','custom') DEFAULT 'weekly',
    agenda_template JSON COMMENT 'Шаблон повестки — массив строк',
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_series_created (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Протоколы совещаний
CREATE TABLE IF NOT EXISTS meeting_protocols (
    id INT AUTO_INCREMENT PRIMARY KEY,
    series_id INT DEFAULT NULL COMMENT 'FK на серию (NULL = разовое совещание)',
    meeting_date DATE NOT NULL,
    topic VARCHAR(500) NOT NULL COMMENT 'Тема совещания',
    participants JSON NOT NULL COMMENT 'Массив имён участников',
    questions TEXT COMMENT 'Обсуждённые вопросы (markdown/plain text)',
    notes TEXT COMMENT 'Дополнительные заметки',
    status ENUM('draft','final') DEFAULT 'draft',
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_proto_date (meeting_date),
    INDEX idx_proto_series (series_id),
    INDEX idx_proto_status (status),
    CONSTRAINT fk_proto_series FOREIGN KEY (series_id) REFERENCES meeting_protocol_series(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Решения/задачи из протоколов
CREATE TABLE IF NOT EXISTS protocol_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    text TEXT NOT NULL COMMENT 'Текст решения',
    responsible_person VARCHAR(100) NOT NULL COMMENT 'Ответственный (имя пользователя)',
    deadline DATE DEFAULT NULL,
    status ENUM('pending','done','overdue') DEFAULT 'pending',
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dec_proto (protocol_id),
    INDEX idx_dec_responsible (responsible_person),
    INDEX idx_dec_deadline (deadline, status),
    CONSTRAINT fk_dec_proto FOREIGN KEY (protocol_id) REFERENCES meeting_protocols(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
