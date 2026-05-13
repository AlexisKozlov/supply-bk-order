-- Повторяющиеся задачи: шаблоны карточек + расписания
-- Этап 6 модуля «Задачи».
--
-- Структура (после CEO + Eng ревью дизайн-дока):
--   tasks_card_templates              — тело шаблона (title/desc/priority)
--   tasks_template_assignees          — общие исполнители (не зависят от доски)
--   tasks_template_checklist          — общий чек-лист
--   tasks_template_schedules          — расписания (1-N на шаблон):
--                                       доска, колонка, правило, lead, offset,
--                                       next_run_date, is_active
--   tasks_template_schedule_labels    — метки per-расписание
--                                       (label.board_id == schedule.target_board_id)
--
-- Безопасная миграция: только новые таблицы, существующие данные не трогаются.
-- Каскады FK: удалили шаблон → удалились все его дочерние записи; удалили
-- доску или колонку → удалились расписания на них; удалили метку доски →
-- удалилась её связь со шаблоном-расписанием.

CREATE TABLE IF NOT EXISTS tasks_card_templates (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_name    VARCHAR(100) NOT NULL COMMENT 'FK → users.name (личные шаблоны)',
  title         VARCHAR(255) NOT NULL,
  description   TEXT NULL,
  priority      ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  is_archived   TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tct_owner (owner_name, is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks_template_assignees (
  template_id   INT UNSIGNED NOT NULL,
  user_name     VARCHAR(100) NOT NULL,
  PRIMARY KEY (template_id, user_name),
  INDEX idx_tta_user (user_name),
  CONSTRAINT fk_tta_tpl FOREIGN KEY (template_id) REFERENCES tasks_card_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks_template_checklist (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id   INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  INDEX idx_ttc_tpl (template_id, sort_order),
  CONSTRAINT fk_ttc_tpl FOREIGN KEY (template_id) REFERENCES tasks_card_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks_template_schedules (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id         INT UNSIGNED NOT NULL,
  target_board_id     INT UNSIGNED NOT NULL,
  target_column_id    INT UNSIGNED NOT NULL,
  recurrence_kind     ENUM('daily','weekly','monthly') NOT NULL,
  weekday             TINYINT NULL          COMMENT '1..7 (Пн..Вс), только для weekly',
  day_of_month        TINYINT NULL          COMMENT '1..31, только для monthly; >длины месяца => последний день',
  lead_days           SMALLINT NOT NULL DEFAULT 0  COMMENT 'За сколько дней до due_date создавать карточку',
  due_offset_days     SMALLINT NOT NULL DEFAULT 0  COMMENT 'Срок карточки = момент создания + offset',
  last_run_date       DATE NULL,
  next_run_date       DATE NOT NULL          COMMENT 'Считается при сохранении; cron берёт WHERE next_run_date <= CURDATE()',
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  deactivated_reason  VARCHAR(100) NULL      COMMENT 'no_access | board_archived | manual',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tts_cron (is_active, next_run_date),
  INDEX idx_tts_tpl (template_id),
  CONSTRAINT fk_tts_tpl   FOREIGN KEY (template_id)      REFERENCES tasks_card_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_tts_board FOREIGN KEY (target_board_id)  REFERENCES tasks_boards(id)         ON DELETE CASCADE,
  CONSTRAINT fk_tts_col   FOREIGN KEY (target_column_id) REFERENCES tasks_columns(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks_template_schedule_labels (
  schedule_id   INT UNSIGNED NOT NULL,
  label_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (schedule_id, label_id),
  INDEX idx_ttsl_label (label_id),
  CONSTRAINT fk_ttsl_sched FOREIGN KEY (schedule_id) REFERENCES tasks_template_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_ttsl_label FOREIGN KEY (label_id)    REFERENCES tasks_labels(id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
