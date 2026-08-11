-- Напоминания закупкам о немаршрутизированных возвратах кег.
--
-- Заявку на возврат подаёт ресторан, а машину и водителя проставляет отдел
-- закупок — это и есть маршрутизация (статус SUBMITTED → ROUTED). Если её не
-- сделать до вечера, наутро вывоз некому выполнять: на 11 августа сейчас
-- десять заявок и ни одной маршрутизированной.
--
-- Напоминания уходят не всем подряд, а отмеченным сотрудникам: список
-- отмечают в самом разделе «Возврат кег».
--
-- Храним имя пользователя, а не id: так же сделано в остальных местах
-- портала (audit_log.user_name, user_presence.user_name), и связь с users
-- идёт по name.

CREATE TABLE IF NOT EXISTS keg_routing_reminder_subs (
  user_name  VARCHAR(255) NOT NULL,
  is_enabled TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(255) NULL,
  PRIMARY KEY (user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
