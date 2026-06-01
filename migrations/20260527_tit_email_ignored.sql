-- Возможность скрывать непривязанные письма из UI («Пропустить»).
-- Сценарий: поставщик сам отправил заявку охране, или письмо неинтересно.
ALTER TABLE tit_email_log
  ADD COLUMN is_ignored TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD INDEX idx_titmail_ignored (is_ignored, status);
