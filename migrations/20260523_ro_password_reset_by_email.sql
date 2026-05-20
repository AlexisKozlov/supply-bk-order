-- Сброс пароля ресторана по email (этап B).
--
-- Отдельный flow от Telegram-сброса: длинная криптостойкая ссылка по email,
-- TTL 30 минут, одноразово. Telegram-сброс остаётся как fallback (особенно
-- для ресторанов, у которых email ещё не указан или не подтверждён).
--
-- Email-сброс работает только для тех ro_users, у которых
-- email_verified_at IS NOT NULL — иначе любой может вписать чужой email
-- и угнать пароль.

CREATE TABLE IF NOT EXISTS `ro_password_reset_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `token`      CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rprt_token` (`token`),
  KEY `idx_rprt_user_created` (`user_id`, `created_at`),
  KEY `idx_rprt_email_created` (`email`, `created_at`),
  KEY `idx_rprt_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Токены сброса пароля ресторанов через email (TTL 30 минут)';

CREATE TABLE IF NOT EXISTS `ro_password_reset_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(255) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `result`     ENUM('sent','not_found','rate_limited','unverified','token_invalid','token_expired','token_used','reset_ok') NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rprl_ip_created` (`ip_address`, `created_at`),
  KEY `idx_rprl_email_created` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Лог попыток сброса пароля ресторанов по email (троттлинг + аудит)';
