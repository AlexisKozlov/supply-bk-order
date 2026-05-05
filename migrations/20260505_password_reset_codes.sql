-- Сброс пароля кабинета ресторана через Telegram.
--
-- Бэкенд (api/includes/rpc.php, методы request_password_reset / verify_reset_code /
-- reset_password) уже использует эти таблицы, но миграции не было — таблицы
-- нужно создать, иначе функция падает с SQL-ошибкой при первом же запросе.
--
-- password_reset_codes — короткоживущие 6-значные коды и одноразовые reset_token'ы.
-- password_reset_logs  — лог попыток для троттлинга (по IP и по ресторану).

CREATE TABLE IF NOT EXISTS `password_reset_codes` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `restaurant_number` SMALLINT UNSIGNED NOT NULL,
  `code`              CHAR(6) NOT NULL,
  `reset_token`       CHAR(64) NULL DEFAULT NULL,
  `expires_at`        DATETIME NOT NULL,
  `used_at`           DATETIME NULL DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prc_restaurant_code` (`restaurant_number`, `code`),
  KEY `idx_prc_token` (`reset_token`),
  KEY `idx_prc_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_logs` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `restaurant_number` SMALLINT UNSIGNED NOT NULL,
  `ip_address`        VARCHAR(45) NULL DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prl_ip_created` (`ip_address`, `created_at`),
  KEY `idx_prl_restaurant_created` (`restaurant_number`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
