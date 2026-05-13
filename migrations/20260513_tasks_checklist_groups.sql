-- Поддержка НЕСКОЛЬКИХ чек-листов на одну карточку (группы).
--
-- Раньше: tasks_checklist — плоский список пунктов на карточку.
-- Теперь: tasks_checklists — таблица групп (со своим названием),
-- tasks_checklist.checklist_id — FK к группе.
--
-- Бэкфилл: для каждой карточки с уже существующими пунктами создаём одну
-- группу «Чек-лист» и привязываем все её пункты к этой группе. Это
-- безопасно: на UI пункты как были, так и останутся, только теперь они
-- лежат в первой группе.

CREATE TABLE IF NOT EXISTS tasks_checklists (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id       INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL DEFAULT 'Чек-лист',
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chkl_card (card_id, sort_order),
  CONSTRAINT fk_chkl_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Колонка-ссылка у пунктов на группу
ALTER TABLE tasks_checklist
  ADD COLUMN checklist_id INT UNSIGNED NULL AFTER card_id,
  ADD INDEX idx_chk_group (checklist_id);

-- Бэкфилл: одна группа «Чек-лист» на каждую карточку с существующими пунктами
INSERT INTO tasks_checklists (card_id, title, sort_order)
SELECT DISTINCT card_id, 'Чек-лист', 0
FROM tasks_checklist
WHERE card_id NOT IN (SELECT card_id FROM tasks_checklists);

-- Привязываем все «висящие» пункты к их группе
UPDATE tasks_checklist ci
JOIN tasks_checklists cg ON cg.card_id = ci.card_id
SET ci.checklist_id = cg.id
WHERE ci.checklist_id IS NULL;

-- Теперь можно сделать FK на группу (ON DELETE CASCADE — удаление группы
-- автоматически уносит её пункты).
ALTER TABLE tasks_checklist
  ADD CONSTRAINT fk_chk_group FOREIGN KEY (checklist_id) REFERENCES tasks_checklists(id) ON DELETE CASCADE;
