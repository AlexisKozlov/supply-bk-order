-- Модуль «Новинки» для ресторанов.
--
-- Что делает: рестораны в кабинете и телеграм-боте видят раздел «Новинки» —
-- товары, недавно появившиеся в справочнике (products.created_at). Товар
-- считается новинкой 3 недели с даты появления. Закупщик по желанию добавляет
-- описание, фото и дату старта продаж, может скрыть ложную новинку (например,
-- если импортировалась старая карточка) или продлить/сократить срок показа.
--
-- Сам список новинок берётся из products по дате. Эта таблица хранит только
-- редакторские данные закупщика, привязанные к товару по products.id.
-- Видимость — по бизнес-группе (BK_VM / PS), как и весь справочник товаров.

CREATE TABLE IF NOT EXISTS `product_novelties` (
  `product_id`       CHAR(36)     NOT NULL,
  `description`      TEXT         NULL,
  `sales_start_date` DATE         NULL,
  `photo_path`       VARCHAR(255) NULL,
  -- Ложная новинка (реимпорт старой карточки): закупщик прячет из раздела.
  `is_hidden`        TINYINT(1)   NOT NULL DEFAULT 0,
  -- Переопределение конца показа. NULL = created_at + 3 недели (NOVELTY_DAYS).
  -- Позволяет продлить (дата в будущем) или убрать раньше (дата в прошлом).
  `show_until`       DATETIME     NULL,
  `updated_by`       VARCHAR(255) NULL,
  `updated_at`       DATETIME     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at`       DATETIME     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
