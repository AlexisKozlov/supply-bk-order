-- Перенос старых Telegram-подписок ресторанов из veg_telegram_subs
-- в новую таблицу ro_telegram_subs.
-- Старую таблицу не удаляем: в ней пока остаются настройки уведомлений по остаткам.

INSERT IGNORE INTO ro_telegram_subs (
  restaurant_number,
  legal_entity_group,
  chat_id,
  first_name,
  username,
  notify_so_reminders,
  created_at
)
SELECT
  CAST(vs.restaurant_number AS UNSIGNED) AS restaurant_number,
  r.legal_entity_group,
  CAST(vs.chat_id AS SIGNED) AS chat_id,
  vs.first_name,
  vs.username,
  COALESCE(vs.notify_veg_reminders, 1) AS notify_so_reminders,
  COALESCE(vs.created_at, NOW()) AS created_at
FROM veg_telegram_subs vs
JOIN restaurants r
  ON r.number = CAST(vs.restaurant_number AS UNSIGNED)
 AND r.active = 1
 AND r.legal_entity_group IN ('BK_VM', 'PS')
WHERE vs.chat_id REGEXP '^-?[0-9]+$';

UPDATE ro_telegram_subs rs
JOIN veg_telegram_subs vs
  ON CAST(vs.restaurant_number AS UNSIGNED) = rs.restaurant_number
 AND CAST(vs.chat_id AS SIGNED) = rs.chat_id
JOIN restaurants r
  ON r.number = rs.restaurant_number
 AND r.active = 1
 AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
SET
  rs.first_name = COALESCE(vs.first_name, rs.first_name),
  rs.username = COALESCE(vs.username, rs.username),
  rs.notify_so_reminders = COALESCE(vs.notify_veg_reminders, rs.notify_so_reminders)
WHERE vs.chat_id REGEXP '^-?[0-9]+$';
