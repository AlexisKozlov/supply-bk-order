-- Типы вопросов и свободные ответы в модуле «Опросы»

ALTER TABLE survey_questions
  ADD COLUMN type ENUM('choice','scale','text') NOT NULL DEFAULT 'choice' AFTER text;

ALTER TABLE survey_answers
  MODIFY option_id INT UNSIGNED NULL,
  ADD COLUMN numeric_value TINYINT UNSIGNED NULL AFTER option_id,
  ADD COLUMN text_value TEXT NULL AFTER numeric_value;
