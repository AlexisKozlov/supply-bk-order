-- Снимок отправки в заявках на пропуск: что именно ушло охране.
-- Раньше в логе хранились только адресаты и путь к xlsx, поэтому в карточке
-- отправленной заявки нельзя было увидеть сами данные (машины, тему, текст письма).
--
-- subject / body_text — письмо как оно ушло (тема и текст могут правиться перед отправкой);
-- payload_json — строки xlsx на момент отправки (plate_number, sms_number, start_time,
-- end_time, status, allow_company, warehause, supplier).
-- Для старых записей поля остаются пустыми — UI в этом случае показывает
-- текущий состав машин заявки с пометкой.
ALTER TABLE tit_send_log
  ADD COLUMN subject      VARCHAR(500) NULL AFTER file_path,
  ADD COLUMN body_text    TEXT         NULL AFTER subject,
  ADD COLUMN payload_json LONGTEXT     NULL AFTER body_text;
