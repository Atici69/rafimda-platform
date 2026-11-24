ALTER TABLE `rafimda_vendor_products` ADD COLUMN `moderation` varchar(12) NOT NULL DEFAULT 'approved';
ALTER TABLE `rafimda_vendor_products` ADD COLUMN `moderation_note` varchar(255) NOT NULL DEFAULT '';
ALTER TABLE `rafimda_vendor_products` ADD COLUMN `moderated_at` datetime DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `rafimda_import_log` (
  `id_import` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `file_name` varchar(190) NOT NULL DEFAULT '',
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_created` int(11) NOT NULL DEFAULT 0,
  `rows_updated` int(11) NOT NULL DEFAULT 0,
  `rows_failed` int(11) NOT NULL DEFAULT 0,
  `errors` text DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_import`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
