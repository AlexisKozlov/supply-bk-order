-- Повторное подключение поставщика: возврат сохранённых настроек.
--
-- Отключение гасит график и шаблон товаров (is_active = 0), но при обратном
-- подключении мастер запускался пустым и перезаписывал всё заново — обещание
-- «при повторном подключении всё вернётся» не выполнялось.
--
-- Флаг помечает строки, выключенные ИМЕННО отключением поставщика: при
-- возврате поднимаем только их, а строки, убранные раньше руками, остаются
-- выключенными.

ALTER TABLE supplier_schedules
    ADD COLUMN disabled_by_disconnect TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Выключено отключением поставщика от модуля заявок';

ALTER TABLE so_templates
    ADD COLUMN disabled_by_disconnect TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Выключено отключением поставщика от модуля заявок';

-- Уже отключённые поставщики: считаем, что их выключенные строки погасило
-- именно отключение — иначе им возвращать было бы нечего.
UPDATE supplier_schedules ss
   JOIN suppliers s ON s.id = ss.supplier_id
   SET ss.disabled_by_disconnect = 1
 WHERE s.so_enabled = 0 AND ss.is_active = 0;

UPDATE so_templates t
   JOIN suppliers s ON s.id = t.supplier_id
   SET t.disabled_by_disconnect = 1
 WHERE s.so_enabled = 0 AND t.is_active = 0;
