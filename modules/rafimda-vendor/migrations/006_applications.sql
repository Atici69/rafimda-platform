CREATE TABLE IF NOT EXISTS `rafimda_vendor_applications` (
  `id_application` int(11) NOT NULL AUTO_INCREMENT,
  `app_no` varchar(24) NOT NULL DEFAULT '',
  `id_user` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `person_type` varchar(16) NOT NULL DEFAULT 'company',
  `contact_name` varchar(120) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `district` varchar(80) NOT NULL DEFAULT '',
  `categories` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'new',
  `admin_note` text DEFAULT NULL,
  `missing_docs` text DEFAULT NULL,
  `terms_version` varchar(32) NOT NULL DEFAULT '',
  `kvkk_ack_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) NOT NULL DEFAULT 0,
  `reviewed_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_application`),
  UNIQUE KEY `app_no` (`app_no`),
  KEY `id_user` (`id_user`),
  KEY `id_vendor` (`id_vendor`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_vendor_app_docs` (
  `id_doc` int(11) NOT NULL AUTO_INCREMENT,
  `id_application` int(11) NOT NULL,
  `doc_type` varchar(32) NOT NULL DEFAULT 'other',
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(190) NOT NULL DEFAULT '',
  `mime` varchar(64) NOT NULL DEFAULT '',
  `size` int(11) NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_doc`),
  KEY `id_application` (`id_application`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_vendor_app_log` (
  `id_app_log` int(11) NOT NULL AUTO_INCREMENT,
  `id_application` int(11) NOT NULL,
  `from_status` varchar(16) NOT NULL DEFAULT '',
  `to_status` varchar(16) NOT NULL DEFAULT '',
  `note` text DEFAULT NULL,
  `actor` varchar(16) NOT NULL DEFAULT 'system',
  `actor_id` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_app_log`),
  KEY `id_application` (`id_application`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_notifications` (
  `id_notification` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL DEFAULT 0,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `audience` varchar(16) NOT NULL DEFAULT 'user',
  `type` varchar(40) NOT NULL DEFAULT '',
  `title` varchar(190) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `link` varchar(255) NOT NULL DEFAULT '',
  `email_status` varchar(24) NOT NULL DEFAULT 'skipped',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_notification`),
  KEY `user_read` (`id_user`, `is_read`),
  KEY `vendor_read` (`id_vendor`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
