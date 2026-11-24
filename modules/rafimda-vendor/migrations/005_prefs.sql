CREATE TABLE IF NOT EXISTS `rafimda_vendor_prefs` (
  `id_vendor` int(11) NOT NULL,
  `low_stock` int(11) NOT NULL DEFAULT 5,
  `default_cargo` varchar(60) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
