-- Поддержка корректировок из кабинета ресторана (паритет с Telegram-ботом).
--
-- Что меняется в order_corrections:
--   1. restaurant_chat_id — становится NULL-able. Для отправок из кабинета
--      Telegram-чата нет; данные о подавшем хранятся в submitter_name.
--   2. submitter_source — новая колонка-индикатор откуда пришла корректировка
--      ('telegram' для старых и бот-записей, 'cabinet' для веб-отправок).
--   3. submitter_comment — общее поле «причина» от ресторана, одно на батч
--      (для всех строк одной отправки одинаковое значение).
--   4. batch_uuid — общий ключ строк одной отправки. Нужен, чтобы кабинет мог:
--      (а) сгруппировать позиции одной корректировки для отображения,
--      (б) редактировать/отменять батч пока ВСЕ его позиции в pending.
--      Для старых telegram-записей остаётся NULL — там группировка делается
--      по (restaurant_number, delivery_date, restaurant_chat_id), как и раньше.
--   5. status — добавляется значение 'cancelled' (ресторан отозвал свою
--      корректировку из кабинета пока она ещё «ожидает»).
--
-- Все изменения обратно-совместимые: старый код бота и существующая логика
-- группировки по chat_id продолжают работать без правок.

ALTER TABLE order_corrections
  MODIFY COLUMN restaurant_chat_id BIGINT NULL COMMENT 'chat_id подавшего из TG; NULL = подача из кабинета';

ALTER TABLE order_corrections
  ADD COLUMN submitter_source ENUM('telegram','cabinet') NOT NULL DEFAULT 'telegram' AFTER submitter_name;

ALTER TABLE order_corrections
  ADD COLUMN submitter_comment TEXT NULL COMMENT 'Причина от ресторана, общая для всех позиций батча' AFTER unit_of_measure;

ALTER TABLE order_corrections
  ADD COLUMN batch_uuid CHAR(36) NULL COMMENT 'Общий ключ позиций одной отправки из кабинета' AFTER submitter_comment;

ALTER TABLE order_corrections
  ADD INDEX idx_corr_batch_uuid (batch_uuid);

ALTER TABLE order_corrections
  MODIFY COLUMN status ENUM('pending','in_progress','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';
