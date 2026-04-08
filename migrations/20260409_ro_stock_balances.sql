-- Остатки склада для модуля заказов ресторанов
CREATE TABLE IF NOT EXISTS ro_stock_balances (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku             VARCHAR(50) NOT NULL,
  product_name    VARCHAR(255) NOT NULL,
  quantity        DECIMAL(10,2) NOT NULL DEFAULT 0,
  warehouse       VARCHAR(50) NOT NULL COMMENT 'Сухой / Холод+Мороз',
  balance_date    DATE NOT NULL,
  uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ro_stock_sku_date (sku, balance_date),
  INDEX idx_ro_stock_date (balance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
