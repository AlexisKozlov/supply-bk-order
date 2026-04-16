-- ═══════════════════════════════════════════════════════════════
-- Модуль «Опросы» для ресторанов через Telegram-бот
-- ═══════════════════════════════════════════════════════════════

-- Опросы
CREATE TABLE IF NOT EXISTS surveys (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(255) NOT NULL,
  description     TEXT,
  legal_entity_group VARCHAR(16) NOT NULL DEFAULT 'BK_VM' COMMENT 'BK_VM или PS',
  status          ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  allow_comment   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 — комментарий разрешён',
  remind_after_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Напомнить через N часов',
  sent_at         DATETIME DEFAULT NULL COMMENT 'Когда была рассылка',
  created_by      VARCHAR(100) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at       DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вопросы опроса
CREATE TABLE IF NOT EXISTS survey_questions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id   INT UNSIGNED NOT NULL,
  text        VARCHAR(500) NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Варианты ответов
CREATE TABLE IF NOT EXISTS survey_options (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  text        VARCHAR(255) NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ответы ресторанов (один на опрос)
CREATE TABLE IF NOT EXISTS survey_responses (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id           INT UNSIGNED NOT NULL,
  restaurant_number   INT UNSIGNED NOT NULL,
  legal_entity_group  VARCHAR(16) NOT NULL,
  telegram_chat_id    BIGINT DEFAULT NULL,
  comment             TEXT DEFAULT NULL,
  submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resp (survey_id, restaurant_number),
  FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ответы на конкретные вопросы
CREATE TABLE IF NOT EXISTS survey_answers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  option_id   INT UNSIGNED NOT NULL,
  FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE,
  FOREIGN KEY (option_id)   REFERENCES survey_options(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог напоминаний (чтобы не слать дважды)
CREATE TABLE IF NOT EXISTS survey_reminder_log (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id         INT UNSIGNED NOT NULL,
  restaurant_number INT UNSIGNED NOT NULL,
  sent_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rem (survey_id, restaurant_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
