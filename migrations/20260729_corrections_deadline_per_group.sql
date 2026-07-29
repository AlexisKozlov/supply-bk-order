-- Дедлайн корректировок — свой у каждой группы юрлиц.
--
-- Было: одно время на весь портал (corrections_deadline_time).
-- У «Бургер БК»/«Воглия Матта» и «Пицца Стар» разные графики работы,
-- поэтому время приёма корректировок должно настраиваться отдельно.
--
-- Новые ключи: corrections_deadline_time_BK_VM, corrections_deadline_time_PS.
-- Старый ключ остаётся запасным значением: если для группы время не
-- задано, берётся общее, и только потом — 10:00 из кода.

INSERT INTO app_settings (skey, svalue, updated_by)
SELECT 'corrections_deadline_time_BK_VM',
       COALESCE((SELECT svalue FROM app_settings s WHERE s.skey = 'corrections_deadline_time'), '10:00'),
       'migration'
ON DUPLICATE KEY UPDATE skey = skey;

INSERT INTO app_settings (skey, svalue, updated_by)
SELECT 'corrections_deadline_time_PS',
       COALESCE((SELECT svalue FROM app_settings s WHERE s.skey = 'corrections_deadline_time'), '10:00'),
       'migration'
ON DUPLICATE KEY UPDATE skey = skey;
