-- Миграция: добавить legal_entity_group в products
--
-- Зачем: как и для suppliers, нужна единая колонка-флаг группы юрлиц,
-- чтобы фильтровать справочник товаров для Пицца Стар (группа 'PS')
-- отдельно от Бургер БК + Воглия Матта (группа 'BK_VM').
--
-- Важно: существующая колонка `legal_entity` у products используется
-- per-запись (есть и БК-товары, и ВМ-товары), поэтому её НЕ удаляем.
-- `legal_entity_group` — просто быстрый фильтр поверх.

ALTER TABLE products
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

CREATE INDEX idx_products_le_group ON products (legal_entity_group);

-- Заполняем для существующих товаров на основании legal_entity.
-- Бургер БК и Воглия Матта → 'BK_VM', Пицца Стар → 'PS'.
UPDATE products
SET legal_entity_group = CASE
    WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
    ELSE 'BK_VM'
END;
