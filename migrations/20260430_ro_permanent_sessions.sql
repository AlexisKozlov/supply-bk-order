-- Постоянный режим заказов ресторанов.
-- Сессии остаются как техническая привязка для старой схемы БД,
-- но больше не ограничивают работу неделей.

INSERT INTO ro_sessions (week_start, week_end, legal_entity_group, status, created_by)
VALUES
  ('2000-01-01', '2099-12-31', 'BK_VM', 'active', 'permanent'),
  ('2000-01-01', '2099-12-31', 'PS', 'active', 'permanent')
ON DUPLICATE KEY UPDATE
  week_end = VALUES(week_end),
  status = 'active',
  created_by = 'permanent';

-- Переносим последние настройки открытия дат/дедлайнов из старых сессий
-- в постоянные сессии соответствующих групп.
INSERT INTO ro_deadline_overrides (
  session_id,
  delivery_date,
  is_open,
  soft_deadline,
  hard_deadline,
  created_by
)
SELECT
  permanent.id,
  old_override.delivery_date,
  old_override.is_open,
  old_override.soft_deadline,
  old_override.hard_deadline,
  COALESCE(NULLIF(old_override.created_by, ''), 'migration')
FROM ro_deadline_overrides old_override
JOIN ro_sessions old_session
  ON old_session.id = old_override.session_id
JOIN (
  SELECT
    COALESCE(NULLIF(rs.legal_entity_group, ''), 'BK_VM') AS legal_entity_group,
    d.delivery_date,
    MAX(rs.id) AS session_id
  FROM ro_deadline_overrides d
  JOIN ro_sessions rs ON rs.id = d.session_id
  WHERE COALESCE(rs.created_by, '') <> 'permanent'
  GROUP BY COALESCE(NULLIF(rs.legal_entity_group, ''), 'BK_VM'), d.delivery_date
) latest
  ON latest.session_id = old_session.id
 AND latest.delivery_date = old_override.delivery_date
JOIN ro_sessions permanent
  ON permanent.legal_entity_group = COALESCE(NULLIF(old_session.legal_entity_group, ''), 'BK_VM')
 AND permanent.created_by = 'permanent'
WHERE COALESCE(old_session.created_by, '') <> 'permanent'
ON DUPLICATE KEY UPDATE
  is_open = VALUES(is_open),
  soft_deadline = VALUES(soft_deadline),
  hard_deadline = VALUES(hard_deadline),
  created_by = VALUES(created_by);
