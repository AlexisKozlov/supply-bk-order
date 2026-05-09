-- 2026-05-09: одноразовые download-токены для скачивания файлов.
-- Заменяют небезопасную передачу session_token в URL: токен короткоживущий
-- (15 минут), привязан к конкретному файлу, помечается used_at при первом
-- использовании (одноразовый). Утечка токена в логи nginx и Referer перестаёт
-- давать доступ к сессии пользователя.

CREATE TABLE IF NOT EXISTS download_tokens (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token CHAR(32) NOT NULL,
  user_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(64) NULL,
  UNIQUE KEY uk_token (token),
  KEY idx_expires (expires_at),
  KEY idx_user (user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
