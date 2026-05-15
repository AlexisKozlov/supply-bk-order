-- Модуль «Задачи»: расширенные настройки доски
--
-- Добавляет к доске шесть настроек:
--   auto_timer        — авто-старт таймера при создании задачи на этой доске
--   default_priority  — приоритет, который подставляется новым задачам
--   default_assignee  — исполнитель по умолчанию для новых задач (users.name)
--   default_column_id — колонка по умолчанию (для быстрого создания/виджета)
--   accent_color      — HEX-цвет акцента доски (оформление)
--   compact_cards     — компактный режим карточек
--
-- Безопасная миграция: все колонки nullable либо с DEFAULT, существующие
-- доски получают «выключено / не задано», поведение не меняется.

ALTER TABLE tasks_boards
  ADD COLUMN auto_timer        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Авто-старт таймера при создании задачи',
  ADD COLUMN default_priority  VARCHAR(10)  NULL COMMENT 'Приоритет новых задач: low/medium/high/urgent',
  ADD COLUMN default_assignee  VARCHAR(100) NULL COMMENT 'Исполнитель новых задач (users.name)',
  ADD COLUMN default_column_id INT UNSIGNED NULL COMMENT 'Колонка по умолчанию для новых задач',
  ADD COLUMN accent_color      VARCHAR(20)  NULL COMMENT 'HEX-цвет акцента доски',
  ADD COLUMN compact_cards     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Компактный режим карточек';
