CREATE TABLE IF NOT EXISTS `rafimda_vendors` (
  `id_vendor` int(11) NOT NULL AUTO_INCREMENT,
  `store_name` varchar(120) NOT NULL,
  `store_slug` varchar(120) NOT NULL,
  `legal_name` varchar(190) NOT NULL DEFAULT '',
  `tax_office` varchar(120) NOT NULL DEFAULT '',
  `tax_number` varchar(32) NOT NULL DEFAULT '',
  `phone` varchar(32) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `city` varchar(80) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `plan_code` varchar(32) NOT NULL DEFAULT 'free',
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `owner_user_id` int(11) NOT NULL,
  `rejection_reason` varchar(255) NOT NULL DEFAULT '',
  `approved_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_vendor`),
  UNIQUE KEY `store_slug` (`store_slug`),
  KEY `status` (`status`),
  KEY `owner_user_id` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_vendor_staff` (
  `id_staff` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `role` varchar(16) NOT NULL DEFAULT 'viewer',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_staff`),
  UNIQUE KEY `vendor_user` (`id_vendor`,`id_user`),
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_vendor_products` (
  `id_product` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_product`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_audit_log` (
  `id_log` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) DEFAULT NULL,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `action` varchar(64) NOT NULL,
  `entity` varchar(32) NOT NULL DEFAULT '',
  `entity_id` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `meta` text DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  KEY `vendor_date` (`id_vendor`,`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
