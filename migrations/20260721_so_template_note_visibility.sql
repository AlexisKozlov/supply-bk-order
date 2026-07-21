-- Заявки поставщикам: примечание к товару (для ресторанов) и ограничение
-- доступности товара по регионам/ресторанам.
--
-- 1. so_templates.note — примечание, которое видят рестораны под названием
--    товара в форме заказа. Пусто = примечания нет.
-- 2. so_template_visibility — кому виден товар. Правило: если у товара НЕТ
--    строк здесь — видят все; если есть — видят только рестораны выбранных
--    регионов ИЛИ выбранные поимённо рестораны (объединение).

ALTER TABLE `so_templates`
  ADD COLUMN `note` VARCHAR(500) DEFAULT NULL COMMENT 'Примечание для ресторанов' AFTER `min_qty`;

CREATE TABLE IF NOT EXISTS `so_template_visibility` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT UNSIGNED NOT NULL,
  `scope_type` ENUM('region','restaurant') NOT NULL,
  `scope_value` VARCHAR(100) NOT NULL COMMENT 'название региона или номер ресторана',
  UNIQUE KEY `uniq_tpl_scope` (`template_id`, `scope_type`, `scope_value`),
  INDEX `idx_template` (`template_id`),
  FOREIGN KEY (`template_id`) REFERENCES `so_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Ограничение доступности товара шаблона по регионам/ресторанам';
