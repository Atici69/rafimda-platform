CREATE TABLE IF NOT EXISTS `rso_accounts` (
  `id_account` int(11) NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(16) NOT NULL DEFAULT 'platform',
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `platform` varchar(16) NOT NULL,
  `display_name` varchar(190) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'not_connected',
  `token_enc` text DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `scopes` varchar(500) NOT NULL DEFAULT '',
  `oauth_state` varchar(64) NOT NULL DEFAULT '',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_account`),
  UNIQUE KEY `owner_platform` (`owner_type`, `id_vendor`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rso_posts` (
  `id_post` int(11) NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(16) NOT NULL DEFAULT 'platform',
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `id_product` int(11) NOT NULL,
  `platform` varchar(16) NOT NULL,
  `format` varchar(16) NOT NULL,
  `title` varchar(190) NOT NULL DEFAULT '',
  `caption` text DEFAULT NULL,
  `hashtags` varchar(500) NOT NULL DEFAULT '',
  `media` text DEFAULT NULL,
  `price_snapshot` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `external_id` varchar(120) NOT NULL DEFAULT '',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `created_by` varchar(24) NOT NULL DEFAULT '',
  `approved_by` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_post`),
  KEY `status_time` (`status`, `scheduled_at`),
  KEY `product_platform` (`id_product`, `platform`),
  KEY `owner` (`owner_type`, `id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rso_events` (
  `id_event` int(11) NOT NULL AUTO_INCREMENT,
  `id_post` int(11) NOT NULL DEFAULT 0,
  `event` varchar(32) NOT NULL,
  `detail` varchar(1000) NOT NULL DEFAULT '',
  `is_error` tinyint(1) NOT NULL DEFAULT 0,
  `actor` varchar(24) NOT NULL DEFAULT 'system',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_event`),
  KEY `id_post` (`id_post`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rso_metrics` (
  `id_metric` int(11) NOT NULL AUTO_INCREMENT,
  `id_post` int(11) NOT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `reach` int(11) NOT NULL DEFAULT 0,
  `likes` int(11) NOT NULL DEFAULT 0,
  `comments` int(11) NOT NULL DEFAULT 0,
  `shares` int(11) NOT NULL DEFAULT 0,
  `fetched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_metric`),
  KEY `id_post` (`id_post`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
