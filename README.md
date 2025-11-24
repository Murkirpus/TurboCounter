<img src="./2025-11-24.png" alt="Демонстрация" width="800">
# TurboCounter + many sites
🚀 Счетчик с множественными API v3.0 и постоянным кешированием!

Что делает:

API-запросы кэшируются навсегда
Повторные IP = 0 новых запросов
Один IP = максимум 1 запрос за все время

Экономия: ~98% 🎯

Обновлено 24.11.2025

Modern visitor counter with geolocation and DDoS protection
A powerful tool for tracking and analyzing website visits with low server load

## 📋 Overview

TurboCounter is an advanced solution for webmasters who need a reliable way to collect and analyze visitor statistics for their website. The counter is equipped with visitor geolocation functionality, data visualization, and an effective DDoS protection system.

## ✨ Features

- 🛡️ **DDoS Protection**: Optimized for high loads with an intelligent queueing system
- 🌍 **Visitor Geolocation**: Determine country, city, and coordinates by IP with high accuracy
- 🚀 **Database Caching**: High performance through multi-level caching
- 📊 **Administrative Panel**: Clear statistics with interactive charts and detailed tables
- 📱 **Device Recognition**: Accurate detection of browsers, mobile devices, and tablets
- 🗺️ **Map Visualization**: Display visitors on an interactive world map in real-time
- 🔍 **Traffic Source Analysis**: Detailed tracking of visitor acquisition channels
- 📈 **Period Comparison**: Powerful tools for analyzing traffic dynamics
- 🔥 **Activity Heatmaps**: Visualization of activity by hours and days of the week

## 🔧 Requirements

- PHP 7.0 or higher
- MySQL/MariaDB
- Access to server file system
- Permissions to create and write to directories
- Optional: ipinfo.io API key for enhanced geolocation

## 🚀 Installation and Setup

### 1. Database Preparation

Create a new database and user for the counter:

```sql
CREATE DATABASE site_counter;
CREATE USER 'site_counter'@'localhost' IDENTIFIED BY 'site_counter';
GRANT ALL PRIVILEGES ON site_counter.* TO 'site_counter'@'localhost';
FLUSH PRIVILEGES;
```

### 2. File Download

