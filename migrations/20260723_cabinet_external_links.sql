-- Управляемые внешние ссылки в кабинете ресторана (раньше — хардкод Лидское/Салатория).
-- Ссылка привязана к юрлицу; видимость дополнительно сужается по регионам/ресторанам
-- (как so_template_visibility).
CREATE TABLE IF NOT EXISTS cabinet_external_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legal_entity VARCHAR(100) NOT NULL,
  name VARCHAR(200) NOT NULL,
  url VARCHAR(500) NOT NULL,
  icon_key VARCHAR(40) NOT NULL DEFAULT 'package',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_le_active (legal_entity, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cabinet_external_link_visibility (
  link_id INT UNSIGNED NOT NULL,
  scope_type ENUM('region','restaurant') NOT NULL,
  scope_value VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_link_scope (link_id, scope_type, scope_value),
  KEY idx_link (link_id),
  CONSTRAINT fk_cel_visibility_link FOREIGN KEY (link_id) REFERENCES cabinet_external_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
