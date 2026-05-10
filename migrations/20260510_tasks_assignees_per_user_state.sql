-- Поля персонального состояния назначения в tasks_assignees
-- Для фичи «карточки на доске исполнителя»: один экземпляр карточки в
-- tasks_cards, но у каждого исполнителя свой статус и порядок на его доске.
-- column_id NULL = карточка ещё не размещалась исполнителем (бэк положит
-- в первую обычную колонку). is_done и done_at уже существовали в БД —
-- их роль расширяется: «исполнитель закрыл свою часть, карточка пропадает
-- с его доски, но оригинал у автора остаётся».

ALTER TABLE tasks_assignees
  ADD COLUMN column_id  INT UNSIGNED NULL AFTER user_name,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER column_id,
  ADD INDEX idx_ta_user_done (user_name, is_done),
  ADD CONSTRAINT fk_ta_column FOREIGN KEY (column_id) REFERENCES tasks_columns(id) ON DELETE SET NULL;
