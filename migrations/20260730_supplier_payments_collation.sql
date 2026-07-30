-- Напоминания об оплатах падали на каждом запуске крона:
--   payment reminder error: Illegal mix of collations
--     (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='
-- Причина: таблица supplier_payments создана с utf8mb4_general_ci, а orders.id —
-- utf8mb4_unicode_ci, поэтому JOIN orders o ON o.id = sp.order_id в
-- cron_telegram.php не выполнялся вообще.
--
-- Это единственный JOIN с supplier_payments в проекте (остальные обращения —
-- одиночная таблица с bind-параметрами), поэтому приводим таблицу целиком
-- к общей для базы collation utf8mb4_unicode_ci.

ALTER TABLE `supplier_payments`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `supplier_payments`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
