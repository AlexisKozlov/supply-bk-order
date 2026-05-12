-- Журнал запусков cron_delivery_reminders.php.
--
-- Каждый запуск (раз в 5 мин) пишет одну запись со статистикой:
-- сколько подписок обработано, сколько сообщений отправлено по каналам.
-- Поле error содержит сообщение об ошибке если запуск упал.
--
-- Применение: позволяет видеть, что крон работает, и анализировать пропуски.
-- Старые записи (>30 дней) можно вычищать отдельным cleanup-кроном.

CREATE TABLE IF NOT EXISTS reminder_cron_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  -- Локальные поставщики
  sup_portal SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sup_tg     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sup_skip   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Основная поставка
  main_portal SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  main_tg     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  main_skip   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Статус и сводка
  status ENUM('ok','error') NOT NULL DEFAULT 'ok',
  error_text TEXT NULL,
  KEY idx_rcl_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
