-- Конструктор загрузки машин
-- Таблицы: tl_vehicles, tl_plans, tl_trucks, tl_assignments

-- Справочник типов машин
CREATE TABLE IF NOT EXISTS tl_vehicles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL COMMENT 'Название типа машины',
  capacity_pallets INT UNSIGNED NOT NULL DEFAULT 33 COMMENT 'Вместимость в паллетах',
  capacity_kg     DECIMAL(10,1) NOT NULL DEFAULT 20000 COMMENT 'Грузоподъёмность, кг',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tl_vehicles (name, capacity_pallets, capacity_kg, sort_order) VALUES
  ('Фура 20т', 33, 20000, 1),
  ('Фура 10т', 18, 10000, 2),
  ('Газель', 4, 1500, 3);

-- План загрузки на дату
CREATE TABLE IF NOT EXISTS tl_plans (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_date   DATE NOT NULL,
  allow_mixed_modes TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=можно смешивать режимы в одной машине',
  status          ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  note            TEXT NULL,
  created_by      VARCHAR(100) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tl_plans_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Машины в плане
CREATE TABLE IF NOT EXISTS tl_trucks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id         INT UNSIGNED NOT NULL,
  vehicle_id      INT UNSIGNED NULL COMMENT 'NULL если пользовательские параметры',
  custom_name     VARCHAR(100) NULL COMMENT 'Название, если не из справочника',
  capacity_pallets INT UNSIGNED NOT NULL DEFAULT 33,
  capacity_kg     DECIMAL(10,1) NOT NULL DEFAULT 20000,
  mode            ENUM('any','dry','cold','frozen') NOT NULL DEFAULT 'any' COMMENT 'Режим: any=смешанный',
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (plan_id) REFERENCES tl_plans(id) ON DELETE CASCADE,
  FOREIGN KEY (vehicle_id) REFERENCES tl_vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Назначения (что загружено в машину)
CREATE TABLE IF NOT EXISTS tl_assignments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  truck_id        INT UNSIGNED NOT NULL,
  assign_type     ENUM('order','category','item') NOT NULL COMMENT 'Уровень: целый заказ / категория / позиция',
  order_id        INT UNSIGNED NULL COMMENT 'ro_orders.id',
  category        VARCHAR(20) NULL COMMENT 'Сухой/Холод/Мороз — для category',
  order_item_id   INT UNSIGNED NULL COMMENT 'ro_order_items.id — для item',
  restaurant_number SMALLINT UNSIGNED NOT NULL,
  pallets         DECIMAL(6,2) NOT NULL DEFAULT 0,
  weight_kg       DECIMAL(10,1) NOT NULL DEFAULT 0,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (truck_id) REFERENCES tl_trucks(id) ON DELETE CASCADE,
  INDEX idx_tl_assign_order (order_id),
  INDEX idx_tl_assign_truck (truck_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
