-- Миграция: Новые таблицы для расширенной админки
-- Дата: 2026-03-03

-- 1. Таблица для хранения ошибок
CREATE TABLE IF NOT EXISTS `error_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `level` ENUM('error','warning','info') NOT NULL DEFAULT 'error',
  `source` ENUM('frontend','backend') NOT NULL DEFAULT 'frontend',
  `message` TEXT NOT NULL,
  `stack` TEXT DEFAULT NULL,
  `user_name` VARCHAR(255) DEFAULT NULL,
  `url` VARCHAR(2048) DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_error_logs_level` (`level`),
  INDEX `idx_error_logs_source` (`source`),
  INDEX `idx_error_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Таблица для записей об обновлениях (changelog)
CREATE TABLE IF NOT EXISTS `changelog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(255) DEFAULT NULL,
  INDEX `idx_changelog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Добавить ip_address и user_agent в user_sessions
ALTER TABLE `user_sessions`
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(512) DEFAULT NULL AFTER `ip_address`;
