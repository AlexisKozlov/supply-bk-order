-- Добавляем диапазон дат в сессию овощей
ALTER TABLE veg_sessions
  ADD COLUMN date_from DATE DEFAULT NULL COMMENT 'Начало недели доставки' AFTER name,
  ADD COLUMN date_to DATE DEFAULT NULL COMMENT 'Конец недели доставки' AFTER date_from;
