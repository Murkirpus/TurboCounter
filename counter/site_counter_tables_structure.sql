CREATE TABLE `geo_cache` (
  `ip_address` varchar(45) NOT NULL,
  `country` varchar(100) NOT NULL DEFAULT 'Неизвестно',
  `city` varchar(100) NOT NULL DEFAULT 'Неизвестно',
  `latitude` float DEFAULT 0,
  `longitude` float DEFAULT 0,
  `region` varchar(100) DEFAULT '',
  `timezone` varchar(50) DEFAULT '',
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ip_address`),
  KEY `idx_geo_cache_updated` (`updated_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `geo_ip_cache` (
  `ip_address` varchar(45) NOT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `latitude` float DEFAULT NULL,
  `longitude` float DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `last_updated` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ip_address`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_url` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `visit_time` datetime NOT NULL,
  `referer` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `latitude` float DEFAULT NULL,
  `longitude` float DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `device` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`visit_time`),
  KEY `idx_visit_time` (`visit_time`)
) ENGINE=MyISAM AUTO_INCREMENT=38594 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
