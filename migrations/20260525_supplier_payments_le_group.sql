-- supplier_payments: добавляем legal_entity_group для фильтрации по группе юрлиц.
--
-- Фронтенд /payments показывает финансовому отделу оплаты сразу по всей
-- группе (БК+ВМ или ПС). Раньше фильтр был только по конкретному юрлицу
-- (orderStore.settings.legalEntity), что давало половину картины.
--
-- Решение по образцу products/suppliers: отдельная колонка legal_entity_group
-- (VARCHAR(8): 'BK_VM' | 'PS') + триггер BEFORE INSERT с автозаполнением
-- из legal_entity. CRUD-RBAC уже умеет проверять доступ по
-- legal_entity_group (см. crud.php строка 96-102).

ALTER TABLE supplier_payments
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NULL AFTER legal_entity;

-- Бэкфилл существующих записей.
UPDATE supplier_payments
SET legal_entity_group = CASE
    WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
    ELSE 'BK_VM'
END
WHERE legal_entity_group IS NULL;

-- Индекс для фильтрации.
ALTER TABLE supplier_payments
  ADD INDEX IF NOT EXISTS idx_sp_le_group (legal_entity_group);

-- Триггер автозаполнения для новых записей.
DROP TRIGGER IF EXISTS trg_supplier_payments_le_group_ins;

CREATE TRIGGER trg_supplier_payments_le_group_ins
BEFORE INSERT ON supplier_payments
FOR EACH ROW
BEGIN
    IF NEW.legal_entity_group IS NULL OR NEW.legal_entity_group = '' THEN
        SET NEW.legal_entity_group = CASE
            WHEN NEW.legal_entity LIKE '%Пицца Стар%' THEN 'PS'
            ELSE 'BK_VM'
        END;
    END IF;
END;
