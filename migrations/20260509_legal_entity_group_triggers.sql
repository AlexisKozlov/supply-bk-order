-- 2026-05-09: триггеры автозаполнения legal_entity_group по legal_entity.
-- Дополнение к 20260509_legal_entity_group_columns.sql.
-- Без них новые INSERT-ы (которых много, ~10 точек в коде, плюс cron_telegram)
-- получат default 'BK_VM' даже для PS-юрлиц. Триггеры избавляют от необходимости
-- править каждый INSERT и гарантируют согласованность даже при ручных вставках
-- через phpMyAdmin или импорты.

DELIMITER $$

-- ─── orders ─────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_orders_le_group_ins$$
CREATE TRIGGER trg_orders_le_group_ins BEFORE INSERT ON orders
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_orders_le_group_upd$$
CREATE TRIGGER trg_orders_le_group_upd BEFORE UPDATE ON orders
FOR EACH ROW BEGIN
    IF NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
        ELSE SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

-- ─── so_orders ──────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_so_orders_le_group_ins$$
CREATE TRIGGER trg_so_orders_le_group_ins BEFORE INSERT ON so_orders
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_so_orders_le_group_upd$$
CREATE TRIGGER trg_so_orders_le_group_upd BEFORE UPDATE ON so_orders
FOR EACH ROW BEGIN
    IF NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
        ELSE SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

-- ─── product_prices ─────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_product_prices_le_group_ins$$
CREATE TRIGGER trg_product_prices_le_group_ins BEFORE INSERT ON product_prices
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_product_prices_le_group_upd$$
CREATE TRIGGER trg_product_prices_le_group_upd BEFORE UPDATE ON product_prices
FOR EACH ROW BEGIN
    IF NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
        ELSE SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

-- ─── ro_templates ───────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_ro_templates_le_group_ins$$
CREATE TRIGGER trg_ro_templates_le_group_ins BEFORE INSERT ON ro_templates
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_ro_templates_le_group_upd$$
CREATE TRIGGER trg_ro_templates_le_group_upd BEFORE UPDATE ON ro_templates
FOR EACH ROW BEGIN
    IF NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
        ELSE SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

DELIMITER ;
