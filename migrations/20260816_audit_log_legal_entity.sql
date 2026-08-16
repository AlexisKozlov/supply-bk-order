-- Журнал действий: юрлицо в каждой записи.
--
-- Было: серверный помощник auditLog() не заполнял колонку legal_entity вообще,
-- поэтому 4349 записей из 5422 оставались «ничьими» — всё, что делал сервер
-- (заявки поставщикам, импорты, напоминания, сборы остатков, тендеры).
--
-- Часть сущностей общая для «Бургер БК» и «Воглии Матты» (корректировки,
-- напоминания ресторанов, загрузка машин) — у них одного юрлица нет в принципе.
-- Для них добавляем группу юрлиц, чтобы запись всё равно не была ничьей.

ALTER TABLE `audit_log`
  ADD COLUMN `legal_entity_group` VARCHAR(10) NULL DEFAULT NULL AFTER `legal_entity`;

CREATE INDEX `idx_audit_log_created_at` ON `audit_log` (`created_at`);
CREATE INDEX `idx_audit_log_le_group` ON `audit_log` (`legal_entity_group`);

-- Группа выводится из юрлица тем же правилом, что и в остальных таблицах
-- (см. trg_products_before_insert и getEntityGroup в PHP).
DROP TRIGGER IF EXISTS `trg_audit_log_le_group_ins`;
CREATE TRIGGER `trg_audit_log_le_group_ins` BEFORE INSERT ON `audit_log`
FOR EACH ROW
BEGIN
    IF NEW.legal_entity IS NOT NULL AND NEW.legal_entity <> '' THEN
        IF NEW.legal_entity LIKE '%Пицца Стар%' THEN
            SET NEW.legal_entity_group = 'PS';
        ELSE
            SET NEW.legal_entity_group = 'BK_VM';
        END IF;
    END IF;
END;
