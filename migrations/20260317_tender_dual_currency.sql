-- Двойная валюта в тендерных ценах (RUB + BYN)
ALTER TABLE tender_offer_prices
  ADD COLUMN price_rub DECIMAL(12,2) DEFAULT NULL AFTER price,
  ADD COLUMN price_byn DECIMAL(12,2) DEFAULT NULL AFTER price_rub;

-- Перенести существующие цены в price_rub (по умолчанию считаем что цены были в рублях)
UPDATE tender_offer_prices SET price_rub = price WHERE price IS NOT NULL AND price > 0;
