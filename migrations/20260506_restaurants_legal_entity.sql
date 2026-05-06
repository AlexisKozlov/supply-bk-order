-- Добавляем явную колонку legal_entity в таблицу restaurants.
--
-- Зачем: в legal_entities.php стоит хардкод «если номер ресторана = 3, то это
-- ООО «Воглия Матта». Если ресторан 3 переедет в другое юрлицо или появится
-- второй ВМ-ресторан — заказы начнут писаться не туда. Делаем колонку
-- источником истины.
--
-- Заполняем: PS → ООО «Пицца Стар», номер 3 BK_VM → ООО «Воглия Матта»,
-- остальные BK_VM → ООО «Бургер БК».

ALTER TABLE restaurants
  ADD COLUMN IF NOT EXISTS legal_entity VARCHAR(255) NOT NULL DEFAULT '' AFTER legal_entity_group;

UPDATE restaurants
SET legal_entity = CASE
  WHEN legal_entity_group = 'PS' THEN 'ООО "Пицца Стар"'
  WHEN number = 3 THEN 'ООО "Воглия Матта"'
  ELSE 'ООО "Бургер БК"'
END
WHERE legal_entity = '' OR legal_entity IS NULL;
