<?php
session_start();

// Конфигурация базы данных по умолчанию
$default_config = [
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter',
    'count_unique_ip' => true,
    'count_interval' => 3600,
    'excluded_ips' => ['127.0.0.1'],
    'mmdb_path' => __DIR__ . '/../GeoLite2-City.mmdb',
    'sxgeo_path' => __DIR__ . '/../SxGeoCity.dat',
    'use_external_api' => true,
    'api_url' => 'https://ipinfo.io/{ip}/json',
    'api_token' => '',
    'counter_style' => 'digital',
    'items_per_page' => 10,
    'max_queue_size' => 1000,
    'queue_batch_size' => 50,
    'auto_process_chance' => 5,
    'cache_ttl' => 604800,
    'cleanup_chance' => 2,
    'sites' => []
];

// Загружаем сохраненные настройки
$configFile = __DIR__ . '/counter_config.php';
if (file_exists($configFile)) {
    include $configFile;
    // Объединяем с настройками по умолчанию
    $config = array_merge($default_config, $config);
} else {
    $config = $default_config;
}

// Получаем выбранный сайт из GET параметра или сессии
$currentSite = $_GET['site'] ?? $_SESSION['current_site'] ?? $config['default_site'] ?? 'main';
$_SESSION['current_site'] = $currentSite;
// Функция для получения конфигурации базы данных для текущего сайта
function getCurrentSiteConfig($config, $siteKey) {
    if (isset($config['sites'][$siteKey])) {
        // Объединяем общие настройки с настройками конкретного сайта
        return array_merge($config, $config['sites'][$siteKey]);
    }
    return $config;
}

// Получаем конфигурацию для текущего сайта
$siteConfig = getCurrentSiteConfig($config, $currentSite);

// Функция для подключения к базе данных
function connectDB($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Ошибка подключения к базе данных ({$config['db_name']}): " . $e->getMessage());
        return null; // Вместо die()
    }
}
// Функция для получения статистики по всем сайтам
function getAllSitesStats($config) {
    $sitesStats = [];
    
    foreach ($config['sites'] as $siteKey => $siteConfig) {
        try {
            $siteFullConfig = array_merge($config, $siteConfig);
            $pdo = connectDB($siteFullConfig);
            
            // Если подключение не удалось, записываем ошибку и переходим к следующему
            if (!$pdo) {
                $sitesStats[$siteKey] = [
                    'name' => $siteConfig['name'],
                    'stats' => ['error' => 'Ошибка подключения к БД'],
                    'url' => $siteConfig['url'] ?? '',
                    'color' => $siteConfig['color'] ?? '#dc3545'
                ];
                continue;
            }
            
            // Получаем основную статистику
            $stats = [];
            
            // Общее количество посещений
            $stmt = $pdo->query("SELECT COUNT(*) FROM visits");
            $stats['total_visits'] = $stmt->fetchColumn();
            
            // Уникальные посетители
            $stmt = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits");
            $stats['unique_visitors'] = $stmt->fetchColumn();
            
            // Посещения за сегодня
            $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = CURDATE()");
            $stats['today_visits'] = $stmt->fetchColumn();
            
            // Посещения за вчера
            $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
            $stats['yesterday_visits'] = $stmt->fetchColumn();
            
            // Посещения за месяц
            $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE MONTH(visit_time) = MONTH(CURDATE()) AND YEAR(visit_time) = YEAR(CURDATE())");
            $stats['month_visits'] = $stmt->fetchColumn();
            
            $sitesStats[$siteKey] = [
                'name' => $siteConfig['name'],
                'stats' => $stats,
                'url' => $siteConfig['url'] ?? '',
                'color' => $siteConfig['color'] ?? '#007bff'
            ];
            
        } catch (Exception $e) {
            $sitesStats[$siteKey] = [
                'name' => $siteConfig['name'],
                'stats' => ['error' => 'Ошибка выполнения запроса'],
                'url' => $siteConfig['url'] ?? '',
                'color' => $siteConfig['color'] ?? '#dc3545'
            ];
            // Логируем ошибку, но продолжаем работу
            error_log("Ошибка получения статистики для сайта {$siteKey}: " . $e->getMessage());
            continue;
        }
    }
    
    return $sitesStats;
}
// Аутентификация пользователя
function authenticateUser($pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        return $user;
    }
    return false;
}

// Проверка авторизации
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

// Функция для очистки базы данных
function cleanupDatabase($pdo, $daysToKeep = 0) {
    try {
        // Начинаем транзакцию для безопасного удаления
        $pdo->beginTransaction();
        
        if ($daysToKeep > 0) {
            // Удаляем только старые записи
            $stmt = $pdo->prepare("DELETE FROM visits WHERE visit_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$daysToKeep]);
            
            $stmt = $pdo->prepare("DELETE FROM geo_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$daysToKeep]);
        } else {
            // Полная очистка
            $stmt = $pdo->prepare("DELETE FROM visits");
            $stmt->execute();
            
            // Проверяем, существует ли таблица geo_cache
            $stmt = $pdo->query("SHOW TABLES LIKE 'geo_cache'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("DELETE FROM geo_cache");
                $stmt->execute();
            }
        }
        
        // Фиксируем транзакцию
        $pdo->commit();
        
        return true;
    } catch (PDOException $e) {
        // В случае ошибки откатываем изменения
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Ошибка при очистке базы данных: " . $e->getMessage());
        return false;
    }
}
// Обработка запроса на выход
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Проверка авторизации
checkAuth();

// Подключаемся к базе данных текущего сайта
$pdo = connectDB($siteConfig);

// Проверяем успешность подключения
if (!$pdo) {
    // Если не удалось подключиться к текущему сайту, пробуем основную базу
    $pdo = connectDB($config);
    
    if (!$pdo) {
        // Если и к основной не подключились, показываем ошибку
        $_SESSION['message'] = [
            'type' => 'danger',
            'text' => 'Ошибка подключения к базе данных! Проверьте настройки.'
        ];
        
        // Устанавливаем пустые данные для избежания ошибок
        $basicStats = ['total_visits' => 0, 'unique_visitors' => 0, 'today_unique' => 0, 'today_visits' => 0, 'yesterday_visits' => 0, 'month_visits' => 0];
        $dbSize = 0;
        $popularPages = ['data' => [], 'total' => 0, 'pages' => 0, 'current' => 1];
        $browserStats = $deviceStats = $hourlyStats = $dailyStats = $countryStats = $cityStats = [];
        $recentVisits = ['data' => [], 'total' => 0, 'pages' => 0, 'current' => 1];
        $mapData = $referrerStats = $referrerDomainStats = [];
        $notifications = [];
        
        // Продолжаем выполнение с пустыми данными
    }
}
// Обработка действия очистки базы данных
if (isset($_POST['cleanup_db']) && $_POST['cleanup_db'] === '1') {
    if (isset($_POST['confirm_cleanup']) && $_POST['confirm_cleanup'] === 'confirm') {
        $cleanupResult = cleanupDatabase($pdo); // или cleanupDatabase($pdo, 0) для полной очистки
        if ($cleanupResult) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => 'База данных успешно очищена!'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'danger',
                'text' => 'Ошибка при очистке базы данных!'
            ];
        }
    } else {
        $_SESSION['message'] = [
            'type' => 'warning',
            'text' => 'Операция отменена! Для очистки базы необходимо подтверждение.'
        ];
    }
    header("Location: index.php?site=" . $currentSite);
    exit;
}

