-- Миграция: колонки для Пицца Стар
--
-- 1) restaurants.dodo_is_number — отдельный номер ДОДО ИС (у Додо два
--    номера: «№ ресторана» и «№ ДОДО ИС», они часто не совпадают).
--    NULL для БК+ВМ, заполняется при импорте графика Додо.
-- 2) delivery_schedule.dough_time — второе время доставки в тот же день:
--    тесто для ПС-ресторанов развозится отдельно от остальной продукции,
--    в пределах одного дня недели может быть два разных окна.

ALTER TABLE restaurants
    ADD COLUMN dodo_is_number SMALLINT UNSIGNED DEFAULT NULL AFTER number;

CREATE INDEX idx_restaurants_dodo_is ON restaurants (dodo_is_number);

ALTER TABLE delivery_schedule
    ADD COLUMN dough_time VARCHAR(30) DEFAULT NULL AFTER delivery_time;
