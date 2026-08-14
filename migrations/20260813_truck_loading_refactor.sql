-- Загрузка машин: группа юрлица в назначениях + метка версии плана.
--
-- 1) tl_assignments.legal_entity_group — сервер записывает группу юрлица заказа
--    («BK_VM» или «PS») прямо в назначение. Раньше группы в ответе API не было,
--    и фронт угадывал её по номеру ресторана (номер ≥ 1000 = «Пицца Стар»).
--    Колонка NULL-разрешена: у старых строк группа подставляется при чтении
--    из ro_orders (см. GET tl/plan в api/includes/truck_loading.php).
--
-- 2) tl_plans.updated_at — метка версии плана для защиты от затирания чужой
--    работы: клиент присылает её при сохранении, и если в базе значение новее,
--    сохранение отклоняется с 409. На боевой базе колонка уже есть, поэтому
--    добавление идёт через IF NOT EXISTS (MariaDB) и на ней ничего не меняет.

ALTER TABLE tl_assignments
  ADD COLUMN IF NOT EXISTS legal_entity_group VARCHAR(16) NULL
      COMMENT 'Группа юрлица заказа: BK_VM | PS'
  AFTER restaurant_number;

ALTER TABLE tl_plans
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL
      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      COMMENT 'Версия плана для оптимистичной блокировки'
  AFTER created_at;

-- Заполнить группу у уже существующих назначений (данных мало, это разовая правка).
UPDATE tl_assignments a
  JOIN ro_orders o ON o.id = a.order_id
   SET a.legal_entity_group = o.legal_entity_group
 WHERE a.legal_entity_group IS NULL OR a.legal_entity_group = '';
