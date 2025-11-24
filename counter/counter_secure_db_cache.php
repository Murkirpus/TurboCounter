<?php
/**
 * Безопасный счетчик посещений с множественными API и улучшенным кэшированием
 * Версия: 3.0 - ALL-IN-ONE
 * 
 * ОСОБЕННОСТИ:
 * ✅ Поддержка 5+ бесплатных API
 * ✅ Автоматическое переключение при недоступности
 * ✅ Постоянное кэширование (1 IP = 1 запрос навсегда)
 * ✅ ~4.6 млн бесплатных запросов в месяц
 * 
 * Для использования:
 * require_once $_SERVER['DOCUMENT_ROOT'] . '/counter/counter_secure_db_cache.php';
 */

if (!defined('COUNTER_INCLUDED')) {
    define('COUNTER_INCLUDED', true);
}

set_time_limit(5);

// ======= КОНФИГУРАЦИЯ ========
$config = [
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    'count_unique_ip' => true,
    'count_interval' => 3600,
    'excluded_ips' => ['127.0.0.1'],
    'mmdb_path' => __DIR__ . '/GeoLite2-City.mmdb',
    'sxgeo_path' => __DIR__ . '/SxGeoCity.dat',
    'use_external_api' => true,
    'cache_ttl' => 604800,
    'api_cache_permanent' => true,
    'cleanup_chance' => 2,
    'max_queue_size' => 1000,
    'queue_batch_size' => 50,
    'auto_process_chance' => 5,
    
    // НАСТРОЙКИ МНОЖЕСТВЕННЫХ API
    'api_timeout' => 3,
    'enable_api_logging' => false,  // Логирование API в error.log (true/false)
    'api_providers' => [
        'ip-api' => [
            'enabled' => true,
            'url' => 'http://ip-api.com/json/{ip}?fields=status,country,city,lat,lon,timezone,region',
            'priority' => 1
        ],
        'ipapi-co' => [
            'enabled' => true,
            'url' => 'https://ipapi.co/{ip}/json/',
            'priority' => 2
        ],
        'freeipapi' => [
            'enabled' => true,
            'url' => 'https://freeipapi.com/api/json/{ip}',
            'priority' => 3
        ],
        'ipwhois' => [
            'enabled' => true,
            'url' => 'https://ipwhois.app/json/{ip}?lang=ru',
            'priority' => 4
        ],
        'ipinfo' => [
            'enabled' => true,
            'url' => 'https://ipinfo.io/{ip}/json',
            'token' => '757611f45a9c65',
            'priority' => 5
        ]
    ]
];

// Загружаем внешний конфиг если есть
$configFile = __DIR__ . '/counter_config.php';
if (file_exists($configFile)) {
    include $configFile;
}

