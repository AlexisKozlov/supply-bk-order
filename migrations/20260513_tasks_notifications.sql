-- Уведомления модуля задач (этапы 1-3)
-- Получатель — user_name (для согласованности с tasks_assignees).
-- Хранит источник (кто инициировал) и тип события + payload (JSON).

CREATE TABLE IF NOT EXISTS tasks_notifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_name       VARCHAR(100) NOT NULL,
  source_user     VARCHAR(100) NULL,
  card_id         INT UNSIGNED NULL,
  board_id        INT UNSIGNED NULL,
  type            VARCHAR(40) NOT NULL,
  -- assigned | comment | closed | reopened | due_changed | mention
  payload         JSON NULL,
  is_read         TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  read_at         DATETIME NULL,
  INDEX idx_tn_user_unread (user_name, is_read, created_at),
  INDEX idx_tn_card (card_id),
  CONSTRAINT fk_tn_card  FOREIGN KEY (card_id)  REFERENCES tasks_cards(id)  ON DELETE CASCADE,
  CONSTRAINT fk_tn_board FOREIGN KEY (board_id) REFERENCES tasks_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
