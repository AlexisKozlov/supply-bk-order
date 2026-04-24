-- Подписчики на уведомления о ненайденных штрихкодах из сканера ресторанов.
-- Если таблица пуста — уведомления не шлются никому.

CREATE TABLE IF NOT EXISTS `ro_scan_unknown_subscribers` (
    `user_name` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
