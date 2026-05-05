-- Колонка «Архив» на каждой доске
-- Добавляем флаг is_archive_column в tasks_columns, авто-создаём колонку «Архив» для всех досок,
-- переносим в неё уже архивные карточки. После миграции архивные карточки видны в этой колонке,
-- их можно перетащить обратно в работу — moveCard автоматически снимет is_archived.

ALTER TABLE tasks_columns
  ADD COLUMN is_archive_column TINYINT(1) NOT NULL DEFAULT 0,
  ADD INDEX idx_col_archive (is_archive_column);

-- Колонка «Архив» для каждой доски, у которой её ещё нет
INSERT INTO tasks_columns (board_id, title, color, sort_order, is_archive_column, is_done_column)
SELECT b.id, 'Архив', '#9E9E9E', 9999, 1, 0
FROM tasks_boards b
WHERE NOT EXISTS (
  SELECT 1 FROM tasks_columns c WHERE c.board_id = b.id AND c.is_archive_column = 1
);

-- Перенести уже существующие архивные карточки в архив-колонку соответствующей доски
UPDATE tasks_cards c
JOIN tasks_columns ac ON ac.board_id = c.board_id AND ac.is_archive_column = 1
SET c.column_id = ac.id
WHERE c.is_archived = 1;
