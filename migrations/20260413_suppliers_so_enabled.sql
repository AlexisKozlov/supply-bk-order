-- Миграция: флаг so_enabled в suppliers
--
-- Зачем: в модуле «Заявки поставщикам» должны показываться только те
-- поставщики, которые реально принимают заявки от ресторанов — не все 39
-- записей из справочника подряд. Флаг позволяет включать/выключать
-- участие в SO-модуле без удаления настроек, расписаний и шаблонов.
--
-- Backfill: активируем флаг только у тех, у кого уже есть хотя бы одна
-- строка в so_supplier_schedules (пункт 5а из согласования с пользователем).

ALTER TABLE suppliers
    ADD COLUMN so_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

CREATE INDEX idx_suppliers_so_enabled ON suppliers (so_enabled);

UPDATE suppliers s
   SET s.so_enabled = 1
 WHERE EXISTS (
     SELECT 1 FROM so_supplier_schedules ss
     WHERE ss.supplier_id = s.id AND ss.is_active = 1
 );
