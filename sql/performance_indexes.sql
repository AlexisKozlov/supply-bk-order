-- Индексы для производительности
-- Запускать один раз. IF NOT EXISTS гарантирует безопасный повторный запуск.

-- Заказы: поиск ожидающих/просроченных поставок (крон + API)
CREATE INDEX IF NOT EXISTS idx_orders_delivery ON orders (delivery_date, received_at, legal_entity);

-- История цен: проверка изменений за последние N минут (крон)
CREATE INDEX IF NOT EXISTS idx_price_history_changed ON price_history (changed_at, legal_entity);

-- Остатки 1С: проверка загрузок за последние N минут (крон)
CREATE INDEX IF NOT EXISTS idx_stock_1c_updated ON stock_1c (updated_at, legal_entity);

-- Сроки годности: поиск истекающих (крон + страница)
CREATE INDEX IF NOT EXISTS idx_stock_malling_expiry ON stock_malling (expiry_date, expiry_status, customer);

-- Аналитика: критические остатки (крон)
CREATE INDEX IF NOT EXISTS idx_analysis_data_entity ON analysis_data (legal_entity);

-- Лог уведомлений: дедупликация при отправке (крон)
CREATE INDEX IF NOT EXISTS idx_tg_notif_log ON tg_notification_log (notification_type, legal_entity, chat_id, sent_at);

-- Уведомления: выборка по типу и дате (крон)
CREATE INDEX IF NOT EXISTS idx_notifications_type_date ON notifications (type, created_at);

-- Продажи ресторанов: проверка новых данных (крон)
CREATE INDEX IF NOT EXISTS idx_restaurant_sales_date ON restaurant_sales (created_at);

-- Сессии: очистка истёкших
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires ON user_sessions (expires_at);
