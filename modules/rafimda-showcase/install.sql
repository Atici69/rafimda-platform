CREATE TABLE IF NOT EXISTS `rs_slots` (
  `code` varchar(32) NOT NULL,
  `title` varchar(80) NOT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'product',
  `max_items` int(11) NOT NULL DEFAULT 8,
  `vendor_requestable` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rs_items` (
  `id_item` int(11) NOT NULL AUTO_INCREMENT,
  `slot_code` varchar(32) NOT NULL,
  `id_vendor` int(11) NOT NULL DEFAULT 0,
  `id_product` int(11) NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `image_url` varchar(255) NOT NULL DEFAULT '',
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `position` int(11) NOT NULL DEFAULT 0,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `note` varchar(255) NOT NULL DEFAULT '',
  `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_item`),
  KEY `slot` (`slot_code`,`status`),
  KEY `id_vendor` (`id_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
