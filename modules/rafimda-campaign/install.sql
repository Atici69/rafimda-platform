CREATE TABLE IF NOT EXISTS `rc_campaigns` (
  `id_campaign` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `type` varchar(24) NOT NULL,
  `title` varchar(120) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `benefit_days` int(11) NOT NULL DEFAULT 30,
  `calc_mode` varchar(16) NOT NULL DEFAULT 'fixed_rate',
  `value` decimal(5,2) NOT NULL DEFAULT 0.00,
  `min_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `max_orders` int(11) DEFAULT NULL,
  `new_vendor_days` int(11) DEFAULT NULL,
  `plan_codes` varchar(190) NOT NULL DEFAULT '',
  `vendor_ids` varchar(500) NOT NULL DEFAULT '',
  `auto_enroll` tinyint(1) NOT NULL DEFAULT 0,
  `vendor_can_join` tinyint(1) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campaign`),
  UNIQUE KEY `code` (`code`),
  KEY `type_active` (`type`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rc_enrollments` (
  `id_enrollment` int(11) NOT NULL AUTO_INCREMENT,
  `id_campaign` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL,
  `started_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `orders_used` int(11) NOT NULL DEFAULT 0,
  `source` varchar(12) NOT NULL DEFAULT 'auto',
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_enrollment`),
  UNIQUE KEY `campaign_vendor` (`id_campaign`,`id_vendor`),
  KEY `id_vendor` (`id_vendor`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rc_charges` (
  `id_charge` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_item` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) NOT NULL DEFAULT 'due',
  `id_campaign` int(11) NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_charge`),
  UNIQUE KEY `id_item` (`id_item`),
  KEY `status` (`status`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
