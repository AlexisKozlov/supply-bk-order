-- Фикс collation для restaurants.legal_entity_group:
-- было utf8mb4_general_ci, остальные ro_* таблицы (ro_orders, ro_sessions и др.)
-- используют utf8mb4_unicode_ci. JOIN по legal_entity_group падал с
-- "Illegal mix of collations" → 500 на /api/ro/admin/status.
ALTER TABLE restaurants
  MODIFY legal_entity_group VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;
