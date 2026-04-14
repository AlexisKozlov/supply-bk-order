ALTER TABLE ro_tg_tokens
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER restaurant_number;

UPDATE ro_tg_tokens
SET legal_entity_group = CASE
  WHEN restaurant_number REGEXP '^[0-9]+$' AND CAST(restaurant_number AS UNSIGNED) >= 1000 THEN 'PS'
  ELSE 'BK_VM'
END
WHERE legal_entity_group IS NULL OR legal_entity_group = '';

SET @has_group_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ro_tg_tokens'
    AND index_name = 'idx_ro_tg_tokens_rest_group'
);
SET @sql := IF(@has_group_idx = 0, 'ALTER TABLE ro_tg_tokens ADD KEY idx_ro_tg_tokens_rest_group (restaurant_number, legal_entity_group, expires_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
