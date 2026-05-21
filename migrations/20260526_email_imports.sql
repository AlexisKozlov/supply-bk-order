-- Импорт данных через email.
--
-- Идея: бухгалтер/закупщик/1С шлёт письмо со Excel-выгрузкой на ящик
-- import@supply-department.online. Cron раз в N минут забирает письма по
-- IMAP, скачивает вложения, регистрирует запись в email_imports.
--
-- Дальше — РУЧНОЕ подтверждение: закупщик в админке видит очередь, жмёт
-- «Открыть в импорте» — попадает на /import с уже подставленным файлом,
-- проходит обычный импорт. Это намеренно: один кривой Excel не должен
-- автоматически уронить production-данные.
--
-- В таблице senders — белый список отправителей. Если адрес не в списке —
-- письмо игнорируется (записывается со status='rejected', причина —
-- 'sender_not_whitelisted'). Это основная защита от мусора и подмены.

CREATE TABLE IF NOT EXISTS `email_imports` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id`      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Message-Id из заголовка письма — защита от повторной обработки',
  `from_email`      VARCHAR(255) NOT NULL,
  `from_name`       VARCHAR(255) NULL DEFAULT NULL,
  `subject`         VARCHAR(500) NULL DEFAULT NULL,
  `received_at`     DATETIME     NOT NULL,
  `type`            VARCHAR(40)  NOT NULL DEFAULT 'unknown' COMMENT 'restaurant_sales / stock_1c / analysis / unknown',
  `legal_entity`    VARCHAR(120) NULL DEFAULT NULL COMMENT 'предзаполнение для /import; берётся из правила whitelist',
  `file_name`       VARCHAR(255) NULL DEFAULT NULL,
  `file_path`       VARCHAR(500) NULL DEFAULT NULL COMMENT 'относительный путь от api/, например uploads/email_imports/12.xlsx',
  `size_bytes`      INT UNSIGNED NULL DEFAULT NULL,
  `status`          ENUM('pending','applied','dismissed','rejected','error')
                    NOT NULL DEFAULT 'pending',
  `applied_by`      VARCHAR(120) NULL DEFAULT NULL,
  `applied_at`      DATETIME     NULL DEFAULT NULL,
  `applied_count`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'сколько строк прошло в применении',
  `notes`           VARCHAR(500) NULL DEFAULT NULL COMMENT 'rejection-причина или комментарий админа',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_message_id` (`message_id`),
  KEY `idx_status_received` (`status`, `received_at`),
  KEY `idx_type_received` (`type`, `received_at`),
  KEY `idx_from_email` (`from_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Очередь импорта данных по email';

CREATE TABLE IF NOT EXISTS `email_import_senders` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`          VARCHAR(255) NOT NULL,
  `type`           VARCHAR(40)  NOT NULL DEFAULT 'restaurant_sales' COMMENT 'restaurant_sales / stock_1c / analysis',
  `legal_entity`   VARCHAR(120) NULL DEFAULT NULL COMMENT 'юрлицо по умолчанию для писем с этого адреса',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `note`           VARCHAR(255) NULL DEFAULT NULL,
  `created_by`     VARCHAR(120) NULL DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Белый список отправителей email-импорта';
