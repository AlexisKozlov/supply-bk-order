-- Добавить колонку editing_order_id в user_presence для блокировки одновременного редактирования
ALTER TABLE user_presence ADD COLUMN IF NOT EXISTS editing_order_id VARCHAR(36) DEFAULT NULL;
