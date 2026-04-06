-- ============================================================
-- Универсальный модуль заявок поставщикам (so_*)
-- Рестораны подают заявки напрямую поставщикам
-- У каждого поставщика свой график по каждому ресторану
-- ============================================================

-- График заказов/поставок по поставщикам
-- Связывает поставщика + ресторан + дни заказа/поставки
CREATE TABLE IF NOT EXISTS so_supplier_schedules (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       CHAR(36) NOT NULL COMMENT 'FK → suppliers.id',
  restaurant_id     INT UNSIGNED NOT NULL COMMENT 'FK → restaurants.id',
  order_day         TINYINT NOT NULL COMMENT 'День заказа: 1=ПН, 2=ВТ, 3=СР, 4=ЧТ, 5=ПТ, 6=СБ, 7=ВС',
  delivery_day      TINYINT NOT NULL COMMENT 'День поставки: 1=ПН ... 7=ВС',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at        DATETIME DEFAULT NULL,
  updated_by        VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uq_ss_schedule (supplier_id, restaurant_id, order_day),
  INDEX idx_ss_supplier (supplier_id),
  INDEX idx_ss_restaurant (restaurant_id),
  CONSTRAINT fk_ss_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сессии приёма заявок (по поставщику)
CREATE TABLE IF NOT EXISTS so_sessions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       CHAR(36) NOT NULL COMMENT 'FK → suppliers.id',
  week_start        DATE NOT NULL,
  week_end          DATE NOT NULL,
  status            ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
  deadline_time     TIME NOT NULL DEFAULT '14:00:00' COMMENT 'Дедлайн подачи заявки',
  created_by        VARCHAR(100) NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_so_session (supplier_id, week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявка ресторана (одна на ресторан + поставщик + дату поставки)
CREATE TABLE IF NOT EXISTS so_orders (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id        INT UNSIGNED NOT NULL,
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  supplier_id       CHAR(36) NOT NULL,
  delivery_date     DATE NOT NULL,
  order_date        DATE NOT NULL COMMENT 'Дата подачи заявки (по графику)',
  status            ENUM('draft','submitted','locked') NOT NULL DEFAULT 'draft',
  submitted_at      TIMESTAMP NULL,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  legal_entity      VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"',
  UNIQUE KEY uq_so_order (session_id, restaurant_number, delivery_date),
  INDEX idx_so_orders_date (delivery_date),
  INDEX idx_so_orders_rest (restaurant_number),
  INDEX idx_so_orders_supplier (supplier_id),
  FOREIGN KEY (session_id) REFERENCES so_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции заявки
CREATE TABLE IF NOT EXISTS so_order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  product_id        CHAR(36) NOT NULL COMMENT 'FK → products.id',
  sku               VARCHAR(50) NOT NULL,
  product_name      VARCHAR(255) NOT NULL,
  quantity          DECIMAL(10,2) NOT NULL DEFAULT 0,
  INDEX idx_so_items_order (order_id),
  FOREIGN KEY (order_id) REFERENCES so_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаблон товаров для поставщика (какие товары доступны для заказа)
CREATE TABLE IF NOT EXISTS so_templates (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       CHAR(36) NOT NULL COMMENT 'FK → suppliers.id',
  legal_entity      VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"',
  product_id        CHAR(36) NULL COMMENT 'FK → products.id (может быть NULL для ручных позиций)',
  sku               VARCHAR(50) NOT NULL,
  product_name      VARCHAR(255) NOT NULL,
  sort_order        SMALLINT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_so_tpl (supplier_id, legal_entity, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переопределение дедлайна для конкретной даты
CREATE TABLE IF NOT EXISTS so_deadline_overrides (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id        INT UNSIGNED NOT NULL,
  delivery_date     DATE NOT NULL,
  deadline_time     TIME NOT NULL DEFAULT '14:00:00',
  created_by        VARCHAR(100) NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_so_deadline (session_id, delivery_date),
  FOREIGN KEY (session_id) REFERENCES so_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
