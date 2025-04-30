<?php
/**
 * Класс для определения геолокации по IP-адресу
 * Использует комбинированный подход: сначала локальная база, потом внешний API
 */
class GeoIPLocator {
    // Путь к локальной базе данных MaxMind
    private $mmdbPath = null;
    
    // Путь к локальной базе данных SxGeo
    private $sxgeoPath = null;
    
    // Настройки API
    private $useExternalAPI = true;
    private $apiURL = 'https://ipinfo.io/{ip}/json';
    private $apiToken = null; // Токен для ipinfo.io
    
    // Кэш результатов
    private $cacheResults = true;
    private $pdo = null;
    
    /**
     * Конструктор класса
     * 
     * @param array $config Массив настроек
     *   - mmdb_path: путь к файлу GeoLite2-City.mmdb
     *   - sxgeo_path: путь к файлу SxGeoCity.dat
     *   - use_external_api: использовать ли внешний API
     *   - api_url: URL API (с плейсхолдером {ip})
     *   - api_token: токен API (если нужен)
     *   - cache_results: кэшировать ли результаты
     *   - pdo: объект PDO для кэширования
     */
    public function __construct($config = []) {
        // Настройки MaxMind
        if (isset($config['mmdb_path'])) {
            $this->mmdbPath = $config['mmdb_path'];
        } else {
            $this->mmdbPath = __DIR__ . '/GeoLite2-City.mmdb';
        }
        
        // Настройки SxGeo
        if (isset($config['sxgeo_path'])) {
            $this->sxgeoPath = $config['sxgeo_path'];
        } else {
            $this->sxgeoPath = __DIR__ . '/SxGeoCity.dat';
        }
        
        // Настройки API
        if (isset($config['use_external_api'])) {
            $this->useExternalAPI = (bool)$config['use_external_api'];
        }
        
        if (isset($config['api_url'])) {
            $this->apiURL = $config['api_url'];
        }
        
        if (isset($config['api_token'])) {
            $this->apiToken = $config['api_token'];
        }
        
        // Настройки кэширования
        if (isset($config['cache_results'])) {
            $this->cacheResults = (bool)$config['cache_results'];
        }
        
        if (isset($config['pdo'])) {
            $this->pdo = $config['pdo'];
            
            // Создаем таблицу для кэша, если её еще нет
            if ($this->cacheResults && $this->pdo) {
                try {
                    $this->pdo->exec("
                        CREATE TABLE IF NOT EXISTS geo_ip_cache (
                            ip_address VARCHAR(45) PRIMARY KEY,
                            country VARCHAR(100),
                            city VARCHAR(100),
                            latitude FLOAT,
                            longitude FLOAT,
                            region VARCHAR(100),
                            timezone VARCHAR(50),
                            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                } catch (PDOException $e) {
                    error_log("GeoIPLocator: Ошибка создания таблицы кэша: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Получает информацию о местоположении по IP-адресу
     * 
     * @param string $ip IP-адрес
     * @return array Массив с информацией о местоположении
     */
    public function getLocation($ip) {
        // Подготавливаем стандартный результат
        $result = [
            'country' => 'Неизвестно',
            'city' => 'Неизвестно',
            'latitude' => 0,
            'longitude' => 0,
            'region' => '',
            'timezone' => ''
        ];
        
        // Проверяем кэш
        if ($this->cacheResults && $this->pdo) {
            $cached = $this->getFromCache($ip);
            if ($cached) {
                return $cached;
            }
        }
        
        // Пробуем получить данные из MaxMind
        $mmResult = $this->getLocationFromMaxMind($ip);
        if ($mmResult && $mmResult['country'] != 'Неизвестно') {
            $result = $mmResult;
        } 
        // Если не получилось, пробуем SxGeo
        else {
            $sxResult = $this->getLocationFromSxGeo($ip);
            if ($sxResult && $sxResult['country'] != 'Неизвестно') {
                $result = $sxResult;
            } 
            // Если и это не сработало, пробуем внешний API
            elseif ($this->useExternalAPI) {
                $apiResult = $this->getLocationFromAPI($ip);
                if ($apiResult && $apiResult['country'] != 'Неизвестно') {
                    $result = $apiResult;
                }
            }
        }
        
        // Сохраняем результат в кэш
        if ($this->cacheResults && $this->pdo && $result['country'] != 'Неизвестно') {
            $this->saveToCache($ip, $result);
        }
        
        return $result;
    }
    
    /**
     * Получает информацию из MaxMind GeoIP2
     * 
     * @param string $ip IP-адрес
     * @return array|null Массив с информацией или null при ошибке
     */
    private function getLocationFromMaxMind($ip) {
        // Проверяем, существует ли файл базы данных и доступна ли библиотека
        if (!file_exists($this->mmdbPath) || !class_exists('\GeoIp2\Database\Reader')) {
            return null;
        }
        
        try {
            // Инициализируем Reader
            $reader = new \GeoIp2\Database\Reader($this->mmdbPath);
            
            // Запрашиваем информацию о городе
            $record = $reader->city($ip);
            
            return [
                'country' => $record->country->name ?? $record->country->isoCode ?? 'Неизвестно',
                'city' => $record->city->name ?? 'Неизвестно',
                'latitude' => $record->location->latitude ?? 0,
                'longitude' => $record->location->longitude ?? 0,
                'region' => $record->mostSpecificSubdivision->name ?? '',
                'timezone' => $record->location->timeZone ?? ''
            ];
        } catch (\Exception $e) {
            // Логируем ошибку, если необходимо
            error_log("GeoIPLocator MaxMind Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получает информацию из SxGeo
     * 
     * @param string $ip IP-адрес
     * @return array|null Массив с информацией или null при ошибке
     */
    private function getLocationFromSxGeo($ip) {
        // Проверяем, существует ли файл базы данных и доступен ли класс
        if (!file_exists($this->sxgeoPath) || !class_exists('SxGeo')) {
            return null;
        }
        
        try {
            $SxGeo = new SxGeo($this->sxgeoPath);
            $geoData = $SxGeo->getCityFull($ip);
            
            if (!$geoData) {
                return null;
            }
            
            return [
                'country' => $geoData['country']['name_ru'] ?? $geoData['country']['iso'] ?? 'Неизвестно',
                'city' => $geoData['city']['name_ru'] ?? 'Неизвестно',
                'latitude' => $geoData['city']['lat'] ?? 0,
                'longitude' => $geoData['city']['lon'] ?? 0,
                'region' => $geoData['region']['name_ru'] ?? '',
                'timezone' => $geoData['city']['timezone'] ?? ''
            ];
        } catch (\Exception $e) {
            // Логируем ошибку, если необходимо
            error_log("GeoIPLocator SxGeo Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получает информацию из внешнего API
     * 
     * @param string $ip IP-адрес
     * @return array|null Массив с информацией или null при ошибке
     */
    private function getLocationFromAPI($ip) {
        // Формируем URL запроса
        $url = str_replace('{ip}', $ip, $this->apiURL);
        
        // Добавляем токен, если он есть
        if ($this->apiToken) {
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $url .= $separator . 'token=' . urlencode($this->apiToken);
        }
        
        try {
            // Инициализируем cURL
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 3);
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            // Проверяем успешность запроса
            if ($status == 200 && $response) {
                $data = json_decode($response, true);
                
                // Формат ответа зависит от API
                // Пример для ipinfo.io:
                if (isset($data['country'])) {
                    $loc = explode(',', $data['loc'] ?? '0,0');
                    return [
                        'country' => $data['country'] ?? 'Неизвестно',
                        'city' => $data['city'] ?? 'Неизвестно',
                        'latitude' => $loc[0] ?? 0,
                        'longitude' => $loc[1] ?? 0,
                        'region' => $data['region'] ?? '',
                        'timezone' => $data['timezone'] ?? ''
                    ];
                }
            }
        } catch (\Exception $e) {
            // Логируем ошибку, если необходимо
            error_log("GeoIPLocator API Error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Получает информацию из кэша
     * 
     * @param string $ip IP-адрес
     * @return array|null Массив с информацией или null, если не найдено
     */
    private function getFromCache($ip) {
        if (!$this->pdo) {
            return null;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    country, city, latitude, longitude, region, timezone 
                FROM geo_ip_cache 
                WHERE ip_address = ? AND last_updated > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$ip]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result;
            }
        } catch (\PDOException $e) {
            error_log("GeoIPLocator Cache Error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Сохраняет информацию в кэш
     * 
     * @param string $ip IP-адрес
     * @param array $data Массив с информацией
     */
    private function saveToCache($ip, $data) {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO geo_ip_cache 
                    (ip_address, country, city, latitude, longitude, region, timezone) 
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    country = VALUES(country),
                    city = VALUES(city),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    region = VALUES(region),
                    timezone = VALUES(timezone),
                    last_updated = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $ip,
                $data['country'],
                $data['city'],
                $data['latitude'],
                $data['longitude'],
                $data['region'],
                $data['timezone']
            ]);
        } catch (\PDOException $e) {
            error_log("GeoIPLocator Cache Save Error: " . $e->getMessage());
        }
    }
}