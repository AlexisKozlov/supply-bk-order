-- Учёт установленного приложения (PWA) у ресторанов.
-- Кабинет при загрузке сообщает, что открыт с иконки на телефоне
-- (display-mode: standalone), и мы помечаем сессию этого устройства.
-- Нужно, чтобы закупка видела, кто уже поставил приложение, а кто нет.

ALTER TABLE ro_user_sessions
  ADD COLUMN is_pwa TINYINT(1) NOT NULL DEFAULT 0 AFTER remember,
  ADD COLUMN pwa_first_seen_at DATETIME DEFAULT NULL AFTER is_pwa;
