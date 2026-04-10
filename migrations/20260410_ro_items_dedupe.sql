-- ═══════════════════════════════════════════════════════════
-- Заказы ресторанов: защита от дубликатов позиций.
-- Раньше в ro_order_items мог попасть один и тот же SKU
-- несколькими строками (фронт присылал их раздельно), из-за
-- чего экспорт в Excel показывал задвоенные позиции.
-- ═══════════════════════════════════════════════════════════

-- 1) Слияние уже существующих дубликатов: оставляем строку с
-- минимальным id, обновив её quantity до суммы по группе.
UPDATE ro_order_items oi
JOIN (
    SELECT MIN(id) AS keep_id, order_id, sku, SUM(quantity) AS total_qty
    FROM ro_order_items
    GROUP BY order_id, sku
    HAVING COUNT(*) > 1
) d ON d.keep_id = oi.id
SET oi.quantity = d.total_qty;

DELETE oi FROM ro_order_items oi
JOIN (
    SELECT MIN(id) AS keep_id, order_id, sku
    FROM ro_order_items
    GROUP BY order_id, sku
    HAVING COUNT(*) > 1
) d ON d.order_id = oi.order_id AND d.sku = oi.sku AND oi.id <> d.keep_id;

-- 2) Уникальные ключи — гарантия, что БД больше не пустит дубли
-- ни в одной из таблиц позиций заказа.
ALTER TABLE ro_order_items
  ADD UNIQUE KEY uk_ro_items_order_sku (order_id, sku);
ALTER TABLE so_order_items
  ADD UNIQUE KEY uk_so_items_order_sku (order_id, sku);
ALTER TABLE order_items
  ADD UNIQUE KEY uk_order_items_order_sku (order_id, sku);
