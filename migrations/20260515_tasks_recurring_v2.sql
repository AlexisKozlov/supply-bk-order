-- Повторяющиеся задачи v2: больше вариантов повтора, окончание, видимость
--
-- К расписанию (tasks_template_schedules):
--   interval_n  — «каждые N» дней/недель/месяцев (раз в 2 недели и т.п.)
--   weekdays    — CSV дней недели 1-7 для weekly (несколько дней: «1,3,5»,
--                 «по будням» = «1,2,3,4,5»). Старое поле weekday остаётся
--                 для обратной совместимости (используется как fallback).
--   end_kind    — окончание повтора: never | until | count
--   end_date    — повторять до этой даты (end_kind = until)
--   end_count   — повторить N раз (end_kind = count)
--   runs_done   — счётчик уже созданных карточек
--
-- К карточке (tasks_cards):
--   source_schedule_id — каким расписанием создана карточка (для показа
--                        результата повтора). FK ON DELETE SET NULL —
--                        удаление расписания не трогает сами карточки.
--
-- Безопасная миграция: все колонки nullable либо с DEFAULT, существующие
-- расписания получают «каждые 1, бессрочно» — поведение не меняется.

ALTER TABLE tasks_template_schedules
  ADD COLUMN interval_n  SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Каждые N (дней/недель/месяцев)',
  ADD COLUMN weekdays    VARCHAR(20)  NULL COMMENT 'CSV дней недели 1-7 для weekly',
  ADD COLUMN end_kind    ENUM('never','until','count') NOT NULL DEFAULT 'never' COMMENT 'Окончание повтора',
  ADD COLUMN end_date    DATE NULL COMMENT 'Повторять до этой даты (end_kind=until)',
  ADD COLUMN end_count   SMALLINT UNSIGNED NULL COMMENT 'Повторить N раз (end_kind=count)',
  ADD COLUMN runs_done   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Сколько карточек уже создано';

ALTER TABLE tasks_cards
  ADD COLUMN source_schedule_id INT UNSIGNED NULL COMMENT 'Расписание-источник повторяющейся задачи';

ALTER TABLE tasks_cards
  ADD CONSTRAINT fk_tc_source_sched FOREIGN KEY (source_schedule_id)
    REFERENCES tasks_template_schedules(id) ON DELETE SET NULL;
