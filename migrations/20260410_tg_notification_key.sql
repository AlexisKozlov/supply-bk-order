-- Колонка notification_key для уникальных ключей напоминаний.
-- Используется для напоминаний заказов ресторанов (ro_*) и заявок поставщикам (so_*).
-- Старые поля notification_type/legal_entity/chat_id остаются для старых уведомлений (overdue_delivery и т.п.).
ALTER TABLE tg_notification_log
    ADD COLUMN IF NOT EXISTS notification_key VARCHAR(255) DEFAULT NULL,
    ADD INDEX IF NOT EXISTS idx_notification_key (notification_key, sent_at),
    MODIFY notification_type VARCHAR(50) NOT NULL DEFAULT '',
    MODIFY legal_entity VARCHAR(255) NOT NULL DEFAULT '',
    MODIFY chat_id BIGINT(20) NOT NULL DEFAULT 0;
