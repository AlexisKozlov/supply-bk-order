-- Переименование: changed_by_chat_id хранил внутренний id ru_users, не chat_id Telegram.
-- Колонка переименовывается в changed_by_ru_user_id для соответствия реальному содержимому.
ALTER TABLE keg_return_bso_history
    CHANGE COLUMN changed_by_chat_id changed_by_ru_user_id INT NULL;
