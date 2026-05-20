-- Мультисессии для кабинетов ресторанов.
--
-- Зачем: до этой миграции у ресторана была ровно одна активная сессия —
-- колонка ro_users.session_token. Любой новый вход затирал старую, в одном
-- ресторане мог работать только один сотрудник, и каждые 24 часа всех
-- выкидывало (жёсткий потолок в коде). Рестораны попросили возможность
-- одновременно работать с нескольких устройств и не входить заново каждый день.
--
-- Что меняется:
--   1. Сессии переезжают из ro_users в отдельную таблицу ro_user_sessions.
--      Несколько строк на одного ro_user_id = несколько устройств.
--   2. У каждой сессии теперь есть собственный expires_at (абсолютный потолок),
--      remember-флаг (24 часа или 30 дней), IP/UA для страницы «активные устройства».
--   3. Лимит активных сессий на ресторан — 5; шестой логин выбивает самую старую
--      (логика на стороне PHP, ENFORCE'ится при INSERT).
--
-- Совместимость:
--   - Колонки ro_users.session_token / session_active_until остаются NULL —
--     убирать их сейчас опасно: rpc.php (сброс пароля), импорт, отчёты могут
--     на них смотреть. Уберём отдельной миграцией после полного перехода.
--   - При накатывании миграции переносим действующие сессии в новую таблицу,
--     чтобы никого не выкинуть в момент деплоя.

CREATE TABLE IF NOT EXISTS ro_user_sessions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ro_user_id      INT UNSIGNED    NOT NULL,
  token           VARCHAR(64)     NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME     NOT NULL,
  remember        TINYINT(1)   NOT NULL DEFAULT 0,
  ip_address      VARCHAR(45)  NULL,
  user_agent      VARCHAR(512) NULL,
  device_label    VARCHAR(120) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ro_user_sessions_token (token),
  KEY idx_ro_user_sessions_user (ro_user_id, last_seen_at),
  KEY idx_ro_user_sessions_expires (expires_at),
  CONSTRAINT fk_ro_user_sessions_user
    FOREIGN KEY (ro_user_id) REFERENCES ro_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переносим действующие сессии. Условия:
--   - session_token не NULL (есть кому переезжать)
--   - session_active_until ещё в будущем (3-часовой таймер не истёк)
--   - last_login_at был не более 24 часов назад (старый абсолютный потолок)
-- Для перенесённых ставим expires_at = NOW() + 24 часа (старое поведение,
-- remember был выключен), чтобы поведение не сильно отличалось.
INSERT INTO ro_user_sessions (ro_user_id, token, created_at, last_seen_at, expires_at, remember, ip_address, user_agent)
SELECT
  ru.id,
  ru.session_token,
  COALESCE(ru.last_login_at, NOW()),
  NOW(),
  DATE_ADD(NOW(), INTERVAL 24 HOUR),
  0,
  NULL,
  NULL
FROM ro_users ru
WHERE ru.session_token IS NOT NULL
  AND ru.session_token <> ''
  AND ru.session_active_until IS NOT NULL
  AND ru.session_active_until > NOW()
  AND ru.last_login_at IS NOT NULL
  AND ru.last_login_at > (NOW() - INTERVAL 24 HOUR)
ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at);
