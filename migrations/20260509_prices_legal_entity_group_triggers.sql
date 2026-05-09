-- 2026-05-09: триггеры авто-заполнения legal_entity_group для price_agreements
-- и price_history. По аналогии с триггерами для orders/product_prices
-- (см. 20260509_legal_entity_group_triggers.sql), чтобы код мог писать
-- только legal_entity, а group синхронизировалась автоматически.

DELIMITER $$

-- ─── price_agreements ───────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_price_agreements_le_group_ins$$
CREATE TRIGGER trg_price_agreements_le_group_ins BEFORE INSERT ON price_agreements
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_price_agreements_le_group_upd$$
CREATE TRIGGER trg_price_agreements_le_group_upd BEFORE UPDATE ON price_agreements
FOR EACH ROW BEGIN
    IF NEW.legal_entity <> OLD.legal_entity THEN
        IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
        ELSE SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END$$

-- ─── price_history ──────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_price_history_le_group_ins$$
CREATE TRIGGER trg_price_history_le_group_ins BEFORE INSERT ON price_history
FOR EACH ROW BEGIN
    IF NEW.legal_entity LIKE '%Пицца%' THEN SET NEW.legal_entity_group = 'PS';
    ELSE SET NEW.legal_entity_group = 'BK_VM';
    END IF;
END$$

DELIMITER ;
