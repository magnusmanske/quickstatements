CREATE TABLE `batch` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ts_created` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ts_last_change` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_item` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `site` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'wikidata',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `user` (`user`(191)),
  KEY `ts_last_change` (`ts_last_change`),
  KEY `user_2` (`user`(160),`ts_last_change`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `command` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `num` int(11) NOT NULL,
  `json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` tinytext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ts_change` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `batch_id_2` (`batch_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `api_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `batch_oauth` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `serialized` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `serialized_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
