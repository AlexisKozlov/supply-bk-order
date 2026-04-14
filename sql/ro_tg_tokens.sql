CREATE TABLE IF NOT EXISTS ro_tg_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    telegram_chat_id BIGINT NOT NULL,
    restaurant_number VARCHAR(20) DEFAULT NULL,
    legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_chat_id (telegram_chat_id),
    INDEX idx_ro_tg_tokens_rest_group (restaurant_number, legal_entity_group, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
