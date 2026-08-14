-- Загрузка машин: направления доставки.
--
-- Зачем: автораспределение раньше было чистой арифметикой (режимы хранения +
-- вместимость), география не учитывалась — в один рейс попадали Минск и Полоцк.
-- Направление задаёт географию рейса, и машина везёт заказы только одного
-- направления.
--
-- Как задаётся направление (решение пользователя):
--   cities              — список ГОРОДОВ: все рестораны этих городов попадают
--                         в направление автоматически, включая новые;
--   include_restaurants — номера ресторанов, добавленных вручную (не из этих
--                         городов, но возить их надо этим рейсом);
--   exclude_restaurants — номера ресторанов, исключённых вручную (из этих
--                         городов, но этим рейсом не возить).
-- Приоритет при пересечении направлений — по sort_order (меньше = важнее).
--
-- Номера ресторанов хранятся СЫРЫМИ, как в restaurants.number: «Пицца Стар»
-- лежит в диапазоне 1001+, преобразование в PS01 — задача интерфейса.
-- Города сравниваются с restaurants.city без учёта регистра и лишних пробелов
-- (сравнение делает PHP, см. tlNormalizeCity в api/includes/truck_loading.php).

CREATE TABLE IF NOT EXISTS tl_directions (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(120) NOT NULL COMMENT 'Название направления, напр. «Витебск — Полоцк»',
  cities              JSON NULL DEFAULT NULL COMMENT 'Массив городов (restaurants.city)',
  include_restaurants JSON NULL DEFAULT NULL COMMENT 'Массив номеров ресторанов, добавленных вручную',
  exclude_restaurants JSON NULL DEFAULT NULL COMMENT 'Массив номеров ресторанов, исключённых вручную',
  sort_order          SMALLINT NOT NULL DEFAULT 0 COMMENT 'Приоритет: меньше = выше при пересечении',
  is_active           TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Мягкое удаление: 0 = удалено',
  created_by          VARCHAR(255) NULL COMMENT 'Кто создал',
  created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tl_directions_active (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Направления доставки для модуля «Загрузка машин»';

-- Направление машины в плане. NULL = машина без направления (сборный рейс или
-- заказы, не попавшие ни в одно направление). ON DELETE SET NULL: если
-- направление когда-нибудь удалят физически, планы не рассыпаются.
ALTER TABLE tl_trucks
  ADD COLUMN IF NOT EXISTS direction_id INT UNSIGNED NULL
      COMMENT 'Направление доставки (tl_directions.id), NULL = без направления'
  AFTER vehicle_id;

ALTER TABLE tl_trucks
  ADD CONSTRAINT IF NOT EXISTS tl_trucks_ibfk_3
  FOREIGN KEY (direction_id)
  REFERENCES tl_directions (id) ON DELETE SET NULL;
