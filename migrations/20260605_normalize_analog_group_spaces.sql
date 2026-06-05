-- Нормализация пробелов в analog_group: TRIM + схлопывание кратных пробелов в один.
-- Причина: «Коробка Кламшелл  универсальный» (2 пробела) и «Коробка Кламшелл
-- универсальный» (1 пробел) считались РАЗНЫМИ группами аналогов. Новые позиции
-- (574763, 157531) попали в 1-пробельную, а реализация/расход — в 2-пробельной →
-- не матчились, в анализе видны как две одинаковые группы. Пробелы в названии
-- группы смысла не несут, нормализуем во всех таблицах с analog_group. 2026-06-05.
UPDATE products
   SET analog_group = TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '))
 WHERE analog_group IS NOT NULL
   AND analog_group <> TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '));

UPDATE restaurant_sales
   SET analog_group = TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '))
 WHERE analog_group IS NOT NULL
   AND analog_group <> TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '));

UPDATE sa_order_items
   SET analog_group = TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '))
 WHERE analog_group IS NOT NULL
   AND analog_group <> TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '));

UPDATE hidden_analogs
   SET analog_group = TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '))
 WHERE analog_group IS NOT NULL
   AND analog_group <> TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '));

UPDATE report_exclusions
   SET analog_group = TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '))
 WHERE analog_group IS NOT NULL
   AND analog_group <> TRIM(REGEXP_REPLACE(analog_group, '[[:space:]]+', ' '));
