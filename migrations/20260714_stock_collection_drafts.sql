-- Черновик остатков ресторана: то, что уже введено в форму, но ещё не сдано.
--
-- Зачем отдельная таблица, а не stock_collection_data: при отправке незаполненные
-- позиции уходят как 0 («остатков нет»), и такие данные сразу видит закупщик.
-- Черновик же хранит ровно то, что человек успел ввести, ничего не додумывает и
-- в отчёты не попадает.
--
-- Одна запись на пару «сбор + ресторан»: значения лежат внутри JSON (payload) —
-- сохранение черновика это один UPDATE, а не сотня строк на каждое нажатие клавиши.
CREATE TABLE IF NOT EXISTS stock_collection_drafts (
  id                INT(11)      NOT NULL AUTO_INCREMENT,
  collection_id     INT(11)      NOT NULL,
  restaurant_number VARCHAR(20)  NOT NULL,
  payload           LONGTEXT     NOT NULL,          -- {"<product_id>":[{"expiry_date":"2026-08-01","stock":"12"}]}
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sc_draft (collection_id, restaurant_number),
  KEY idx_sc_draft_coll (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
