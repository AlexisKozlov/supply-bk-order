-- ============================================================
-- Заказы ресторанов — временный модуль
-- Все таблицы с префиксом ro_ для лёгкого удаления
-- ============================================================

-- Учётные записи ресторанов (логин = номер ресторана)
CREATE TABLE IF NOT EXISTS ro_users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  legal_entity    VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login_at   TIMESTAMP NULL,
  session_token   VARCHAR(64) NULL,
  session_active_until TIMESTAMP NULL,
  UNIQUE KEY uq_ro_users_rest (restaurant_number),
  INDEX idx_ro_users_token (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Недельные сессии приёма заявок
CREATE TABLE IF NOT EXISTS ro_sessions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  week_start      DATE NOT NULL,
  week_end        DATE NOT NULL,
  status          ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
  created_by      VARCHAR(100) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ro_sessions_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заказ ресторана (один на ресторан + дату достав��и)
CREATE TABLE IF NOT EXISTS ro_orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id      INT UNSIGNED NOT NULL,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  delivery_date   DATE NOT NULL,
  status          ENUM('draft','submitted','edited','locked') NOT NULL DEFAULT 'draft',
  submitted_at    TIMESTAMP NULL,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by      VARCHAR(100) NULL,
  legal_entity    VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"',
  UNIQUE KEY uq_ro_orders (session_id, restaurant_number, delivery_date),
  INDEX idx_ro_orders_date (delivery_date),
  INDEX idx_ro_orders_rest (restaurant_number),
  FOREIGN KEY (session_id) REFERENCES ro_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции заказа
CREATE TABLE IF NOT EXISTS ro_order_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  sku             VARCHAR(50) NOT NULL,
  product_name    VARCHAR(255) NOT NULL,
  category        VARCHAR(20) NOT NULL COMMENT 'Сухой / Холод / Мороз',
  quantity        DECIMAL(10,2) NOT NULL DEFAULT 0,
  comment         VARCHAR(500) NULL,
  INDEX idx_ro_items_order (order_id),
  FOREIGN KEY (order_id) REFERENCES ro_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаблон товаров по категориям хранения (настраивается закупщиками)
CREATE TABLE IF NOT EXISTS ro_templates (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legal_entity    VARCHAR(100) NOT NULL DEFAULT 'ООО "Бург��р БК"',
  category        VARCHAR(20) NOT NULL COMMENT 'Сухой / Холод / Мороз',
  sku             VARCHAR(50) NOT NULL,
  product_name    VARCHAR(255) NOT NULL,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ro_tpl (legal_entity, category, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Продление дедлайна
CREATE TABLE IF NOT EXISTS ro_deadline_overrides (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id      INT UNSIGNED NOT NULL,
  delivery_date   DATE NOT NULL,
  soft_deadline   TIME NOT NULL DEFAULT '10:00:00',
  hard_deadline   TIME NOT NULL DEFAULT '13:00:00',
  created_by      VARCHAR(100) NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ro_deadline (session_id, delivery_date),
  FOREIGN KEY (session_id) REFERENCES ro_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Корректировки заказов ресторанов (отдельно от основных order_corrections)
CREATE TABLE IF NOT EXISTS ro_corrections (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NULL,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  restaurant_chat_id BIGINT NULL,
  submitter_name  VARCHAR(255) NULL,
  delivery_date   DATE NOT NULL,
  action          ENUM('add','remove','change') NOT NULL,
  sku             VARCHAR(50) NOT NULL,
  product_name    VARCHAR(255) NOT NULL,
  quantity        DECIMAL(10,2) NOT NULL,
  comment         VARCHAR(500) NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer_name   VARCHAR(100) NULL,
  reviewed_at     TIMESTAMP NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ro_corr_order (order_id),
  INDEX idx_ro_corr_rest (restaurant_number),
  FOREIGN KEY (order_id) REFERENCES ro_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
