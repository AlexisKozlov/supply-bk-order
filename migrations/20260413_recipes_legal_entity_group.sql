-- Миграция: добавить legal_entity_group в recipes и recipe_groups
--
-- Зачем: справочник рецептур до сих пор был общим для всех юрлиц,
-- поэтому при выборе Пицца Стар в базе показывались рецепты БК+ВМ.
-- Теперь каждая рецептура и каждая группа рецептур привязана к группе
-- юрлиц ('BK_VM' | 'PS'). Существующие записи помечаем как 'BK_VM'.

ALTER TABLE recipes
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER thk;

CREATE INDEX idx_recipes_le_group ON recipes (legal_entity_group);

UPDATE recipes SET legal_entity_group = 'BK_VM' WHERE legal_entity_group IS NULL OR legal_entity_group = '';

ALTER TABLE recipe_groups
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER name;

CREATE INDEX idx_recipe_groups_le_group ON recipe_groups (legal_entity_group);

UPDATE recipe_groups SET legal_entity_group = 'BK_VM' WHERE legal_entity_group IS NULL OR legal_entity_group = '';
