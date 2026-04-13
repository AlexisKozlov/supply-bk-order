-- Миграция: добавить legal_entity_group в suppliers
--
-- Зачем: готовимся к подключению третьего юрлица «ООО "Пицца Стар"» (Додо).
-- У него будут полностью свои поставщики — а значит, нужно различать,
-- к какой группе юрлиц относится каждый поставщик.
--
-- Формат: как в таблице restaurants — varchar(20) с кодами 'BK_VM' / 'PS'.
-- Всем существующим поставщикам проставляем 'BK_VM' (это текущее поведение).

ALTER TABLE suppliers
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

-- Индекс для быстрой фильтрации справочника по группе юрлиц.
CREATE INDEX idx_suppliers_le_group ON suppliers (legal_entity_group);

-- Явно проставляем всем существующим записям BK_VM (страховка на случай,
-- если колонка уже когда-то создавалась вручную и без DEFAULT).
UPDATE suppliers SET legal_entity_group = 'BK_VM' WHERE legal_entity_group = '' OR legal_entity_group IS NULL;
