CREATE TABLE IF NOT EXISTS `rafimda_commission_rules` (
  `id_rule` int(11) NOT NULL AUTO_INCREMENT,
  `scope` varchar(16) NOT NULL,
  `id_target` int(11) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `include_children` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `note` varchar(255) NOT NULL DEFAULT '',
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_rule`),
  UNIQUE KEY `scope_target` (`scope`,`id_target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `rafimda_vendor_order_items` ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL;

ALTER TABLE `rafimda_vendor_order_items` ADD COLUMN `commission_amount` decimal(12,2) DEFAULT NULL;

ALTER TABLE `rafimda_vendor_order_items` ADD COLUMN `commission_source` varchar(24) NOT NULL DEFAULT '';
