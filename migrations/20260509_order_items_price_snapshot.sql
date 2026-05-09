-- 2026-05-09: снэпшот цены закупки в order_items.
-- Раньше сумма заказа считалась через JOIN с product_prices на лету.
-- При изменении прайса задним числом «уезжали» суммы старых заказов
-- в дашборде и истории — некорректная отчётность.
-- Теперь:
--   * При сохранении новых заказов цена записывается в order_items.price.
--   * dashboard_kpi и др. отчёты используют COALESCE(oi.price, pp.price, 0):
--     если есть исторический snapshot — берём его, иначе fallback на текущий прайс.
--   * Старые записи (до этой миграции) остаются с NULL — fallback работает,
--     поведение для них прежнее (точечный пересчёт по текущему прайсу).

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS price DECIMAL(12,2) NULL DEFAULT NULL AFTER transit,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NULL DEFAULT NULL AFTER price,
  ADD COLUMN IF NOT EXISTS unit_type VARCHAR(16) NULL DEFAULT NULL AFTER vat_rate,
  ADD COLUMN IF NOT EXISTS currency VARCHAR(8) NULL DEFAULT NULL AFTER unit_type;
