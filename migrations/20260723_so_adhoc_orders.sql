-- Внеплановые заявки (довоз): закупки вносят заявку ресторану на дату вне графика.
-- is_adhoc = 1 — пометка «довоз» (показывается вне графика, попадает в сводку поставщику).
-- adhoc_deadline — дедлайн, заданный закупками вручную: до него ресторан может править;
--   NULL = заявка финальная сразу (ресторан только видит).
ALTER TABLE so_orders
  ADD COLUMN is_adhoc TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN adhoc_deadline DATETIME NULL DEFAULT NULL AFTER is_adhoc;
