-- Миграция: добавить legal_entity_group в dist_sessions
--
-- Зачем: модуль «Распределение новинок» был общим для всех юрлиц,
-- поэтому при выборе Пицца Стар пользователь видел сессии БК+ВМ и
-- список ресторанов обоих юрлиц вперемешку. Теперь каждая сессия
-- привязана к группе юрлиц.

ALTER TABLE dist_sessions
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER name;

CREATE INDEX idx_dist_sessions_le_group ON dist_sessions (legal_entity_group);

UPDATE dist_sessions SET legal_entity_group = 'BK_VM'
    WHERE legal_entity_group IS NULL OR legal_entity_group = '';
