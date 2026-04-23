-- Миграция: legal_entity_group в chat_conversations
--
-- Зачем: чат отдела закупок с ресторанами был общим — при переключении на
-- Пицца Стар видны чужие диалоги БК+ВМ. Добавляем колонку, backfill
-- через restaurants, новые сообщения бот будет ставить сразу.

ALTER TABLE chat_conversations
    ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM' AFTER restaurant_name;

CREATE INDEX idx_chat_conversations_le_group ON chat_conversations (legal_entity_group);

UPDATE chat_conversations cc
    LEFT JOIN restaurants r ON r.number = cc.restaurant_number AND r.active = 1
    SET cc.legal_entity_group = COALESCE(
        r.legal_entity_group,
        CASE WHEN CAST(cc.restaurant_number AS UNSIGNED) >= 1000 THEN 'PS' ELSE 'BK_VM' END
    );
