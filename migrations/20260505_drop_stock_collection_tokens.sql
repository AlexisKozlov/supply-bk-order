-- Удаляем таблицу публичных токенов сбора остатков.
--
-- Причина: убираем публичную ссылку /stock-form/:token, по которой любой
-- мог выбрать чужой ресторан и заполнить за него остатки.
-- Сбор остатков теперь идёт только двумя путями:
--   1) Личный кабинет ресторана (X-RO-Token, см. api/includes/restaurant_orders.php)
--   2) Telegram-бот (привязка chat_id → ресторан в ro_telegram_subs)
--
-- Перед накаткой убедись, что задеплоен соответствующий код, в котором
-- больше нет SELECT/INSERT/DELETE по этой таблице (RPC sc_validate_token,
-- sc_get_restaurants, sc_submit_stock, sc_create_token удалены).
--
-- Откат: вернуть код из git и пересоздать таблицу из sql/stock_collection_tables.sql.

DROP TABLE IF EXISTS stock_collection_tokens;
