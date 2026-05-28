-- Состояние Telegram-бота: «в каком режиме сейчас пользователь» + блокировки
-- AI-провайдеров. Заменяет файлы в /tmp, которые пропадают при перезагрузке
-- сервера или чистке tmpwatch и не имеют атомарной защиты от race conditions.
--
-- Хелперы — в api/includes/bot_state.php.
--
-- TTL обеспечивается полем expires_at: tgStateGet возвращает null если
-- expires_at < NOW(). Периодическая чистка протухших строк — отдельный
-- крон (или просто полагаемся на индекс idx_tg_state_expires).
--
-- Безопасна к откату: DROP TABLE возвращает всё как было; код устойчив к
-- отсутствию таблиц (хелперы внутри try/catch, fallback в null).

CREATE TABLE IF NOT EXISTS tg_state (
  chat_id    BIGINT       NOT NULL COMMENT 'Telegram chat_id',
  mode       VARCHAR(50)  NOT NULL COMMENT 'chat|corr|cards|import|sc|restord|soord|survey|rest_stock',
  payload    JSON         NULL     COMMENT 'Произвольное состояние режима',
  expires_at DATETIME     NULL     COMMENT 'TTL: после этого момента строка считается пустой',
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, mode),
  INDEX idx_tg_state_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Состояние диалога пользователя с Telegram-ботом (заменяет /tmp файлы)';

CREATE TABLE IF NOT EXISTS tg_provider_block (
  provider      VARCHAR(50)  NOT NULL COMMENT 'gemini|openrouter|groq',
  model         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'У groq — конкретная модель, у остальных пусто',
  blocked_until DATETIME     NOT NULL,
  reason        VARCHAR(255) NULL     COMMENT 'Опционально: код/описание причины блокировки',
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Блокировки AI-провайдеров (заменяет /tmp/gemini_blocked.txt и т.п.)';
