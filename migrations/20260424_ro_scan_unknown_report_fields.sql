-- Поля, которые заполняет ресторан при сообщении о ненайденном штрихкоде:
-- название товара, комментарий и путь к загруженному фото.

ALTER TABLE `ro_scan_unknown`
    ADD COLUMN `reporter_name` VARCHAR(500) DEFAULT NULL AFTER `notes`,
    ADD COLUMN `reporter_comment` VARCHAR(1000) DEFAULT NULL AFTER `reporter_name`,
    ADD COLUMN `reporter_photo_path` VARCHAR(500) DEFAULT NULL AFTER `reporter_comment`;
