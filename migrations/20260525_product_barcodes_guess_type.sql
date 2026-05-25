-- Эвристика для миграционных штрихкодов: проставить тип по multiplicity товара.
--
-- После первоначального переноса products.gtin → product_barcodes все 353
-- записи имели barcode_type='unknown'. По правилу пользователя:
--   multiplicity > 1 → товар продаётся штуками (коробка содержит multiplicity штук),
--                      значит штрихкод в карточке — это штрихкод штуки;
--   multiplicity = 1 или NULL → товар учитывается коробками, штрихкод — коробки.
--
-- products.sku не уникален (один SKU может быть у нескольких юрлиц с разными
-- multiplicity), поэтому берём MAX по всем строкам с этим SKU — это «безопасный»
-- выбор: если хоть у одного юрлица товар штучный, считаем штрихкод штучным.
--
-- Затрагивает только записи с типом 'unknown'. Уже проставленные вручную типы
-- (box/piece/pack/other) не перетираются.

UPDATE product_barcodes b
SET b.barcode_type = CASE
    WHEN (SELECT MAX(COALESCE(p.multiplicity, 1)) FROM products p WHERE p.sku = b.sku) > 1
        THEN 'piece'
    ELSE 'box'
END
WHERE b.barcode_type = 'unknown';
