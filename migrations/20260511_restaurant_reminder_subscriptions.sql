-- Подписки ресторана на напоминания о подаче заявок поставщикам.
--
-- Связка (restaurant_id, supplier_id) — на каждую пару одна подписка.
-- Не привязываемся к конкретной таблице расписания (so/локальная) —
-- работаем единообразно через supplier_schedules.
--
-- Каналы: portal_enabled (показывать в кабинете ресторана + колокольчик),
-- telegram_enabled (рассылать в Telegram-бот зарегистрированным подписчикам).
--
-- Этап 2 модуля «Напоминания» — мастер-данные подписок. Cron-логика и
-- журнал отправок добавятся на этапе 3.

CREATE TABLE IF NOT EXISTS restaurant_reminder_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  supplier_id CHAR(36) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'мастер-выключатель подписки',
  portal_enabled TINYINT(1) NOT NULL DEFAULT 1,
  telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  UNIQUE KEY uniq_rrs_pair (restaurant_id, supplier_id),
  KEY idx_rrs_restaurant (restaurant_id),
  KEY idx_rrs_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_reminder_tg_subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  display_name VARCHAR(120) NOT NULL COMMENT 'имя/должность сотрудника ресторана',
  chat_id VARCHAR(100) NULL COMMENT 'telegram chat_id когда привяжет бота',
  link_code VARCHAR(32) NULL COMMENT 'одноразовый код для привязки чата',
  link_code_expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rrts_sub (subscription_id),
  KEY idx_rrts_chat (chat_id),
  CONSTRAINT fk_rrts_sub FOREIGN KEY (subscription_id) REFERENCES restaurant_reminder_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
