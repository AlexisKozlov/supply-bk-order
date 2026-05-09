-- 2026-05-09: добавление колонок period_frequency и truck_pallets в plans.
-- Раньше фронт (PlanningView) отправлял эти поля при сохранении, но в
-- crud.php writeWhitelist['plans'] их не было — поля молча отбрасывались.
-- В результате после загрузки сохранённого плана:
--   * period_frequency сбрасывался в дефолт 'w1' (раз в неделю), границы
--     периодов сдвигались, расчёты ехали;
--   * truck_pallets терялся, отображение «загрузка машин» сбивалось.
-- Пользователь каждый раз настраивал заново.

ALTER TABLE plans
  ADD COLUMN IF NOT EXISTS period_frequency VARCHAR(8) DEFAULT NULL AFTER period_count,
  ADD COLUMN IF NOT EXISTS truck_pallets INT DEFAULT NULL AFTER period_frequency;
