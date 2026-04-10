-- ═══════════════════════════════════════════════════════════
-- Залоговые цены: интеграция в модуль «Цены и ПСЦ»
-- Добавляем в product_prices колонку price_type, чтобы рядом
-- с закупочными ценами хранить залоговые (без отдельной таблицы).
-- ═══════════════════════════════════════════════════════════

ALTER TABLE `product_prices`
  ADD COLUMN `price_type` ENUM('purchase','deposit') NOT NULL DEFAULT 'purchase'
  AFTER `unit_type`;

-- Обновляем уникальный ключ: теперь разрешается иметь и закупочную,
-- и залоговую цену для одной пары sku+поставщик+юрлицо.
ALTER TABLE `product_prices` DROP INDEX `uk_pp_sku_supplier_le`;
ALTER TABLE `product_prices`
  ADD UNIQUE KEY `uk_pp_sku_supplier_le_type` (`sku`,`supplier`,`legal_entity`,`price_type`);

-- Приводим коллацию к единой (utf8mb4_unicode_ci) — иначе при JOIN/сравнении
-- с ro_order_items / products падает ошибка «Illegal mix of collations».
ALTER TABLE `product_prices` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `price_history` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
