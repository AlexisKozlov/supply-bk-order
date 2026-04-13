-- Миграция: legal_entity в meeting_protocols и meeting_protocol_series
--
-- Зачем: протоколы собраний были общими на всё приложение — при выборе
-- Пицца Стар в сайдбаре пользователь видел БК-протоколы. Каждое собрание
-- теперь привязано к конкретному юрлицу. Существующие 1 протокол и
-- 12 решений помечаем как «Бургер БК».

ALTER TABLE meeting_protocols
    ADD COLUMN legal_entity VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"' AFTER topic;

CREATE INDEX idx_meeting_protocols_legal_entity ON meeting_protocols (legal_entity);

UPDATE meeting_protocols SET legal_entity = 'ООО "Бургер БК"'
    WHERE legal_entity IS NULL OR legal_entity = '';

-- meeting_protocol_series тоже per-entity, чтобы серии регулярных совещаний
-- не показывались в чужом юрлице
ALTER TABLE meeting_protocol_series
    ADD COLUMN legal_entity VARCHAR(100) NOT NULL DEFAULT 'ООО "Бургер БК"' AFTER name;

CREATE INDEX idx_meeting_protocol_series_legal_entity ON meeting_protocol_series (legal_entity);

UPDATE meeting_protocol_series SET legal_entity = 'ООО "Бургер БК"'
    WHERE legal_entity IS NULL OR legal_entity = '';
