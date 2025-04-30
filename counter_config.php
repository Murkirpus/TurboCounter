<?php
$config = [
    // Настройки базы данных
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    
    // Настройки счетчика
    'count_unique_ip' => true, // Подсчитывать только уникальные IP
    'count_interval' => 3600, // Интервал в секундах для уникальных посещений (1 час)
    'excluded_ips' => ['127.0.0.1'], // IP-адреса, которые не нужно учитывать
    
    // Настройки геолокации
    'mmdb_path' => __DIR__ . '/GeoLite2-City.mmdb', // Путь к файлу MaxMind GeoIP2
    'sxgeo_path' => __DIR__ . '/SxGeoCity.dat', // Путь к файлу SxGeo
    'use_external_api' => true, // Использовать внешний API если локальные базы не дали результат
    'api_url' => 'https://ipinfo.io/{ip}/json',
    'api_token' => '757611f45a9c61', // Ваш токен для API
    
    // Настройки защиты
    'max_queue_size' => 1000, // Максимальное количество файлов в очереди
    'queue_batch_size' => 50, // Количество записей для обработки за раз
    'auto_process_chance' => 5, // Вероятность автоматической обработки очереди (%)
    
    // Настройки кэша в БД
    'cache_ttl' => 604800, // Время жизни кэша в секундах (1 день)
    'cleanup_chance' => 2  // Вероятность очистки старых записей кэша (%)
];
?>
