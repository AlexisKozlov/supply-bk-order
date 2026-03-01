-- Индексы для производительности
-- Выполнить вручную на БД

-- order_items: ускоряет JOIN при загрузке заказов с позициями
CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id);

-- delivery_schedule: ускоряет выборку расписания по ресторану
CREATE INDEX IF NOT EXISTS idx_delivery_schedule_restaurant_id ON delivery_schedule(restaurant_id);

-- analysis_data: ускоряет фильтрацию по юр. лицу
CREATE INDEX IF NOT EXISTS idx_analysis_data_legal_entity ON analysis_data(legal_entity);

-- audit_log: ускоряет просмотр истории по сущности
CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log(entity_type, entity_id);

-- orders: ускоряет выборку по юр. лицу и дате
CREATE INDEX IF NOT EXISTS idx_orders_legal_entity ON orders(legal_entity);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at);

-- notifications: ускоряет выборку для пользователя
CREATE INDEX IF NOT EXISTS idx_notifications_target_user ON notifications(target_user);
