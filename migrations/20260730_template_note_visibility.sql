-- Примечание к товару: своя видимость, отдельно от доступности товара.
--
-- Было: so_template_visibility ограничивает, каким ресторанам товар вообще
-- доступен для заказа. Примечание при этом видели все, кому доступен товар.
-- Нужно иначе: товар заказывают все, а пояснение к нему («в Минске берите
-- только паллетами», «эту позицию согласуйте с закупкой») адресуется
-- отдельным регионам или ресторанам.
--
-- Решение: та же таблица, но с типом записи. kind='access' — прежние
-- ограничения доступности, kind='note' — кому показывать примечание.
-- Существующие строки помечаем как 'access', поведение не меняется.

ALTER TABLE so_template_visibility
  ADD COLUMN kind ENUM('access','note') NOT NULL DEFAULT 'access' AFTER template_id;

-- Уникальность теперь с учётом типа: у товара может быть и ограничение
-- доступности, и адресное примечание для тех же регионов.
ALTER TABLE so_template_visibility
  DROP INDEX uniq_tpl_scope;

ALTER TABLE so_template_visibility
  ADD UNIQUE KEY uniq_tpl_kind_scope (template_id, kind, scope_type, scope_value);
