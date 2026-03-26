-- Поля поставщика: страна и отсрочка
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS country VARCHAR(20) DEFAULT 'BY' COMMENT 'BY, RU, другие';
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS payment_delay_days INT DEFAULT NULL COMMENT 'Дни отсрочки оплаты';

-- Таблица оплат
CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id CHAR(36) NOT NULL COMMENT 'Связь с заказом',
  supplier VARCHAR(255) NOT NULL,
  legal_entity VARCHAR(100) DEFAULT NULL,
  delivery_date DATE NOT NULL COMMENT 'Дата фактической поставки',
  payment_delay_days INT NOT NULL DEFAULT 0 COMMENT 'Отсрочка в днях',
  payment_due_date DATE NOT NULL COMMENT 'Дата окончания отсрочки',
  payment_date DATE NOT NULL COMMENT 'Ближайший ВТ или ЧТ после due_date',
  request_deadline DATETIME NOT NULL COMMENT 'Дедлайн заявки: предыдущий день 15:00',
  amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Сумма (из заказа или вручную)',
  currency VARCHAR(10) DEFAULT 'RUB',
  status ENUM('upcoming','request_due','paid','cancelled') NOT NULL DEFAULT 'upcoming',
  created_by VARCHAR(100) DEFAULT NULL,
  paid_by VARCHAR(100) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  note TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pay_status (status),
  INDEX idx_pay_date (payment_date),
  INDEX idx_pay_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
