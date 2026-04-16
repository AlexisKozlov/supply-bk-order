-- Кратность в шаблонах заказов ресторанов должна храниться отдельно
-- от карточки товара в products, чтобы изменения шаблона не меняли справочник.

ALTER TABLE ro_templates
    ADD COLUMN multiplicity INT NOT NULL DEFAULT 1 AFTER product_name;

UPDATE ro_templates t
LEFT JOIN products p
  ON p.sku = t.sku
 AND p.legal_entity = t.legal_entity
 AND p.is_active = 1
SET t.multiplicity = COALESCE(NULLIF(p.multiplicity, 0), 1)
WHERE t.multiplicity IS NULL OR t.multiplicity <= 0;
