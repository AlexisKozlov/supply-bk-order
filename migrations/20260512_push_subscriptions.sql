-- Web Push подписки для PWA-уведомлений.
--
-- Хранит endpoint браузера + ключи шифрования (p256dh, auth) для каждой
-- подписки. Привязка к сотруднику ресторана: restaurant_number + опционально
-- ro_tg_sub_id (если хотим связать с конкретным сотрудником, который подписан
-- в TG). Один пользователь может иметь несколько подписок (телефон + ноутбук).

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_number SMALLINT UNSIGNED NULL COMMENT 'номер ресторана если подписался сотрудник ресторана',
  legal_entity_group VARCHAR(20) NULL COMMENT 'BK_VM или PS',
  user_id INT UNSIGNED NULL COMMENT 'если подписался пользователь системы (закупка/админ)',
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  UNIQUE KEY uniq_endpoint (endpoint(255)),
  KEY idx_ps_rest (restaurant_number, legal_entity_group),
  KEY idx_ps_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расширяем ENUM каналов reminder_runs: добавляем 'push' для дедупа push-уведомлений.
ALTER TABLE reminder_runs
  MODIFY COLUMN channel ENUM('portal','telegram','push') NOT NULL;
