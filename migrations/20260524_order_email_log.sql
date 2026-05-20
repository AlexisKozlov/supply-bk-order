-- Аудит отправок заявки поставщику по email с портала.
--
-- Записывается при каждой попытке отправки через SMTP-инфраструктуру
-- (новая кнопка «Email с портала» в /order). Дублирующая отправка через
-- локальный почтовый клиент (mailto:) тут не фиксируется — её следов нет.
--
-- Хранение: 90 дней — достаточно для разборов «кому отправляли заявку».

CREATE TABLE IF NOT EXISTS `order_email_log` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_user_id`   VARCHAR(36)  NULL DEFAULT NULL,
  `sender_user_name` VARCHAR(120) NULL DEFAULT NULL,
  `recipients`       TEXT         NOT NULL COMMENT 'email-адреса через запятую',
  `subject`          VARCHAR(255) NOT NULL,
  `supplier`         VARCHAR(255) NULL DEFAULT NULL,
  `legal_entity`     VARCHAR(120) NULL DEFAULT NULL,
  `delivery_date`    DATE         NULL DEFAULT NULL,
  `items_count`      SMALLINT     NULL DEFAULT NULL,
  `success`          TINYINT(1)   NOT NULL DEFAULT 0,
  `error_message`    VARCHAR(500) NULL DEFAULT NULL,
  `ip_address`       VARCHAR(45)  NULL DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_oel_user_created` (`sender_user_id`, `created_at`),
  KEY `idx_oel_supplier_created` (`supplier`, `created_at`),
  KEY `idx_oel_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Аудит отправки заявок поставщикам через портал по email';
