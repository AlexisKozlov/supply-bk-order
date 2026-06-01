-- Модуль «Заявка на пропуск» (ТиТ).
-- Создаются только новые таблицы, существующие схемы не трогаются.

-- ── Сами заявки ──
CREATE TABLE IF NOT EXISTS tit_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        CHAR(36)     NULL,                       -- основной заказ (NULL = ручное создание)
  supplier_id     CHAR(36)     NULL,                       -- snapshot ID поставщика
  supplier_name   VARCHAR(255) NOT NULL DEFAULT '',         -- snapshot имени для UI/xlsx
  supplier_email  VARCHAR(255) NOT NULL DEFAULT '',         -- адрес, на который шли письмо
  legal_entity        VARCHAR(100) NOT NULL DEFAULT '',
  legal_entity_group  VARCHAR(8)   NOT NULL DEFAULT 'BK_VM',
  delivery_date   DATE         NOT NULL,
  status          ENUM('WAITING','DATA_RECEIVED','READY','SENT','CANCELLED') NOT NULL DEFAULT 'WAITING',
  outgoing_message_id  VARCHAR(255) NULL,                   -- Message-Id письма поставщику (для In-Reply-To)
  request_code    VARCHAR(32)  NULL,                        -- зарезервировано: код в теме письма (на потом)
  created_by      VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tit_order      (order_id),
  KEY idx_tit_supplier   (supplier_id, delivery_date),
  KEY idx_tit_status     (status, delivery_date),
  KEY idx_tit_le_group   (legal_entity_group, delivery_date),
  KEY idx_tit_outgoing   (outgoing_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Машины в заявке (одна заявка может иметь 1+ машин, если поставщик повёз несколькими) ──
CREATE TABLE IF NOT EXISTS tit_vehicles (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id     INT UNSIGNED NOT NULL,
  plate          VARCHAR(20)  NOT NULL DEFAULT '',     -- нормализованный (AM23245)
  plate_raw      VARCHAR(255) NOT NULL DEFAULT '',     -- что прислал поставщик
  phone          VARCHAR(15)  NOT NULL DEFAULT '',     -- нормализованный (375XXXXXXXXX)
  phone_raw      VARCHAR(50)  NOT NULL DEFAULT '',
  warehouse      TINYINT      NOT NULL DEFAULT 6,      -- 1 или 6
  allow_company  TINYINT      NOT NULL DEFAULT 8,      -- 8 (склад 6) или 32 (склад 1)
  entry_kind     TINYINT      NOT NULL DEFAULT 1,      -- 1 = выгрузка, 2 = загрузка
  start_time     DATETIME     NULL,
  end_time       DATETIME     NULL,
  source         ENUM('EMAIL_TEXT','EMAIL_OCR','MANUAL','SUGGESTION') NOT NULL DEFAULT 'MANUAL',
  email_log_id   INT UNSIGNED NULL,                    -- ссылка на письмо, из которого пришли данные
  confirmed_by   VARCHAR(255) NULL,
  confirmed_at   DATETIME     NULL,
  needs_review   TINYINT(1)   NOT NULL DEFAULT 1,      -- 1 = пока не подтверждено закупкой
  deleted_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_titv_request (request_id, deleted_at),
  KEY idx_titv_email   (email_log_id),
  CONSTRAINT fk_titv_request FOREIGN KEY (request_id) REFERENCES tit_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Все входящие письма по заявкам (история, защита от дубликатов) ──
CREATE TABLE IF NOT EXISTS tit_email_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id      INT UNSIGNED NULL,                   -- NULL = не привязали
  message_id      VARCHAR(255) NOT NULL,
  from_email      VARCHAR(255) NOT NULL DEFAULT '',
  from_name       VARCHAR(255) NOT NULL DEFAULT '',
  subject         VARCHAR(500) NOT NULL DEFAULT '',
  received_at     DATETIME     NOT NULL,
  body_excerpt    TEXT         NULL,
  has_attachment  TINYINT(1)   NOT NULL DEFAULT 0,
  attachment_path VARCHAR(512) NULL,                   -- путь к сохранённому скану накладной
  parsed_plate    VARCHAR(20)  NULL,
  parsed_phone    VARCHAR(15)  NULL,
  parsed_via      ENUM('EMAIL_TEXT','EMAIL_OCR','BOTH','NONE') NOT NULL DEFAULT 'NONE',
  status          ENUM('MATCHED','UNMATCHED','ERROR') NOT NULL DEFAULT 'UNMATCHED',
  error_message   TEXT         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_titmail_msgid (message_id),
  KEY idx_titmail_request (request_id, received_at),
  KEY idx_titmail_status  (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Лог отправок охране ──
CREATE TABLE IF NOT EXISTS tit_send_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id      INT UNSIGNED NOT NULL,
  file_path       VARCHAR(512) NOT NULL,
  recipients      TEXT         NOT NULL,               -- JSON массив адресов
  cc_email        VARCHAR(255) NOT NULL DEFAULT '',    -- закупщик в CC
  test_mode       TINYINT(1)   NOT NULL DEFAULT 1,
  sent_by         VARCHAR(255) NULL,
  sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  smtp_message_id VARCHAR(255) NULL,
  smtp_error      TEXT         NULL,
  PRIMARY KEY (id),
  KEY idx_titsend_request (request_id, sent_at),
  CONSTRAINT fk_titsend_request FOREIGN KEY (request_id) REFERENCES tit_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Настройки модуля (получатели, тестовый режим, тестовый адрес) ──
CREATE TABLE IF NOT EXISTS tit_settings (
  setting_key   VARCHAR(64)  NOT NULL,
  setting_value TEXT         NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tit_settings (setting_key, setting_value) VALUES
  ('test_mode',    '1'),
  ('test_email',   'a_kozlov@burger-king.by'),
  ('security_recipients', JSON_ARRAY(
      'guard_w6@ttl.by',
      's_goncharov@ttl.by',
      'n_bogdanov@ttl.by',
      'guard_w10@ttl.by',
      'guard_w1@ttl.by',
      'a_martynov@ttl.by',
      'e_krutalevich@ttl.by'
  )),
  ('email_template_addition',
   'Перед отгрузкой, пожалуйста, пришлите скан накладной.\nВ ответ на это письмо укажите номер машины и телефон водителя.'),
  ('imap_poll_minutes', '5')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ── Последняя известная машина по каждому поставщику (для «подставить прошлую») ──
CREATE TABLE IF NOT EXISTS tit_supplier_defaults (
  supplier_id   CHAR(36)     NOT NULL,
  last_plate    VARCHAR(20)  NULL,
  last_phone    VARCHAR(15)  NULL,
  last_used_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
