-- Поля в restaurants
ALTER TABLE restaurants
  ADD COLUMN pickup_address VARCHAR(500) NULL COMMENT 'Адрес погрузки для ТТН возврата кег',
  ADD COLUMN pickup_weekdays TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Битмаска: бит 0=Пн, 1=Вт, ..., 6=Вс';

-- Реквизиты юрлиц (для шапки ТТН)
CREATE TABLE IF NOT EXISTS legal_entity_details (
  legal_entity_code VARCHAR(20) NOT NULL PRIMARY KEY COMMENT 'BK | VM | PS — соответствует значениям из legalEntities.js',
  full_name VARCHAR(255) NOT NULL,
  unp VARCHAR(20) NOT NULL,
  address VARCHAR(500) NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сидеры реквизитов
INSERT INTO legal_entity_details (legal_entity_code, full_name, unp, address) VALUES
  ('BK', 'ООО "Бургер БК"', '192415615', '220125, г. Минск, ул. Скрыганова, 14, к. 412')
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name);
INSERT INTO legal_entity_details (legal_entity_code, full_name, unp, address) VALUES
  ('VM', 'ООО "Воглия Матта"', '000000000', 'УНП и адрес ВМ — заполните в настройках')
ON DUPLICATE KEY UPDATE legal_entity_code=legal_entity_code;

-- Каталог кег
CREATE TABLE IF NOT EXISTS keg_catalog (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  photo_url VARCHAR(500) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO keg_catalog (code, name, sort_order) VALUES
  ('100111', 'Кега INBEV GER DIN 30л', 1),
  ('100112', 'Кега с лого EFES KEG DIN 30л', 2)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Заявки на возврат кег
CREATE TABLE IF NOT EXISTS keg_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  bso_series VARCHAR(4) NULL DEFAULT NULL COMMENT 'Серия БСО, 2 буквы',
  bso_number VARCHAR(20) NULL DEFAULT NULL COMMENT 'Номер БСО, цифры',
  return_date DATE NOT NULL,
  vehicle VARCHAR(50) NULL COMMENT 'Гос. номер ТС',
  driver VARCHAR(255) NULL COMMENT 'ФИО водителя',
  sender_position_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Сдал грузоотправитель: должность + ФИО',
  status ENUM('DRAFT','SUBMITTED','ROUTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  submitted_at DATETIME NULL,
  routed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_by_chat_id BIGINT NULL COMMENT 'chat_id ресторана-создателя (если из ЛК)',
  created_by_user VARCHAR(100) NULL COMMENT 'user.name (если из портала закупок)',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_keg_bso (restaurant_id, bso_series, bso_number),
  INDEX idx_keg_status (status),
  INDEX idx_keg_date (return_date),
  INDEX idx_keg_restaurant (restaurant_id),
  CONSTRAINT fk_keg_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции (кеги в заявке)
CREATE TABLE IF NOT EXISTS keg_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  keg_code VARCHAR(20) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_kri (request_id, keg_code),
  INDEX idx_kri_request (request_id),
  CONSTRAINT fk_kri_request FOREIGN KEY (request_id) REFERENCES keg_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_kri_keg FOREIGN KEY (keg_code) REFERENCES keg_catalog(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
