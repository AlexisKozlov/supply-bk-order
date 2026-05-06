-- Связь protocol_decisions ↔ tasks_cards: одна колонка для синхронизации.
-- Карточки кладутся на основную доску ответственного (первую неархивную по sort_order).
-- Если такой доски нет — создаётся стандартная.
-- Миграция идемпотентна: повторный запуск не ломает данные.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'protocol_decisions' AND column_name = 'tasks_card_id');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE protocol_decisions ADD COLUMN tasks_card_id INT UNSIGNED NULL, ADD INDEX idx_dec_card (tasks_card_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
