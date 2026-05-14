-- Модуль «Задачи»: цвет фона карточки + таймер времени работы
--
-- F4 — цвет фона карточки:
--   tasks_cards.color — HEX-цвет (например '#FFE0B2'). NULL = без цвета.
--   Цвет показывается как лёгкая тонировка карточки на канбане и подложка
--   шапки в модалке. На приоритет (полоска слева) и метки (сверху) не влияет.
--
-- C4 — таймер на карточке:
--   tasks_card_time — записи учёта времени, одна запись = один интервал
--   start..stop. Открытая запись (stopped_at IS NULL) считается «таймер бежит».
--   У одного пользователя может быть только одна открытая запись на карточку.
--   seconds заполняется при остановке для быстрой суммы по карточке без
--   пересчёта по timestamp'ам.
--
-- Безопасная миграция: ALTER только добавляет nullable колонку без default,
-- новая таблица создаётся через IF NOT EXISTS. Существующие данные не трогаем.

ALTER TABLE tasks_cards
  ADD COLUMN color VARCHAR(20) NULL COMMENT 'HEX-цвет фона карточки, NULL = без цвета'
  AFTER priority;

CREATE TABLE IF NOT EXISTS tasks_card_time (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id       INT UNSIGNED NOT NULL,
  user_name     VARCHAR(100) NOT NULL COMMENT 'FK → users.name',
  started_at    DATETIME NOT NULL,
  stopped_at    DATETIME NULL COMMENT 'NULL = таймер бежит',
  seconds       INT UNSIGNED NULL COMMENT 'Длительность в секундах, заполняется при остановке',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tct_card (card_id),
  INDEX idx_tct_running (user_name, stopped_at),
  CONSTRAINT fk_tct_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
