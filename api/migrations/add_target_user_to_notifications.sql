ALTER TABLE notifications ADD COLUMN target_user VARCHAR(100) DEFAULT NULL AFTER created_by;
CREATE INDEX idx_notifications_target_user ON notifications (target_user);
