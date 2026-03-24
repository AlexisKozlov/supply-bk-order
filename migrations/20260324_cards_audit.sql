-- Добавляем поля для отслеживания кто создал/изменил карточку
ALTER TABLE `cards`
  ADD COLUMN `created_by` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL;
