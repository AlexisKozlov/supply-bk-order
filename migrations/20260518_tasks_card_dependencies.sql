-- Модуль «Задачи»: зависимости между карточками (блокирует / заблокирована)
--
-- Одна строка = одна связь «blocker блокирует blocked»:
--   blocker_card_id должна быть выполнена раньше blocked_card_id.
-- Направления в интерфейсе выводятся из этой таблицы:
--   «X блокирует»     — строки, где blocker_card_id = X;
--   «X заблокирована» — строки, где blocked_card_id = X.
-- Карточка считается фактически заблокированной, если хотя бы один её
-- blocker ещё не выполнен (is_done = 0 и не в архиве).
--
-- Циклы не допускаются — проверка делается в коде (api/includes/tasks.php)
-- перед вставкой новой зависимости.
--
-- Безопасная миграция: только CREATE TABLE IF NOT EXISTS, существующие
-- данные не затрагиваются.

CREATE TABLE IF NOT EXISTS tasks_card_dependencies (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  blocker_card_id  INT UNSIGNED NOT NULL COMMENT 'Карточка, которая должна быть выполнена первой',
  blocked_card_id  INT UNSIGNED NOT NULL COMMENT 'Карточка, которая ждёт',
  created_by       VARCHAR(100) NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tcd_pair (blocker_card_id, blocked_card_id),
  INDEX idx_tcd_blocker (blocker_card_id),
  INDEX idx_tcd_blocked (blocked_card_id),
  CONSTRAINT fk_tcd_blocker FOREIGN KEY (blocker_card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_tcd_blocked FOREIGN KEY (blocked_card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