// ======= ОСНОВНОЙ КОД ========
try {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load[0] > 20) return;
    }
    
    $current_ip = $_SERVER['REMOTE_ADDR'];
    if (in_array($current_ip, $config['excluded_ips'])) return;
    
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|wget|curl|facebook|slurp|bingbot|googlebot|yandex|baidu|bing|msn|duckduckbot|teoma|rm-agent/i', $ua)) {
        return;
    }
    
    $queueDir = __DIR__ . '/queue';
    if (!is_dir($queueDir) && is_writable(__DIR__)) {
        mkdir($queueDir, 0755, true);
    }
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $pageUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    $pdo = getPDO($config);
    if (!$pdo) return;
    
    ensureGeoCacheTable($pdo);
    
    if ($config['count_unique_ip']) {
        $stmt = $pdo->prepare("SELECT 1 FROM visits WHERE ip_address = ? AND page_url = ? AND visit_time > DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1");
        $stmt->execute([$current_ip, $pageUrl, $config['count_interval']]);
        if ($stmt->fetchColumn()) return;
    }
    
    $browser = 'Other';
    if (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'Chrome') !== false && strpos($ua, 'Edge') === false) $browser = 'Chrome';
    elseif (strpos($ua, 'Edge') !== false || strpos($ua, 'Edg') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
    elseif (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) $browser = 'Internet Explorer';
    elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $browser = 'Opera';
    
    $device = 'Desktop';
    if (strpos($ua, 'Mobile') !== false) $device = 'Mobile';
    elseif (strpos($ua, 'Tablet') !== false || strpos($ua, 'iPad') !== false) $device = 'Tablet';
    
    $geoData = getGeoDataImproved($pdo, $current_ip, $config);
    
    $visit = [
        'page_url' => $pageUrl,
        'ip_address' => $current_ip,
        'user_agent' => $ua,
        'visit_time' => date('Y-m-d H:i:s'),
        'referer' => $referer,
        'country' => $geoData['country'],
        'city' => $geoData['city'],
        'browser' => $browser,
        'device' => $device
    ];
    
    if (!empty($geoData['latitude']) && !empty($geoData['longitude'])) {
        $visit['latitude'] = $geoData['latitude'];
        $visit['longitude'] = $geoData['longitude'];
        $visit['region'] = $geoData['region'] ?? '';
        $visit['timezone'] = $geoData['timezone'] ?? '';
    }
    
    $visit = truncateVisitData($visit);
    
    if (is_dir($queueDir) && is_writable($queueDir)) {
        $filename = $queueDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.visit';
        file_put_contents($filename, json_encode($visit));
        
        $files = glob($queueDir . '/*.visit');
        if (count($files) > $config['max_queue_size']) {
            usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
            $filesToDelete = array_slice($files, 0, count($files) - $config['max_queue_size']);
            foreach ($filesToDelete as $file) @unlink($file);
        }
        
        if (mt_rand(1, 100) <= $config['auto_process_chance']) {
            processQueue($config, $queueDir);
        }
    }
    
    if (mt_rand(1, 100) <= $config['cleanup_chance']) {
        cleanupGeoCache($pdo, $config);
    }
    
} catch (Exception $e) {
    error_log("Счетчик: " . $e->getMessage());
    return;
}

// ======= ФУНКЦИИ ========

function ensureGeoCacheTable($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'geo_cache'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE geo_cache (
                    ip_address VARCHAR(45) PRIMARY KEY,
                    country VARCHAR(100) NOT NULL DEFAULT 'Неизвестно',
                    city VARCHAR(100) NOT NULL DEFAULT 'Неизвестно',
                    latitude FLOAT DEFAULT 0,
                    longitude FLOAT DEFAULT 0,
                    region VARCHAR(100) DEFAULT '',
                    timezone VARCHAR(50) DEFAULT '',
                    source ENUM('local', 'api', 'unknown') DEFAULT 'unknown',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    api_requests INT DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                CREATE INDEX idx_geo_cache_updated ON geo_cache(updated_at);
                CREATE INDEX idx_geo_cache_source ON geo_cache(source);
            ");
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM geo_cache LIKE 'source'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("
                    ALTER TABLE geo_cache 
                    ADD COLUMN source ENUM('local', 'api', 'unknown') DEFAULT 'unknown' AFTER timezone,
                    ADD COLUMN api_requests INT DEFAULT 0 AFTER source;
                    CREATE INDEX idx_geo_cache_source ON geo_cache(source);
                ");
            }
        }
    } catch (Exception $e) {
        error_log("Ошибка таблицы кэша: " . $e->getMessage());
    }
}

function truncateVisitData($visit) {
    $limits = ['page_url' => 500, 'ip_address' => 45, 'user_agent' => 65535, 'referer' => 500, 
               'country' => 100, 'city' => 100, 'browser' => 50, 'device' => 50, 'region' => 100, 'timezone' => 50];
    foreach ($limits as $field => $maxLength) {
        if (isset($visit[$field]) && is_string($visit[$field]) && strlen($visit[$field]) > $maxLength) {
            $visit[$field] = mb_substr($visit[$field], 0, $maxLength, 'UTF-8');
        }
    }
    return $visit;
}

