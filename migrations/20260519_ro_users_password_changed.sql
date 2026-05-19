-- Дата/время последней смены пароля кабинета ресторана.
-- Заполняется при смене пароля самим рестораном (change-password)
-- и при любом сбросе/установке пароля через админ-панель.

ALTER TABLE ro_users
  ADD COLUMN password_changed_at TIMESTAMP NULL AFTER last_seen_at;
