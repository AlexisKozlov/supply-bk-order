-- Журнал входов: привести сортировку к общей и добавить индекс.
--
-- Таблица login_log писалась с марта (527 записей на момент миграции), но
-- лежала в utf8mb4_general_ci, тогда как users — в utf8mb4_unicode_ci.
-- Любой JOIN по имени пользователя падал с «Illegal mix of collations»,
-- поэтому журнал так и не подключили ни к одному экрану: данные копились,
-- а посмотреть их было нельзя.
--
-- Индекс — чтобы «последний вход» по каждому сотруднику считался по ключу,
-- а не перебором всей таблицы.

ALTER TABLE login_log
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE login_log
  ADD INDEX idx_login_log_user_created (user_name, created_at);
