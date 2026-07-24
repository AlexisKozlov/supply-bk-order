-- Глобальные настройки портала (ключ-значение). Одно значение на весь портал.
-- Первый сценарий — контакт поддержки в Telegram, чтобы не хардкодить его в коде.

CREATE TABLE IF NOT EXISTS `app_settings` (
  `skey`       VARCHAR(100) NOT NULL,
  `svalue`     TEXT DEFAULT NULL,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Начальное значение = текущий захардкоженный контакт, чтобы на глаз ничего не изменилось.
INSERT INTO `app_settings` (`skey`, `svalue`) VALUES ('support_telegram', 'alexiskozlov')
ON DUPLICATE KEY UPDATE `svalue` = `svalue`;
