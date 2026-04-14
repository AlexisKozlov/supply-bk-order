ALTER TABLE ro_deadline_overrides
  ADD COLUMN IF NOT EXISTS is_open TINYINT(1) NOT NULL DEFAULT 1
  AFTER delivery_date;
