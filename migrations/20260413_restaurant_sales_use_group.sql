-- Миграция: restaurant_sales переезжает с legal_entity на legal_entity_group
--
-- Зачем: реализация для «Бургер БК» и «Воглия Матта» едина (одна выгрузка
-- из 1С на обе юрсущности), а «Пицца Стар» имеет свои данные. Поэтому
-- фильтровать по конкретному юрлицу не нужно — достаточно группы
-- 'BK_VM' | 'PS'. Это проще и соответствует реальному процессу: БК/ВМ
-- на экране «Анализ → Реализация» должны видеть одинаковый набор данных.

-- 1) Сначала заменим значения, чтобы они влезли в VARCHAR(20)
UPDATE restaurant_sales
    SET legal_entity = CASE
        WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
        ELSE 'BK_VM'
    END;

-- 2) Переименуем колонку и сузим тип
ALTER TABLE restaurant_sales
    CHANGE COLUMN legal_entity legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM';