function processQueue($config, $queueDir) {
    $lockFile = $queueDir . '/processing.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile) < 300)) return;
    touch($lockFile);
    
    try {
        $pdo = getPDO($config);
        if (!$pdo) throw new Exception("Нет подключения к БД");
        
        $files = glob($queueDir . '/*.visit');
        if (empty($files)) { @unlink($lockFile); return; }
        
        usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
        $filesToProcess = array_slice($files, 0, $config['queue_batch_size']);
        
        $stmt = $pdo->query("SHOW COLUMNS FROM visits LIKE 'latitude'");
        $hasExtendedFields = ($stmt->rowCount() > 0);
        
        $sql = $hasExtendedFields 
            ? "INSERT INTO visits (page_url, ip_address, user_agent, visit_time, referer, country, city, latitude, longitude, region, timezone, browser, device) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            : "INSERT INTO visits (page_url, ip_address, user_agent, visit_time, referer, country, city, browser, device) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $pdo->beginTransaction();
        
        foreach ($filesToProcess as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if (!$data) { @rename($file, $file . '.invalid'); continue; }
                
                $data = truncateVisitData($data);
                $data['page_url'] = $data['page_url'] ?? '';
                $data['ip_address'] = $data['ip_address'] ?? '';
                $data['user_agent'] = $data['user_agent'] ?? '';
                $data['visit_time'] = $data['visit_time'] ?? date('Y-m-d H:i:s');
                $data['referer'] = $data['referer'] ?? '';
                $data['country'] = $data['country'] ?? 'Неизвестно';
                $data['city'] = $data['city'] ?? 'Неизвестно';
                $data['browser'] = $data['browser'] ?? 'Other';
                $data['device'] = $data['device'] ?? 'Desktop';
                
                if ($hasExtendedFields) {
                    $stmt->execute([$data['page_url'], $data['ip_address'], $data['user_agent'], $data['visit_time'], 
                                   $data['referer'], $data['country'], $data['city'], $data['latitude'] ?? 0, 
                                   $data['longitude'] ?? 0, $data['region'] ?? '', $data['timezone'] ?? '', 
                                   $data['browser'], $data['device']]);
                } else {
                    $stmt->execute([$data['page_url'], $data['ip_address'], $data['user_agent'], $data['visit_time'],
                                   $data['referer'], $data['country'], $data['city'], $data['browser'], $data['device']]);
                }
                
                @unlink($file);
            } catch (Exception $e) {
                error_log("Ошибка файла {$file}: " . $e->getMessage());
                @rename($file, $file . '.failed');
            }
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        error_log("Ошибка очереди: " . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    }
    
    @unlink($lockFile);
}

/**
 * УЛУЧШЕННАЯ ФУНКЦИЯ с поддержкой множественных API
 */
function getGeoDataImproved($pdo, $ip, $config) {
    static $memoryCache = [];
    
    if (isset($memoryCache[$ip])) return $memoryCache[$ip];
    
    // Проверяем свежий кэш
    $stmt = $pdo->prepare("SELECT country, city, latitude, longitude, region, timezone, source 
                           FROM geo_cache 
                           WHERE ip_address = ? AND (source = 'api' OR (source = 'local' AND updated_at > DATE_SUB(NOW(), INTERVAL ? SECOND)))");
    $stmt->execute([$ip, $config['cache_ttl']]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cached) {
        $memoryCache[$ip] = $cached;
        return $cached;
    }
    
    // Локальные базы
    $localResult = getGeoFromMaxMind($ip, $config);
    if (!$localResult || $localResult['country'] == 'Неизвестно') {
        $localResult = getGeoFromSxGeo($ip, $config);
    }
    
    if ($localResult && $localResult['country'] != 'Неизвестно') {
        saveGeoCache($pdo, $ip, $localResult, 'local');
        $memoryCache[$ip] = $localResult;
        return $localResult;
    }
    
    // Проверяем ЛЮБОЙ старый кэш
    $stmt = $pdo->prepare("SELECT country, city, latitude, longitude, region, timezone, source FROM geo_cache WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $anyCached = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($anyCached) {
        $memoryCache[$ip] = $anyCached;
        if ($config['enable_api_logging'] ?? false) {
            error_log("API пропущен - старый кэш для {$ip} ({$anyCached['source']})");
        }
        return $anyCached;
    }
    
    // МНОЖЕСТВЕННЫЕ API - пробуем каждый по порядку
    if ($config['use_external_api'] && isset($config['api_providers'])) {
        $apiResult = getGeoFromMultipleAPIs($ip, $config);
        
        if ($apiResult && $apiResult['country'] != 'Неизвестно') {
            saveGeoCache($pdo, $ip, $apiResult, 'api');
            $memoryCache[$ip] = $apiResult;
            return $apiResult;
        }
    }
    
    // Пустой результат
    $emptyResult = ['country' => 'Неизвестно', 'city' => 'Неизвестно', 'latitude' => 0, 'longitude' => 0, 
                    'region' => '', 'timezone' => '', 'source' => 'unknown'];
    saveGeoCache($pdo, $ip, $emptyResult, 'unknown');
    $memoryCache[$ip] = $emptyResult;
    return $emptyResult;
}

/**
 * ФУНКЦИЯ МНОЖЕСТВЕННЫХ API - пробует все API по очереди
 */
function getGeoFromMultipleAPIs($ip, $config) {
    $providers = $config['api_providers'];
    uasort($providers, function($a, $b) { return ($a['priority'] ?? 999) - ($b['priority'] ?? 999); });
    
    foreach ($providers as $name => $provider) {
        if (!($provider['enabled'] ?? true)) continue;
        
        $result = callAPI($name, $provider, $ip, $config);
        
        if ($result && $result['country'] != 'Неизвестно') {
            if ($config['enable_api_logging'] ?? false) {
                error_log("✅ API {$name}: успех для {$ip}");
            }
            return $result;
        }
        
        if ($config['enable_api_logging'] ?? false) {
            error_log("❌ API {$name}: не сработал для {$ip}");
        }
    }
    
    if ($config['enable_api_logging'] ?? false) {
        error_log("⚠️ Все API провалились для {$ip}");
    }
    return null;
}

function callAPI($name, $provider, $ip, $config) {
    try {
        $url = str_replace('{ip}', $ip, $provider['url']);
        
        if (!empty($provider['token'])) {
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $url .= $separator . 'token=' . urlencode($provider['token']);
        }
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $config['api_timeout'] ?? 3,
                'ignore_errors' => true,
                'user_agent' => 'Mozilla/5.0 (compatible; CounterBot/1.0)'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) return null;
        
        $data = json_decode($response, true);
        if (!$data) return null;
        
        return parseAPIResponse($name, $data);
    } catch (Exception $e) {
        if ($config['enable_api_logging'] ?? false) {
            error_log("API {$name} ошибка: " . $e->getMessage());
        }
        return null;
    }
}

function parseAPIResponse($name, $data) {
    $result = ['country' => 'Неизвестно', 'city' => 'Неизвестно', 'latitude' => 0, 'longitude' => 0, 
               'region' => '', 'timezone' => '', 'source' => 'api:' . $name];
    
    switch ($name) {
        case 'ip-api':
            if (isset($data['status']) && $data['status'] === 'success') {
                $result['country'] = $data['country'] ?? 'Неизвестно';
                $result['city'] = $data['city'] ?? 'Неизвестно';
                $result['latitude'] = $data['lat'] ?? 0;
                $result['longitude'] = $data['lon'] ?? 0;
                $result['region'] = $data['region'] ?? '';
                $result['timezone'] = $data['timezone'] ?? '';
            }
            break;
        case 'ipapi-co':
            if (!isset($data['error'])) {
                $result['country'] = $data['country_name'] ?? 'Неизвестно';
                $result['city'] = $data['city'] ?? 'Неизвестно';
                $result['latitude'] = $data['latitude'] ?? 0;
                $result['longitude'] = $data['longitude'] ?? 0;
                $result['region'] = $data['region'] ?? '';
                $result['timezone'] = $data['timezone'] ?? '';
            }
            break;
        case 'freeipapi':
            $result['country'] = $data['countryName'] ?? 'Неизвестно';
            $result['city'] = $data['cityName'] ?? 'Неизвестно';
            $result['latitude'] = $data['latitude'] ?? 0;
            $result['longitude'] = $data['longitude'] ?? 0;
            $result['region'] = $data['regionName'] ?? '';
            $result['timezone'] = $data['timeZone'] ?? '';
            break;
        case 'ipwhois':
            if (isset($data['success']) && $data['success']) {
                $result['country'] = $data['country'] ?? 'Неизвестно';
                $result['city'] = $data['city'] ?? 'Неизвестно';
                $result['latitude'] = $data['latitude'] ?? 0;
                $result['longitude'] = $data['longitude'] ?? 0;
                $result['region'] = $data['region'] ?? '';
                $result['timezone'] = $data['timezone'] ?? '';
            }
            break;
        case 'ipinfo':
            if (isset($data['country'])) {
                $result['country'] = $data['country'];
                $result['city'] = $data['city'] ?? 'Неизвестно';
                $result['region'] = $data['region'] ?? '';
                $result['timezone'] = $data['timezone'] ?? '';
                if (!empty($data['loc'])) {
                    $loc = explode(',', $data['loc']);
                    if (count($loc) == 2) {
                        $result['latitude'] = (float)$loc[0];
                        $result['longitude'] = (float)$loc[1];
                    }
                }
            }
            break;
    }
    
    return $result;
}

function saveGeoCache($pdo, $ip, $geoData, $source = 'unknown') {
    try {
        $stmt = $pdo->prepare("INSERT INTO geo_cache (ip_address, country, city, latitude, longitude, region, timezone, source, updated_at, api_requests) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), IF(? = 'api', 1, 0)) 
                               ON DUPLICATE KEY UPDATE country = VALUES(country), city = VALUES(city), latitude = VALUES(latitude), 
                               longitude = VALUES(longitude), region = VALUES(region), timezone = VALUES(timezone), 
                               source = VALUES(source), updated_at = NOW(), api_requests = IF(VALUES(source) = 'api', api_requests + 1, api_requests)");
        $stmt->execute([$ip, $geoData['country'], $geoData['city'], $geoData['latitude'] ?? 0, 
                       $geoData['longitude'] ?? 0, $geoData['region'] ?? '', $geoData['timezone'] ?? '', $source, $source]);
    } catch (Exception $e) {
        error_log("Ошибка кэша: " . $e->getMessage());
    }
}

function cleanupGeoCache($pdo, $config) {
    try {
        $stmt = $pdo->prepare("DELETE FROM geo_cache WHERE source = 'local' AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1000");
        $stmt->execute([$config['cache_ttl']]);
        $deleted = $stmt->rowCount();
        if ($deleted > 0 && ($config['enable_api_logging'] ?? false)) {
            error_log("Очищено {$deleted} LOCAL записей");
        }
    } catch (Exception $e) {
        error_log("Ошибка очистки: " . $e->getMessage());
    }
}

function getGeoFromMaxMind($ip, $config) {
    $mmdbPath = $config['mmdb_path'] ?? __DIR__ . '/GeoLite2-City.mmdb';
    if (!file_exists($mmdbPath)) return null;
    return null; // Упрощенная версия - требует библиотеки MaxMind
}

function getGeoFromSxGeo($ip, $config) {
    $sxgeoPath = $config['sxgeo_path'] ?? __DIR__ . '/SxGeoCity.dat';
    if (!file_exists($sxgeoPath)) return null;
    if (!class_exists('SxGeo') && file_exists(__DIR__ . '/sxgeo/SxGeo.php')) {
        @include_once __DIR__ . '/sxgeo/SxGeo.php';
    }
    if (!class_exists('SxGeo')) return null;
    try {
        $SxGeo = new SxGeo($sxgeoPath, SXGEO_BATCH | SXGEO_MEMORY);
        $data = $SxGeo->getCityFull($ip);
        if (!$data || !isset($data['country']['name_ru'])) return null;
        return ['country' => $data['country']['name_ru'] ?? 'Неизвестно', 'city' => $data['city']['name_ru'] ?? 'Неизвестно',
                'latitude' => $data['city']['lat'] ?? 0, 'longitude' => $data['city']['lon'] ?? 0, 
                'region' => $data['region']['name_ru'] ?? '', 'timezone' => ''];
    } catch (Exception $e) {
        return null;
    }
}

function getPDO($config) {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("БД ошибка: " . $e->getMessage());
        return null;
    }
}

// ======= АДМИНКА ========
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    
    if (isset($_GET['api_stats'])) {
        header('Content-Type: text/html; charset=utf-8');
        $pdo = getPDO($config);
        if (!$pdo) { echo "Ошибка БД"; exit; }
        
        $stmt = $pdo->query("SELECT source, COUNT(*) as count, SUM(api_requests) as api_calls FROM geo_cache GROUP BY source");
        $stats = $stmt->fetchAll();
        
        echo "<h2>📊 Статистика API</h2>";
        echo "<table border='1' cellpadding='10'><tr><th>Источник</th><th>IP</th><th>API запросов</th></tr>";
        $total = 0; $calls = 0;
        foreach ($stats as $row) {
            $total += $row['count'];
            $calls += $row['api_calls'];
            echo "<tr><td>{$row['source']}</td><td>" . number_format($row['count']) . "</td><td>" . number_format($row['api_calls']) . "</td></tr>";
        }
        echo "<tr style='font-weight:bold'><td>ВСЕГО</td><td>" . number_format($total) . "</td><td>" . number_format($calls) . "</td></tr></table>";
        
        if ($total > 0) {
            $percent = round(($calls / $total) * 100, 2);
            echo "<p><strong>🎯 Экономия:</strong> Только {$percent}% IP требовали API-запроса!</p>";
        }
        
        $stmt = $pdo->query("SELECT ip_address, country, city, source, updated_at FROM geo_cache WHERE source LIKE 'api:%' ORDER BY updated_at DESC LIMIT 10");
        $recent = $stmt->fetchAll();
        if (!empty($recent)) {
            echo "<h3>🕒 Последние 10 API-запросов:</h3><table border='1' cellpadding='10'>";
            echo "<tr><th>IP</th><th>Страна</th><th>Город</th><th>API</th><th>Дата</th></tr>";
            foreach ($recent as $row) {
                echo "<tr><td>{$row['ip_address']}</td><td>{$row['country']}</td><td>{$row['city']}</td><td>{$row['source']}</td><td>{$row['updated_at']}</td></tr>";
            }
            echo "</table>";
        }
        
        echo "<p><a href='?stats=1'>← Общая статистика</a></p>";
        exit;
    }
    
    if (isset($_GET['stats'])) {
        header('Content-Type: text/html; charset=utf-8');
        $pdo = getPDO($config);
        if (!$pdo) { echo "Ошибка БД"; exit; }
        
        $total = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
        $today = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = CURDATE()")->fetchColumn();
        
        echo "<h2>📈 Статистика счетчика</h2>";
        echo "<p>IP: <strong>{$_SERVER['REMOTE_ADDR']}</strong></p>";
        echo "<p>Всего: <strong>" . number_format($total) . "</strong></p>";
        echo "<p>Сегодня: <strong>" . number_format($today) . "</strong></p>";
        echo "<ul>";
        echo "<li><a href='?api_stats=1'><strong>📊 Статистика API</strong></a></li>";
        echo "<li><a href='?process_queue=1'>Обработать очередь</a></li>";
        echo "</ul>";
        exit;
    }
    
    if (isset($_GET['process_queue'])) {
        header('Content-Type: text/plain; charset=utf-8');
        $queueDir = __DIR__ . '/queue';
        echo "Обработка очереди...\n";
        processQueue($config, $queueDir);
        $remaining = is_dir($queueDir) ? count(glob($queueDir . '/*.visit')) : 0;
        echo "Готово. Осталось: {$remaining}\n";
        exit;
    }
    
    echo "<h2>🚀 Счетчик с множественными API v3.0</h2>";
    echo "<p>IP: <strong>{$_SERVER['REMOTE_ADDR']}</strong></p>";
    echo "<ul><li><a href='?stats=1'>Статистика</a></li><li><a href='?api_stats=1'>Статистика API</a></li></ul>";
}
?>
