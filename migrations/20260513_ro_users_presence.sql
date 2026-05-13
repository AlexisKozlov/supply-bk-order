-- Презенс ресторанов в кабинете: какую страницу смотрит сейчас и когда был
-- последний heartbeat. Используется в /admin → «Сессии» для блока
-- «Рестораны онлайн».
--
-- last_seen_at — обновляется по heartbeat-таймеру кабинета (раз в 15 сек).
-- last_page    — название текущей страницы из меню кабинета.

ALTER TABLE ro_users
  ADD COLUMN last_page     VARCHAR(120) NULL AFTER last_login_at,
  ADD COLUMN last_seen_at  TIMESTAMP    NULL AFTER last_page,
  ADD INDEX idx_ro_users_last_seen (last_seen_at);
