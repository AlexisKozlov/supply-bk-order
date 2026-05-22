-- search_logs: добавляем «кто искал» — закупщик или ресторан
-- Раньше страница поиска карточек была публичной и логировала только запрос.
-- Теперь поиск закрыт авторизацией, и мы хотим видеть в аудите, кто его сделал.
-- Старые записи остаются с NULL во всех новых полях — обратная совместимость есть.

ALTER TABLE search_logs
  ADD COLUMN searcher_kind      VARCHAR(20)  NULL COMMENT 'supply | restaurant',
  ADD COLUMN searcher_name      VARCHAR(200) NULL COMMENT 'Имя закупщика или ro:<номер ресторана>',
  ADD COLUMN restaurant_number  VARCHAR(20)  NULL COMMENT 'Номер ресторана, если искал ресторан',
  ADD COLUMN legal_entity       VARCHAR(100) NULL COMMENT 'Юрлицо, в контексте которого искали',
  ADD INDEX idx_search_logs_kind_date (searcher_kind, created_at);
