-- Тендеры: разрешить 3 знака после запятой в количествах и ценах.
-- Раньше decimal(_,2)/(_,1) обрезали третий знак — фронт стал слать step=0.001.
ALTER TABLE tender_items
  MODIFY COLUMN quantity DECIMAL(14,3) DEFAULT NULL,
  MODIFY COLUMN monthly_consumption DECIMAL(14,3) DEFAULT NULL;
ALTER TABLE tender_offer_prices
  MODIFY COLUMN price DECIMAL(14,3) DEFAULT NULL,
  MODIFY COLUMN price_rub DECIMAL(14,3) DEFAULT NULL,
  MODIFY COLUMN price_byn DECIMAL(14,3) DEFAULT NULL;
