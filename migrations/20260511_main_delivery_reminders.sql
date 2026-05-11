-- Напоминания об основной поставке (заявка со склада в ресторан).
--
-- Контекст: до сих пор модуль «Напоминания» работал только с локальными
-- поставщиками (so_enabled=0). Теперь добавляем «основную поставку» — ту,
-- которую ресторан заказывает со склада через 1С. Дедлайн подачи задаёт
-- закупка на странице /delivery-schedule для каждой строки расписания
-- (ресторан × день_доставки).
--
-- 1) В delivery_schedule добавляем поля:
--    order_day      — день недели, в который ресторан подаёт заявку (1..7).
--    order_deadline — крайний срок подачи (TIME).
--    NULL = напоминать не нужно (закупка ещё не настроила).
--
-- 2) Подписки на основную поставку — одна на ресторан.
--    Логика та же, что у supplier-подписок, но без supplier_id.
--
-- 3) Привязка TG-сотрудников к подписке (повторное использование ro_telegram_subs).

ALTER TABLE delivery_schedule
  ADD COLUMN order_day TINYINT NULL COMMENT 'день недели подачи заявки (1=ПН..7=ВС)' AFTER day_of_week,
  ADD COLUMN order_deadline TIME NULL COMMENT 'крайний срок подачи заявки' AFTER order_day;

CREATE TABLE IF NOT EXISTS restaurant_main_delivery_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'мастер-выключатель',
  portal_enabled TINYINT(1) NOT NULL DEFAULT 1,
  telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  UNIQUE KEY uniq_rmds_rest (restaurant_id),
  CONSTRAINT fk_rmds_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restaurant_main_delivery_tg_subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  ro_tg_sub_id INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rmdts_pair (subscription_id, ro_tg_sub_id),
  KEY idx_rmdts_sub (subscription_id),
  CONSTRAINT fk_rmdts_sub FOREIGN KEY (subscription_id) REFERENCES restaurant_main_delivery_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
