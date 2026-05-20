-- Файлы в опросах.
--
-- Зачем: рестораны должны прикладывать фото/документы к ответам (фото
-- брака, акты, накладные и т.п.). До сих пор опрос принимал только
-- варианты (choice), оценку (scale) и свободный текст (text) — без
-- вложений.
--
-- Что меняется:
--   1. В survey_questions.type добавляется новый тип 'files' — вопрос
--      «прикрепите файлы».
--   2. Новая колонка survey_questions.files_required — обязательно ли
--      хотя бы один файл. Default 1 = обязательно. В админке можно снять.
--   3. Новая таблица survey_response_files — сами файлы.
--
-- Черновики: ресторан грузит файлы ещё до того, как нажал «Отправить»
-- (естественный UX: «загрузил, увидел превью, поправил, отправил»).
-- Поэтому survey_response_files.response_id NULLable: пока ответ ещё не
-- сабмитнут, файлы висят как черновик с парой (survey_id, restaurant_number,
-- legal_entity_group, question_id). При submit-survey транзакция привязывает
-- их к новому response_id.
--
-- Старые черновики (response_id IS NULL старше 30 дней) подметает cron.

ALTER TABLE survey_questions
  MODIFY type ENUM('choice','scale','text','files') NOT NULL DEFAULT 'choice',
  ADD COLUMN files_required TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Только для type=files: 1 — нужен хотя бы один файл'
    AFTER type;

CREATE TABLE IF NOT EXISTS survey_response_files (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Привязка к опросу/вопросу/ресторану — заполняется всегда (даже у черновика).
  survey_id           INT UNSIGNED NOT NULL,
  question_id         INT UNSIGNED NOT NULL,
  restaurant_number   INT UNSIGNED NOT NULL,
  legal_entity_group  VARCHAR(16)  NOT NULL,
  -- Привязка к ответу: NULL у черновика, заполняется при submit-survey.
  response_id         INT UNSIGNED NULL,
  -- Сам файл (на диске).
  file_path           VARCHAR(500) NOT NULL,
  file_name           VARCHAR(255) NOT NULL,
  mime_type           VARCHAR(120) NOT NULL,
  file_size           INT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_srf_response (response_id),
  KEY idx_srf_draft (survey_id, restaurant_number, legal_entity_group, question_id, response_id),
  KEY idx_srf_cleanup (response_id, created_at),
  CONSTRAINT fk_srf_response FOREIGN KEY (response_id)
    REFERENCES survey_responses(id) ON DELETE CASCADE,
  CONSTRAINT fk_srf_survey FOREIGN KEY (survey_id)
    REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_srf_question FOREIGN KEY (question_id)
    REFERENCES survey_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
