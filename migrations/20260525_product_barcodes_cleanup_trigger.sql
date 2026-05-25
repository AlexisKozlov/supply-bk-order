-- Очистка orphan-записей product_barcodes при удалении товара.
--
-- products.sku не уникален (один SKU может существовать у нескольких юрлиц),
-- поэтому ставить FK с ON DELETE CASCADE нельзя. Вместо FK — триггер,
-- который удаляет штрихкоды только когда последняя строка с этим sku в
-- products исчезла (т.е. товар полностью удалён, а не только из одного юрлица).

DROP TRIGGER IF EXISTS trg_products_delete_orphan_barcodes;

CREATE TRIGGER trg_products_delete_orphan_barcodes
AFTER DELETE ON products
FOR EACH ROW
BEGIN
    IF (SELECT COUNT(*) FROM products WHERE sku = OLD.sku) = 0 THEN
        DELETE FROM product_barcodes WHERE sku = OLD.sku;
    END IF;
END;
