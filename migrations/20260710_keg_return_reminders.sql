-- Напоминания по возврату кег: подписки ресторана + новые типы напоминаний.
-- Третий вид напоминаний (после supplier и main_delivery). Одна подписка на
-- ресторан, по образцу restaurant_main_delivery_subscriptions. График вывоза
-- берётся из restaurants.pickup_weekdays (битмаска), дедлайн подачи считает
-- kegCalcDeadline (10:00 последнего рабочего дня перед вывозом).

CREATE TABLE IF NOT EXISTS restaurant_keg_return_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'мастер-выключатель',
  portal_enabled TINYINT(1) NOT NULL DEFAULT 1,
  telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  UNIQUE KEY uniq_rkrs_rest (restaurant_id),
  CONSTRAINT fk_rkrs_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_keg_return_tg_subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  ro_tg_sub_id INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rkrts_pair (subscription_id, ro_tg_sub_id),
  KEY idx_rkrts_sub (subscription_id),
  CONSTRAINT fk_rkrts_sub FOREIGN KEY (subscription_id) REFERENCES restaurant_keg_return_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Новые типы напоминаний для дедупликации рассылки в reminder_runs:
--   keg_return  — «подайте заявку на возврат кег» (перед дедлайном 10:00)
--   keg_invoice — «передайте накладные бухгалтерии» (после дня возврата)
ALTER TABLE reminder_runs
  MODIFY COLUMN reminder_kind ENUM('supplier','main_delivery','keg_return','keg_invoice') NOT NULL DEFAULT 'supplier';
