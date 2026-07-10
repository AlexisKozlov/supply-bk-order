-- Возврат кег: статус «Не сдана» (кеги не были сданы в день вывоза).
-- Ставится рестораном (со след. дня после даты возврата, для маршрутизированной
-- заявки) или закупкой. Закупка может вернуть обратно в ROUTED. При переходе в
-- NOT_RETURNED уходит письмо бухгалтерии на адреса из settings
-- (key='keg_not_returned_emails'). Новые колонки NULL — обратная совместимость.

ALTER TABLE keg_returns
  MODIFY COLUMN status ENUM('DRAFT','SUBMITTED','ROUTED','CANCELLED','NOT_RETURNED') NOT NULL DEFAULT 'DRAFT';

ALTER TABLE keg_returns
  ADD COLUMN not_returned_at DATETIME NULL AFTER cancelled_at,
  ADD COLUMN not_returned_by VARCHAR(100) NULL AFTER not_returned_at;
