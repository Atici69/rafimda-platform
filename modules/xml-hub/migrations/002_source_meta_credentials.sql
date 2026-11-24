CREATE TABLE IF NOT EXISTS `xh_product_meta` (
  `id_product` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `source_type` varchar(10) NOT NULL DEFAULT 'manual',
  `id_connection` int(11) NOT NULL DEFAULT 0,
  `id_supplier` int(11) NOT NULL DEFAULT 0,
  `cargo_price` decimal(12,2) DEFAULT NULL,
  `desc_hash` char(40) NOT NULL DEFAULT '',
  `image_urls` mediumtext DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_product`),
  KEY `source` (`id_vendor`,`source_type`),
  KEY `id_connection` (`id_connection`),
  KEY `id_supplier` (`id_supplier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_credentials` (
  `id_connection` int(11) NOT NULL,
  `auth_type` varchar(10) NOT NULL DEFAULT 'basic',
  `username_enc` varchar(600) NOT NULL DEFAULT '',
  `password_enc` varchar(600) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_connection`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_changes` (
  `id_change` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_product` int(11) NOT NULL,
  `id_connection` int(11) NOT NULL DEFAULT 0,
  `id_run` int(11) NOT NULL DEFAULT 0,
  `kind` varchar(16) NOT NULL,
  `summary` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_change`),
  KEY `id_product` (`id_product`),
  KEY `id_connection` (`id_connection`,`id_run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
