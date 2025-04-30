<?php
/**
 * Безопасный счетчик посещений с защитой от DDoS-атак
 * С кэшированием геоданных в базе данных вместо файлов
 * 
 * Для использования добавьте в свой сайт:
 * require_once $_SERVER['DOCUMENT_ROOT'] . '/counter/counter_secure_db_cache.php';
 */

// Запрещаем прямой доступ к файлу (если файл не включен через require)
if (!defined('COUNTER_INCLUDED')) {
    define('COUNTER_INCLUDED', true);
}

// Ограничиваем время выполнения
set_time_limit(5);

// ======= БЛОК КОНФИГУРАЦИИ ========
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
    'api_token' => '757611f45a9c65', // Ваш токен для API
    
    // Настройки защиты
    'max_queue_size' => 1000, // Максимальное количество файлов в очереди
    'queue_batch_size' => 50, // Количество записей для обработки за раз
    'auto_process_chance' => 5, // Вероятность автоматической обработки очереди (%)
    
    // Настройки кэша в БД
    'cache_ttl' => 604800, // Время жизни кэша в секундах (1 день)
    'cleanup_chance' => 2  // Вероятность очистки старых записей кэша (%)
];

// Загружаем сохраненные настройки, если они есть
$configFile = __DIR__ . '/counter_config.php';
if (file_exists($configFile)) {
    include $configFile;
}

// ======= ОСНОВНОЙ КОД СЧЕТЧИКА ========
try {
    // Проверяем нагрузку сервера
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load[0] > 20) {
            // При экстремальной нагрузке просто выходим
            return;
        }
    }
    
    // Проверяем, нужно ли исключить этот IP-адрес
    $current_ip = $_SERVER['REMOTE_ADDR'];
    if (in_array($current_ip, $config['excluded_ips'])) {
        return; // Заканчиваем выполнение скрипта
    }
    
    // Проверка на ботов (быстрая проверка, без подключения к БД)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|wget|curl|facebook|slurp|bingbot|googlebot|yandex|baidu|bing|msn|duckduckbot|teoma|rm-agent/i', $ua)) {
        return; // Пропускаем ботов
    }
    
    // Создаем директорию для очереди, если её нет
    $queueDir = __DIR__ . '/queue';
    if (!is_dir($queueDir) && is_writable(__DIR__)) {
        mkdir($queueDir, 0755, true);
    }
    
    // Получаем информацию о посетителе
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $pageUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    // Подключаемся к базе данных для проверки уникальности и получения геоданных
    $pdo = getPDO($config);
    if (!$pdo) {
        // Если не удалось подключиться к базе, выходим
        return;
    }
    
    // Проверяем наличие таблицы для кэша геоданных и создаем её, если нужно
    ensureGeoCacheTable($pdo);
    
    // Проверяем уникальность, если требуется
    if ($config['count_unique_ip']) {
        // Проверяем в БД, было ли посещение этой страницы с этого IP в заданном интервале
        $stmt = $pdo->prepare("
            SELECT 1 FROM visits 
            WHERE ip_address = ? 
            AND page_url = ? 
            AND visit_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            LIMIT 1
        ");
        $stmt->execute([$current_ip, $pageUrl, $config['count_interval']]);
        
        if ($stmt->fetchColumn()) {
            return; // Уже было посещение в заданный интервал
        }
    }
    
    // Определяем браузер
    $browser = 'Other';
    if (strpos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($ua, 'Chrome') !== false && strpos($ua, 'Edge') === false) {
        $browser = 'Chrome';
    } elseif (strpos($ua, 'Edge') !== false || strpos($ua, 'Edg') !== false) {
        $browser = 'Edge';
    } elseif (strpos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) {
        $browser = 'Internet Explorer';
    } elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) {
        $browser = 'Opera';
    }
    
    // Определяем устройство
    $device = 'Desktop';
    if (strpos($ua, 'Mobile') !== false) {
        $device = 'Mobile';
    } elseif (strpos($ua, 'Tablet') !== false || strpos($ua, 'iPad') !== false) {
        $device = 'Tablet';
    }
    
    // Получаем геоданные (с кэшированием в БД)
    $geoData = getGeoData($pdo, $current_ip, $config);
    
    // Создаем запись для очереди
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
    
    // Если есть координаты, добавляем их
    if (!empty($geoData['latitude']) && !empty($geoData['longitude'])) {
        $visit['latitude'] = $geoData['latitude'];
        $visit['longitude'] = $geoData['longitude'];
        $visit['region'] = $geoData['region'] ?? '';
        $visit['timezone'] = $geoData['timezone'] ?? '';
    }
    
    // Записываем в очередь
    if (is_dir($queueDir) && is_writable($queueDir)) {
        $filename = $queueDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.visit';
        file_put_contents($filename, json_encode($visit));
        
        // Проверяем, не слишком ли большая очередь
        $files = glob($queueDir . '/*.visit');
        if (count($files) > $config['max_queue_size']) {
            // Очищаем самые старые файлы
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $filesToDelete = array_slice($files, 0, count($files) - $config['max_queue_size']);
            foreach ($filesToDelete as $file) {
                @unlink($file);
            }
        }
        
        // С определенной вероятностью запускаем обработку очереди
        if (mt_rand(1, 100) <= $config['auto_process_chance']) {
            processQueue($config, $queueDir);
        }
    }
    
    // С определенной вероятностью запускаем очистку старых записей кэша
    if (mt_rand(1, 100) <= $config['cleanup_chance']) {
        cleanupGeoCache($pdo, $config);
    }
    
} catch (Exception $e) {
    // Изолируем ошибки счетчика от сайта
    error_log("Счетчик: " . $e->getMessage());
    return;
}

