CREATE TABLE IF NOT EXISTS `rd_dealers` (
  `id_dealer` int(11) NOT NULL AUTO_INCREMENT,
  `owner_user_id` int(11) NOT NULL,
  `id_parent` int(11) NOT NULL DEFAULT 0,
  `company_name` varchar(160) NOT NULL,
  `contact_name` varchar(120) NOT NULL DEFAULT '',
  `phone` varchar(32) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `city` varchar(80) NOT NULL DEFAULT '',
  `tax_office` varchar(120) NOT NULL DEFAULT '',
  `tax_number` varchar(32) NOT NULL DEFAULT '',
  `note` text DEFAULT NULL,
  `referral_code` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `paused_by_parent` tinyint(1) NOT NULL DEFAULT 0,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `parent_share_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `plan_code` varchar(32) NOT NULL DEFAULT '',
  `plan_expires_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) NOT NULL DEFAULT '',
  `approved_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_dealer`),
  UNIQUE KEY `owner_user_id` (`owner_user_id`),
  UNIQUE KEY `referral_code` (`referral_code`),
  KEY `id_parent` (`id_parent`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_plans` (
  `id_plan` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `monthly_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `max_sub_dealers` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_plan`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_subscriptions` (
  `id_sub` int(11) NOT NULL AUTO_INCREMENT,
  `id_dealer` int(11) NOT NULL,
  `plan_code` varchar(32) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(12) NOT NULL DEFAULT 'requested',
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_sub`),
  KEY `id_dealer` (`id_dealer`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_customers` (
  `id_rdc` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `id_dealer` int(11) NOT NULL,
  `source` varchar(12) NOT NULL DEFAULT 'ref',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_rdc`),
  UNIQUE KEY `id_user` (`id_user`),
  KEY `id_dealer` (`id_dealer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_commissions` (
  `id_commission` int(11) NOT NULL AUTO_INCREMENT,
  `id_dealer` int(11) NOT NULL,
  `id_source_dealer` int(11) NOT NULL,
  `id_order` int(11) NOT NULL,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `type` varchar(10) NOT NULL DEFAULT 'direct',
  `order_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_commission`),
  UNIQUE KEY `order_dealer_type` (`id_order`,`id_dealer`,`type`),
  KEY `id_dealer` (`id_dealer`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_audit_log` (
  `id_log` int(11) NOT NULL AUTO_INCREMENT,
  `id_dealer` int(11) DEFAULT NULL,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `id_admin` int(11) NOT NULL DEFAULT 0,
  `action` varchar(64) NOT NULL,
  `entity` varchar(32) NOT NULL DEFAULT '',
  `entity_id` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `meta` text DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  KEY `id_dealer` (`id_dealer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
