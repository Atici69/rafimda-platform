CREATE TABLE IF NOT EXISTS `rafimda_category_map` (
  `source_key` char(40) NOT NULL,
  `source_text` varchar(255) NOT NULL DEFAULT '',
  `id_category` int(11) NOT NULL DEFAULT 0,
  `suggest_id` int(11) NOT NULL DEFAULT 0,
  `origin` varchar(8) NOT NULL DEFAULT 'auto',
  `score` decimal(6,4) NOT NULL DEFAULT 0,
  `hits` int(11) NOT NULL DEFAULT 0,
  `date_upd` datetime DEFAULT NULL,
  PRIMARY KEY (`source_key`),
  KEY `origin` (`origin`),
  KEY `id_category` (`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
