-- Таблица для отслеживания гостей (неавторизованных пользователей)
CREATE TABLE IF NOT EXISTS guest_presence (
    session_id VARCHAR(64) PRIMARY KEY,
    page VARCHAR(100) DEFAULT 'search-cards',
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Добавить created_at в search_logs (если колонки нет)
ALTER TABLE search_logs ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP;
