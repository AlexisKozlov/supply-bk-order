-- Индивидуальная отметка «моя часть готова» у каждого соисполнителя.

ALTER TABLE tasks_assignees
  ADD COLUMN is_done TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN done_at DATETIME NULL,
  ADD INDEX idx_ta_done (card_id, is_done);

-- Backfill: для уже закрытых карточек все соисполнители считаются «готовыми».
UPDATE tasks_assignees ta
JOIN tasks_cards c ON c.id = ta.card_id
SET ta.is_done = 1, ta.done_at = COALESCE(c.completed_at, NOW())
WHERE c.is_done = 1;
