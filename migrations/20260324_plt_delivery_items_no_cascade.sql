-- Убираем каскадное удаление позиций поставок при удалении товара из справочника.
-- Теперь если товар удалён — позиция поставки сохраняется, product_id станет NULL.

ALTER TABLE `plt_delivery_items`
  DROP FOREIGN KEY `plt_delivery_items_ibfk_2`;

ALTER TABLE `plt_delivery_items`
  MODIFY `product_id` INT DEFAULT NULL,
  ADD CONSTRAINT `fk_plt_di_product`
    FOREIGN KEY (`product_id`) REFERENCES `plt_products`(`id`) ON DELETE SET NULL;
