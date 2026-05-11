-- Унификация модели расписаний и дедлайнов поставщиков.
--
-- Контекст: исторически расписания и правила дедлайнов жили в so_*-таблицах
-- (модуль "Заявки поставщикам", Камако и пр.) и были привязаны к флагу
-- suppliers.so_enabled. Для локальных поставщиков (so_enabled=0) аналогичных
-- структур не было. Это вело к необходимости делать вторую параллельную
-- модель — что архитектурный дубль.
--
-- Решение: переименовать so_*-таблицы в нейтральные имена и сделать их
-- общими для обоих типов поставщиков (so и локальных). Флаг so_enabled
-- сохраняется и означает только "поставщик принимает заявки через портал".
-- Расписание у него такое же, как у локального.
--
-- Плюс новая таблица supplier_schedule_deadlines — дедлайн на уровне
-- (поставщик, ресторан, день заказа). Используется как точное правило
-- поверх дефолтных правил поставщика (supplier_default_deadlines).
--
-- ВНИМАНИЕ: после применения этой миграции код в api/includes/supplier_orders.php,
-- api/includes/bot_rest.php, api/cron_telegram.php должен ссылаться на новые
-- имена (правки в коде идут вместе с миграцией).

-- 1) Переименование таблицы расписаний.
RENAME TABLE so_supplier_schedules TO supplier_schedules;

-- 2) Переименование таблицы правил-дедлайнов.
RENAME TABLE so_deadline_rules TO supplier_default_deadlines;

-- 3) Новая таблица: дедлайн на уровне (поставщик, ресторан, день заказа).
-- Имеет приоритет над supplier_default_deadlines.
CREATE TABLE IF NOT EXISTS supplier_schedule_deadlines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id CHAR(36) NOT NULL,
  restaurant_id INT UNSIGNED NOT NULL,
  order_day TINYINT UNSIGNED NOT NULL COMMENT '1=Пн ... 7=Вс',
  deadline_time TIME NOT NULL COMMENT 'до этого времени надо подать заявку',
  notes VARCHAR(500) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) NULL,
  UNIQUE KEY uniq_sd_pair_day (supplier_id, restaurant_id, order_day),
  KEY idx_sd_supplier (supplier_id),
  KEY idx_sd_restaurant (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
