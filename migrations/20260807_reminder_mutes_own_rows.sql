-- Напоминания о заявках: выключение самим рестораном переносим из «глушилки»
-- закупок (so_reminder_mutes) в его собственную подписку.
--
-- Старый кабинет писал выключение в so_reminder_mutes с created_by = 'ro:<номер>',
-- и сразу после этого показывал ресторану баннер «напоминания выключил отдел
-- закупок». Крон учитывает оба источника, поэтому рассылка от переноса не
-- меняется — меняется только автор выключения.

INSERT INTO restaurant_reminder_subscriptions
    (restaurant_id, supplier_id, is_enabled, portal_enabled, telegram_enabled, cron_managed, updated_at, updated_by)
SELECT m.restaurant_id, m.supplier_id, 0, 0, 1, 1, NOW(), m.created_by
FROM so_reminder_mutes m
WHERE m.created_by LIKE 'ro:%'
ON DUPLICATE KEY UPDATE
    is_enabled = 0,
    portal_enabled = 0,
    cron_managed = 1,
    updated_at = NOW();

DELETE FROM so_reminder_mutes WHERE created_by LIKE 'ro:%';
