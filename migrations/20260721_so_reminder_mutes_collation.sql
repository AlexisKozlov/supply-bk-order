-- Приводим collation supplier_id к suppliers.id (utf8mb4_unicode_ci), иначе
-- сравнение m.supplier_id = ss.supplier_id в подзапросах ломается с ошибкой
-- «Illegal mix of collations».

ALTER TABLE `so_reminder_mutes`
  MODIFY `supplier_id` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
