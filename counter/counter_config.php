<?php
// Конфигурация для нескольких сайтов
$sites_config = array (
  'main' => 
  array (
    'name' => 'Основной сайт',
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    'url' => 'https://kinoprostor.xyz',
    'color' => '#007bff',
  ),
  'blog' => 
  array (
    'name' => 'Блог',
    'db_host' => 'dj-x.info',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    'url' => 'https://dj-x.info',
    'color' => '#28a745',
  ),
);

// Общие настройки (применяются ко всем сайтам)
$config = array (
  'db_host' => 'localhost',
  'db_name' => 'site_counter',
  'db_user' => 'site_counter',
  'db_pass' => 'site_counter',
  'count_unique_ip' => true,
  'count_interval' => 86400,
  'excluded_ips' => 
  array (
    0 => '127.0.0.1',
  ),
  'mmdb_path' => 'GeoLite2-City.mmdb',
  'sxgeo_path' => 'SxGeoCity.dat',
  'use_external_api' => true,
  'api_url' => 'https://ipinfo.io/{ip}/json',
  'api_token' => '7575a9c65',
  'max_queue_size' => 1000,
  'queue_batch_size' => 50,
  'auto_process_chance' => 5,
  'cache_ttl' => 604800,
  'cleanup_chance' => 2,
  'counter_style' => 'simple',
  'items_per_page' => 25,
  'default_site' => 'main',
);

// Добавляем сайты обратно
$config['sites'] = $sites_config;
?>
