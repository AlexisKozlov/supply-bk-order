-- Миграция: добавить legal_entity_group в stock_collections
--
-- Зачем: сборы остатков создаются под одно юрлицо (например, БК),
-- но в ЛК ресторана и в Telegram-боте они показываются на всю группу
-- (BK+VM). Это приводило к рассинхрону: сотрудник ВМ не видел свой
-- ожидаемый сбор, хотя ресторан ВМ его уже наполняет. Решение:
-- область видимости сбора — это группа юрлиц. Колонка legal_entity
-- остаётся для аудита (кто создал), legal_entity_group — для фильтрации.

ALTER TABLE stock_collections
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

UPDATE stock_collections
SET legal_entity_group = CASE
        WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
        ELSE 'BK_VM'
    END
WHERE legal_entity_group IS NULL OR legal_entity_group = '' OR legal_entity_group = 'BK_VM';

CREATE INDEX idx_stock_collections_le_group_status
    ON stock_collections (legal_entity_group, status);
