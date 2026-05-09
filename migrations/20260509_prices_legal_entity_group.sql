-- Миграция: цены живут на уровне группы юрлиц (BK_VM или PS), а не отдельно
-- по каждому юрлицу.
--
-- Зачем: фактически цены БК и ВМ всегда совпадают (поставщик и склад общие),
-- импорт уже дублировал одну цену на оба юрлица. На уровне БД это давало 2x
-- записи, риск рассинхронизации при ручном вводе и неоднозначность.
-- Решение: одна цена на группу. Колонку legal_entity не удаляем — она
-- становится «кто внёс/откуда импортировано», а область видимости —
-- legal_entity_group. Колонка group уже есть в product_prices (миграция
-- 20260509_legal_entity_group_columns.sql), осталось дедуплицировать
-- и поменять unique-индекс. Для price_agreements/price_history добавляем
-- колонку и триггеры авто-заполнения.

-- ─── product_prices: дедуп по группе и новый unique ──────────────────
-- Оставляем самую свежую запись (по MAX(id)) на каждую группу+sku+supplier+price_type.
-- Проверка показала: дублирующиеся записи в одной группе всегда совпадают
-- по цене (np>1 = 0), поэтому потери информации нет.
CREATE TEMPORARY TABLE pp_keep_ids AS
SELECT MAX(id) AS id
FROM product_prices
GROUP BY sku, supplier, legal_entity_group, price_type;

DELETE FROM product_prices
WHERE id NOT IN (SELECT id FROM pp_keep_ids);

DROP TEMPORARY TABLE pp_keep_ids;

ALTER TABLE product_prices
    DROP INDEX uk_pp_sku_supplier_le_type;

ALTER TABLE product_prices
    ADD UNIQUE KEY uk_pp_sku_supplier_leg_type (sku, supplier, legal_entity_group, price_type);

-- ─── price_agreements: добавляем колонку группы ──────────────────────
ALTER TABLE price_agreements
    ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

UPDATE price_agreements
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END
 WHERE legal_entity_group IS NULL OR legal_entity_group = '' OR legal_entity_group = 'BK_VM';

CREATE INDEX IF NOT EXISTS idx_price_agreements_le_group ON price_agreements (legal_entity_group);

-- ─── price_history: добавляем колонку группы ─────────────────────────
ALTER TABLE price_history
    ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(8) NOT NULL DEFAULT 'BK_VM' AFTER legal_entity;

UPDATE price_history
   SET legal_entity_group = CASE WHEN legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END
 WHERE legal_entity_group IS NULL OR legal_entity_group = '' OR legal_entity_group = 'BK_VM';

CREATE INDEX IF NOT EXISTS idx_price_history_le_group ON price_history (legal_entity_group);
