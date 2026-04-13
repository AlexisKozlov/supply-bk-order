-- Миграция: добавить legal_entity в restaurant_sales
--
-- Зачем: данные реализации были общими для всех юрлиц — при переключении
-- на Пицца Стар пользователь видел реализацию БК+ВМ. Теперь каждая запись
-- привязана к конкретному юрлицу. Существующие 72k строк помечаем как
-- «Бургер БК», чтобы при переключении на ВМ/ПС там было пусто (если ВМ
-- нужно, пусть заливают отдельно).

ALTER TABLE restaurant_sales
    ADD COLUMN legal_entity VARCHAR(255) NOT NULL DEFAULT 'ООО "Бургер БК"' AFTER sale_date;

UPDATE restaurant_sales
    SET legal_entity = 'ООО "Бургер БК"'
    WHERE legal_entity IS NULL OR legal_entity = '';

-- Новый UNIQUE: теперь (sale_date, analog_group, legal_entity)
ALTER TABLE restaurant_sales DROP INDEX uk_date_group;
ALTER TABLE restaurant_sales
    ADD UNIQUE KEY uk_rs_date_group_entity (sale_date, analog_group, legal_entity);

CREATE INDEX idx_restaurant_sales_legal_entity ON restaurant_sales (legal_entity);
