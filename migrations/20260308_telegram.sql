-- Поле для Telegram chat_id в таблице users
ALTER TABLE users ADD COLUMN telegram_chat_id BIGINT DEFAULT NULL;

-- Настройки уведомлений для каждого пользователя
CREATE TABLE IF NOT EXISTS telegram_settings (
  user_name VARCHAR(255) PRIMARY KEY,
  psc_expiry TINYINT(1) DEFAULT 1 COMMENT 'ПСЦ истекает',
  overdue_delivery TINYINT(1) DEFAULT 1 COMMENT 'Просроченная поставка',
  price_changed TINYINT(1) DEFAULT 1 COMMENT 'Цены изменились',
  low_stock TINYINT(1) DEFAULT 0 COMMENT 'Остатки заканчиваются',
  daily_summary TINYINT(1) DEFAULT 1 COMMENT 'Ежедневная сводка',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
