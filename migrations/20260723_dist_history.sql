-- История действий в модуле распределения (dist_*).
-- Отдельный журнал модуля: кто, когда и что нажал в сессии распределения.
-- Записи НЕ удаляются вместе с сессией (нет FK CASCADE) — чтобы событие
-- «удалил сессию» тоже сохранялось.
CREATE TABLE IF NOT EXISTS dist_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  legal_entity_group VARCHAR(16) NOT NULL,
  action VARCHAR(32) NOT NULL,          -- session_created / session_deleted / session_closed /
                                        -- session_reopened / product_added / product_removed /
                                        -- note_saved / cell_shipped / cell_qty /
                                        -- cell_bulk_shipped / cell_bulk_import
  session_product_id INT NULL,          -- для действий по клетке/товару
  restaurant_number VARCHAR(16) NULL,   -- для действий по клетке/примечанию
  old_value VARCHAR(255) NULL,          -- было (для клеток)
  new_value VARCHAR(255) NULL,          -- стало (для клеток)
  detail VARCHAR(255) NULL,             -- имя сессии / «57 ресторанов» / «120 клеток» и т.п.
  user_name VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_session (session_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
