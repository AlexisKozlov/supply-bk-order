-- Первые безопасные индексы для частых фильтров портала.
-- Миграция не меняет данные, только ускоряет выборки.

CREATE INDEX IF NOT EXISTS idx_products_group_supplier_active_name
  ON products (legal_entity_group, supplier, is_active, name);

CREATE INDEX IF NOT EXISTS idx_products_group_active_sku
  ON products (legal_entity_group, is_active, sku);

CREATE INDEX IF NOT EXISTS idx_suppliers_group_active_name
  ON suppliers (legal_entity_group, is_active, short_name);

CREATE INDEX IF NOT EXISTS idx_restaurant_sales_group_date_analog
  ON restaurant_sales (legal_entity_group, sale_date, analog_group);

CREATE INDEX IF NOT EXISTS idx_audit_log_entity_created
  ON audit_log (entity_type, entity_id, created_at);

CREATE INDEX IF NOT EXISTS idx_stock_malling_uploaded
  ON stock_malling (uploaded_at);
