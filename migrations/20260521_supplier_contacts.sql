-- Контакты поставщиков для кабинетов ресторанов.
--
-- Под каждую пару «ресторан + поставщик» — отдельный список карточек
-- (имя, должность, телефон, мессенджеры). Редактирование — только закупка
-- (роли с full-доступом к модулю restaurant-orders). Ресторан только читает.
--
-- kind:
--   'external' — контакт реального поставщика (Камако, Лидское и т.д.).
--                supplier_id обязателен, entity_group игнорируется.
--   'internal' — внутренний контакт «своего склада».
--                supplier_id = NULL, entity_group обязателен ('BK_VM' / 'PS').
--
-- ON DELETE RESTRICT: нельзя удалить поставщика или ресторан, если есть
-- привязанные контакты — закупщик сначала вычистит их вручную.
--
-- Поля phone/whatsapp/viber хранятся в E.164 (нормализуются бэком при сохранении).
-- telegram — username без @ и без URL (ссылка строится на фронте).

CREATE TABLE IF NOT EXISTS `restaurant_supplier_contacts` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `kind`          ENUM('external','internal') NOT NULL DEFAULT 'external',
  `supplier_id`   CHAR(36) NULL DEFAULT NULL,
  `entity_group`  VARCHAR(20) NULL DEFAULT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `role`          VARCHAR(60) NULL DEFAULT NULL,
  `phone`         VARCHAR(20) NULL DEFAULT NULL COMMENT 'E.164, без пробелов/скобок',
  `email`         VARCHAR(100) NULL DEFAULT NULL,
  `telegram`      VARCHAR(60) NULL DEFAULT NULL COMMENT 'username без @ и без URL',
  `whatsapp`      VARCHAR(20) NULL DEFAULT NULL COMMENT 'E.164',
  `viber`         VARCHAR(20) NULL DEFAULT NULL COMMENT 'E.164',
  `notes`         VARCHAR(500) NULL DEFAULT NULL,
  `tags`          JSON NULL DEFAULT NULL COMMENT 'массив свободных тегов',
  `is_primary`    TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'под будущий «отпускной режим»',
  `sort_order`    INT NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(36) NULL DEFAULT NULL COMMENT 'users.id (UUID), без FK на случай удаления юзера',
  KEY `idx_rsc_restaurant_kind` (`restaurant_id`, `kind`, `sort_order`),
  KEY `idx_rsc_supplier` (`supplier_id`),
  KEY `idx_rsc_entity_group` (`kind`, `entity_group`),
  CONSTRAINT `fk_rsc_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_rsc_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Контакты поставщиков для кабинетов ресторанов';
