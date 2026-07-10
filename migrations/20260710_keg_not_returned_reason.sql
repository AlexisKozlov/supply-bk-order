-- Возврат кег: причина статуса «Не сдана».
-- Ресторан при отметке «Кеги не сдал» обязан указать причину (пресет + опц.
-- комментарий), закупка — по желанию. Храним готовую строку. NULL — не указана.

ALTER TABLE keg_returns
  ADD COLUMN not_returned_reason VARCHAR(500) NULL AFTER not_returned_by;
