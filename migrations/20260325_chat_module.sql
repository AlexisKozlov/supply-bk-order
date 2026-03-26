-- Чат между ресторанами и отделом закупок
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_number VARCHAR(20) NOT NULL,
    restaurant_chat_id BIGINT NOT NULL,
    restaurant_name VARCHAR(255) DEFAULT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME DEFAULT NULL,
    closed_by VARCHAR(255) DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_last (status, last_message_at DESC),
    INDEX idx_rest_chat (restaurant_chat_id, status),
    INDEX idx_rest_num (restaurant_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    direction ENUM('from_restaurant', 'from_purchasing') NOT NULL,
    sender_name VARCHAR(255) NOT NULL,
    message_text TEXT DEFAULT NULL,
    photo_file_id VARCHAR(512) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conv_created (conversation_id, created_at),
    INDEX idx_unread (conversation_id, is_read, direction),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE telegram_settings
    ADD COLUMN IF NOT EXISTS chat_notifications TINYINT(1) NOT NULL DEFAULT 0;
