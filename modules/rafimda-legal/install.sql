CREATE TABLE IF NOT EXISTS `rl_documents` (
  `id_document` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `title` varchar(190) NOT NULL,
  `grp` varchar(24) NOT NULL DEFAULT 'customer',
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `needs_review` tinyint(1) NOT NULL DEFAULT 1,
  `review_note` varchar(255) NOT NULL DEFAULT '',
  `current_version` int(11) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_document`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rl_versions` (
  `id_version` int(11) NOT NULL AUTO_INCREMENT,
  `id_document` int(11) NOT NULL,
  `version` varchar(16) NOT NULL,
  `body` mediumtext NOT NULL,
  `change_note` varchar(255) NOT NULL DEFAULT '',
  `body_sha256` char(64) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_version`),
  KEY `id_document` (`id_document`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rl_acceptances` (
  `id_acceptance` int(11) NOT NULL AUTO_INCREMENT,
  `id_document` int(11) NOT NULL,
  `id_version` int(11) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `version` varchar(16) NOT NULL DEFAULT '',
  `id_user` int(11) NOT NULL DEFAULT 0,
  `context` varchar(24) NOT NULL DEFAULT '',
  `ref` varchar(64) NOT NULL DEFAULT '',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `ua_hash` char(64) NOT NULL DEFAULT '',
  `accepted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_acceptance`),
  KEY `user_doc` (`id_user`, `id_document`),
  KEY `ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rl_consents` (
  `id_consent` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `visitor` char(32) NOT NULL DEFAULT '',
  `consent_type` varchar(32) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(24) NOT NULL DEFAULT '',
  `id_version` int(11) NOT NULL DEFAULT 0,
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_consent`),
  KEY `user_type` (`id_user`, `consent_type`),
  KEY `visitor_type` (`visitor`, `consent_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
