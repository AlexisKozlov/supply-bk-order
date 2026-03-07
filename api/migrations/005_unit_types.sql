-- Расширение типов единиц для цен: тыс/шт, кг, литр
ALTER TABLE product_prices
  MODIFY COLUMN unit_type ENUM('piece','box','thousand','kg','liter') NOT NULL DEFAULT 'piece';
