-- Уведомления ресторану о правках закупщика в ЗАКРЫТОМ сборе остатков.
--
-- Закупщик правит цифры после закрытия (ресторан ошибся, прислал уточнение
-- позже). Ресторан закрытый сбор в кабинете не видит, поэтому о правке узнать
-- неоткуда. Шлём одно сообщение в Telegram — но не на каждую ячейку, иначе
-- при правке десяти позиций прилетит десять сообщений.
--
-- Правки копятся здесь, крон раз в несколько минут отправляет накопленное
-- одним сообщением и помечает отправленным.

CREATE TABLE IF NOT EXISTS sc_edit_notifications (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id     INT UNSIGNED NOT NULL,
    restaurant_number VARCHAR(20) NOT NULL,
    -- Накопленные изменения: [{product, was, now}] — что и на что поменяли.
    changes           TEXT NOT NULL,
    -- Момент последней правки: ждём тишины, чтобы не слать в середине работы.
    last_edit_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at           DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pending (collection_id, restaurant_number, sent_at),
    KEY idx_pending (sent_at, last_edit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
