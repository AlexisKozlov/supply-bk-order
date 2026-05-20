-- Email учётной записи ресторана + подтверждение по ссылке.
--
-- Зачем: восстановление пароля ресторана через email (вторая опция помимо
-- Telegram). Логин ресторана по-прежнему по номеру + паролю, email — только
-- для уведомлений и сброса пароля.
--
-- email_verified_at = NULL означает, что адрес введён, но ещё не подтверждён.
-- Сброс пароля по email будет работать только для подтверждённых адресов.

ALTER TABLE ro_users
  ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER legal_entity,
  ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER email,
  ADD INDEX idx_ro_users_email (email);

CREATE TABLE IF NOT EXISTS `ro_email_verification_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `token`      CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_revt_token` (`token`),
  KEY `idx_revt_user` (`user_id`),
  KEY `idx_revt_email_created` (`email`, `created_at`),
  KEY `idx_revt_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Токены подтверждения email ресторанов (TTL 24 часа)';
