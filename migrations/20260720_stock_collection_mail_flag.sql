-- Флаг «по этому сбору рассылку писем заказывал портал».
--
-- Зачем: крон догоняет письма о старте сбора (если фоновый запуск из портала
-- не сработал). Без флага под догон попадал ЛЮБОЙ активный сбор моложе суток,
-- в том числе созданный до появления рассылки. Теперь крон трогает только те
-- сборы, где рассылку действительно заказывали.
--
-- Существующим сборам ставим 1 только там, где письма уже уходили — чтобы
-- незавершённая рассылка всё-таки доехала, а остальных не задело.

ALTER TABLE `stock_collections`
  ADD COLUMN `mail_start_requested` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Портал заказывал рассылку писем о старте сбора' AFTER `deadline_at`;

UPDATE `stock_collections` sc
SET sc.mail_start_requested = 1
WHERE EXISTS (
  SELECT 1 FROM `stock_collection_mail_log` ml
  WHERE ml.collection_id = sc.id AND ml.kind = 'start'
);
