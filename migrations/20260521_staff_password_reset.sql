-- Восстановление пароля сотрудников по email.
--
-- Отдельный flow от ресторанного сброса (там 6-значный код по Telegram).
-- Здесь — длинная криптостойкая ссылка по email, действует 30 минут, одноразово.
--
-- Используется в api/includes/rpc.php методами:
--   request_staff_password_reset — генерирует токен, отправляет письмо
--   verify_staff_reset_token     — проверяет токен (для отображения формы)
--   reset_staff_password         — устанавливает новый пароль

CREATE TABLE IF NOT EXISTS `staff_password_reset_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    VARCHAR(36)  NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `token`      CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_spr_token` (`token`),
  KEY `idx_spr_user_created` (`user_id`, `created_at`),
  KEY `idx_spr_email_created` (`email`, `created_at`),
  KEY `idx_spr_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Токены сброса пароля сотрудников (по email)';

CREATE TABLE IF NOT EXISTS `staff_password_reset_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(255) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45)  NULL DEFAULT NULL,
  `result`     ENUM('sent','not_found','rate_limited','no_email','token_ok','token_invalid','token_expired','token_used','reset_ok') NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sprl_ip_created` (`ip_address`, `created_at`),
  KEY `idx_sprl_email_created` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Лог попыток сброса пароля сотрудников (троттлинг + аудит)';
