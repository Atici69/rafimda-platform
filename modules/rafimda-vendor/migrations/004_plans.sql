CREATE TABLE IF NOT EXISTS `rafimda_plans` (
  `id_plan` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_products` int(11) DEFAULT NULL,
  `max_xml_connections` int(11) DEFAULT NULL,
  `max_marketplace_accounts` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_plan`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_plan_log` (
  `id_plan_log` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `old_plan` varchar(32) NOT NULL DEFAULT '',
  `new_plan` varchar(32) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_plan_log`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `rafimda_vendors` ADD COLUMN `plan_expires_at` datetime DEFAULT NULL;
