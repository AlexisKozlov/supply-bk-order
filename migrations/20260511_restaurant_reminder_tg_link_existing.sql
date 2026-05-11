-- Привязка подписчиков напоминаний к существующим tg-сотрудникам ресторана.
--
-- Изначально таблица restaurant_reminder_tg_subscribers хранила имя сотрудника
-- и одноразовый код для привязки чата через команду боту. Это неудобно: у нас
-- уже есть ro_telegram_subs — таблица верифицированных Telegram-привязок
-- сотрудников ресторана. Переделываем подписчиков напоминаний на ссылку в эту
-- таблицу — пользователь просто отмечает галочкой кто получает напоминания.

-- Чистим черновики до изменения схемы (запись с пустым ro_tg_sub_id нам не нужна).
TRUNCATE TABLE restaurant_reminder_tg_subscribers;

ALTER TABLE restaurant_reminder_tg_subscribers
  DROP COLUMN display_name,
  DROP COLUMN chat_id,
  DROP COLUMN link_code,
  DROP COLUMN link_code_expires_at,
  ADD COLUMN ro_tg_sub_id INT UNSIGNED NOT NULL AFTER subscription_id,
  ADD UNIQUE KEY uniq_rrts_pair (subscription_id, ro_tg_sub_id),
  ADD KEY idx_rrts_ro_sub (ro_tg_sub_id);
