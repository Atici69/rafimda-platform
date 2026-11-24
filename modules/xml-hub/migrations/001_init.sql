CREATE TABLE IF NOT EXISTS `xh_suppliers` (
  `id_supplier` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL,
  `website` varchar(190) NOT NULL DEFAULT '',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_supplier`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_connections` (
  `id_connection` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `id_supplier` int(11) NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL,
  `source_type` varchar(8) NOT NULL DEFAULT 'url',
  `url` varchar(1000) NOT NULL DEFAULT '',
  `file_path` varchar(190) NOT NULL DEFAULT '',
  `item_path` varchar(190) NOT NULL DEFAULT '',
  `mapping` mediumtext DEFAULT NULL,
  `options` text DEFAULT NULL,
  `interval_minutes` int(11) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'draft',
  `last_run_at` datetime DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_connection`),
  KEY `id_vendor` (`id_vendor`),
  KEY `due` (`status`,`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_items` (
  `id_item` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_connection` int(11) NOT NULL,
  `item_key` varchar(100) NOT NULL,
  `id_product` int(11) NOT NULL DEFAULT 0,
  `row_hash` char(40) NOT NULL DEFAULT '',
  `data` mediumtext DEFAULT NULL,
  `state` varchar(12) NOT NULL DEFAULT 'new',
  `error` varchar(255) NOT NULL DEFAULT '',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_run_id` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_item`),
  UNIQUE KEY `conn_key` (`id_connection`,`item_key`),
  KEY `id_product` (`id_product`),
  KEY `state` (`id_connection`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_runs` (
  `id_run` int(11) NOT NULL AUTO_INCREMENT,
  `id_connection` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `kind` varchar(8) NOT NULL DEFAULT 'import',
  `status` varchar(10) NOT NULL DEFAULT 'queued',
  `selected` mediumtext DEFAULT NULL,
  `snapshot_path` varchar(190) NOT NULL DEFAULT '',
  `item_offset` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `created` int(11) NOT NULL DEFAULT 0,
  `updated` int(11) NOT NULL DEFAULT 0,
  `unchanged` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `next_attempt_at` datetime DEFAULT NULL,
  `message` varchar(255) NOT NULL DEFAULT '',
  `lock_token` varchar(32) NOT NULL DEFAULT '',
  `locked_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_run`),
  KEY `id_connection` (`id_connection`),
  KEY `queue` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_errors` (
  `id_error` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_connection` int(11) NOT NULL,
  `id_run` int(11) NOT NULL DEFAULT 0,
  `item_key` varchar(100) NOT NULL DEFAULT '',
  `level` varchar(8) NOT NULL DEFAULT 'error',
  `message` varchar(500) NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_error`),
  KEY `id_connection` (`id_connection`,`id_error`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_price_history` (
  `id_history` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_product` int(11) NOT NULL,
  `id_connection` int(11) NOT NULL,
  `id_run` int(11) NOT NULL DEFAULT 0,
  `old_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_history`),
  KEY `id_product` (`id_product`,`id_history`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xh_stock_history` (
  `id_history` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_product` int(11) NOT NULL,
  `id_connection` int(11) NOT NULL,
  `id_run` int(11) NOT NULL DEFAULT 0,
  `old_value` decimal(12,3) NOT NULL DEFAULT 0.000,
  `new_value` decimal(12,3) NOT NULL DEFAULT 0.000,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_history`),
  KEY `id_product` (`id_product`,`id_history`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
