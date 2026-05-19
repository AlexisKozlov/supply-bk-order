-- ============================================================
-- Модуль «Сбор заказа основной поставки»
-- Помощник для ресторанов: собрать заказ и подготовить к загрузке в 1С УТ
-- Все таблицы с префиксом sa_ для лёгкого удаления
-- ============================================================

-- Заказ ресторана (один на ресторан + дату доставки)
CREATE TABLE IF NOT EXISTS sa_orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_number   SMALLINT UNSIGNED NOT NULL,
  legal_entity        VARCHAR(100) NOT NULL,
  legal_entity_group  VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  delivery_date       DATE NOT NULL,
  comment             VARCHAR(500) NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by          VARCHAR(100) NULL COMMENT 'заполняется только когда заказ правит отдел закупок',
  UNIQUE KEY uq_sa_orders (restaurant_number, delivery_date),
  INDEX idx_sa_orders_date (delivery_date),
  INDEX idx_sa_orders_group (legal_entity_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Позиции заказа
CREATE TABLE IF NOT EXISTS sa_order_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  sku             VARCHAR(50) NOT NULL,
  product_name    VARCHAR(255) NOT NULL,
  external_code   VARCHAR(20) NULL COMMENT 'снимок внешнего кода для экспорта в 1С УТ',
  analog_group    VARCHAR(255) NULL COMMENT 'снимок группы аналогов',
  category        VARCHAR(20) NOT NULL COMMENT 'Сухой / Холод / Мороз',
  multiplicity    INT NOT NULL DEFAULT 1,
  quantity        DECIMAL(10,2) NOT NULL DEFAULT 0,
  INDEX idx_sa_items_order (order_id),
  FOREIGN KEY (order_id) REFERENCES sa_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
