-- Подзадачи: добавляем self-FK к tasks_cards
-- Карточка с parent_card_id != NULL = подзадача, не показывается на основной доске.
-- Глубина — только 1 уровень (валидация на бэке).

ALTER TABLE tasks_cards
  ADD COLUMN parent_card_id INT UNSIGNED NULL AFTER board_id,
  ADD INDEX idx_card_parent (parent_card_id),
  ADD CONSTRAINT fk_card_parent FOREIGN KEY (parent_card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE;
