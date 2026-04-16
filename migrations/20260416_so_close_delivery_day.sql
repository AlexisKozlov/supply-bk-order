-- Добавить флаг принудительного закрытия дня поставки.
-- Если is_closed = 1, день закрыт для подачи заявок вне зависимости от времени.
ALTER TABLE so_deadline_overrides
  ADD COLUMN is_closed TINYINT NOT NULL DEFAULT 0 AFTER deadline_time;
