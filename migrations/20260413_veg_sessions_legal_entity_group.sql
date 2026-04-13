-- Миграция: legal_entity_group в veg_sessions
--
-- Планета Ресторанов используется сейчас только БК+ВМ. Когда для
-- Пицца Стар будут заводиться свои сессии, они должны быть в своей
-- группе. Существующие 5 сессий помечаем BK_VM.

ALTER TABLE veg_sessions
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER status;

CREATE INDEX idx_veg_sessions_le_group ON veg_sessions (legal_entity_group);

UPDATE veg_sessions SET legal_entity_group = 'BK_VM'
    WHERE legal_entity_group IS NULL OR legal_entity_group = '';
