-- Флаги для управления модулем «Возврат кег»:
--   * на уровне юрлица — keg_returns_enabled в ro_module_settings;
--   * на уровне ресторана — keg_returns_enabled в restaurants.
-- Кабинет ресторана показывает таб «Возврат кег» только если оба = 1
-- и pickup_weekdays задан.

ALTER TABLE ro_module_settings
  ADD COLUMN keg_returns_enabled TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Возврат кег включён для юрлица';

ALTER TABLE restaurants
  ADD COLUMN keg_returns_enabled TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Возврат кег включён для ресторана';
