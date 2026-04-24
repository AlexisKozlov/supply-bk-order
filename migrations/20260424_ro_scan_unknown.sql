-- Накопление неизвестных штрихкодов из сканера ресторанов.
-- Если ресторан сканирует тот же GTIN снова — увеличиваем seen_count и обновляем last_seen.

CREATE TABLE IF NOT EXISTS `ro_scan_unknown` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gtin` VARCHAR(64) NOT NULL,
    `restaurant_number` INT NOT NULL,
    `legal_entity_group` VARCHAR(16) NOT NULL,
    `seen_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` VARCHAR(16) NOT NULL DEFAULT 'new',  -- new | resolved | ignored
    `notes` VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gtin_restaurant` (`gtin`, `restaurant_number`),
    KEY `idx_status_last` (`status`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
