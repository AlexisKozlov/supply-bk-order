-- Модуль «Передача дел» (отпуск, больничный, увольнение).
-- Документ собирается порталом: приходы и позиции заказов за период
-- подтягиваются автоматически, комментарии и зоны ответственности —
-- вручную. После сборки данные хранятся снимком, чтобы документ не
-- «поплыл», когда заказы изменятся.

CREATE TABLE IF NOT EXISTS handover_docs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(255) NOT NULL,
  author_id       CHAR(36) NULL,
  -- Логин (users.name) автора: по нему проверяется право на правку.
  -- author_name отображается в документе и может быть изменён вручную.
  author_login    VARCHAR(255) NOT NULL DEFAULT '',
  author_name     VARCHAR(255) NOT NULL DEFAULT '',
  author_role     VARCHAR(255) NOT NULL DEFAULT '',
  date_from       DATE NOT NULL,
  date_to         DATE NOT NULL,
  return_date     DATE NULL,
  emergency_note  TEXT NULL,
  status          ENUM('draft','final') NOT NULL DEFAULT 'draft',
  legal_entities  TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_author (author_id),
  KEY idx_period (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кому передаём: строка «ответственный — зона — контакт».
CREATE TABLE IF NOT EXISTS handover_people (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  doc_id      INT NOT NULL,
  user_id     CHAR(36) NULL,
  name        VARCHAR(255) NOT NULL DEFAULT '',
  zone        VARCHAR(500) NOT NULL DEFAULT '',
  scope       VARCHAR(500) NOT NULL DEFAULT '',
  contact     VARCHAR(255) NOT NULL DEFAULT '',
  sort_order  INT NOT NULL DEFAULT 0,
  KEY idx_doc (doc_id),
  CONSTRAINT fk_ho_people_doc FOREIGN KEY (doc_id) REFERENCES handover_docs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Поставщик в документе: снимок заявок + кто ведёт + ручные примечания.
CREATE TABLE IF NOT EXISTS handover_suppliers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  doc_id         INT NOT NULL,
  supplier_name  VARCHAR(255) NOT NULL,
  person_id      INT NULL,
  contacts       TEXT NULL,
  correction_rule VARCHAR(500) NOT NULL DEFAULT '',
  docs_rule      VARCHAR(500) NOT NULL DEFAULT '',
  attention      TEXT NULL,
  orders_json    LONGTEXT NULL,
  included       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order     INT NOT NULL DEFAULT 0,
  KEY idx_doc (doc_id),
  CONSTRAINT fk_ho_sup_doc FOREIGN KEY (doc_id) REFERENCES handover_docs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Универсальные строки прочих разделов. kind:
--   weekly   — регулярные дела по дням недели
--   topic    — отдельные темы (овощи, новинки, замены)
--   payment  — оплаты, документы, растаможка
--   control  — незакрытые вопросы на контроле
--   escalate — к кому идти с вопросами
--   file     — вложения к документу
CREATE TABLE IF NOT EXISTS handover_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  doc_id      INT NOT NULL,
  kind        VARCHAR(20) NOT NULL,
  c1          VARCHAR(500) NOT NULL DEFAULT '',
  c2          VARCHAR(500) NOT NULL DEFAULT '',
  c3          VARCHAR(500) NOT NULL DEFAULT '',
  c4          VARCHAR(500) NOT NULL DEFAULT '',
  c5          VARCHAR(500) NOT NULL DEFAULT '',
  done        TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  KEY idx_doc_kind (doc_id, kind),
  CONSTRAINT fk_ho_items_doc FOREIGN KEY (doc_id) REFERENCES handover_docs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
