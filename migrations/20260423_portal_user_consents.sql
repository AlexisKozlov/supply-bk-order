CREATE TABLE IF NOT EXISTS `portal_user_consents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` VARCHAR(32) NOT NULL COMMENT 'staff или restaurant',
  `subject_key` VARCHAR(120) NOT NULL COMMENT 'email/имя пользователя или ресторан',
  `display_name` VARCHAR(255) DEFAULT NULL,
  `consent_code` VARCHAR(64) NOT NULL,
  `consent_version` VARCHAR(32) NOT NULL,
  `accepted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_portal_user_consents_subject` (`subject_type`, `subject_key`),
  KEY `idx_portal_user_consents_code` (`consent_code`, `consent_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
