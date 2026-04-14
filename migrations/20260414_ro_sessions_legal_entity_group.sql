ALTER TABLE ro_sessions
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER week_end;

UPDATE ro_sessions
SET legal_entity_group = 'BK_VM'
WHERE legal_entity_group IS NULL OR legal_entity_group = '';

SET @has_old_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_sessions'
    AND index_name = 'uq_ro_sessions_week'
);
SET @sql := IF(@has_old_uq > 0, 'ALTER TABLE ro_sessions DROP INDEX uq_ro_sessions_week', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_sessions'
    AND index_name = 'uq_ro_sessions_group_week'
);
SET @sql := IF(@has_new_uq = 0, 'ALTER TABLE ro_sessions ADD UNIQUE KEY uq_ro_sessions_group_week (legal_entity_group, week_start)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_group_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_sessions'
    AND index_name = 'idx_ro_sessions_group_status'
);
SET @sql := IF(@has_group_idx = 0, 'ALTER TABLE ro_sessions ADD KEY idx_ro_sessions_group_status (legal_entity_group, status, week_end)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