// ======= ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ========

/**
 * Проверка и создание таблицы кэша геоданных
 */
function ensureGeoCacheTable($pdo) {
    try {
        // Проверяем существование таблицы geo_cache
        $stmt = $pdo->query("SHOW TABLES LIKE 'geo_cache'");
        if ($stmt->rowCount() == 0) {
            // Создаем таблицу, если её нет
            $pdo->exec("
                CREATE TABLE geo_cache (
                    ip_address VARCHAR(45) PRIMARY KEY,
                    country VARCHAR(100) NOT NULL DEFAULT 'Неизвестно',
                    city VARCHAR(100) NOT NULL DEFAULT 'Неизвестно',
                    latitude FLOAT DEFAULT 0,
                    longitude FLOAT DEFAULT 0,
                    region VARCHAR(100) DEFAULT '',
                    timezone VARCHAR(50) DEFAULT '',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_geo_cache_updated ON geo_cache(updated_at);
            ");
        }
    } catch (Exception $e) {
        error_log("Ошибка создания таблицы кэша: " . $e->getMessage());
    }
}

/**
 * Обработка очереди посещений
 */
function processQueue($config, $queueDir) {
    $lockFile = $queueDir . '/processing.lock';
    
    // Проверяем, не обрабатывается ли очередь уже
    if (file_exists($lockFile)) {
        $lockTime = filemtime($lockFile);
        if (time() - $lockTime < 300) { // 5 минут
            return; // Другой процесс уже обрабатывает очередь
        }
    }
    
    // Создаем файл блокировки
    touch($lockFile);
    
    try {
        // Получаем соединение с БД
        $pdo = getPDO($config);
        if (!$pdo) {
            throw new Exception("Не удалось подключиться к базе данных");
        }
        
        // Получаем файлы для обработки
        $files = glob($queueDir . '/*.visit');
        if (empty($files)) {
            // Нет файлов для обработки
            @unlink($lockFile);
            return;
        }
        
        // Сортируем файлы по времени создания (сначала старые)
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Ограничиваем количество обрабатываемых файлов
        $filesToProcess = array_slice($files, 0, $config['queue_batch_size']);
        
        // Проверяем наличие расширенных полей в таблице
        $stmt = $pdo->query("SHOW COLUMNS FROM visits LIKE 'latitude'");
        $hasExtendedFields = ($stmt->rowCount() > 0);
        
        // Подготавливаем SQL запрос
        if ($hasExtendedFields) {
            $sql = "
                INSERT INTO visits (
                    page_url, ip_address, user_agent, visit_time, referer, 
                    country, city, latitude, longitude, region, timezone, browser, device
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
        } else {
            $sql = "
                INSERT INTO visits (
                    page_url, ip_address, user_agent, visit_time, referer, 
                    country, city, browser, device
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
        }
        
        $stmt = $pdo->prepare($sql);
        
        // Начинаем транзакцию
        $pdo->beginTransaction();
        $processed = 0;
        
        foreach ($filesToProcess as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if (!$data) continue;
                
                // Выполняем вставку
                if ($hasExtendedFields) {
                    $stmt->execute([
                        $data['page_url'],
                        $data['ip_address'],
                        $data['user_agent'],
                        $data['visit_time'],
                        $data['referer'],
                        $data['country'],
                        $data['city'],
                        $data['latitude'] ?? 0,
                        $data['longitude'] ?? 0,
                        $data['region'] ?? '',
                        $data['timezone'] ?? '',
                        $data['browser'],
                        $data['device']
                    ]);
                } else {
                    $stmt->execute([
                        $data['page_url'],
                        $data['ip_address'],
                        $data['user_agent'],
                        $data['visit_time'],
                        $data['referer'],
                        $data['country'],
                        $data['city'],
                        $data['browser'],
                        $data['device']
                    ]);
                }
                
                // Удаляем обработанный файл
                @unlink($file);
                $processed++;
            } catch (Exception $e) {
                error_log("Ошибка обработки файла {$file}: " . $e->getMessage());
                // Пропускаем проблемный файл
                @rename($file, $file . '.error');
            }
        }
        
        // Фиксируем транзакцию
        $pdo->commit();
        
    } catch (Exception $e) {
        // Логируем ошибку
        error_log("Ошибка при обработке очереди: " . $e->getMessage());
        
        // Откатываем транзакцию, если она активна
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    
    // Удаляем файл блокировки
    @unlink($lockFile);
}

/**
 * Получение геоданных по IP с кэшированием в базе данных и памяти PHP
 * 
 * @param PDO $pdo Объект соединения с базой данных
 * @param string $ip IP-адрес посетителя
 * @param array $config Массив с настройками
 * @return array Данные о местоположении
 */
function getGeoData($pdo, $ip, $config) {
    // Локальный кэш в памяти (только для текущего запроса PHP)
    static $memoryCache = [];
    
    // Проверяем кэш в памяти - самый быстрый вариант
    if (isset($memoryCache[$ip])) {
        return $memoryCache[$ip];
    }
    
    // Проверяем, есть ли данные в кэше БД
    $stmt = $pdo->prepare("
        SELECT country, city, latitude, longitude, region, timezone
        FROM geo_cache
        WHERE ip_address = ? AND updated_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, $config['cache_ttl']]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Если данные есть в кэше БД и они не устарели, сохраняем в памяти и возвращаем
    if ($cached) {
        $memoryCache[$ip] = $cached; // Сохраняем в памяти
        return $cached;
    }
    
    // Стандартный результат, если не найдем данные
    $result = [
        'country' => 'Неизвестно',
        'city' => 'Неизвестно',
        'latitude' => 0,
        'longitude' => 0,
        'region' => '',
        'timezone' => ''
    ];
    
    // 1. Сначала пробуем получить данные из MaxMind GeoIP2
    $mmdbResult = getGeoFromMaxMind($ip, $config);
    if ($mmdbResult && $mmdbResult['country'] != 'Неизвестно') {
        $result = $mmdbResult;
    }
    // 2. Если MaxMind не дал результатов, пробуем SxGeo
    else {
        $sxgeoResult = getGeoFromSxGeo($ip, $config);
        if ($sxgeoResult && $sxgeoResult['country'] != 'Неизвестно') {
            $result = $sxgeoResult;
        }
        // 3. Если локальные базы не сработали, пробуем внешний API
        elseif ($config['use_external_api']) {
            // Формируем URL запроса
            $url = str_replace('{ip}', $ip, $config['api_url']);
            
            // Добавляем токен, если он есть
            if (!empty($config['api_token'])) {
                $separator = (strpos($url, '?') !== false) ? '&' : '?';
                $url .= $separator . 'token=' . urlencode($config['api_token']);
            }
            
            // Делаем запрос к API
            $response = @file_get_contents($url);
            
            if ($response !== false) {
                $data = json_decode($response, true);
                
                if (isset($data['country'])) {
                    $result['country'] = $data['country'];
                    $result['city'] = $data['city'] ?? 'Неизвестно';
                    
                    // Извлекаем координаты из loc (для ipinfo.io)
                    if (!empty($data['loc'])) {
                        $loc = explode(',', $data['loc']);
                        if (count($loc) == 2) {
                            $result['latitude'] = (float)$loc[0];
                            $result['longitude'] = (float)$loc[1];
                        }
                    }
                    
                    $result['region'] = $data['region'] ?? '';
                    $result['timezone'] = $data['timezone'] ?? '';
                }
            }
        }
    }
    
    // Сохраняем результат в кэш базы данных
    try {
        $stmt = $pdo->prepare("
            INSERT INTO geo_cache (
                ip_address, country, city, latitude, longitude, region, timezone, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, NOW()
            ) ON DUPLICATE KEY UPDATE 
                country = VALUES(country),
                city = VALUES(city),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                region = VALUES(region),
                timezone = VALUES(timezone),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $ip,
            $result['country'],
            $result['city'],
            $result['latitude'],
            $result['longitude'],
            $result['region'],
            $result['timezone']
        ]);
    } catch (Exception $e) {
        error_log("Ошибка сохранения в кэш: " . $e->getMessage());
    }
    
    // Сохраняем в кэш памяти
    $memoryCache[$ip] = $result;
    
    return $result;
}

/**
 * Очистка старых записей кэша геоданных
 */
function cleanupGeoCache($pdo, $config) {
    try {
        // Удаляем записи старше указанного TTL (по умолчанию 1 день)
        $stmt = $pdo->prepare("
            DELETE FROM geo_cache 
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            LIMIT 1000
        ");
        $stmt->execute([$config['cache_ttl']]);
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            error_log("Очищено {$deleted} старых записей из кэша геоданных");
        }
    } catch (Exception $e) {
        error_log("Ошибка очистки кэша: " . $e->getMessage());
    }
}

/**
 * Получение геоданных из MaxMind GeoIP2
 */
function getGeoFromMaxMind($ip, $config) {
    $mmdbPath = $config['mmdb_path'] ?? __DIR__ . '/GeoLite2-City.mmdb';
    
    // Проверяем наличие файла базы данных
    if (!file_exists($mmdbPath)) {
        return null;
    }
    
    // Проверяем наличие необходимого класса
    if (!class_exists('MaxMind\\Db\\Reader') && file_exists(__DIR__ . '/vendor/autoload.php')) {
        // Если есть composer autoloader, пробуем загрузить через него
        @include_once __DIR__ . '/vendor/autoload.php';
    }
    
    // Если класс всё равно не найден, используем встроенный Reader
    if (!class_exists('MaxMind\\Db\\Reader')) {
        // Реализация простого ридера для MMDB
        if (!class_exists('SimpleMMDBReader')) {
            class SimpleMMDBReader {
                private $db;
                
                public function __construct($filename) {
                    $this->db = @fopen($filename, 'rb');
                    if (!$this->db) {
                        throw new Exception("Не удалось открыть файл базы данных");
                    }
                    
                    // Читаем метаданные и подготавливаем базу
                    // Упрощенная версия, для полной работы потребовалась бы полноценная реализация
                }
                
                public function get($ip) {
                    // Упрощенная реализация поиска по IP
                    // Для полноценной работы потребовалась бы более сложная логика
                    
                    // В данной упрощенной версии будем возвращать шаблонный результат
                    // для демонстрации структуры возвращаемых данных
                    
                    // Для некоторых тестовых IP возвращаем данные для примера
                    if ($ip == '8.8.8.8') {
                        return [
                            'country' => ['names' => ['en' => 'United States'], 'iso_code' => 'US'],
                            'city' => ['names' => ['en' => 'Mountain View']],
                            'location' => ['latitude' => 37.386, 'longitude' => -122.0838, 'time_zone' => 'America/Los_Angeles'],
                            'subdivisions' => [['names' => ['en' => 'California']]]
                        ];
                    }
                    
                    return null;
                }
                
                public function close() {
                    if ($this->db) {
                        fclose($this->db);
                    }
                }
            }
        }
        
        try {
            $reader = new SimpleMMDBReader($mmdbPath);
            $record = $reader->get($ip);
            $reader->close();
            
            if (!$record) {
                return null;
            }
            
            return [
                'country' => $record['country']['names']['en'] ?? $record['country']['iso_code'] ?? 'Неизвестно',
                'city' => $record['city']['names']['en'] ?? 'Неизвестно',
                'latitude' => $record['location']['latitude'] ?? 0,
                'longitude' => $record['location']['longitude'] ?? 0,
                'region' => isset($record['subdivisions'][0]['names']['en']) ? $record['subdivisions'][0]['names']['en'] : '',
                'timezone' => $record['location']['time_zone'] ?? ''
            ];
        } catch (Exception $e) {
            error_log("Ошибка при работе с MaxMind: " . $e->getMessage());
            return null;
        }
    } else {
        // Если класс найден, используем стандартный ридер MaxMind
        try {
            $reader = new \MaxMind\Db\Reader($mmdbPath);
            $record = $reader->get($ip);
            $reader->close();
            
            if (!$record) {
                return null;
            }
            
            return [
                'country' => $record['country']['names']['en'] ?? $record['country']['iso_code'] ?? 'Неизвестно',
                'city' => $record['city']['names']['en'] ?? 'Неизвестно',
                'latitude' => $record['location']['latitude'] ?? 0,
                'longitude' => $record['location']['longitude'] ?? 0,
                'region' => isset($record['subdivisions'][0]['names']['en']) ? $record['subdivisions'][0]['names']['en'] : '',
                'timezone' => $record['location']['time_zone'] ?? ''
            ];
        } catch (Exception $e) {
            error_log("Ошибка при работе с MaxMind: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Получение геоданных из SxGeoCity
 */
function getGeoFromSxGeo($ip, $config) {
    $sxgeoPath = $config['sxgeo_path'] ?? __DIR__ . '/SxGeoCity.dat';
    
    // Проверяем наличие файла базы данных
    if (!file_exists($sxgeoPath)) {
        return null;
    }
    
    // Проверяем наличие класса SxGeo
    if (!class_exists('SxGeo')) {
        // Если необходимого класса нет, попробуем его загрузить
        if (file_exists(__DIR__ . '/SxGeo.php')) {
            @include_once __DIR__ . '/SxGeo.php';
        } else {
            // Если файл класса отсутствует, используем упрощенную реализацию
            if (!class_exists('SimpleSxGeo')) {
                class SimpleSxGeo {
                    private $db;
                    
                    public function __construct($filename) {
                        $this->db = @fopen($filename, 'rb');
                        if (!$this->db) {
                            throw new Exception("Не удалось открыть файл базы данных");
                        }
                    }
                    
                    public function getCityFull($ip) {
                        // Упрощенная реализация поиска по IP
                        // Для полноценной работы потребовалась бы более сложная логика
                        
                        // Для некоторых тестовых IP возвращаем данные для примера
                        if ($ip == '8.8.8.8') {
                            return [
                                'country' => ['name_ru' => 'США', 'iso' => 'US'],
                                'city' => ['name_ru' => 'Маунтин-Вью', 'lat' => 37.386, 'lon' => -122.0838, 'timezone' => 'America/Los_Angeles'],
                                'region' => ['name_ru' => 'Калифорния']
                            ];
                        }
                        
                        return null;
                    }
                    
                    public function __destruct() {
                        if ($this->db) {
                            fclose($this->db);
                        }
                    }
                }
            }
            
            try {
                $sxgeo = new SimpleSxGeo($sxgeoPath);
                $record = $sxgeo->getCityFull($ip);
                
                if (!$record) {
                    return null;
                }
                
                return [
                    'country' => $record['country']['name_ru'] ?? $record['country']['iso'] ?? 'Неизвестно',
                    'city' => $record['city']['name_ru'] ?? 'Неизвестно',
                    'latitude' => $record['city']['lat'] ?? 0,
                    'longitude' => $record['city']['lon'] ?? 0,
                    'region' => $record['region']['name_ru'] ?? '',
                    'timezone' => $record['city']['timezone'] ?? ''
                ];
            } catch (Exception $e) {
                error_log("Ошибка при работе с SxGeo: " . $e->getMessage());
                return null;
            }
        }
    }
    
    // Используем стандартный SxGeo, если класс доступен
    if (class_exists('SxGeo')) {
        try {
            $sxgeo = new SxGeo($sxgeoPath);
            $record = $sxgeo->getCityFull($ip);
            
            if (!$record) {
                return null;
            }
            
            return [
                'country' => $record['country']['name_ru'] ?? $record['country']['iso'] ?? 'Неизвестно',
                'city' => $record['city']['name_ru'] ?? 'Неизвестно',
                'latitude' => $record['city']['lat'] ?? 0,
                'longitude' => $record['city']['lon'] ?? 0,
                'region' => $record['region']['name_ru'] ?? '',
                'timezone' => $record['city']['timezone'] ?? ''
            ];
        } catch (Exception $e) {
            error_log("Ошибка при работе с SxGeo: " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

/**
 * Получение соединения с базой данных
 */
function getPDO($config) {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
                $config['db_user'],
                $config['db_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Ошибка подключения к базе данных: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

// ======= КОД ДЛЯ ПРЯМОГО ВЫЗОВА СКРИПТА ========
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    
    if (isset($_GET['process_queue']) && $_GET['process_queue'] == '1') {
        // Ручной запуск обработки очереди
        header('Content-Type: text/plain; charset=utf-8');
        echo "Начинаем обработку очереди...\n";
        
        $pdo = getPDO($config);
        processQueue($config, __DIR__ . '/queue');
        
        echo "Обработка завершена.\n";
        exit;
    }
    
    if (isset($_GET['cleanup']) && $_GET['cleanup'] == '1') {
        // Ручная очистка старых данных
        header('Content-Type: text/plain; charset=utf-8');
        
        $pdo = getPDO($config);
        if (!$pdo) {
            echo "Ошибка подключения к базе данных\n";
            exit;
        }
        
        echo "Начинаем очистку старых данных...\n";
        
        // Очистка старых записей кэша геоданных
        try {
            $stmt = $pdo->prepare("
                DELETE FROM geo_cache 
                WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$config['cache_ttl']]);
            $deleted = $stmt->rowCount();
            echo "Удалено {$deleted} устаревших записей из кэша геоданных\n";
        } catch (Exception $e) {
            echo "Ошибка при очистке кэша геоданных: " . $e->getMessage() . "\n";
        }
        
        // Очистка старых записей из базы данных
        try {
            $stmt = $pdo->prepare("DELETE FROM visits WHERE visit_time < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1000");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            echo "Удалено {$deleted} старых записей из базы данных\n";
        } catch (Exception $e) {
            echo "Ошибка при очистке базы данных: " . $e->getMessage() . "\n";
        }
        
        // Очистка старых файлов с ошибками
        $queueDir = __DIR__ . '/queue';
        if (is_dir($queueDir)) {
            $errorFiles = glob($queueDir . '/*.error');
            $lastWeek = time() - 604800; // 7 дней
            $cleaned = 0;
            
            foreach ($errorFiles as $file) {
                if (filemtime($file) < $lastWeek) {
                    @unlink($file);
                    $cleaned++;
                }
            }
            
            echo "Очищено {$cleaned} старых файлов с ошибками\n";
        }
        
        echo "Очистка завершена\n";
        exit;
    }
    
    if (isset($_GET['migrate']) && $_GET['migrate'] == '1') {
        // Миграция кэша из файлов в базу данных
        header('Content-Type: text/plain; charset=utf-8');
        
        $pdo = getPDO($config);
        if (!$pdo) {
            echo "Ошибка подключения к базе данных\n";
            exit;
        }
        
        // Проверяем наличие таблицы для кэша геоданных
        ensureGeoCacheTable($pdo);
        
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            echo "Директория кэша не найдена\n";
            exit;
        }
        
        echo "Начинаем миграцию кэша из файлов в базу данных...\n";
        
        // Получаем все файлы кэша геоданных
        $cacheFiles = glob($cacheDir . '/geo_*.json');
        $total = count($cacheFiles);
        $migrated = 0;
        $failed = 0;
        
        foreach ($cacheFiles as $file) {
            $data = @json_decode(file_get_contents($file), true);
            if (!$data || !isset($data['country'])) {
                $failed++;
                continue;
            }
            
            // Извлекаем IP из имени файла
            $filename = basename($file);
            $ipHash = preg_replace('/^geo_(.+)\.json$/', '$1', $filename);
            
            // Ищем соответствующий IP в таблице visits
            $stmt = $pdo->prepare("
                SELECT DISTINCT ip_address 
                FROM visits 
                WHERE MD5(ip_address) = ?
                LIMIT 1
            ");
            $stmt->execute([$ipHash]);
            $ipResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ipResult) {
                $failed++;
                continue;
            }
            
            $ip = $ipResult['ip_address'];
            
            // Сохраняем в кэш БД
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO geo_cache (
                        ip_address, country, city, latitude, longitude, region, timezone, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, NOW()
                    ) ON DUPLICATE KEY UPDATE 
                        country = VALUES(country),
                        city = VALUES(city),
                        latitude = VALUES(latitude),
                        longitude = VALUES(longitude),
                        region = VALUES(region),
                        timezone = VALUES(timezone),
                        updated_at = NOW()
                ");
                
                $stmt->execute([
                    $ip,
                    $data['country'],
                    $data['city'],
                    $data['latitude'] ?? 0,
                    $data['longitude'] ?? 0,
                    $data['region'] ?? '',
                    $data['timezone'] ?? ''
                ]);
                
                $migrated++;
                
                // Удаляем файл после успешной миграции
                @unlink($file);
                
                // Выводим прогресс каждые 100 записей
                if ($migrated % 100 == 0) {
                    echo "Обработано $migrated из $total файлов\n";
                    // Сбрасываем буфер вывода
                    if (ob_get_level() > 0) {
                        ob_flush();
                        flush();
                    }
                }
                
            } catch (Exception $e) {
                error_log("Ошибка миграции файла $file: " . $e->getMessage());
                $failed++;
            }
        }
        
        echo "Миграция завершена. Успешно: $migrated, с ошибками: $failed из $total файлов\n";
        exit;
    }
    
    if (isset($_GET['stats']) && $_GET['stats'] == '1') {
        // Запрос статистики
        header('Content-Type: text/html; charset=utf-8');
        
        $pdo = getPDO($config);
        
        if (!$pdo) {
            echo "<h2>Ошибка подключения к базе данных</h2>";
            exit;
        }
        
        // Общая статистика
        $total = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
        $today = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = CURDATE()")->fetchColumn();
        $unique = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits")->fetchColumn();
        
        // Статистика кэша
        $cacheCount = $pdo->query("SELECT COUNT(*) FROM geo_cache")->fetchColumn();
        $cacheToday = $pdo->query("SELECT COUNT(*) FROM geo_cache WHERE DATE(updated_at) = CURDATE()")->fetchColumn();
        
        echo "<h2>Статистика счетчика посещений</h2>";
        echo "<p>Ваш IP: <strong>" . $_SERVER['REMOTE_ADDR'] . "</strong></p>";
        echo "<p>Всего посещений: <strong>" . number_format($total, 0, '.', ' ') . "</strong></p>";
        echo "<p>Посещений сегодня: <strong>" . number_format($today, 0, '.', ' ') . "</strong></p>";
        echo "<p>Уникальных посетителей: <strong>" . number_format($unique, 0, '.', ' ') . "</strong></p>";
        
        echo "<h3>Статистика кэша геоданных</h3>";
        echo "<p>Всего записей в кэше: <strong>" . number_format($cacheCount, 0, '.', ' ') . "</strong></p>";
        echo "<p>Обновлено сегодня: <strong>" . number_format($cacheToday, 0, '.', ' ') . "</strong></p>";
        
        // Состояние очереди
        $queueDir = __DIR__ . '/queue';
        $queueFiles = is_dir($queueDir) ? glob($queueDir . '/*.visit') : [];
        $errorFiles = is_dir($queueDir) ? glob($queueDir . '/*.error') : [];
        
        echo "<h3>Состояние очереди</h3>";
        echo "<p>Файлов в очереди: <strong>" . count($queueFiles) . "</strong></p>";
        echo "<p>Файлов с ошибками: <strong>" . count($errorFiles) . "</strong></p>";
        
        if (!empty($queueFiles)) {
            echo "<p><a href='?process_queue=1' class='btn'>Обработать очередь сейчас</a></p>";
        }
        
        echo "<h3>Обслуживание</h3>";
        echo "<ul>";
        echo "<li><a href='?cleanup=1'>Очистить старые данные</a></li>";
        echo "<li><a href='?migrate=1'>Перенести геоданные из файлов в базу</a></li>";
        echo "</ul>";
        
        // Добавляем немного стилей для красоты
        echo "<style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h2 { color: #333; }
            h3 { color: #555; margin-top: 20px; }
            p { margin: 10px 0; }
            .btn { 
                display: inline-block; 
                padding: 8px 16px; 
                background-color: #4CAF50; 
                color: white; 
                text-decoration: none; 
                border-radius: 4px; 
            }
            .btn:hover { background-color: #45a049; }
            ul { list-style-type: none; padding: 0; }
            li { margin: 8px 0; }
            li a { 
                color: #2196F3; 
                text-decoration: none; 
            }
            li a:hover { text-decoration: underline; }
        </style>";
        
        exit;
    }
    
    // Информация о скрипте
    header('Content-Type: text/html; charset=utf-8');
    echo "<h2>Безопасный счетчик посещений с кэшированием в БД</h2>";
    echo "<p>Ваш IP: <strong>" . $_SERVER['REMOTE_ADDR'] . "</strong></p>";
    echo "<p>Этот файл предназначен для подключения к другим страницам с помощью:</p>";
    echo "<pre>require_once \$_SERVER['DOCUMENT_ROOT'] . '/counter/counter_secure_db_cache.php';</pre>";
    echo "<ul>";
    echo "<li><a href='?stats=1'>Просмотр статистики</a></li>";
    echo "<li><a href='?process_queue=1'>Обработать очередь вручную</a></li>";
    echo "<li><a href='?cleanup=1'>Очистить старые данные</a></li>";
    echo "<li><a href='?migrate=1'>Перенести геоданные из файлов в базу</a></li>";
    echo "</ul>";
    
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #333; }
        pre { background-color: #f5f5f5; padding: 10px; border-radius: 4px; }
        ul { list-style-type: none; padding: 0; }
        li { margin: 8px 0; }
        li a { 
            color: #2196F3; 
            text-decoration: none; 
        }
        li a:hover { text-decoration: underline; }
    </style>";
}
?>