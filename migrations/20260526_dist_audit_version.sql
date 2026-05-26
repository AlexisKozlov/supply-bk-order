-- Распределение: аудит изменений + оптимистичная блокировка
--
-- Зачем:
-- 1. Без created_by/updated_by нельзя понять, кто отгрузил конкретному
--    ресторану. При спорах между ресторанами и закупкой концов не найти.
-- 2. Без version два пользователя, одновременно правящие одну клетку
--    матрицы, молча затирают друг друга. После миграции бэк сверяет
--    version и возвращает 409 при конфликте.
--
-- Безопасно: только ADD COLUMN, существующие данные не трогаем.
-- Старый бэк продолжит работать (новые колонки имеют DEFAULT'ы), но не
-- будет писать created_by/updated_by — это нормально для переходного
-- момента до выкладки нового бэка.

ALTER TABLE `dist_entries`
  ADD COLUMN `created_by` VARCHAR(100) DEFAULT NULL COMMENT 'Кто впервые проставил клетку',
  ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL COMMENT 'Кто последний правил',
  ADD COLUMN `version` INT NOT NULL DEFAULT 1 COMMENT 'Счётчик ревизий для оптимистичной блокировки';

ALTER TABLE `dist_notes`
  ADD COLUMN `created_by` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `updated_by` VARCHAR(100) DEFAULT NULL;
