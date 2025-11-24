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
    'db_host' => 'localhost',
    'db_name' => 'dj-x.info_counter',
    'db_user' => 'dj-x.info_counter',
    'db_pass' => 'dj-x.info_counter',
    'url' => 'https://dj-x.info',
    'color' => '#28a745',
  ),
  'murkir' => 
  array (
    'name' => 'DDoS Щит',
    'db_host' => 'localhost',
    'db_name' => 'murkir_counter',
    'db_user' => 'murkir_counter',
    'db_pass' => 'murkir_counter',
    'url' => 'https://murkir.pp.ua',
    'color' => '#ff7300',
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
  'api_token' => '757611333',
  'counter_style' => 'simple',
  'items_per_page' => 25,
  'max_queue_size' => 1000,
  'queue_batch_size' => 50,
  'auto_process_chance' => 5,
  'cache_ttl' => 604800,
  'cleanup_chance' => 2,
  'default_site' => 'main',
  'name' => 'Основной сайт',
  'url' => 'https://kinoprostor.xyz',
  'color' => '#007bff',
  
  // ========== НОВЫЕ НАСТРОЙКИ ДЛЯ МНОЖЕСТВЕННЫХ API ==========
  
  // Постоянное кэширование API-данных (важно!)
  'api_cache_permanent' => true,
  
  // Таймаут для API-запросов (секунды)
  'api_timeout' => 3,
  
  // Логирование API в error.log (true/false)
  // false = не писать в логи (рекомендуется для продакшена)
  // true = писать в логи (для отладки)
  'enable_api_logging' => false,
  
  // Настройка множественных API
  // Система автоматически пробует каждый API по порядку priority
  // Если один не работает - переключается на следующий
  'api_providers' => array(
    
    // 1. ip-api.com - ПЕРВЫЙ (БЕЗ РЕГИСТРАЦИИ, БЫСТРЫЙ)
    'ip-api' => array(
      'enabled' => true,
      'url' => 'http://ip-api.com/json/{ip}?fields=status,country,city,lat,lon,timezone,region',
      'priority' => 1,
      'limit' => '45/мин',
      'description' => 'Быстрый, без регистрации'
    ),
    
    // 2. ipapi.co - ВТОРОЙ (БЕЗ РЕГИСТРАЦИИ, HTTPS)
    'ipapi-co' => array(
      'enabled' => true,
      'url' => 'https://ipapi.co/{ip}/json/',
      'priority' => 2,
      'limit' => '1000/день',
      'description' => 'HTTPS, хороший лимит'
    ),
    
    // 3. freeipapi.com - ТРЕТИЙ (БЕЗ РЕГИСТРАЦИИ, КОММЕРЦИЯ OK)
    'freeipapi' => array(
      'enabled' => true,
      'url' => 'https://freeipapi.com/api/json/{ip}',
      'priority' => 3,
      'limit' => '60/мин',
      'description' => 'Коммерческое использование разрешено'
    ),
    
    // 4. ipwhois.io - ЧЕТВЕРТЫЙ (БЕЗ РЕГИСТРАЦИИ, РУССКИЙ)
    'ipwhois' => array(
      'enabled' => true,
      'url' => 'https://ipwhois.app/json/{ip}?lang=ru',
      'priority' => 4,
      'limit' => '10k/месяц',
      'description' => 'Русский язык, большой лимит'
    ),
    
    // 5. ipinfo.io - ПОСЛЕДНИЙ/ЗАПАСНОЙ (ВАШ ТОКЕН)
    'ipinfo' => array(
      'enabled' => true,
      'url' => 'https://ipinfo.io/{ip}/json',
      'token' => '757611f45a9c65',  // Ваш токен из api_token
      'priority' => 5,
      'limit' => '50k/месяц',
      'description' => 'Ваш основной API (используется последним как запасной)'
    )
  ),
);

// Добавляем сайты обратно
$config['sites'] = $sites_config;
?>