// Отображение сообщений
$message = null;
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
// Функция для получения размера базы данных
function getDatabaseSize($pdo, $dbName) {
    $stmt = $pdo->prepare("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb'
        FROM information_schema.tables
        WHERE table_schema = :dbName
    ");
    $stmt->bindParam(':dbName', $dbName, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['size_mb'] : 0;
}

// Функция для сохранения настроек
function saveCounterConfig($config) {
    $configFile = __DIR__ . '/counter_config.php';
    $content = "<?php\n\$config = " . var_export($config, true) . ";\n?>";
    file_put_contents($configFile, $content);
}

// Функция для фильтрации данных по дате
function getFilteredStats($pdo, $startDate = null, $endDate = null) {
    $where = '';
    $params = [];
    
    if ($startDate) {
        $where .= " WHERE visit_time >= ?";
        $params[] = $startDate;
        
        if ($endDate) {
            $where .= " AND visit_time <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM visits
        $where
    ");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
// Функция для форматирования URL в виде кликабельных ссылок
function formatUrlToLink($url) {
    return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">' . 
           htmlspecialchars(substr($url, 0, 50) . (strlen($url) > 50 ? '...' : '')) . 
           '</a>';
}

// Функция для форматирования IP-адреса с ссылкой на сервис определения
function formatIpWithLink($ip) {
    return '<a href="https://whatismyipaddress.com/ip/' . htmlspecialchars($ip) . '" target="_blank" rel="noopener noreferrer">' . 
           htmlspecialchars($ip) . 
           '</a>';
}

// Функция для проверки и создания уведомлений
function checkNotifications($pdo) {
    $notifications = [];
    
    $todayVisits = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = CURDATE()")->fetchColumn();
    $yesterdayVisits = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
    
    if ($yesterdayVisits > 0 && $todayVisits > $yesterdayVisits * 1.5) {
        $notifications[] = [
            'type' => 'success',
            'message' => 'Сегодня наблюдается значительный рост трафика (+' . round(($todayVisits - $yesterdayVisits) / $yesterdayVisits * 100) . '% к вчерашнему дню)'
        ];
    }
    
    $stmt = $pdo->query("
        SELECT country FROM visits 
        WHERE DATE(visit_time) = CURDATE() 
        GROUP BY country 
        HAVING country NOT IN (
            SELECT DISTINCT country FROM visits WHERE DATE(visit_time) < CURDATE()
        )
    ");
    $newCountries = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($newCountries) > 0) {
        $notifications[] = [
            'type' => 'info',
            'message' => 'Сегодня посетители из новых стран: ' . implode(', ', $newCountries)
        ];
    }
    
    return $notifications;
}
// Функция экспорта данных
function exportData($pdo, $format = 'csv') {
    set_time_limit(300);
    ini_set('memory_limit', '256M');
    ob_end_clean();
    
    try {
        $stmt = $pdo->query("
            SELECT 
                id, page_url, ip_address, country, city, browser, device, visit_time
            FROM visits
            ORDER BY visit_time DESC
        ");
        
        if ($format == 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename=visits_export_' . date('Y-m-d') . '.csv');
            
            $tempFile = tempnam(sys_get_temp_dir(), 'export_');
            $output = fopen($tempFile, 'w');
            
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['ID', 'Страница', 'IP', 'Страна', 'Город', 'Браузер', 'Устройство', 'Время посещения']);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['id'],
                    $row['page_url'],
                    $row['ip_address'],
                    $row['country'],
                    $row['city'],
                    $row['browser'],
                    $row['device'],
                    $row['visit_time']
                ]);
            }
            
            fclose($output);
            readfile($tempFile);
            unlink($tempFile);
            exit;
        } 
        elseif ($format == 'excel') {
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename=visits_export_' . date('Y-m-d') . '.xls');
            
            echo '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Export Data</title>
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 5px; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <table>
        <tr>
            <th>ID</th>
            <th>Страница</th>
            <th>IP</th>
            <th>Страна</th>
            <th>Город</th>
            <th>Браузер</th>
            <th>Устройство</th>
            <th>Время посещения</th>
        </tr>';
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo '<tr>
                <td>' . htmlspecialchars($row['id']) . '</td>
                <td>' . htmlspecialchars($row['page_url']) . '</td>
                <td>' . htmlspecialchars($row['ip_address']) . '</td>
                <td>' . htmlspecialchars($row['country']) . '</td>
                <td>' . htmlspecialchars($row['city']) . '</td>
                <td>' . htmlspecialchars($row['browser']) . '</td>
                <td>' . htmlspecialchars($row['device']) . '</td>
                <td>' . htmlspecialchars($row['visit_time']) . '</td>
            </tr>';
                
                if (ob_get_length() > 10240) {
                    ob_flush();
                    flush();
                }
            }
            
            echo '</table>
</body>
</html>';
            exit;
        }
    } catch (Exception $e) {
        error_log('Export error: ' . $e->getMessage());
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ошибка при экспорте: ' . $e->getMessage();
        exit;
    }
}

// Обработка запроса экспорта
if (isset($_GET['export'])) {
    exportData($pdo, $_GET['export']);
}
// Получение основной статистики
function getBasicStats($pdo) {
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits");
    $stats['total_visits'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits");
    $stats['unique_visitors'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits WHERE DATE(visit_time) = CURDATE()");
    $stats['today_unique'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = CURDATE()");
    $stats['today_visits'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE DATE(visit_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    $stats['yesterday_visits'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE MONTH(visit_time) = MONTH(CURDATE()) AND YEAR(visit_time) = YEAR(CURDATE())");
    $stats['month_visits'] = $stmt->fetchColumn();
    
    return $stats;
}

// Получение популярных страниц с пагинацией
function getPopularPages($pdo, $page = 1, $perPage = 10) {
    $page = intval($page);
    $perPage = intval($perPage);
    $offset = ($page - 1) * $perPage;
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM (
            SELECT page_url
            FROM visits
            GROUP BY page_url
        ) as page_count
    ");
    $totalItems = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT page_url, COUNT(*) as visit_count
        FROM visits
        GROUP BY page_url
        ORDER BY visit_count DESC
        LIMIT :offset, :perPage
    ");
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    
    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $totalItems,
        'pages' => ceil($totalItems / $perPage),
        'current' => $page
    ];
}

// Получение статистики по браузерам
function getBrowserStats($pdo) {
    $stmt = $pdo->query("
        SELECT browser, COUNT(*) as count
        FROM visits
        GROUP BY browser
        ORDER BY count DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение статистики по устройствам
function getDeviceStats($pdo) {
    $stmt = $pdo->query("
        SELECT device, COUNT(*) as count
        FROM visits
        GROUP BY device
        ORDER BY count DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Получение данных для графика по часам
function getHourlyStats($pdo) {
    $stmt = $pdo->query("
        SELECT HOUR(visit_time) as hour, COUNT(*) as count
        FROM visits
        WHERE DATE(visit_time) = CURDATE()
        GROUP BY HOUR(visit_time)
        ORDER BY hour
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение данных для графика по дням
function getDailyStats($pdo, $days = 30) {
    $days = intval($days);
    $stmt = $pdo->prepare("
        SELECT DATE(visit_time) as date, COUNT(*) as count
        FROM visits
        WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
        GROUP BY DATE(visit_time)
        ORDER BY date
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение статистики по странам
function getCountryStats($pdo, $limit = 20) {
    $limit = intval($limit);
    $stmt = $pdo->prepare("
        SELECT country, COUNT(*) as visit_count
        FROM visits
        WHERE country != 'Неизвестно' AND country != 'Unknown'
        GROUP BY country
        ORDER BY visit_count DESC
        LIMIT $limit
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение статистики по городам
function getCityStats($pdo, $limit = 20) {
    $limit = intval($limit);
    $stmt = $pdo->prepare("
        SELECT city, country, COUNT(*) as visit_count
        FROM visits
        WHERE city != 'Неизвестно' AND city != 'Unknown'
        GROUP BY city, country
        ORDER BY visit_count DESC
        LIMIT $limit
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение данных сравнения периодов
function getPeriodsComparisonData($pdo, $period1Start, $period1End, $period2Start, $period2End) {
    $result = [
        'period1' => [],
        'period2' => []
    ];
    
    if ($period1Start && $period1End) {
        $stmt = $pdo->prepare("
            SELECT DATE(visit_time) as date, COUNT(*) as count
            FROM visits
            WHERE visit_time BETWEEN ? AND ?
            GROUP BY DATE(visit_time)
            ORDER BY date
        ");
        $stmt->execute([$period1Start, $period1End . ' 23:59:59']);
        $result['period1'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($period2Start && $period2End) {
        $stmt = $pdo->prepare("
            SELECT DATE(visit_time) as date, COUNT(*) as count
            FROM visits
            WHERE visit_time BETWEEN ? AND ?
            GROUP BY DATE(visit_time)
            ORDER BY date
        ");
        $stmt->execute([$period2Start, $period2End . ' 23:59:59']);
        $result['period2'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $result;
}

// Получение данных для тепловой карты (по дням недели и часам)
function getHeatmapData($pdo) {
    $stmt = $pdo->query("
        SELECT 
            DAYOFWEEK(visit_time) as day_of_week,
            HOUR(visit_time) as hour,
            COUNT(*) as count
        FROM visits
        WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DAYOFWEEK(visit_time), HOUR(visit_time)
        ORDER BY day_of_week, hour
    ");
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Получение информации о последних посещениях с геоданными и с пагинацией
function getRecentVisitsWithGeo($pdo, $page = 1, $perPage = 20, $totalExport = null) {
    if ($totalExport !== null) {
        $limit = intval($totalExport);
        $stmt = $pdo->prepare("
            SELECT 
                id, page_url, ip_address, country, city, browser, device, visit_time
            FROM visits
            ORDER BY visit_time DESC
            LIMIT $limit
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $page = intval($page);
    $perPage = intval($perPage);
    $offset = ($page - 1) * $perPage;
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM visits");
    $totalItems = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT 
            id, page_url, ip_address, country, city, browser, device, visit_time
        FROM visits
        ORDER BY visit_time DESC
        LIMIT :offset, :perPage
    ");
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    
    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $totalItems,
        'pages' => ceil($totalItems / $perPage),
        'current' => $page
    ];
}

// Функция для создания навигации по страницам
function createPagination($currentPage, $totalPages, $pageParam, $baseUrl = 'index.php', $scrollToId = '') {
    global $currentSite;
    
    $html = '<nav aria-label="Навигация по страницам"><ul class="pagination justify-content-center">';
    
    $params = $_GET;
    
    $createUrl = function($page) use ($params, $pageParam, $baseUrl, $scrollToId, $currentSite) {
        $params[$pageParam] = $page;
        $params['site'] = $currentSite;
        $queryString = http_build_query($params);
        $url = $baseUrl . '?' . $queryString;
        
        if (!empty($scrollToId)) {
            $url .= '#' . $scrollToId;
        }
        
        return $url;
    };
    
    $prevDisabled = ($currentPage <= 1) ? ' disabled' : '';
    $prevUrl = ($currentPage > 1) ? $createUrl($currentPage - 1) : '#';
    $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . $prevUrl . '">Предыдущая</a></li>';
    
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $createUrl(1) . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = ($i == $currentPage) ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $createUrl($i) . '">' . $i . '</a></li>';
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $createUrl($totalPages) . '">' . $totalPages . '</a></li>';
    }
    
    $nextDisabled = ($currentPage >= $totalPages) ? ' disabled' : '';
    $nextUrl = ($currentPage < $totalPages) ? $createUrl($currentPage + 1) : '#';
    $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . $nextUrl . '">Следующая</a></li>';
    
    $html .= '</ul></nav>';
    return $html;
}
// Получение данных для карты
function getVisitsForMap($pdo, $limit = 1000) {
    $limit = intval($limit);
    
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM visits LIKE 'latitude'");
        $hasCoordinates = ($stmt->rowCount() > 0);
    } catch (PDOException $e) {
        $hasCoordinates = false;
    }
    
    if ($hasCoordinates) {
        $stmt = $pdo->prepare("
            SELECT 
                ip_address, country, city, latitude, longitude,
                COUNT(*) as visits_count
            FROM visits
            WHERE 
                country != 'Неизвестно' AND 
                country != 'Unknown' AND
                city != 'Неизвестно' AND
                city != 'Unknown' AND
                latitude != 0 AND
                longitude != 0
            GROUP BY ip_address, country, city, latitude, longitude
            ORDER BY MAX(visit_time) DESC
            LIMIT $limit
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                ip_address, country, city,
                COUNT(*) as visits_count
            FROM visits
            WHERE 
                country != 'Неизвестно' AND 
                country != 'Unknown' AND
                city != 'Неизвестно' AND
                city != 'Unknown'
            GROUP BY ip_address, country, city
            ORDER BY MAX(visit_time) DESC
            LIMIT $limit
        ");
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функция для получения статистики по источникам переходов
function getReferrerStats($pdo, $limit = 15) {
    $limit = intval($limit);
    $stmt = $pdo->prepare("
        SELECT 
            referer,
            COUNT(*) as count
        FROM visits
        WHERE 
            referer != '' AND 
            referer IS NOT NULL
        GROUP BY referer
        ORDER BY count DESC
        LIMIT $limit
    ");
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функция для получения доменов из URL
function extractDomain($url) {
    if (empty($url)) return "";
    
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        $url = "http://" . $url;
    }
    
    $parts = parse_url($url);
    
    if (isset($parts['host'])) {
        $host = $parts['host'];
        if (substr($host, 0, 4) === 'www.') {
            $host = substr($host, 4);
        }
        return $host;
    }
    
    return "";
}

// Функция для группировки рефереров по доменам
function getReferrerStatsByDomain($pdo, $limit = 15) {
    $limit = intval($limit);
    $stmt = $pdo->query("
        SELECT 
            referer
        FROM visits
        WHERE 
            referer != '' AND 
            referer IS NOT NULL
    ");
    
    $domains = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $domain = extractDomain($row['referer']);
        if (!empty($domain)) {
            if (!isset($domains[$domain])) {
                $domains[$domain] = 1;
            } else {
                $domains[$domain]++;
            }
        }
    }
    
    arsort($domains);
    
    return array_slice($domains, 0, $limit, true);
}
// Определяем текущую страницу
$currentPage = $_GET['page'] ?? 'dashboard';

// Обработка параметров пагинации
$currentPagePopular = isset($_GET['popular_page']) ? (int)$_GET['popular_page'] : 1;
$currentPageRecent = isset($_GET['recent_page']) ? (int)$_GET['recent_page'] : 1;
$itemsPerPage = $siteConfig['items_per_page'] ?? 10;

// Обработка формы фильтрации
$filterStartDate = $_GET['start_date'] ?? null;
$filterEndDate = $_GET['end_date'] ?? null;

if ($filterStartDate || $filterEndDate) {
    $filteredVisits = getFilteredStats($pdo, $filterStartDate, $filterEndDate);
}

// Обработка сравнения периодов
$period1Start = $_GET['period1_start'] ?? null;
$period1End = $_GET['period1_end'] ?? null;
$period2Start = $_GET['period2_start'] ?? null;
$period2End = $_GET['period2_end'] ?? null;

if (($period1Start && $period1End) || ($period2Start && $period2End)) {
    $periodsComparisonData = getPeriodsComparisonData($pdo, $period1Start, $period1End, $period2Start, $period2End);
}

// Обработка страницы настроек
if (isset($_GET['page']) && $_GET['page'] == 'settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newConfig = $siteConfig;
        
        $newConfig['count_unique_ip'] = isset($_POST['count_unique_ip']);
        $newConfig['count_interval'] = (int)$_POST['count_interval'];
        $newConfig['excluded_ips'] = explode("\n", $_POST['excluded_ips']);
        $newConfig['excluded_ips'] = array_map('trim', $newConfig['excluded_ips']);
        
        $newConfig['use_external_api'] = isset($_POST['use_external_api']);
        $newConfig['api_url'] = $_POST['api_url'];
        $newConfig['api_token'] = $_POST['api_token'];
        
        $newConfig['counter_style'] = $_POST['counter_style'];
        $newConfig['items_per_page'] = (int)$_POST['items_per_page'];
        
        saveCounterConfig($newConfig);
        
        header("Location: index.php?page=settings&site=" . $currentSite . "&saved=1");
        exit;
    }
    
    $currentPage = 'settings';
} elseif (isset($_GET['page']) && $_GET['page'] == 'analytics') {
    $currentPage = 'analytics';
    $heatmapData = getHeatmapData($pdo);
} else {
    $currentPage = 'dashboard';
}

// Получение статистики по всем сайтам для главной страницы
$allSitesStats = [];
if (!empty($siteConfig['sites'])) {
    $allSitesStats = getAllSitesStats($siteConfig);
}

// Получение всех данных для отображения с учетом пагинации
$basicStats = getBasicStats($pdo);
$dbSize = getDatabaseSize($pdo, $siteConfig['db_name']);
$popularPages = getPopularPages($pdo, $currentPagePopular, $itemsPerPage);
$browserStats = getBrowserStats($pdo);
$deviceStats = getDeviceStats($pdo);
$hourlyStats = getHourlyStats($pdo);
$dailyStats = getDailyStats($pdo);
$countryStats = getCountryStats($pdo);
$cityStats = getCityStats($pdo);
$recentVisits = getRecentVisitsWithGeo($pdo, $currentPageRecent, $itemsPerPage);
$mapData = getVisitsForMap($pdo);
$referrerStats = getReferrerStats($pdo);
$referrerDomainStats = getReferrerStatsByDomain($pdo);

// Параметры для пагинации
$popularPagesPageParam = "popular_page";
$recentVisitsPageParam = "recent_page";

// Получаем уведомления
$notifications = checkNotifications($pdo);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Административная панель счетчика посещений</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php if ($currentPage == 'dashboard'): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <?php endif; ?>
    <style>
        .stats-card {
            margin-bottom: 20px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
            color: white;
        }
        .sidebar a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
        }
        .sidebar a:hover {
            color: white;
        }
        .content {
            padding: 20px;
        }
        .table-responsive {
            margin-bottom: 20px;
        }
        #visits-map {
            height: 500px;
            width: 100%;
        }
        .pagination {
            margin-top: 15px;
            margin-bottom: 15px;
        }
        .page-selector {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin-bottom: 15px;
        }
        .page-selector select {
            margin-left: 10px;
            width: auto;
        }
.site-card {
    border-left: 4px solid;
    margin-bottom: 15px;
    background-color: transparent;
    border: none;
    transition: all 0.3s ease;
}

.site-card .card-body {
    background-color: rgba(255, 255, 255, 0.05);
    border-radius: 0.375rem;
}

.site-card .card-body a {
    color: #fff !important;
    font-weight: 500;
}

.site-card .quick-stats {
    color: #ccc !important;
}

.site-card .quick-stats small {
    color: #ccc !important;
}

/* Активная карточка */
.site-card.active .card-body {
    background-color: #f8f9fa;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.site-card.active .card-body a {
    color: #333 !important;
    font-weight: bold;
}

.site-card.active .quick-stats {
    color: #666 !important;
}

.site-card.active .quick-stats small {
    color: #666 !important;
}

/* Ховер эффекты */
.site-card:not(.active):hover .card-body {
    background-color: rgba(255, 255, 255, 0.1);
}

.site-card:not(.active):hover .card-body a {
    color: #fff !important;
}

        .site-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .quick-stats {
            font-size: 0.9em;
        }
        .current-site-indicator {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
    <script>
        // Сохранение и восстановление позиции прокрутки
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.hash) {
                const scrollPosition = window.location.hash.substring(1);
                if (!isNaN(scrollPosition)) {
                    window.scrollTo(0, parseInt(scrollPosition));
                }
            }
            
            document.querySelectorAll('.pagination .page-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href') !== '#') {
                        const scrollY = window.scrollY || document.documentElement.scrollTop;
                        const url = this.getAttribute('href');
                        
                        if (url.indexOf('#') === -1) {
                            this.setAttribute('href', url + '#' + scrollY);
                        }
                    }
                });
            });
        });
    </script>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Боковая панель -->
            <div class="col-md-2 sidebar p-3">
                <h3 class="mb-4">Счетчик посещений</h3>
                
                <!-- Селектор сайтов -->
                <?php if (!empty($siteConfig['sites'])): ?>
                <div class="mb-4">
                    <h6 class="text-light mb-3">Выберите сайт:</h6>
                    <?php foreach ($siteConfig['sites'] as $siteKey => $siteInfo): ?>
                    <div class="site-card card mb-2 <?php echo $siteKey === $currentSite ? 'active' : ''; ?>" 
                         style="border-left-color: <?php echo $siteInfo['color'] ?? '#007bff'; ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="index.php?site=<?php echo $siteKey; ?>" class="text-decoration-none">
                                    <strong><?php echo htmlspecialchars($siteInfo['name']); ?></strong>
                                </a>
                                <?php if ($siteKey === $currentSite): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <?php endif; ?>
                            </div>
                            <?php if (isset($allSitesStats[$siteKey])): ?>
                            <div class="quick-stats text-muted mt-1">
                                <?php if (isset($allSitesStats[$siteKey]['stats']['error'])): ?>
                                    <small class="text-danger">Ошибка подключения</small>
                                <?php else: ?>
                                    <small>
                                        Сегодня: <?php echo $allSitesStats[$siteKey]['stats']['today_visits']; ?> | 
                                        Всего: <?php echo $allSitesStats[$siteKey]['stats']['total_visits']; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <ul class="nav flex-column">
                    <li class="nav-item mb-2">
                        <a href="index.php?site=<?php echo $currentSite; ?>" class="nav-link <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>">
                            <i class="bi bi-speedometer2"></i> Панель управления
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="index.php?page=analytics&site=<?php echo $currentSite; ?>" class="nav-link <?php echo $currentPage == 'analytics' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up"></i> Расширенная аналитика
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="index.php?page=settings&site=<?php echo $currentSite; ?>" class="nav-link <?php echo $currentPage == 'settings' ? 'active' : ''; ?>">
                            <i class="bi bi-gear"></i> Настройки
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="sites_manager.php" class="nav-link">
                            <i class="bi bi-collection"></i> Управление сайтами
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="index.php?logout=1" class="nav-link">
                            <i class="bi bi-box-arrow-right"></i> Выход
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Основной контент -->
            <div class="col-md-10 content">
                <?php if ($currentPage == 'dashboard'): ?>
				<!-- Индикатор текущего сайта -->
                <div class="current-site-indicator">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1">
                                <i class="bi bi-globe"></i> 
                                <?php echo htmlspecialchars($siteConfig['name'] ?? 'Текущий сайт'); ?>
                            </h4>
                            <?php if (!empty($siteConfig['url'])): ?>
                                <small>
                                    <a href="<?php echo htmlspecialchars($siteConfig['url']); ?>" target="_blank" class="text-white-50">
                                        <?php echo htmlspecialchars($siteConfig['url']); ?>
                                    </a>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Экспорт
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="index.php?export=csv&site=<?php echo $currentSite; ?>">CSV</a></li>
                                    <li><a class="dropdown-item" href="index.php?export=excel&site=<?php echo $currentSite; ?>">Excel</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Отображение уведомлений -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message['text']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($notifications)): ?>
                    <div class="notifications mb-4">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="alert alert-<?php echo $notification['type']; ?> alert-dismissible fade show" role="alert">
                                <?php echo $notification['message']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
				<!-- Обзор всех сайтов -->
                <?php if (!empty($allSitesStats)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <h5 class="mb-3">Обзор всех сайтов</h5>
                    </div>
                    <?php foreach ($allSitesStats as $siteKey => $siteData): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card stats-card h-100" style="border-left: 4px solid <?php echo $siteData['color']; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title"><?php echo htmlspecialchars($siteData['name']); ?></h6>
                                    <a href="index.php?site=<?php echo $siteKey; ?>" class="btn btn-sm btn-outline-primary">
                                        Открыть
                                    </a>
                                </div>
                                
                                <?php if (isset($siteData['stats']['error'])): ?>
                                    <div class="text-danger">
                                        <i class="bi bi-exclamation-triangle"></i> 
                                        <?php echo $siteData['stats']['error']; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="border-end">
                                                <h4 class="text-primary mb-1"><?php echo $siteData['stats']['today_visits']; ?></h4>
                                                <small class="text-muted">Сегодня</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <h4 class="text-success mb-1"><?php echo $siteData['stats']['total_visits']; ?></h4>
                                            <small class="text-muted">Всего</small>
                                        </div>
                                    </div>
                                    <div class="row text-center mt-2">
                                        <div class="col-6">
                                            <small class="text-info">Уникальные: <?php echo $siteData['stats']['unique_visitors']; ?></small>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-warning">За месяц: <?php echo $siteData['stats']['month_visits']; ?></small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <hr class="my-4">
                <?php endif; ?>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Панель управления</h2>
                    <div>
                        <button type="button" class="btn btn-danger me-2" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                            <i class="bi bi-trash"></i> Очистить базу данных
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-download"></i> Экспорт данных
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                                <li><a class="dropdown-item" href="index.php?export=csv&site=<?php echo $currentSite; ?>">Экспорт в CSV</a></li>
                                <li><a class="dropdown-item" href="index.php?export=excel&site=<?php echo $currentSite; ?>">Экспорт в Excel</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
				<!-- Основная статистика -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Размер БД</h5>
                                <h3 class="text-danger"><?php echo $dbSize; ?> МБ</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Всего</h5>
                                <h3 class="text-primary"><?php echo $basicStats['total_visits']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Уникальных</h5>
                                <h3 class="text-success">
                                    <?php echo $basicStats['unique_visitors']; ?> 
                                    <small class="text-muted fs-6">
                                        (сегодня: <span class="text-info"><?php echo $basicStats['today_unique']; ?></span>)
                                    </small>
                                </h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Сегодня</h5>
                                <h3 class="text-info"><?php echo $basicStats['today_visits']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">За месяц</h5>
                                <h3 class="text-warning"><?php echo $basicStats['month_visits']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Фильтр по дате -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Фильтр по дате</h5>
                                <form class="row g-3" method="get" action="">
                                    <input type="hidden" name="site" value="<?php echo $currentSite; ?>">
                                    <div class="col-md-4">
                                        <label for="start_date" class="form-label">Начальная дата</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" 
                                               value="<?php echo $filterStartDate ?? ''; ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="end_date" class="form-label">Конечная дата</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date"
                                               value="<?php echo $filterEndDate ?? ''; ?>">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">Применить фильтр</button>
                                        <a href="index.php?site=<?php echo $currentSite; ?>" class="btn btn-secondary">Сбросить</a>
                                    </div>
                                </form>
                                
                                <?php if (isset($filteredVisits)): ?>
                                <div class="mt-3">
                                    <div class="alert alert-info">
                                        За выбранный период: <strong><?php echo $filteredVisits; ?></strong> посещений
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Графики -->
                <div class="row mt-4">
                    <!-- График посещений по дням -->
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Посещения по дням (последние 30 дней)</h5>
                                <canvas id="dailyChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- График посещений по часам -->
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Посещения по часам (сегодня)</h5>
                                <canvas id="hourlyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Статистика по странам и городам -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Популярные страны</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Страна</th>
                                                <th>Посещения</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($countryStats as $country): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($country['country']); ?></td>
                                                <td><?php echo $country['visit_count']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Популярные города</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Город</th>
                                                <th>Страна</th>
                                                <th>Посещения</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cityStats as $city): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($city['city']); ?></td>
                                                <td><?php echo htmlspecialchars($city['country']); ?></td>
                                                <td><?php echo $city['visit_count']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Карта посещений -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Карта посещений</h5>
                                <div id="visits-map"></div>
                            </div>
                        </div>
                    </div>
                </div>
				<div class="row mt-4">
                    <!-- Популярные страницы с пагинацией -->
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="card-title">Популярные страницы</h5>
                                </div>
                                <div id="popular-pages-section" class="table-responsive">
                                    <table class="table table-striped" id="popular-pages-table">
                                        <thead>
                                            <tr>
                                                <th>Страница</th>
                                                <th>Посещения</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($popularPages['data'] as $page): ?>
                                            <tr>
                                                <td>
                                                    <a href="<?php echo htmlspecialchars($page['page_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo htmlspecialchars(substr($page['page_url'], 0, 50) . (strlen($page['page_url']) > 50 ? '...' : '')); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo $page['visit_count']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Пагинация для популярных страниц -->
                                <?php if ($popularPages['pages'] > 1): ?>
                                    <?php echo createPagination($popularPages['current'], $popularPages['pages'], $popularPagesPageParam, 'index.php', 'popular-pages-section'); ?>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <button class="btn btn-sm btn-outline-primary open-all-links" data-target="popular-pages-table">
                                        <i class="bi bi-box-arrow-up-right"></i> Открыть все в новых вкладках
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Статистика по браузерам и устройствам -->
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Браузеры и устройства</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Браузеры</h6>
                                        <canvas id="browserChart"></canvas>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Устройства</h6>
                                        <canvas id="deviceChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Последние посещения с пагинацией -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="card-title">Последние посещения</h5>
                                </div>
                                <div id="recent-visits-section" class="table-responsive">
                                    <table class="table table-striped" id="recent-visits-table">
                                        <thead>
                                            <tr>
                                                <th>Время</th>
                                                <th>IP-адрес</th>
                                                <th>Страна</th>
                                                <th>Город</th>
                                                <th>Браузер</th>
                                                <th>Устройство</th>
                                                <th>Страница</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentVisits['data'] as $visit): ?>
                                            <tr>
                                                <td><?php echo date('d.m.Y H:i:s', strtotime($visit['visit_time'])); ?></td>
                                                <td><?php echo formatIpWithLink($visit['ip_address']); ?></td>
                                                <td><?php echo htmlspecialchars($visit['country']); ?></td>
                                                <td><?php echo htmlspecialchars($visit['city']); ?></td>
                                                <td><?php echo htmlspecialchars($visit['browser']); ?></td>
                                                <td><?php echo htmlspecialchars($visit['device']); ?></td>
                                                <td><?php echo formatUrlToLink($visit['page_url']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Пагинация для последних посещений -->
                                <?php if ($recentVisits['pages'] > 1): ?>
                                    <?php echo createPagination($recentVisits['current'], $recentVisits['pages'], $recentVisitsPageParam, 'index.php', 'recent-visits-section'); ?>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <button class="btn btn-sm btn-outline-primary open-all-links" data-target="recent-visits-table">
                                        <i class="bi bi-box-arrow-up-right"></i> Открыть все в новых вкладках
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
				<!-- Блок для статистики по источникам переходов -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Источники переходов по доменам</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Домен</th>
                                                <th>Переходы</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $totalDomainReferrers = array_sum($referrerDomainStats);
                                            foreach ($referrerDomainStats as $domain => $count): 
                                                $percent = ($totalDomainReferrers > 0) ? round(($count / $totalDomainReferrers) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($domain); ?></td>
                                                <td><?php echo $count; ?></td>
                                                <td><?php echo $percent; ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Популярные источники переходов</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped" id="referer-table">
                                        <thead>
                                            <tr>
                                                <th>URL источника</th>
                                                <th>Переходы</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($referrerStats as $referer): ?>
                                            <tr>
                                                <td>
                                                    <a href="<?php echo htmlspecialchars($referer['referer']); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo htmlspecialchars(substr($referer['referer'], 0, 50) . (strlen($referer['referer']) > 50 ? '...' : '')); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo $referer['count']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <button class="btn btn-sm btn-outline-primary open-all-links" data-target="referer-table">
                                        <i class="bi bi-box-arrow-up-right"></i> Открыть все в новых вкладках
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
				<?php elseif ($currentPage == 'analytics'): ?>
                <h2 class="mb-4">Расширенная аналитика - <?php echo htmlspecialchars($siteConfig['name'] ?? 'Текущий сайт'); ?></h2>
                
                <!-- Графики сравнения периодов -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Сравнение периодов</h5>
                                <form class="row g-3" method="get" action="">
                                    <input type="hidden" name="page" value="analytics">
                                    <input type="hidden" name="site" value="<?php echo $currentSite; ?>">
                                    <div class="col-md-3">
                                        <label for="period1_start" class="form-label">Период 1 (начало)</label>
                                        <input type="date" class="form-control" id="period1_start" name="period1_start" value="<?php echo $period1Start ?? ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="period1_end" class="form-label">Период 1 (конец)</label>
                                        <input type="date" class="form-control" id="period1_end" name="period1_end" value="<?php echo $period1End ?? ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="period2_start" class="form-label">Период 2 (начало)</label>
                                        <input type="date" class="form-control" id="period2_start" name="period2_start" value="<?php echo $period2Start ?? ''; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="period2_end" class="form-label">Период 2 (конец)</label>
                                        <input type="date" class="form-control" id="period2_end" name="period2_end" value="<?php echo $period2End ?? ''; ?>">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">Сравнить периоды</button>
                                    </div>
                                </form>
                                <div class="mt-4">
                                    <canvas id="periodsComparisonChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Тепловая карта посещений -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Тепловая карта посещений (по дням недели и часам)</h5>
                                <div class="mt-4">
                                    <canvas id="heatmapChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- График источников переходов -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">Распределение источников переходов</h5>
                                <div class="mt-4">
                                    <canvas id="referrerChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
				<?php elseif ($currentPage == 'settings'): ?>
                <h3 class="mb-4">Настройки счетчика - <?php echo htmlspecialchars($siteConfig['name'] ?? 'Текущий сайт'); ?></h3>
                
                <?php if (isset($_GET['saved']) && $_GET['saved'] == 1): ?>
                    <div class="alert alert-success">
                        Настройки успешно сохранены!
                    </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Основные настройки счетчика</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="count_unique_ip" name="count_unique_ip" <?php echo $siteConfig['count_unique_ip'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="count_unique_ip">
                                    Подсчитывать только уникальных посетителей
                                </label>
                            </div>
                            
                            <div class="mb-3">
                                <label for="count_interval" class="form-label">Интервал для уникальных посещений (секунды)</label>
                                <input type="number" class="form-control" id="count_interval" name="count_interval" value="<?php echo $siteConfig['count_interval']; ?>">
                                <div class="form-text">Например: 3600 = 1 час</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="excluded_ips" class="form-label">Исключенные IP-адреса (по одному на строку)</label>
                                <textarea class="form-control" id="excluded_ips" name="excluded_ips" rows="3"><?php echo implode("\n", $siteConfig['excluded_ips']); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="items_per_page" class="form-label">Количество записей на странице</label>
                                <select class="form-select" id="items_per_page" name="items_per_page">
                                    <option value="10" <?php echo $siteConfig['items_per_page'] == 10 ? 'selected' : ''; ?>>10</option>
                                    <option value="25" <?php echo $siteConfig['items_per_page'] == 25 ? 'selected' : ''; ?>>25</option>
                                    <option value="50" <?php echo $siteConfig['items_per_page'] == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $siteConfig['items_per_page'] == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                                <div class="form-text">Количество записей, отображаемых на одной странице в таблицах</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Настройки геолокации</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="use_external_api" name="use_external_api" <?php echo $siteConfig['use_external_api'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="use_external_api">
                                    Использовать внешний API, если локальная база не дает результатов
                                </label>
                            </div>
                            
                            <div class="mb-3">
                                <label for="api_url" class="form-label">URL API (с {ip} в качестве плейсхолдера)</label>
                                <input type="text" class="form-control" id="api_url" name="api_url" value="<?php echo htmlspecialchars($siteConfig['api_url']); ?>">
                                <div class="form-text">Например: https://ipinfo.io/{ip}/json</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="api_token" class="form-label">Токен API (если требуется)</label>
                                <input type="text" class="form-control" id="api_token" name="api_token" value="<?php echo htmlspecialchars($siteConfig['api_token']); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <h6>Состояние баз геоданных:</h6>
                                <ul class="list-group">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        MaxMind GeoIP2 (GeoLite2-City.mmdb)
                                        <?php if (file_exists($siteConfig['mmdb_path'])): ?>
                                            <span class="badge bg-success">Найдена</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Не найдена</span>
                                        <?php endif; ?>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        SxGeo (SxGeoCity.dat)
                                        <?php if (file_exists($siteConfig['sxgeo_path'])): ?>
                                            <span class="badge bg-success">Найдена</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Не найдена</span>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5>Настройки отображения счетчика</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="counter_style" class="form-label">Стиль счетчика</label>
                                <select class="form-select" id="counter_style" name="counter_style">
                                    <option value="simple" <?php echo $siteConfig['counter_style'] == 'simple' ? 'selected' : ''; ?>>Простой</option>
                                    <option value="digital" <?php echo $siteConfig['counter_style'] == 'digital' ? 'selected' : ''; ?>>Цифровой (LED)</option>
                                    <option value="modern" <?php echo $siteConfig['counter_style'] == 'modern' ? 'selected' : ''; ?>>Современный</option>
                                    <option value="classic" <?php echo $siteConfig['counter_style'] == 'classic' ? 'selected' : ''; ?>>Классический</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <button type="submit" class="btn btn-primary">Сохранить настройки</button>
                        <a href="index.php?site=<?php echo $currentSite; ?>" class="btn btn-secondary">Отмена</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
	<!-- Модальное окно для подтверждения очистки базы данных -->
    <div class="modal fade" id="cleanupModal" tabindex="-1" aria-labelledby="cleanupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="cleanupModalLabel">Подтверждение очистки базы данных</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger fw-bold">ВНИМАНИЕ! Эта операция удалит ВСЕ данные о посещениях для сайта:</p>
                    <p class="fw-bold"><?php echo htmlspecialchars($siteConfig['name'] ?? 'Текущий сайт'); ?></p>
                    <p>Это действие необратимо. Рекомендуется сделать экспорт данных перед очисткой.</p>
                    <p>Для подтверждения очистки базы данных введите слово "confirm" в поле ниже:</p>
                    <form id="cleanupForm" method="post" action="">
                        <input type="hidden" name="cleanup_db" value="1">
                        <input type="hidden" name="site" value="<?php echo htmlspecialchars($currentSite); ?>">
                        <div class="mb-3">
                            <input type="text" class="form-control" name="confirm_cleanup" id="confirmCleanup" 
                                   placeholder="Введите 'confirm'" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" form="cleanupForm" class="btn btn-danger">Очистить базу данных</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($currentPage == 'dashboard'): ?>
    <script>
        // Данные для графиков
        const dailyData = <?php echo json_encode(array_map(function($item) {
            return ['date' => $item['date'], 'count' => (int)$item['count']];
        }, $dailyStats)); ?>;
        
        const hourlyData = <?php echo json_encode(array_map(function($item) {
            return ['hour' => (int)$item['hour'], 'count' => (int)$item['count']];
        }, $hourlyStats)); ?>;
        
        const browserData = <?php echo json_encode(array_map(function($item) {
            return ['browser' => $item['browser'], 'count' => (int)$item['count']];
        }, $browserStats)); ?>;
        
        const deviceData = <?php echo json_encode(array_map(function($item) {
            return ['device' => $item['device'], 'count' => (int)$item['count']];
        }, $deviceStats)); ?>;
        
        // Данные для карты
        const mapData = <?php echo json_encode($mapData); ?>;
		// График посещений по дням
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.map(item => item.date),
                datasets: [{
                    label: 'Посещения',
                    data: dailyData.map(item => item.count),
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // График посещений по часам
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: Array.from({length: 24}, (_, i) => i + ':00'),
                datasets: [{
                    label: 'Посещения',
                    data: Array.from({length: 24}, (_, i) => {
                        const hourData = hourlyData.find(item => item.hour === i);
                        return hourData ? hourData.count : 0;
                    }),
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // График браузеров
        const browserCtx = document.getElementById('browserChart').getContext('2d');
        new Chart(browserCtx, {
            type: 'pie',
            data: {
                labels: browserData.map(item => item.browser),
                datasets: [{
                    data: browserData.map(item => item.count),
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.2)',
                        'rgba(54, 162, 235, 0.2)',
                        'rgba(255, 206, 86, 0.2)',
                        'rgba(75, 192, 192, 0.2)',
                        'rgba(153, 102, 255, 0.2)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)'
                    ],
                    borderWidth: 1
                }]
            }
        });
        
        // График устройств
        const deviceCtx = document.getElementById('deviceChart').getContext('2d');
        new Chart(deviceCtx, {
            type: 'pie',
            data: {
                labels: deviceData.map(item => item.device),
                datasets: [{
                    data: deviceData.map(item => item.count),
                    backgroundColor: [
                        'rgba(255, 159, 64, 0.2)',
                        'rgba(255, 99, 132, 0.2)',
                        'rgba(54, 162, 235, 0.2)'
                    ],
                    borderColor: [
                        'rgba(255, 159, 64, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)'
                    ],
                    borderWidth: 1
                }]
            }
        });
		// Инициализация карты
        const map = L.map('visits-map').setView([0, 0], 2);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // Функция для получения координат по названию города и страны
        async function getCoordinates(city, country) {
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(city)},${encodeURIComponent(country)}`);
                const data = await response.json();
                
                if (data.length > 0) {
                    return [parseFloat(data[0].lat), parseFloat(data[0].lon)];
                }
                return null;
            } catch (e) {
                console.error('Error fetching coordinates:', e);
                return null;
            }
        }
        
        // Добавление маркеров для каждого города
        async function addMarkersToMap() {
            const processedLocations = new Map();
            
            for (const visit of mapData) {
                if (visit.latitude && visit.longitude && visit.latitude != 0 && visit.longitude != 0) {
                    L.marker([visit.latitude, visit.longitude])
                        .addTo(map)
                        .bindPopup(`<b>${visit.city}, ${visit.country}</b><br>Посещений: ${visit.visits_count}`);
                    continue;
                }
                
                const locationKey = `${visit.city}-${visit.country}`;
                
                if (processedLocations.has(locationKey)) {
                    continue;
                }
                
                const coords = await getCoordinates(visit.city, visit.country);
                if (coords) {
                    L.marker(coords)
                        .addTo(map)
                        .bindPopup(`<b>${visit.city}, ${visit.country}</b><br>Посещений: ${visit.visits_count}`);
                    
                    processedLocations.set(locationKey, true);
                }
            }
        }
        
        addMarkersToMap();
        
        // Функция для открытия всех ссылок в таблице в новых вкладках
        document.addEventListener('DOMContentLoaded', function() {
            const openAllButtons = document.querySelectorAll('.open-all-links');
            
            openAllButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tableId = this.getAttribute('data-target');
                    const table = document.getElementById(tableId);
                    
                    if (table) {
                        const links = table.querySelectorAll('a');
                        links.forEach(link => {
                            window.open(link.href, '_blank');
                        });
                    }
                });
            });
        });
    </script>
    <?php endif; ?>
	<?php if ($currentPage == 'analytics'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Сравнение периодов
            <?php if (isset($periodsComparisonData)): ?>
            const periodsComparisonCtx = document.getElementById('periodsComparisonChart').getContext('2d');
            
            const period1Data = <?php echo json_encode(array_map(function($item) {
                return ['date' => $item['date'], 'count' => (int)$item['count']];
            }, $periodsComparisonData['period1'] ?? [])); ?>;
            
            const period2Data = <?php echo json_encode(array_map(function($item) {
                return ['date' => $item['date'], 'count' => (int)$item['count']];
            }, $periodsComparisonData['period2'] ?? [])); ?>;
            
            const datasets = [];
            
            if (period1Data.length > 0) {
                datasets.push({
                    label: 'Период 1 (<?php echo $period1Start; ?> - <?php echo $period1End; ?>)',
                    data: period1Data.map(item => item.count),
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                });
            }
            
            if (period2Data.length > 0) {
                datasets.push({
                    label: 'Период 2 (<?php echo $period2Start; ?> - <?php echo $period2End; ?>)',
                    data: period2Data.map(item => item.count),
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                });
            }
            
            let allDates = [];
            
            if (period1Data.length > 0) {
                allDates = allDates.concat(period1Data.map(item => item.date));
            }
            
            if (period2Data.length > 0) {
                allDates = allDates.concat(period2Data.map(item => item.date));
            }
            
            allDates = [...new Set(allDates)];
            allDates.sort();
            
            new Chart(periodsComparisonCtx, {
                type: 'line',
                data: {
                    labels: allDates,
                    datasets: datasets
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
            <?php endif; ?>
			// Тепловая карта посещений
            const heatmapCtx = document.getElementById('heatmapChart').getContext('2d');
            
            const heatmapData = <?php echo json_encode(array_map(function($item) {
                return [
                    'day_of_week' => (int)$item['day_of_week'],
                    'hour' => (int)$item['hour'],
                    'count' => (int)$item['count']
                ];
            }, $heatmapData ?? [])); ?>;
            
            const daysOfWeek = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
            const hours = Array.from({length: 24}, (_, i) => i + ':00');
            
            const heatmapValues = Array(7).fill().map(() => Array(24).fill(0));
            
            heatmapData.forEach(item => {
                const dayIndex = item.day_of_week - 1;
                const hourIndex = item.hour;
                
                if (dayIndex >= 0 && dayIndex < 7 && hourIndex >= 0 && hourIndex < 24) {
                    heatmapValues[dayIndex][hourIndex] = item.count;
                }
            });
            
            const heatmapDatasets = daysOfWeek.map((day, index) => {
                return {
                    label: day,
                    data: heatmapValues[index],
                    backgroundColor: `hsla(${index * 360 / 7}, 70%, 60%, 0.7)`,
                    borderColor: `hsla(${index * 360 / 7}, 70%, 50%, 1)`,
                    borderWidth: 1
                };
            });
            
            new Chart(heatmapCtx, {
                type: 'bar',
                data: {
                    labels: hours,
                    datasets: heatmapDatasets
                },
                options: {
                    responsive: true,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Час дня'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Количество посещений'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Распределение посещений по дням недели и часам'
                        },
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const dayLabel = context[0].dataset.label;
                                    const hourLabel = context[0].label;
                                    return `${dayLabel}, ${hourLabel}`;
                                }
                            }
                        }
                    }
                }
            });
            
            // График источников переходов
            const referrerData = <?php echo json_encode(array_map(function($domain, $count) {
                return ['domain' => $domain, 'count' => $count];
            }, array_keys($referrerDomainStats), array_values($referrerDomainStats))); ?>;
            
            const backgroundColors = [
                'rgba(54, 162, 235, 0.2)',
                'rgba(255, 99, 132, 0.2)',
                'rgba(255, 206, 86, 0.2)',
                'rgba(75, 192, 192, 0.2)',
                'rgba(153, 102, 255, 0.2)',
                'rgba(255, 159, 64, 0.2)',
                'rgba(201, 203, 207, 0.2)'
            ];
            
            const borderColors = [
                'rgba(54, 162, 235, 1)',
                'rgba(255, 99, 132, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(153, 102, 255, 1)',
                'rgba(255, 159, 64, 1)',
                'rgba(201, 203, 207, 1)'
            ];
            
            const topDomains = referrerData.slice(0, 6);
            const otherCount = referrerData.slice(6).reduce((sum, item) => sum + item.count, 0);
            
            if (otherCount > 0) {
                topDomains.push({domain: 'Другие', count: otherCount});
            }
            
            const referrerCtx = document.getElementById('referrerChart').getContext('2d');
            new Chart(referrerCtx, {
                type: 'pie',
                data: {
                    labels: topDomains.map(item => item.domain),
                    datasets: [{
                        data: topDomains.map(item => item.count),
                        backgroundColor: backgroundColors.slice(0, topDomains.length),
                        borderColor: borderColors.slice(0, topDomains.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        title: {
                            display: true,
                            text: 'Источники переходов'
                        }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
