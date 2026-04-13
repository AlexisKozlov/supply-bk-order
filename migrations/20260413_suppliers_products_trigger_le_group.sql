-- Миграция: триггеры для автоматического заполнения legal_entity_group
--
-- Зачем: при создании поставщика или товара фронт передаёт только
-- legal_entity (полное название юрлица), а legal_entity_group оставался
-- по умолчанию BK_VM. Из-за этого новые записи от Пицца Стар попадали
-- в группу BK_VM и показывались на чужом юрлице.
-- Триггеры вычисляют группу из legal_entity на стороне БД, чтобы любой
-- способ записи (сайт, бот, миграции) работал одинаково.

DROP TRIGGER IF EXISTS trg_suppliers_before_insert;
DROP TRIGGER IF EXISTS trg_suppliers_before_update;
DROP TRIGGER IF EXISTS trg_products_before_insert;
DROP TRIGGER IF EXISTS trg_products_before_update;

DELIMITER $$

CREATE TRIGGER trg_suppliers_before_insert
BEFORE INSERT ON suppliers
FOR EACH ROW
BEGIN
    IF NEW.legal_entity IS NOT NULL AND NEW.legal_entity LIKE '%Пицца Стар%' THEN
        SET NEW.legal_entity_group = 'PS';
    ELSEIF NEW.legal_entity IS NOT NULL THEN
        SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

CREATE TRIGGER trg_suppliers_before_update
BEFORE UPDATE ON suppliers
FOR EACH ROW
BEGIN
    IF NEW.legal_entity IS NOT NULL AND NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца Стар%' THEN
            SET NEW.legal_entity_group = 'PS';
        ELSE
            SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_products_before_insert
BEFORE INSERT ON products
FOR EACH ROW
BEGIN
    IF NEW.legal_entity IS NOT NULL AND NEW.legal_entity LIKE '%Пицца Стар%' THEN
        SET NEW.legal_entity_group = 'PS';
    ELSEIF NEW.legal_entity IS NOT NULL THEN
        SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

CREATE TRIGGER trg_products_before_update
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    IF NEW.legal_entity IS NOT NULL AND NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца Стар%' THEN
            SET NEW.legal_entity_group = 'PS';
        ELSE
            SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

DELIMITER ;
