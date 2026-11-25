CREATE TABLE IF NOT EXISTS `mh_attribute_map` (
  `id_attr_map` int(11) NOT NULL AUTO_INCREMENT,
  `id_account` int(11) NOT NULL,
  `remote_category_id` varchar(60) NOT NULL,
  `attribute_id` varchar(60) NOT NULL,
  `attribute_name` varchar(120) NOT NULL DEFAULT '',
  `value_id` varchar(60) NOT NULL DEFAULT '',
  `value_text` varchar(190) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_attr_map`),
  UNIQUE KEY `acc_cat_attr` (`id_account`,`remote_category_id`,`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mh_brand_map` (
  `id_brand_map` int(11) NOT NULL AUTO_INCREMENT,
  `id_account` int(11) NOT NULL,
  `id_brand` int(11) NOT NULL,
  `remote_brand_id` varchar(60) NOT NULL,
  `remote_brand_name` varchar(190) NOT NULL DEFAULT '',
  `date_upd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_brand_map`),
  UNIQUE KEY `acc_brand` (`id_account`,`id_brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `mh_listings` ADD COLUMN `preview` mediumtext DEFAULT NULL;
