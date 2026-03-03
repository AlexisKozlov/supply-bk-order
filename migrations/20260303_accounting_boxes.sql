-- Миграция: переход на хранение учётных коробок в order_items
-- Дата: 2026-03-03
--
-- ВАЖНО: выполнять при включённом режиме тех. работ,
-- ПОСЛЕ деплоя нового кода.
--
-- Учётная коробка = физическая × кратность.
-- Раньше qty_boxes хранил физические коробки, теперь — учётные.

-- 1. Бэкап
CREATE TABLE order_items_backup_20260303 AS SELECT * FROM order_items;

-- 2. Пересчёт qty_boxes: физические → учётные (× multiplicity)
UPDATE order_items
SET qty_boxes = qty_boxes * multiplicity
WHERE multiplicity > 1;

-- 3. Пересчёт received_qty: физические → учётные (× multiplicity)
UPDATE order_items
SET received_qty = received_qty * multiplicity
WHERE multiplicity > 1 AND received_qty IS NOT NULL;
