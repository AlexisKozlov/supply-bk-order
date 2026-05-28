-- Журнал отправок Telegram-бота. Пишется автоматически из tg_client.php
-- после каждого вызова (sendMessage/edit/delete/answer/typing/document).
--
-- Зачем: сейчас все ошибки доставки видны только в error_log на сервере,
-- никто туда не смотрит, пока что-то не сломается. Эта таблица + страница
-- /admin?tab=tg-monitor показывают: сколько ушло сегодня, сколько упало,
-- по каким кодам, кто заблокировал бота.
--
-- Чистка: cron_telegram.php в начале прогона удаляет записи старше 30 дней.
-- При 10К сообщений в день таблица держится ~300К строк — нормально для
-- индексированного InnoDB.

CREATE TABLE IF NOT EXISTS tg_send_log (
  id          BIGINT       AUTO_INCREMENT PRIMARY KEY,
  method      VARCHAR(50)  NOT NULL COMMENT 'sendMessage|editMessageText|deleteMessage|sendDocument|sendChatAction|answerCallbackQuery|editMessageReplyMarkup',
  chat_id     BIGINT       NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  http_code   SMALLINT     NOT NULL DEFAULT 0,
  error_code  SMALLINT     NULL COMMENT 'Telegram error_code: 400/403/429/...',
  error_text  VARCHAR(255) NULL COMMENT 'Короткое описание ошибки от Telegram',
  ts          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tg_send_log_ts (ts),
  INDEX idx_tg_send_log_chat_ts (chat_id, ts),
  INDEX idx_tg_send_log_ok_ts (ok, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Журнал отправок Telegram-бота (метод, успех, http/error_code)';
