-- Добавляет колонку legal_entity_group в password_reset_codes.
--
-- Зачем: в БД может существовать одинаковый restaurant_number в разных
-- группах юрлиц (BK_VM и PS — например, при интеграции «Пицца Стар»).
-- Раньше логика сброса пароля смотрела только по restaurant_number, что
-- позволяло атакующему случайно (или намеренно) сбросить пароль не той
-- группе. Теперь все запросы фильтруют по паре (restaurant_number, group).
--
-- Бэкфилл: на момент миграции в таблице есть только BK_VM-номера, поэтому
-- безопасно поставить дефолт 'BK_VM' для существующих строк.

ALTER TABLE password_reset_codes
  ADD COLUMN legal_entity_group VARCHAR(20) NOT NULL DEFAULT 'BK_VM'
  COMMENT 'Группа юрлиц: BK_VM | PS — для разделения номеров между группами'
  AFTER restaurant_number;

-- Индекс для частых SELECT по паре (restaurant_number, legal_entity_group, code/reset_token)
ALTER TABLE password_reset_codes
  ADD INDEX idx_prc_rest_group (restaurant_number, legal_entity_group);
