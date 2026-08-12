-- Последняя активность сессии портала.
--
-- Раньше про сессию было известно только «когда вошёл» и «когда истечёт»,
-- поэтому в админке нельзя было отличить живое устройство от забытой вкладки.
-- Колонку двигает getSessionUser() не чаще раза в минуту (helpers.php),
-- чтобы не писать в таблицу на каждый запрос.

ALTER TABLE user_sessions
  ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER created_at;

-- У существующих сессий активность неизвестна — считаем её равной входу.
UPDATE user_sessions SET last_seen_at = created_at WHERE last_seen_at IS NULL;

CREATE INDEX idx_user_sessions_last_seen ON user_sessions (last_seen_at);
