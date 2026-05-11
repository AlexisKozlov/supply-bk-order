-- Этап 3 модуля "Напоминания": журнал отправок и отметки "Сделал заказ".
--
-- reminder_runs — каждый случай отправки уведомления (для дедупликации, чтобы
-- cron не слал дважды за час, и для UI «уже напоминали в HH:MM»). Запись
-- идентифицирует уникальную пару (subscription_id, target_date, order_day, run_hour, channel).
--
-- reminder_acknowledgements — отметка «Сделал заказ» на конкретный день и пару
-- (ресторан, поставщик, order_day). Если есть запись — напоминания на этот
-- день/слот больше не идут.

CREATE TABLE IF NOT EXISTS reminder_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  target_date DATE NOT NULL COMMENT 'день подачи заявки (по Europe/Minsk)',
  order_day TINYINT UNSIGNED NOT NULL COMMENT 'день недели заказа 1..7',
  run_hour TINYINT UNSIGNED NOT NULL COMMENT 'час отправки 0..23, для дедуп',
  channel ENUM('portal','telegram') NOT NULL,
  recipient VARCHAR(120) NULL COMMENT 'chat_id для tg либо user_name для портала',
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_run (subscription_id, target_date, order_day, run_hour, channel, recipient),
  KEY idx_rr_subscription (subscription_id),
  KEY idx_rr_date (target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_acknowledgements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  supplier_id CHAR(36) NOT NULL,
  target_date DATE NOT NULL,
  order_day TINYINT UNSIGNED NOT NULL,
  acknowledged_by VARCHAR(120) NULL,
  acknowledged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  source ENUM('portal','telegram','auto') NOT NULL DEFAULT 'portal',
  UNIQUE KEY uniq_ack (restaurant_id, supplier_id, target_date, order_day),
  KEY idx_ra_restaurant (restaurant_id),
  KEY idx_ra_date (target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
