-- Миграция: legal_entity_group в order_corrections
--
-- Зачем: корректировки заказов ресторанов не имели признака юрлица,
-- поэтому при открытии раздела на ПС-сессии виден список БК+ВМ-заявок.
-- Добавляем колонку и заполняем по restaurants через restaurant_number.
-- PS-номера живут в диапазоне 1001+, BK_VM — ниже, поэтому сопоставление
-- однозначно.

ALTER TABLE order_corrections
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER restaurant_chat_id;

CREATE INDEX idx_order_corrections_le_group ON order_corrections (legal_entity_group);

UPDATE order_corrections oc
    LEFT JOIN restaurants r ON r.number = oc.restaurant_number AND r.active = 1
    SET oc.legal_entity_group = COALESCE(r.legal_entity_group,
        CASE WHEN oc.restaurant_number >= 1000 THEN 'PS' ELSE 'BK_VM' END);
