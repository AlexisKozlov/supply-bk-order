-- Собственное производство: партии теста.
--
-- Сырое тесто перед отправкой в ресторан должно созреть, поэтому изготавливают
-- его не в день поставки. У ресторана с одной поставкой в неделю объём делят на
-- две партии разного дня изготовления: часть делают, например, в пятницу, часть
-- во вторник — иначе к концу недели тесто перестоит.
--
-- 1) op_production_schedule — какой день поставки в какой день изготавливают.
--    На один день поставки может быть одна или две строки (партии).
--    production_dow — день недели изготовления; сама дата считается как
--    ближайший такой день ДО даты поставки (тот же день недели = неделя назад).
--
-- 2) so_order_items.batch_no — к какой партии относится позиция заявки.
--    У всех остальных поставщиков и старых заявок остаётся 1.

CREATE TABLE IF NOT EXISTS op_production_schedule (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id    CHAR(36)     NOT NULL,
  delivery_dow   TINYINT      NOT NULL COMMENT '1=пн … 7=вс, день поставки',
  batch_no       TINYINT      NOT NULL DEFAULT 1 COMMENT '1 или 2',
  production_dow TINYINT      NOT NULL COMMENT '1=пн … 7=вс, день изготовления',
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_op_sched (supplier_id, delivery_dow, batch_no),
  KEY idx_op_sched_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE so_order_items
  ADD COLUMN batch_no TINYINT NOT NULL DEFAULT 1 AFTER quantity;

-- Уникальность позиций заявки — с учётом партии.
--
-- Раньше ключ был (order_id, sku): один товар — одна строка. Для теста этого
-- мало: один и тот же размер приезжает одной поставкой, но изготовлен в разные
-- дни, значит в заявке две строки — партия 1 и партия 2. Защита от дублей
-- остаётся, просто теперь внутри партии.
ALTER TABLE so_order_items
  DROP INDEX uk_so_items_order_sku,
  ADD UNIQUE KEY uk_so_items_order_sku_batch (order_id, sku, batch_no);
