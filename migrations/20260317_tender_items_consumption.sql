-- Добавить колонку расхода в позиции тендера
ALTER TABLE `tender_items`
  ADD COLUMN `monthly_consumption` DECIMAL(12,1) DEFAULT NULL COMMENT 'Расход в месяц (ручной или из analysis_data)' AFTER `unit`;
