-- «Постоянные получатели в копию» для отправки заявок поставщикам по email.
--
-- В карточке поставщика заполняется список email через запятую
-- (бухгалтерия, координатор и т.д.) — они автоматически попадают в CC,
-- когда закупщик жмёт «Email с портала» в /order.
--
-- В лог отправок добавлен отдельный столбец cc_recipients — раньше
-- в order_email_log писались только адресаты To, и копии было не видно
-- при разборах.

ALTER TABLE `suppliers`
  ADD COLUMN `cc_emails` TEXT NULL DEFAULT NULL
  COMMENT 'Email через запятую — всегда в копии заявок поставщику';

ALTER TABLE `order_email_log`
  ADD COLUMN `cc_recipients` TEXT NULL DEFAULT NULL
  COMMENT 'email-адреса CC через запятую'
  AFTER `recipients`;
