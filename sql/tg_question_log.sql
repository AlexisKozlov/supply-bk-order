CREATE TABLE IF NOT EXISTS `tg_question_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_name` VARCHAR(255) NOT NULL,
  `question` TEXT NOT NULL,
  `legal_entity` VARCHAR(255) DEFAULT NULL,
  `asked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_asked` (`asked_at`),
  INDEX `idx_user` (`user_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
