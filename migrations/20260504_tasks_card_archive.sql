-- Архив карточек: добавляем флаг is_archived в tasks_cards
-- Архивные карточки скрываются из доски и поиска, остаются в БД (можно восстановить вручную через UPDATE).
-- Новый workflow: клик по чекбоксу слева от названия карточки → is_done=1 + is_archived=1 → карточка исчезает с доски.

ALTER TABLE tasks_cards
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
  ADD INDEX idx_card_archived (is_archived);
