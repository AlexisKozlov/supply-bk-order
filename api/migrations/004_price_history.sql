-- История изменений цен
CREATE TABLE IF NOT EXISTS price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL,
    supplier VARCHAR(255) NOT NULL,
    legal_entity VARCHAR(255) NOT NULL,
    old_price DECIMAL(12,2) DEFAULT NULL,
    new_price DECIMAL(12,2) NOT NULL,
    old_currency ENUM('BYN','RUB') DEFAULT NULL,
    new_currency ENUM('BYN','RUB') NOT NULL DEFAULT 'BYN',
    agreement_id INT DEFAULT NULL,
    changed_by VARCHAR(100) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ph_sku (sku, supplier, legal_entity),
    INDEX idx_ph_date (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
