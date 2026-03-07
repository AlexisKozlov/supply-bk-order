-- Модуль цен и ПСЦ (Протокол Согласования Цены)

-- Протоколы ПСЦ
CREATE TABLE IF NOT EXISTS price_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL COMMENT 'Номер протокола',
    supplier VARCHAR(255) NOT NULL,
    legal_entity VARCHAR(255) NOT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    valid_from DATE DEFAULT NULL,
    valid_to DATE DEFAULT NULL,
    note TEXT DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL COMMENT 'Имя загруженного файла',
    file_path VARCHAR(500) DEFAULT NULL COMMENT 'Путь к файлу на сервере',
    created_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pa_supplier (supplier),
    INDEX idx_pa_legal_entity (legal_entity),
    INDEX idx_pa_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Текущие цены товаров (актуальный прайс-лист)
CREATE TABLE IF NOT EXISTS product_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL,
    supplier VARCHAR(255) NOT NULL,
    legal_entity VARCHAR(255) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_type ENUM('piece','box') NOT NULL DEFAULT 'piece' COMMENT 'Цена за штуку или за коробку',
    agreement_id INT DEFAULT NULL COMMENT 'Ссылка на ПСЦ, из которого пришла цена',
    updated_by VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pp_sku_supplier_le (sku, supplier, legal_entity),
    INDEX idx_pp_legal_entity (legal_entity),
    INDEX idx_pp_supplier (supplier),
    INDEX idx_pp_agreement (agreement_id),
    CONSTRAINT fk_pp_agreement FOREIGN KEY (agreement_id) REFERENCES price_agreements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
