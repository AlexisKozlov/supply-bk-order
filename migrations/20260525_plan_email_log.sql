-- Аудит отправок плана поставок поставщику по email с портала.
--
-- Записывается при каждой попытке отправки через SMTP-инфраструктуру
-- (кнопка «Email с портала» в /planning). Аналог order_email_log,
-- но для прогнозного плана: вместо delivery_date — текстовый список
-- периодов (например «2026-06, 2026-07, 2026-08»).
--
-- Хранение: 90 дней — достаточно для разборов «кому отправляли план».

CREATE TABLE IF NOT EXISTS `plan_email_log` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_user_id`   VARCHAR(36)  NULL DEFAULT NULL,
  `sender_user_name` VARCHAR(120) NULL DEFAULT NULL,
  `recipients`       TEXT         NOT NULL COMMENT 'email-адреса через запятую',
  `cc_recipients`    TEXT         NULL DEFAULT NULL,
  `subject`          VARCHAR(255) NOT NULL,
  `supplier`         VARCHAR(255) NULL DEFAULT NULL,
  `legal_entity`     VARCHAR(120) NULL DEFAULT NULL,
  `period_labels`    VARCHAR(500) NULL DEFAULT NULL COMMENT 'список периодов через запятую',
  `items_count`      SMALLINT     NULL DEFAULT NULL,
  `success`          TINYINT(1)   NOT NULL DEFAULT 0,
  `error_message`    VARCHAR(500) NULL DEFAULT NULL,
  `ip_address`       VARCHAR(45)  NULL DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pel_user_created` (`sender_user_id`, `created_at`),
  KEY `idx_pel_supplier_created` (`supplier`, `created_at`),
  KEY `idx_pel_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Аудит отправки планов поставок поставщикам по email';
