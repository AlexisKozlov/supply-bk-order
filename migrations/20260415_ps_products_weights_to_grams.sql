UPDATE products
SET
  weight_netto = CASE
    WHEN weight_netto IS NOT NULL AND weight_netto < 50 THEN ROUND(weight_netto * 1000, 2)
    ELSE weight_netto
  END,
  weight_brutto = CASE
    WHEN weight_brutto IS NOT NULL AND weight_brutto < 50 THEN ROUND(weight_brutto * 1000, 2)
    ELSE weight_brutto
  END
WHERE legal_entity = 'ООО "Пицца Стар"'
  AND (
    (weight_netto IS NOT NULL AND weight_netto < 50)
    OR (weight_brutto IS NOT NULL AND weight_brutto < 50)
  );
