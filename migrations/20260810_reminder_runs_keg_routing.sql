-- Новый вид напоминания: маршрутизация возвратов кег.
--
-- reminder_runs хранит «что уже отправлено», чтобы крон (он ходит каждые
-- 5 минут) не слал одно и то же по нескольку раз. Вид напоминания там —
-- ENUM, и без нового значения INSERT для keg_routing не прошёл бы:
-- MariaDB в строгом режиме отвечает ошибкой, в нестрогом молча пишет
-- пустую строку, и защита от повторов перестала бы работать.

ALTER TABLE reminder_runs
  MODIFY `reminder_kind`
    ENUM('supplier','main_delivery','keg_return','keg_invoice','keg_routing')
    NOT NULL DEFAULT 'supplier';
