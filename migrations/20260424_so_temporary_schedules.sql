-- Временный график поставщика на период праздников / переносов.
-- Если дата поставки попадает в период, система берёт этот график
-- вместо основного графика из so_supplier_schedules.

CREATE TABLE IF NOT EXISTS so_supplier_temp_schedule_periods (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       CHAR(36) NOT NULL COMMENT 'FK -> suppliers.id',
  date_from         DATE NOT NULL,
  date_to           DATE NOT NULL,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by        VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uq_so_temp_schedule_supplier (supplier_id),
  INDEX idx_so_temp_schedule_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS so_supplier_temp_schedule_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id         INT UNSIGNED NOT NULL,
  restaurant_id     INT UNSIGNED NOT NULL COMMENT 'FK -> restaurants.id',
  order_day         TINYINT NOT NULL COMMENT 'День заказа: 1=ПН ... 7=ВС',
  delivery_day      TINYINT NOT NULL COMMENT 'День поставки: 1=ПН ... 7=ВС',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by        VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uq_so_temp_schedule_item (period_id, restaurant_id, delivery_day),
  INDEX idx_so_temp_schedule_period (period_id),
  INDEX idx_so_temp_schedule_restaurant (restaurant_id),
  CONSTRAINT fk_so_temp_schedule_period FOREIGN KEY (period_id) REFERENCES so_supplier_temp_schedule_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_so_temp_schedule_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
