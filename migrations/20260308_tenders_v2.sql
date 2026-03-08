-- Добавить новые колонки в существующие таблицы тендеров

-- Тендеры: сводка/обоснование
ALTER TABLE `tenders`
  ADD COLUMN `summary` TEXT DEFAULT NULL COMMENT 'Сводка / обоснование выбора' AFTER `winner_supplier`,
  ADD COLUMN `note` TEXT DEFAULT NULL COMMENT 'Общие примечания к тендеру' AFTER `summary`;

-- Позиции: примечание
ALTER TABLE `tender_items`
  ADD COLUMN `note` TEXT DEFAULT NULL COMMENT 'Примечание к позиции' AFTER `unit`;

-- Предложения: срок поставки, условия оплаты, доп. условия, примечание
ALTER TABLE `tender_offers`
  ADD COLUMN `delivery_days` INT DEFAULT NULL COMMENT 'Срок поставки (дней)' AFTER `supplier`,
  ADD COLUMN `payment_terms` VARCHAR(255) DEFAULT NULL COMMENT 'Условия оплаты' AFTER `delivery_days`,
  ADD COLUMN `conditions` TEXT DEFAULT NULL COMMENT 'Дополнительные условия' AFTER `payment_terms`;

-- Обновить статус ENUM с новыми значениями
ALTER TABLE `tenders`
  MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'draft';
