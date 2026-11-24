CREATE TABLE IF NOT EXISTS `mh_accounts` (
  `id_account` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `marketplace` varchar(20) NOT NULL,
  `label` varchar(120) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'draft',
  `credentials_enc` mediumtext DEFAULT NULL,
  `last_test_at` datetime DEFAULT NULL,
  `last_test_ok` tinyint(1) NOT NULL DEFAULT 0,
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_account`),
  KEY `owner` (`id_vendor`,`marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mh_category_map` (
  `id_map` int(11) NOT NULL AUTO_INCREMENT,
  `id_account` int(11) NOT NULL,
  `id_category` int(11) NOT NULL,
  `remote_category_id` varchar(60) NOT NULL,
  `remote_category_name` varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY (`id_map`),
  UNIQUE KEY `acc_cat` (`id_account`,`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mh_listings` (
  `id_listing` int(11) NOT NULL AUTO_INCREMENT,
  `id_account` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `id_product` int(11) NOT NULL,
  `remote_id` varchar(100) NOT NULL DEFAULT '',
  `state` varchar(10) NOT NULL DEFAULT 'queued',
  `payload_hash` char(40) NOT NULL DEFAULT '',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_listing`),
  UNIQUE KEY `acc_prod` (`id_account`,`id_product`),
  KEY `due` (`state`,`next_attempt_at`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mh_errors` (
  `id_error` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_account` int(11) NOT NULL,
  `id_product` int(11) NOT NULL DEFAULT 0,
  `code` varchar(30) NOT NULL DEFAULT '',
  `message` varchar(255) NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_error`),
  KEY `id_account` (`id_account`,`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
