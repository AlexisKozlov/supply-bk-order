-- Файлы, приложенные к протоколу совещания.
--
-- Таблица давно работает на проде, но миграции в репозитории не было: её
-- создали руками. На чистой базе модуль протоколов падал при попытке
-- приложить или показать файл (rpc/protocols.php, includes/uploads.php).
-- Структура повторяет боевую один в один.
--
-- file_path — только имя файла в api/uploads/protocols/, сам файл лежит на
-- диске. При удалении протокола строки уносит каскад, а файлы с диска
-- подчищает delete_protocol.

CREATE TABLE IF NOT EXISTS `meeting_protocol_files` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `protocol_id` int(11)      NOT NULL,
  `file_name`   varchar(255) NOT NULL,
  `file_path`   varchar(255) NOT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime     DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mpf_proto` (`protocol_id`),
  CONSTRAINT `fk_mpf_proto` FOREIGN KEY (`protocol_id`) REFERENCES `meeting_protocols` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
