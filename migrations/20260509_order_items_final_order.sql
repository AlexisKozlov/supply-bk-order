-- Миграция: точное восстановление количества заказа в штуках.
--
-- Зачем: до миграции в order_items хранились только qty_boxes (учётные коробки)
-- и qty_per_box. При сохранении заказа в режиме unit='pieces' с finalOrder=141
-- и qpb=10 в БД писалось qty_boxes = ceil(141/10) = 15. При загрузке обратно
-- finalOrder = 15 * 10 = 150 — заказ «дрейфует» вверх на 9 штук. Поля
-- final_order и unit_of_measure уже были в whitelist crud.php, но фактически
-- в таблице отсутствовали. Эта миграция их добавляет.
--
-- final_order — исходное количество, как ввёл пользователь (в той единице,
-- что указана в unit_of_measure). При unit='boxes' это equals qty_boxes.
-- При unit='pieces' это исходные штуки (без округления вверх через qpb).

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS final_order INT NULL AFTER transit,
    ADD COLUMN IF NOT EXISTS unit_of_measure VARCHAR(16) NULL AFTER final_order;
