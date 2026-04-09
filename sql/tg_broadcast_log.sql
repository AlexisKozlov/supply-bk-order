CREATE TABLE IF NOT EXISTS `tg_broadcast_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sender` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `recipient_count` INT NOT NULL DEFAULT 0,
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
