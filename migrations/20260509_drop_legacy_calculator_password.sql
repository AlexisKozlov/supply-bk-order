-- 2026-05-09: удаление общего пароля «Калькулятора заказов».
-- Endpoint check_legacy_password удалён из rpc.php в этом же релизе.
-- Все пользователи теперь заходят через индивидуальный логин (check_user_password).
DELETE FROM settings WHERE `key` = 'order_calculator_password';
