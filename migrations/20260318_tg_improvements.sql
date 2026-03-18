-- 1. Добавить поле answer в tg_question_log для сохранения ответов AI
ALTER TABLE tg_question_log
  ADD COLUMN `answer` TEXT DEFAULT NULL AFTER `question`;

-- 2. Таблица дедупликации cron-уведомлений (чтобы не слать одно и то же каждые 5 минут)
CREATE TABLE IF NOT EXISTS `tg_notification_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `notification_type` VARCHAR(50) NOT NULL COMMENT 'overdue_delivery, data_updates, low_stock',
  `legal_entity` VARCHAR(255) NOT NULL,
  `chat_id` BIGINT NOT NULL,
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lookup` (`notification_type`, `legal_entity`, `chat_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
