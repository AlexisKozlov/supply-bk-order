-- История замен номера БСО в заявках на возврат кег.
-- Каждая замена создаёт строку: старый номер/серия → новый, причина, кто и когда.
-- Используется для строгого учёта БСО (БСО — бланк строгой отчётности),
-- чтобы при сверке с 1С УТ можно было увидеть все версии номеров.
--
-- Замена возможна только пока система разрешает её на уровне логики
-- (между дедлайном 10:00 и cutoff 16:00 в день дедлайна — см. keg_returns.php).
-- В этот момент бэкенд: 1) пишет строку сюда, 2) обновляет keg_returns.bso_*.

CREATE TABLE IF NOT EXISTS keg_return_bso_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  old_series VARCHAR(4) NULL DEFAULT NULL COMMENT 'Прежняя серия БСО (могла быть NULL, если номер ещё не был задан)',
  old_number VARCHAR(20) NULL DEFAULT NULL COMMENT 'Прежний номер БСО',
  new_series VARCHAR(4) NOT NULL COMMENT 'Новая серия БСО',
  new_number VARCHAR(20) NOT NULL COMMENT 'Новый номер БСО',
  reason VARCHAR(255) NOT NULL COMMENT 'Причина замены: испорчен при печати, неверный экземпляр, утерян, другое',
  changed_by_chat_id BIGINT NULL COMMENT 'chat_id ресторана, если замену делал ресторан из ЛК',
  changed_by_user VARCHAR(100) NULL COMMENT 'user.name, если замену делал сотрудник закупок',
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bso_hist_request (request_id, changed_at),
  CONSTRAINT fk_bso_hist_req FOREIGN KEY (request_id) REFERENCES keg_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
