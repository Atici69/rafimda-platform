CREATE TABLE IF NOT EXISTS `rafimda_vendor_orders` (
  `id_vendor_order` int(11) NOT NULL AUTO_INCREMENT,
  `id_order` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vendor_earning` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cargo_company` varchar(64) NOT NULL DEFAULT '',
  `tracking_number` varchar(64) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_vendor_order`),
  UNIQUE KEY `order_vendor` (`id_order`,`id_vendor`),
  KEY `id_vendor` (`id_vendor`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_vendor_order_items` (
  `id_item` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor_order` int(11) NOT NULL,
  `id_order_detail` int(11) NOT NULL,
  `id_product` int(11) NOT NULL,
  `product_name` varchar(190) NOT NULL DEFAULT '',
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_item`),
  UNIQUE KEY `order_detail` (`id_order_detail`),
  KEY `id_vendor_order` (`id_vendor_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_ledger` (
  `id_entry` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_vendor_order` int(11) DEFAULT NULL,
  `entry_type` varchar(16) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `available_at` datetime DEFAULT NULL,
  `ref_key` varchar(64) NOT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_entry`),
  UNIQUE KEY `ref_key` (`ref_key`),
  KEY `vendor_date` (`id_vendor`,`id_entry`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_payouts` (
  `id_payout` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference` varchar(64) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_payout`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
