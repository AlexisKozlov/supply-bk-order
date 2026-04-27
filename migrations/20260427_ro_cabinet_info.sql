-- Важная информация в личном кабинете ресторанов.
-- Сообщения создаёт отдел закупок, рестораны видят их в отдельном разделе.

CREATE TABLE IF NOT EXISTS ro_cabinet_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  target_mode ENUM('all','group','restaurants') NOT NULL DEFAULT 'all',
  target_group VARCHAR(20) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  show_popup TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME DEFAULT NULL,
  created_by VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ro_cabinet_posts_public (is_published, published_at, deleted_at),
  KEY idx_ro_cabinet_posts_target (target_mode, target_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ro_cabinet_post_restaurants (
  post_id INT UNSIGNED NOT NULL,
  restaurant_number INT UNSIGNED NOT NULL,
  legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  PRIMARY KEY (post_id, restaurant_number, legal_entity_group),
  KEY idx_ro_cabinet_post_restaurants_rest (restaurant_number, legal_entity_group),
  CONSTRAINT fk_ro_cabinet_post_restaurants_post
    FOREIGN KEY (post_id) REFERENCES ro_cabinet_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ro_cabinet_post_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ro_cabinet_post_files_post (post_id),
  CONSTRAINT fk_ro_cabinet_post_files_post
    FOREIGN KEY (post_id) REFERENCES ro_cabinet_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ro_cabinet_post_reads (
  post_id INT UNSIGNED NOT NULL,
  restaurant_number INT UNSIGNED NOT NULL,
  legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM',
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, restaurant_number, legal_entity_group),
  KEY idx_ro_cabinet_post_reads_rest (restaurant_number, legal_entity_group),
  CONSTRAINT fk_ro_cabinet_post_reads_post
    FOREIGN KEY (post_id) REFERENCES ro_cabinet_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
