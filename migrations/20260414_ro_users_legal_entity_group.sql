ALTER TABLE ro_users
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER restaurant_number;

UPDATE ro_users
SET legal_entity_group = CASE
  WHEN legal_entity LIKE '%Пицца Стар%' THEN 'PS'
  ELSE 'BK_VM'
END
WHERE legal_entity_group IS NULL OR legal_entity_group = '';

SET @has_old_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_users'
    AND index_name = 'uq_ro_users_rest'
);
SET @sql := IF(@has_old_uq > 0, 'ALTER TABLE ro_users DROP INDEX uq_ro_users_rest', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_new_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_users'
    AND index_name = 'uq_ro_users_rest_group'
);
SET @sql := IF(@has_new_uq = 0, 'ALTER TABLE ro_users ADD UNIQUE KEY uq_ro_users_rest_group (restaurant_number, legal_entity_group)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_group_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_users'
    AND index_name = 'idx_ro_users_group_active'
);
SET @sql := IF(@has_group_idx = 0, 'ALTER TABLE ro_users ADD KEY idx_ro_users_group_active (legal_entity_group, is_active)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
