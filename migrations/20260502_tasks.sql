-- ============================================================
-- Модуль «Задачи» — личные канбан-доски сотрудников закупок
-- Префикс таблиц: tasks_*
-- Доступ: владелец доски + admin/manager (просмотр и комментарии)
-- ============================================================

-- ─── Доски (по одной на сотрудника, можно несколько) ───
CREATE TABLE IF NOT EXISTS tasks_boards (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_name      VARCHAR(100) NOT NULL COMMENT 'FK → users.name',
  title           VARCHAR(150) NOT NULL,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  is_archived     TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tb_owner (owner_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Колонки доски (настраиваемые) ───
CREATE TABLE IF NOT EXISTS tasks_columns (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id        INT UNSIGNED NOT NULL,
  title           VARCHAR(100) NOT NULL,
  color           VARCHAR(20) DEFAULT NULL COMMENT 'HEX-цвет полоски колонки',
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  wip_limit       SMALLINT NULL COMMENT 'Макс. карточек в колонке (NULL = без ограничения)',
  is_done_column  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = колонка «Готово» (повтор задачи закрывает её сюда)',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tc_board (board_id, sort_order),
  CONSTRAINT fk_tc_board FOREIGN KEY (board_id) REFERENCES tasks_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Карточки задач ───
CREATE TABLE IF NOT EXISTS tasks_cards (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id        INT UNSIGNED NOT NULL,
  column_id       INT UNSIGNED NOT NULL,
  title           VARCHAR(255) NOT NULL,
  description     TEXT NULL,
  priority        ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  due_date        DATETIME NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  is_done         TINYINT(1) NOT NULL DEFAULT 0,
  created_by      VARCHAR(100) NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  INDEX idx_card_board (board_id),
  INDEX idx_card_column (column_id, sort_order),
  INDEX idx_card_due (due_date),
  CONSTRAINT fk_card_board FOREIGN KEY (board_id) REFERENCES tasks_boards(id) ON DELETE CASCADE,
  CONSTRAINT fk_card_column FOREIGN KEY (column_id) REFERENCES tasks_columns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Метки (теги, цветные) ───
CREATE TABLE IF NOT EXISTS tasks_labels (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id        INT UNSIGNED NOT NULL,
  title           VARCHAR(80) NOT NULL,
  color           VARCHAR(20) NOT NULL DEFAULT '#9E9E9E',
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  INDEX idx_label_board (board_id),
  CONSTRAINT fk_label_board FOREIGN KEY (board_id) REFERENCES tasks_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks_card_labels (
  card_id         INT UNSIGNED NOT NULL,
  label_id        INT UNSIGNED NOT NULL,
  PRIMARY KEY (card_id, label_id),
  CONSTRAINT fk_cl_card  FOREIGN KEY (card_id)  REFERENCES tasks_cards(id)  ON DELETE CASCADE,
  CONSTRAINT fk_cl_label FOREIGN KEY (label_id) REFERENCES tasks_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Чек-листы (подзадачи внутри карточки) ───
CREATE TABLE IF NOT EXISTS tasks_checklist (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id         INT UNSIGNED NOT NULL,
  title           VARCHAR(255) NOT NULL,
  is_done         TINYINT(1) NOT NULL DEFAULT 0,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chk_card (card_id, sort_order),
  CONSTRAINT fk_chk_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Соисполнители (кроме владельца доски) ───
CREATE TABLE IF NOT EXISTS tasks_assignees (
  card_id         INT UNSIGNED NOT NULL,
  user_name       VARCHAR(100) NOT NULL,
  added_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (card_id, user_name),
  INDEX idx_ta_user (user_name),
  CONSTRAINT fk_ta_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Вложения ───
CREATE TABLE IF NOT EXISTS tasks_attachments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id         INT UNSIGNED NOT NULL,
  file_name       VARCHAR(255) NOT NULL,
  file_path       VARCHAR(500) NOT NULL COMMENT 'Путь относительно api/uploads/',
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  mime_type       VARCHAR(100) NULL,
  uploaded_by     VARCHAR(100) NOT NULL,
  uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_att_card (card_id),
  CONSTRAINT fk_att_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Комментарии ───
CREATE TABLE IF NOT EXISTS tasks_comments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id         INT UNSIGNED NOT NULL,
  author_name     VARCHAR(100) NOT NULL,
  body            TEXT NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  edited_at       TIMESTAMP NULL,
  INDEX idx_cmt_card (card_id, created_at),
  CONSTRAINT fk_cmt_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── История изменений карточки ───
CREATE TABLE IF NOT EXISTS tasks_history (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id         INT UNSIGNED NOT NULL,
  user_name       VARCHAR(100) NOT NULL,
  action          VARCHAR(50) NOT NULL COMMENT 'created/moved/renamed/priority/due_date/labels/checklist/comment/...',
  details         TEXT NULL COMMENT 'JSON: {from, to, ...}',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hist_card (card_id, created_at),
  CONSTRAINT fk_hist_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Связь карточки с сущностью портала ───
-- entity_type: order | supplier | product | pricing | plan
CREATE TABLE IF NOT EXISTS tasks_relations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id         INT UNSIGNED NOT NULL,
  entity_type     VARCHAR(40) NOT NULL,
  entity_id       VARCHAR(64) NOT NULL COMMENT 'INT для orders/plans, UUID для suppliers/products/pricing',
  entity_label    VARCHAR(255) NULL COMMENT 'Подсказка для UI (имя поставщика/товара)',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rel (card_id, entity_type, entity_id),
  INDEX idx_rel_entity (entity_type, entity_id),
  CONSTRAINT fk_rel_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Правила повторяющихся задач ───
-- При наступлении next_run крон создаёт новую карточку из template_card_id и сдвигает next_run
CREATE TABLE IF NOT EXISTS tasks_recurrence (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_card_id INT UNSIGNED NOT NULL COMMENT 'Карточка-шаблон',
  frequency       ENUM('daily','weekly','monthly') NOT NULL,
  interval_value  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Каждые N дней/недель/месяцев',
  next_run        DATE NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rec_run (is_active, next_run),
  CONSTRAINT fk_rec_card FOREIGN KEY (template_card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
