-- Прослеживаемость товара (молочка и т.д.)
ALTER TABLE products ADD COLUMN is_traceable TINYINT(1) NOT NULL DEFAULT 0 AFTER external_code;
