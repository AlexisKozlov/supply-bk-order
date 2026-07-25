-- Реестр Telegram-групп для FAQ-бота.
-- Групповой бот отдаёт данные (остатки/номенклатура/аналоги) только в
-- привязанных группах, и юрлицо в ответе ограничивается бизнесом группы
-- (BK_VM или PS) — чтобы группа одного бизнеса не видела данные другого.
CREATE TABLE IF NOT EXISTS tg_faq_groups (
  chat_id            BIGINT       NOT NULL PRIMARY KEY,
  legal_entity_group VARCHAR(16)  NOT NULL,           -- 'BK_VM' | 'PS'
  title              VARCHAR(255) DEFAULT NULL,        -- название группы (для админа)
  registered_by      VARCHAR(128) DEFAULT NULL,        -- кто привязал
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
