-- Множественные штрихкоды у товара.
--
-- Зачем: до этой миграции products.gtin хранил один штрихкод на товар.
-- Когда товар учитывается коробками, в gtin обычно записан штрихкод
-- коробки. Если ресторан сканирует индивидуальную упаковку (сливс,
-- бутылку и т.п.), товар не находится — попадает в ro_scan_unknown.
--
-- Решение: вынести штрихкоды в отдельную таблицу. Один товар (sku) →
-- N штрихкодов, у каждого тип (коробка/штука/упаковка/другое/неизвестно).
-- Один из штрихкодов помечается как is_primary=1 — это «основной»,
-- который продолжает синхронизироваться в products.gtin для обратной
-- совместимости (карточки, импорт, отчёты).
--
-- products.gtin НЕ удаляется — продолжает заполняться (см. синхронизацию
-- в crud.php и в новых RPC product_barcode_*). Удалим отдельной миграцией
-- позже, когда убедимся, что нигде не используется напрямую.

CREATE TABLE IF NOT EXISTS `product_barcodes` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sku`           VARCHAR(100) NOT NULL,
  `barcode`       VARCHAR(64)  NOT NULL,
  `barcode_type`  ENUM('box','piece','pack','other','unknown') NOT NULL DEFAULT 'unknown',
  `qty_per_unit`  DECIMAL(10,3) NULL DEFAULT NULL COMMENT 'сколько учётных единиц в одном таком штрихкоде; для будущего',
  `is_primary`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'основной штрихкод — синхронизируется в products.gtin',
  `source`        ENUM('admin','restaurant','import','migration') NOT NULL DEFAULT 'admin',
  `created_by`    VARCHAR(255) NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pb_barcode` (`barcode`),
  KEY `idx_pb_sku` (`sku`),
  UNIQUE KEY `uk_pb_barcode_sku` (`barcode`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Штрихкоды товаров (один товар — много штрихкодов)';

-- Перенос существующих gtin: для каждой строки products с непустым gtin
-- создаётся запись в product_barcodes с типом 'unknown' (тип будущему
-- админу нужно будет проставить вручную, если важно) и флагом is_primary=1.
--
-- IGNORE — на случай дубликатов (sku, gtin), которые уже есть в проде
-- (332 уникальных gtin при 353 товарах = есть повторы).
INSERT IGNORE INTO `product_barcodes` (`sku`, `barcode`, `barcode_type`, `is_primary`, `source`)
SELECT DISTINCT
  `sku`,
  `gtin`,
  'unknown',
  1,
  'migration'
FROM `products`
WHERE `gtin` IS NOT NULL AND `gtin` <> '' AND `sku` IS NOT NULL AND `sku` <> '';
