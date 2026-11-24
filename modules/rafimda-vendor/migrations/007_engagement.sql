CREATE TABLE IF NOT EXISTS `rafimda_store_profile` (
  `id_vendor` int(11) NOT NULL,
  `logo` varchar(190) NOT NULL DEFAULT '',
  `cover` varchar(190) NOT NULL DEFAULT '',
  `about` text DEFAULT NULL,
  `shipping_note` varchar(255) NOT NULL DEFAULT '',
  `return_note` varchar(255) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_follows` (
  `id_follow` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_follow`),
  UNIQUE KEY `user_vendor` (`id_user`, `id_vendor`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_question_meta` (
  `id_question` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `answered_by` varchar(16) NOT NULL DEFAULT '',
  `answered_user` int(11) NOT NULL DEFAULT 0,
  `masked` tinyint(1) NOT NULL DEFAULT 0,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_question`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_store_questions` (
  `id_store_question` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `author_name` varchar(128) NOT NULL DEFAULT '',
  `question` text NOT NULL,
  `answer` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `answered_user` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_answer` datetime DEFAULT NULL,
  PRIMARY KEY (`id_store_question`),
  KEY `vendor_status` (`id_vendor`, `status`),
  KEY `id_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_review_meta` (
  `id_review` int(11) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `id_order` int(11) NOT NULL DEFAULT 0,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `photos` text DEFAULT NULL,
  `helpful` int(11) NOT NULL DEFAULT 0,
  `vendor_reply` text DEFAULT NULL,
  `vendor_reply_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_review`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_review_votes` (
  `id_vote` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(8) NOT NULL DEFAULT 'product',
  `id_review` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_vote`),
  UNIQUE KEY `kind_review_user` (`kind`, `id_review`, `id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_store_reviews` (
  `id_store_review` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendor` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `id_order` int(11) NOT NULL,
  `author_name` varchar(128) NOT NULL DEFAULT '',
  `rating` tinyint(1) NOT NULL DEFAULT 5,
  `comment` text NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `helpful` int(11) NOT NULL DEFAULT 0,
  `vendor_reply` text DEFAULT NULL,
  `vendor_reply_at` datetime DEFAULT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_store_review`),
  UNIQUE KEY `vendor_order_user` (`id_vendor`, `id_order`, `id_user`),
  KEY `vendor_status` (`id_vendor`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_tickets` (
  `id_ticket` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_no` varchar(24) NOT NULL DEFAULT '',
  `opener` varchar(16) NOT NULL DEFAULT 'customer',
  `id_user` int(11) NOT NULL DEFAULT 0,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `category` varchar(32) NOT NULL DEFAULT 'other',
  `subject` varchar(190) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `id_order` int(11) NOT NULL DEFAULT 0,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_ticket`),
  UNIQUE KEY `ticket_no` (`ticket_no`),
  KEY `id_user` (`id_user`),
  KEY `id_vendor` (`id_vendor`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rafimda_ticket_messages` (
  `id_ticket_message` int(11) NOT NULL AUTO_INCREMENT,
  `id_ticket` int(11) NOT NULL,
  `author` varchar(16) NOT NULL DEFAULT 'customer',
  `author_id` int(11) NOT NULL DEFAULT 0,
  `body` text NOT NULL,
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_ticket_message`),
  KEY `id_ticket` (`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
