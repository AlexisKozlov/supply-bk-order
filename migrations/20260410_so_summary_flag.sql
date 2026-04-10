-- Флаг: получать в Telegram итоговую сводку по заявкам поставщикам после дедлайна
ALTER TABLE telegram_settings
    ADD COLUMN IF NOT EXISTS so_deadline_summary TINYINT(1) NOT NULL DEFAULT 0;
