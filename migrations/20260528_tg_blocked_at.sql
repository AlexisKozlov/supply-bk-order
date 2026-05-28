-- Метка «пользователь заблокировал бота в Telegram».
--
-- Заполняется в api/includes/tg_client.php:tgMarkChatBlocked() при получении
-- от Telegram Bot API ошибок: HTTP 403 («Forbidden: bot was blocked by the
-- user»), HTTP 400 + «chat not found» / «user is deactivated».
--
-- Сбрасывается в api/telegram_bot.php при любом входящем сообщении/нажатии
-- кнопки от этого chat_id (бот снова доступен).
--
-- Cron-выборки получателей рассылок пропускают записи с tg_blocked_at,
-- начиная с момента блокировки в течение 30 дней. После 30 дней — пробуют
-- снова (вдруг человек разблокировал и не писал).
--
-- Безопасна к откату: ALTER TABLE ... DROP COLUMN tg_blocked_at вернёт всё
-- как было; код устойчив к отсутствию колонки (UPDATE'ы внутри try/catch).

ALTER TABLE users
  ADD COLUMN tg_blocked_at DATETIME NULL DEFAULT NULL COMMENT 'Когда сотрудник заблокировал бота в Telegram',
  ADD INDEX idx_users_tg_blocked (tg_blocked_at);

ALTER TABLE ro_telegram_subs
  ADD COLUMN tg_blocked_at DATETIME NULL DEFAULT NULL COMMENT 'Когда ресторан заблокировал бота в Telegram',
  ADD INDEX idx_ro_subs_tg_blocked (tg_blocked_at);
