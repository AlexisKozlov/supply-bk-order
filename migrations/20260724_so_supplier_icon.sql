-- Персональная иконка поставщика для кабинета ресторана.
-- Ключ иконки из общего набора (см. src/lib/cabinetIcons.js: supplierIconKeys).
-- NULL / пусто = автоподбор иконки по названию поставщика (прежнее поведение).
ALTER TABLE so_supplier_settings
  ADD COLUMN icon_key VARCHAR(32) NULL DEFAULT NULL AFTER pause_message;
