-- Перенос заявок Планеты Ресторанов из veg_orders → so_orders / so_order_items
-- Запускать после 20260416_planeta_as_supplier.sql

SET @supplier_id = 'bbbbbbbb-0000-4000-a000-000000000001';

-- ─────────────────────────────────────────────
-- 1. Создаём so_orders (по уникальной паре ресторан+дата)
--    Юрлицо берём из ro_users по номеру ресторана (первый найденный аккаунт)
-- ─────────────────────────────────────────────
INSERT INTO so_orders
  (restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity)
SELECT
  CAST(vo.restaurant_number AS UNSIGNED)                           AS restaurant_number,
  @supplier_id,
  vo.delivery_date,
  DATE_SUB(vo.delivery_date, INTERVAL 1 DAY)                      AS order_date,
  'submitted'                                                      AS status,
  MIN(vo.submitted_at)                                             AS submitted_at,
  COALESCE(
    (SELECT ru.legal_entity FROM ro_users ru
     WHERE ru.restaurant_number = CAST(vo.restaurant_number AS UNSIGNED)
       AND ru.legal_entity_group = 'BK_VM'
     LIMIT 1),
    'ООО "Бургер БК"'
  )                                                                AS legal_entity
FROM veg_orders vo
GROUP BY vo.restaurant_number, vo.delivery_date
ON DUPLICATE KEY UPDATE supplier_id = supplier_id;

-- ─────────────────────────────────────────────
-- 2. Создаём so_order_items
-- ─────────────────────────────────────────────
INSERT INTO so_order_items
  (order_id, product_id, sku, product_name, quantity, admin_qty)
SELECT
  o.id,
  CONCAT('00000000-0000-4000-a000-', LPAD(vo.product_id, 12, '0')) AS product_id,
  CONCAT('PLAN-', LPAD(vo.product_id, 3, '0'))                    AS sku,
  COALESCE(vsp.product_name, CONCAT('Товар ', vo.product_id))     AS product_name,
  vo.quantity,
  vo.admin_qty
FROM veg_orders vo
JOIN so_orders o
  ON  o.supplier_id       = @supplier_id
  AND o.restaurant_number = CAST(vo.restaurant_number AS UNSIGNED)
  AND o.delivery_date     = vo.delivery_date
LEFT JOIN veg_session_products vsp ON vsp.id = vo.product_id
ON DUPLICATE KEY UPDATE so_order_items.quantity = so_order_items.quantity;
