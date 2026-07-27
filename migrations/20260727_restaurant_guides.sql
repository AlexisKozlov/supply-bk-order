-- Инструкции для ресторанов: как работать с кабинетом закупок.
--
-- Раньше подсказки жили внутри вкладок (модалки «Как работают корректировки»
-- и т.п.), а «Помощь» вела только в Telegram. Отдельного места, где ресторан
-- может спокойно прочитать порядок работы, не было.
--
-- Тексты правит закупщик из админки, поэтому хранятся в базе, а не в коде.

CREATE TABLE IF NOT EXISTS ro_guides (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title           VARCHAR(255) NOT NULL,
    -- Короткое описание под заголовком в списке тем.
    summary         VARCHAR(500) NOT NULL DEFAULT '',
    -- Имя иконки из src/lib/icons.js — чтобы список тем читался с одного взгляда.
    icon_key        VARCHAR(40) NOT NULL DEFAULT 'document',
    sort_order      INT NOT NULL DEFAULT 0,
    -- Кому показывать: NULL — всем, иначе код группы юрлиц (BK_VM / PS).
    -- У Пиццы Стар свои модули (нет кег), поэтому инструкции могут отличаться.
    target_group    VARCHAR(20) DEFAULT NULL,
    is_published    TINYINT(1) NOT NULL DEFAULT 0,
    created_by      VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_guides_list (is_published, deleted_at, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаги инструкции: короткий текст и при необходимости скриншот.
CREATE TABLE IF NOT EXISTS ro_guide_steps (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    guide_id        INT UNSIGNED NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    -- Заголовок шага необязателен: бывают просто пояснения без действия.
    title           VARCHAR(255) NOT NULL DEFAULT '',
    body            TEXT NOT NULL,
    -- Путь относительно api/: uploads/restaurant_guides/xxx.png
    image_path      VARCHAR(500) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_steps_guide (guide_id, sort_order),
    CONSTRAINT fk_guide_steps_guide FOREIGN KEY (guide_id)
        REFERENCES ro_guides (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
