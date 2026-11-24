CREATE TABLE IF NOT EXISTS `xh_export_feeds` (
  `id_feed` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT '',
  `token_hash` char(64) NOT NULL,
  `token_hint` varchar(8) NOT NULL DEFAULT '',
  `only_in_stock` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `hits` int(11) NOT NULL DEFAULT 0,
  `last_hit` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_feed`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
