-- Этап 3 «Заявки поставщикам»: защита авто-сигналов ресторанам от повторов.
-- Один сигнал (скоро дедлайн / приём закрыт) уходит один раз на (поставщик, день, тип).
CREATE TABLE IF NOT EXISTS `so_signal_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `signal_type` VARCHAR(32) NOT NULL,
  `restaurants_notified` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_signal` (`supplier_id`, `delivery_date`, `signal_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
