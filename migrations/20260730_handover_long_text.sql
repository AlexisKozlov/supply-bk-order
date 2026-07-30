-- Передача дел: поля под длинный текст.
--
-- Колонки строк разделов и примечаний по поставщику были VARCHAR(500).
-- В реальном документе легко пишут больше: порядок работы по овощам,
-- договорённости с поставщиком, что именно сказать при недовозе. MySQL
-- отвечал «Data too long for column», и портал показывал ошибку 500.

ALTER TABLE handover_items
  MODIFY c1 TEXT NULL,
  MODIFY c2 TEXT NULL,
  MODIFY c3 TEXT NULL,
  MODIFY c4 TEXT NULL,
  MODIFY c5 TEXT NULL;

ALTER TABLE handover_suppliers
  MODIFY correction_rule TEXT NULL,
  MODIFY docs_rule TEXT NULL,
  MODIFY contacts TEXT NULL;

ALTER TABLE handover_people
  MODIFY zone TEXT NULL,
  MODIFY scope TEXT NULL,
  MODIFY contact VARCHAR(500) NULL;
