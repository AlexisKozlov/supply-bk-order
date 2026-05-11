-- Гибкие времена напоминаний для дедлайнов подачи заявок.
--
-- Раньше cron слал «с 8:00 каждый час до дедлайна + финальное за 5 мин».
-- Теперь — список конкретных моментов, заданных закупкой на каждый дедлайн.
--
-- Формат reminder_times: JSON-массив объектов
--   [{"days_before": 1, "time": "17:00"}, {"days_before": 0, "time": "08:00"}, ...]
-- где days_before — за сколько дней до дня подачи слать напоминание
-- (0 = в сам день подачи; 1 = накануне; 2 = за 2 дня и т.п.).
--
-- NULL или пустой массив = напоминаний нет, шлётся только финальное
-- (за 5 мин до дедлайна) как страховочное «крайний оклик».
--
-- Добавляем колонку в три таблицы:
--   supplier_schedule_deadlines   — override на (поставщик, ресторан, день_заказа)
--   supplier_default_deadlines    — дефолт поставщика на день_доставки
--   delivery_schedule              — для основной поставки (склад)
--
-- Также заполняем дефолт у delivery_schedule-строк, которым только что бак-
-- заполнили order_day + order_deadline (BK_VM, region in/out, 09:00):
-- [{1,17:00}, {0,08:00}] — вечер накануне дедлайна + утро в день дедлайна.

ALTER TABLE supplier_schedule_deadlines
  ADD COLUMN reminder_times JSON NULL COMMENT 'список времён напоминаний (days_before+HH:MM)' AFTER deadline_time;

ALTER TABLE supplier_default_deadlines
  ADD COLUMN reminder_times JSON NULL COMMENT 'список времён напоминаний по умолчанию' AFTER deadline_time;

ALTER TABLE delivery_schedule
  ADD COLUMN reminder_times JSON NULL COMMENT 'список времён напоминаний для основной поставки' AFTER order_deadline;

-- Заполняем дефолт у строк с автозаполненной заявкой (auto:bulk-main-deadline)
UPDATE delivery_schedule
SET reminder_times = JSON_ARRAY(
    JSON_OBJECT('days_before', 1, 'time', '17:00'),
    JSON_OBJECT('days_before', 0, 'time', '08:00')
)
WHERE updated_by = 'auto:bulk-main-deadline'
  AND order_day IS NOT NULL
  AND order_deadline IS NOT NULL
  AND reminder_times IS NULL;
