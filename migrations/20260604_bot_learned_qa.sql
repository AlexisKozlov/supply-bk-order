-- Накопленная база знаний бота из рабочих групп.
-- Когда сотрудник отдела закупок отвечает в группе на чей-то вопрос (reply),
-- бот запоминает пару «вопрос → ответ» и переиспользует её в похожих вопросах.
-- Доверяем только ответам сотрудников (определяются по users.telegram_chat_id).
-- Безопасно: новая таблица, ни на что существующее не влияет.
CREATE TABLE IF NOT EXISTS bot_learned_qa (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id    BIGINT NOT NULL                COMMENT 'chat_id группы Telegram (отрицательный)',
  question    TEXT NOT NULL                  COMMENT 'Текст исходного сообщения, на которое ответил сотрудник',
  answer      TEXT NOT NULL                  COMMENT 'Ответ сотрудника',
  author_name VARCHAR(255) DEFAULT NULL      COMMENT 'Имя сотрудника (для аудита)',
  author_role VARCHAR(50)  DEFAULT NULL      COMMENT 'Роль сотрудника на момент ответа',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group (group_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
