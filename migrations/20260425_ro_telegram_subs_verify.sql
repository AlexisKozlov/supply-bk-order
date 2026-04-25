-- Безопасность Telegram-бота: подписка на ресторан только после привязки
-- через 6-значный код в личном кабинете (а не выбором ресторана в боте).
--
-- Добавляем в ro_telegram_subs поля верификации и крайний срок перепривязки
-- для уже существующих «открытых» подписок.
--
-- verified_at         — момент подтверждения (NULL = не подтверждена).
-- verified_via        — способ подтверждения: 'code' | 'admin' | 'migration'.
-- verified_ro_user_id — id записи ro_users, через чей кабинет получен код.
-- must_reverify_by    — после этой даты неподтверждённая подписка перестаёт работать.
--
-- Также переносим из veg_telegram_subs недостающие колонки настроек уведомлений,
-- чтобы окончательно отказаться от старой таблицы.

ALTER TABLE `ro_telegram_subs`
  ADD COLUMN IF NOT EXISTS `verified_at` DATETIME NULL DEFAULT NULL AFTER `notify_so_reminders`,
  ADD COLUMN IF NOT EXISTS `verified_via` VARCHAR(16) NULL DEFAULT NULL AFTER `verified_at`,
  ADD COLUMN IF NOT EXISTS `verified_ro_user_id` INT NULL DEFAULT NULL AFTER `verified_via`,
  ADD COLUMN IF NOT EXISTS `must_reverify_by` DATETIME NULL DEFAULT NULL AFTER `verified_ro_user_id`,
  ADD COLUMN IF NOT EXISTS `notify_so_sessions` TINYINT(1) NOT NULL DEFAULT 1 AFTER `must_reverify_by`,
  ADD COLUMN IF NOT EXISTS `notify_confirmations` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_so_sessions`,
  ADD COLUMN IF NOT EXISTS `notify_stock_reminders` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_confirmations`,
  ADD COLUMN IF NOT EXISTS `notify_stock_sessions` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_stock_reminders`;

-- Индексы для частых выборок (поиск по chat_id, фильтр по verified_at).
ALTER TABLE `ro_telegram_subs`
  ADD INDEX IF NOT EXISTS `idx_ro_telegram_subs_chat` (`chat_id`),
  ADD INDEX IF NOT EXISTS `idx_ro_telegram_subs_verified` (`verified_at`),
  ADD INDEX IF NOT EXISTS `idx_ro_telegram_subs_must_reverify` (`must_reverify_by`);

-- Переносим оставшиеся настройки уведомлений из veg_telegram_subs.
-- Если запись из veg_* есть в ro_*, копируем флаги (по любым непустым значениям).
UPDATE `ro_telegram_subs` rs
JOIN `veg_telegram_subs` vs
  ON CAST(vs.restaurant_number AS UNSIGNED) = rs.restaurant_number
 AND CAST(vs.chat_id AS SIGNED) = rs.chat_id
SET
  rs.notify_so_sessions     = COALESCE(vs.notify_veg_sessions, rs.notify_so_sessions),
  rs.notify_confirmations   = COALESCE(vs.notify_confirmations, rs.notify_confirmations),
  rs.notify_stock_reminders = COALESCE(vs.notify_stock_reminders, rs.notify_stock_reminders),
  rs.notify_stock_sessions  = COALESCE(vs.notify_stock_sessions, rs.notify_stock_sessions)
WHERE vs.chat_id REGEXP '^-?[0-9]+$';

-- Все существующие подписки помечаем как НЕподтверждённые, но не отключаем
-- мгновенно: сразу даём переходное окно 48 часов, чтобы меню ресторана
-- продолжало работать до перепривязки.
UPDATE `ro_telegram_subs`
SET
  `verified_at` = NULL,
  `verified_via` = NULL,
  `verified_ro_user_id` = NULL,
  `must_reverify_by` = COALESCE(`must_reverify_by`, DATE_ADD(NOW(), INTERVAL 48 HOUR))
WHERE `verified_at` IS NULL;

-- Привязываем токены кабинета к конкретному ro_user, чтобы при вводе кода
-- бот знал, какому сотруднику принадлежит подтверждённая подписка.
ALTER TABLE `ro_tg_tokens`
  ADD COLUMN IF NOT EXISTS `ro_user_id` INT NULL DEFAULT NULL AFTER `legal_entity_group`,
  ADD INDEX IF NOT EXISTS `idx_ro_tg_tokens_user` (`ro_user_id`);
