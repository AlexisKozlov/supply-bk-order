-- Миграция: добавить legal_entity_group в pallet_reference
--
-- Зачем: «Паллетовка склада» была общей на всё приложение — при выборе
-- Пицца Стар показывались 580 строк БК+ВМ. Добавляем группу юрлиц,
-- существующие строки помечаем BK_VM (по умолчанию). Уникальность
-- имени товара теперь в рамках группы: БК+ВМ-«Молоко» и ПС-«Молоко»
-- могут существовать параллельно.

ALTER TABLE pallet_reference
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER id;

CREATE INDEX idx_pallet_reference_le_group ON pallet_reference (legal_entity_group);

UPDATE pallet_reference SET legal_entity_group = 'BK_VM'
    WHERE legal_entity_group IS NULL OR legal_entity_group = '';

-- Новый UNIQUE по (legal_entity_group, name): внутри каждой группы свои товары.
-- Старый UNIQUE на name убираем — иначе нельзя будет иметь одноимённые
-- позиции у БК+ВМ и ПС. В зависимости от того, как создавалась таблица
-- исторически, индекс может называться `name` или `uk_name`.
ALTER TABLE pallet_reference DROP INDEX uk_name;
ALTER TABLE pallet_reference
    ADD UNIQUE KEY uk_pallet_ref_group_name (legal_entity_group, name);
