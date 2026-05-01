-- Вторая пачка безопасных индексов для частых экранов API.
-- Миграция не меняет данные, только ускоряет выборки.

CREATE INDEX IF NOT EXISTS idx_so_orders_submitted_rest
  ON so_orders (submitted_at, restaurant_number);

CREATE INDEX IF NOT EXISTS idx_so_orders_supplier_date_entity_rest
  ON so_orders (supplier_id, delivery_date, legal_entity, restaurant_number);

CREATE INDEX IF NOT EXISTS idx_so_orders_supplier_date_status
  ON so_orders (supplier_id, delivery_date, status);

CREATE INDEX IF NOT EXISTS idx_ro_orders_date_status
  ON ro_orders (delivery_date, status);

CREATE INDEX IF NOT EXISTS idx_ro_sessions_status_dates
  ON ro_sessions (status, week_start, week_end);

CREATE INDEX IF NOT EXISTS idx_tl_trucks_plan_sort
  ON tl_trucks (plan_id, sort_order, id);

CREATE INDEX IF NOT EXISTS idx_tl_assignments_truck_sort
  ON tl_assignments (truck_id, sort_order, id);
