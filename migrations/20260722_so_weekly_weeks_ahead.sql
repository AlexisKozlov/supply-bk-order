-- Недельный режим подачи заявок: сколько ближайших недель доставки показывать
-- ресторану для заказа. По умолчанию 1 — ресторан видит только ближайшую
-- открытую неделю; следующая появляется, когда у текущей пройдёт дедлайн.
-- Работает только в недельном режиме (weekly_deadline_dow задан).
ALTER TABLE so_supplier_settings
  ADD COLUMN weekly_weeks_ahead TINYINT NOT NULL DEFAULT 1 AFTER weekly_deadline_time;