1. Download all files from the [GitHub repository](https://github.com/Murkirpus/TurboCounter) and extract them to the `/counter/` directory on your server.
2. Create the necessary directories:
   ```bash
   mkdir -p /path/to/your/site/counter/queue
   mkdir -p /path/to/your/site/counter/cache
   ```
3. Set the correct access permissions:
   ```bash
   chmod 755 /path/to/your/site/counter
   chmod 755 /path/to/your/site/counter/queue
   chmod 755 /path/to/your/site/counter/cache
   ```
4. Download geolocation files:
   - GeoLite2-City.mmdb (can be downloaded from the MaxMind website)
   - SxGeoCity.dat (can be downloaded from the sypexgeo.net website)

### 3. Configuration Setup

Create a `counter_config.php` file in the `/counter/` directory with the following content:

```php
<?php
$config = [
    // Database settings
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    
    // Counter settings
    'count_unique_ip' => true, // Count only unique IPs
    'count_interval' => 3600, // Interval in seconds for unique visits (1 hour)
    'excluded_ips' => ['127.0.0.1'], // IP addresses to exclude
    
    // Geolocation settings
    'mmdb_path' => __DIR__ . '/GeoLite2-City.mmdb', // Path to MaxMind GeoIP2 file
    'sxgeo_path' => __DIR__ . '/SxGeoCity.dat', // Path to SxGeo file
    'use_external_api' => true, // Use external API if local databases don't provide results
    'api_url' => 'https://ipinfo.io/{ip}/json',
    'api_token' => '', // Your API token
    
    // Protection settings
    'max_queue_size' => 1000, // Maximum number of files in the queue
    'queue_batch_size' => 50, // Number of records to process at once
    'auto_process_chance' => 5, // Probability of automatic queue processing (%)
    
    // Database cache settings
    'cache_ttl' => 604800, // Cache lifetime in seconds (7 days)
    'cleanup_chance' => 2,  // Probability of cleaning old cache records (%)
    
    // Display settings
    'counter_style' => 'digital', // simple, digital, modern, classic
    'items_per_page' => 25 // Number of items per page in admin panel
];
?>
```

### 4. Creating the Database Structure

Run the SQL script to create tables:

-- ============================================
-- SQL скрипт для создания таблиц счетчика
-- Версия: 3.2
-- ============================================

-- Использование базы данных (раскомментируйте и укажите имя вашей БД)
-- USE site_counter;

-- ============================================
-- 1. ТАБЛИЦА ПОСЕЩЕНИЙ (visits)
-- ============================================

```CREATE TABLE IF NOT EXISTS `visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_url` varchar(500) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text,
  `visit_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `referer` varchar(500) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Неизвестно',
  `city` varchar(100) DEFAULT 'Неизвестно',
  `browser` varchar(50) DEFAULT 'Other',
  `device` varchar(50) DEFAULT 'Desktop',
  `latitude` float DEFAULT 0,
  `longitude` float DEFAULT 0,
  `region` varchar(100) DEFAULT '',
  `timezone` varchar(50) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_visit_time` (`visit_time`),
  KEY `idx_page_url` (`page_url`(191)),
  KEY `idx_country` (`country`),
  KEY `idx_ip_time` (`ip_address`, `visit_time`),
  KEY `idx_ip_page_time` (`ip_address`, `page_url`(191), `visit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. ТАБЛИЦА КЭША ГЕОДАННЫХ (geo_cache)
-- ============================================

CREATE TABLE IF NOT EXISTS `geo_cache` (
  `ip_address` varchar(45) NOT NULL,
  `country` varchar(100) NOT NULL DEFAULT 'Неизвестно',
  `city` varchar(100) NOT NULL DEFAULT 'Неизвестно',
  `latitude` float DEFAULT 0,
  `longitude` float DEFAULT 0,
  `region` varchar(100) DEFAULT '',
  `timezone` varchar(50) DEFAULT '',
  `source` enum('local','api','unknown') DEFAULT 'unknown',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `api_requests` int(11) DEFAULT 0,
  PRIMARY KEY (`ip_address`),
  KEY `idx_geo_cache_updated` (`updated_at`),
  KEY `idx_geo_cache_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. ПРОВЕРКА СОЗДАННЫХ ТАБЛИЦ
-- ============================================

-- Раскомментируйте для проверки:
-- SHOW TABLES;
-- DESCRIBE visits;
-- DESCRIBE geo_cache;

-- ============================================
-- ГОТОВО!
-- ============================================
```
-- Таблицы созданы успешно.
-- Теперь можно использовать счетчик.

### 5. Creating an Admin User

To create an administrative panel user, you can use the built-in user manager:

1. Open in your browser:
```
http://your-site.com/counter/user_manager.php
```

2. Fill out the form to add a new user:
   - Username (e.g., `admin`)
   - Password
   - Email (optional)

3. Click the "Add User" button

After creating a user, you'll be able to log in to the administrative panel.

Alternatively, you can add a user directly via SQL:

```sql
INSERT INTO `users` (`username`, `password`, `email`) 
VALUES ('admin', '$2y$10$oCb0SzKqac8bM9WvFubkz.6jsLdj9eUWBXLZFLNCo1PN.UOkFyvHG', 'admin@example.com');
```

This will create a user with login `admin` and password `admin`. **Be sure to change the password after your first login!**

### 6. Connecting the Counter to Your Site

Add the following code to a file that should be included on all pages of your site (for example, in header.php or footer.php):

```php
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/counter/counter_secure_db_cache.php';
?>
```

## 📖 Usage

### Counting Visits

After installation, the counter will automatically begin collecting visitor statistics. No additional actions are required.

### Accessing the Administrative Panel

The administrative panel is available at:
```
http://your-site.com/counter/admin/
```

Use the login and password created earlier to log in.

### User Management

To manage administrative panel users, use the user manager interface:

```
http://your-site.com/counter/user_manager.php
```

With this tool you can:
- Add new users
- Modify existing user data
- Change user passwords
- Delete unwanted users

Note that the system will not allow you to delete the last remaining user to prevent loss of access to the administrative panel.

### Displaying the Counter on Your Site

To display the counter on your site, add:

```html
<img src="/counter/counter.php" alt="Visitor Counter">
```

You can change the counter style:

```html
<img src="/counter/counter.php?style=digital" alt="Digital Counter">
```

Available styles: `simple`, `digital`, `modern`, `classic`.

## 🔧 Troubleshooting

### Counter Not Displaying

- Check access permissions to the `/counter/` directory (should be 755)
- Make sure geolocation files exist and are readable
- Check PHP and web server error logs

### Geolocation Problems

- Make sure the paths to GeoLite2-City.mmdb and SxGeoCity.dat files are correctly specified
- Check that the files are not corrupted and have the current version
- When using an external API, make sure the token is correctly specified

### Errors in the Administrative Panel

- Check that the database structure is correctly created
- Make sure the database user has the necessary access rights
- Check that all necessary PHP modules are installed (PDO, JSON, cURL)

## 🛠️ Regular Maintenance

### Queue Processing

If queue mode is enabled, you need to periodically process accumulated data:

```
http://your-site.com/counter/counter_secure_db_cache.php?process_queue=1
```

### Cleaning Up Old Data

To clean up old data and free up space in the database:

```
http://your-site.com/counter/counter_secure_db_cache.php?cleanup=1
```

It is recommended to set up these operations through CRON for automatic execution.

## 🌐 Updating Geolocation Databases

Geolocation databases require regular updates:

- **MaxMind GeoLite2**: Update at least once a month from the [official website](https://dev.maxmind.com/geoip/geoip2/geolite2/)
- **SxGeo**: Update approximately once per quarter from the [developer's website](https://sypexgeo.net/)

## 🔒 Security

- Regularly update administrator passwords
- Restrict access to the `/counter/admin/` directory via .htaccess or web server configuration
- Set up backup for the counter database

## ❤️ Support the Project

If you like this project and want to support its development, you can make a donation via PayPal:

* PayPal: murkir@gmail.com

## 📄 License

This project is distributed under the MIT license. You are free to use, modify, and distribute it provided you retain the copyright information.

## 📞 Contacts

If you have questions or suggestions for improving the counter, please contact us:

- Email: murkir@gmail.com
- GitHub: [https://github.com/Murkirpus/TurboCounter](https://github.com/Murkirpus/TurboCounter)

---

**Note**: The MaxMind and SxGeo geolocation databases are third-party products and are distributed under their own licenses. Please review their terms of use.
