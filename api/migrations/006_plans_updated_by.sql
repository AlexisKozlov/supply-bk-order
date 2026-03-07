-- Поля для отслеживания кто и когда обновил план
ALTER TABLE plans ADD COLUMN updated_by VARCHAR(255) DEFAULT NULL AFTER created_by;
ALTER TABLE plans ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at;
