-- Модуль «Аналоги»: собственная таблица карточек аналогов (независима от
-- справочника products). Переносит данные из Google-таблицы аналогов, включая
-- карточки, которых нет в справочнике портала.

CREATE TABLE IF NOT EXISTS `analog_cards` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `code`               VARCHAR(100) NOT NULL,            -- код как в источнике (BK_68697, 2115624, ПФ013103)
  `sku`                VARCHAR(100) DEFAULT NULL,         -- нормализованный артикул для матчинга с products
  `full_name`          TEXT,                             -- полное наименование (из файла)
  `measure`            VARCHAR(50) DEFAULT NULL,          -- учётная единица отгрузки (кол-во шт/кг/л в упаковке)
  `supplier`           VARCHAR(255) DEFAULT NULL,
  `analog_group`       VARCHAR(255) DEFAULT NULL,
  `legal_entity_group` VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  `in_catalog`         TINYINT(1) NOT NULL DEFAULT 0,     -- есть ли карточка в справочнике портала (products)
  `created_by`         VARCHAR(100) DEFAULT NULL,
  `updated_by`         VARCHAR(100) DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_analog_group` (`analog_group`),
  KEY `idx_sku` (`sku`),
  KEY `idx_group_le` (`legal_entity_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
