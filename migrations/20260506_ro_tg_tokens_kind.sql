-- Разделение типов токенов в ro_tg_tokens.
--
-- Зачем: до этого таблица хранила вместе и длинные токены входа в кабинет
-- (генерируются ботом, 64 hex), и 6-значные коды привязки Telegram. Эндпоинт
-- /api/ro/tg-auth искал токен только по полю `token`, без учёта типа — то есть
-- 6-значный код привязки можно было подобрать перебором (1М вариантов) и зайти
-- в кабинет соответствующего ресторана.
--
-- Что делаем: вводим колонку `kind` со значениями
--   'auth' — длинный токен для входа в личный кабинет ресторана,
--   'bind' — 6-значный код привязки Telegram через бота.
-- Существующие записи размечаем по длине: < 32 символов = bind, иначе auth.

ALTER TABLE ro_tg_tokens
  ADD COLUMN IF NOT EXISTS kind VARCHAR(8) NOT NULL DEFAULT 'auth' AFTER token;

UPDATE ro_tg_tokens
SET kind = CASE WHEN CHAR_LENGTH(token) < 32 THEN 'bind' ELSE 'auth' END
WHERE kind = 'auth' AND CHAR_LENGTH(token) < 32;

SET @has_kind_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_tg_tokens'
    AND index_name = 'idx_ro_tg_tokens_kind_token'
);
SET @sql := IF(@has_kind_idx = 0,
  'ALTER TABLE ro_tg_tokens ADD KEY idx_ro_tg_tokens_kind_token (kind, token, expires_at)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
