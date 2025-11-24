<?php
/**
 * ОПТИМИЗИРОВАННАЯ ВЕРСИЯ ADMIN PANEL (FIXED CHARTS HEIGHT)
 * С кэшированием тяжелых запросов для работы с большими базами данных
 */

session_start();

// ======= НАСТРОЙКИ КЭШИРОВАНИЯ =======
// Время жизни кэша статистики в секундах
define('STATS_CACHE_TTL', 600); // 10 минут для графиков и таблиц
define('TOTALS_CACHE_TTL', 60); // 1 минута для счетчиков в шапке
define('CACHE_DIR', __DIR__ . '/cache/stats'); // Папка для кэша

// Создаем папку кэша, если нет
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}
// =====================================

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
    $config = array_merge($default_config, $config);
} else {
    $config = $default_config;
}

// Получаем выбранный сайт
$currentSite = $_GET['site'] ?? $_SESSION['current_site'] ?? $config['default_site'] ?? 'main';
$_SESSION['current_site'] = $currentSite;

function getCurrentSiteConfig($config, $siteKey) {
    if (isset($config['sites'][$siteKey])) {
        return array_merge($config, $config['sites'][$siteKey]);
    }
    return $config;
}

$siteConfig = getCurrentSiteConfig($config, $currentSite);

function connectDB($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Ошибка подключения к БД ({$config['db_name']}): " . $e->getMessage());
        return null;
    }
}

// Вспомогательная функция для кэширования
function getCachedData($key, $callback, $ttl = STATS_CACHE_TTL) {
    global $currentSite;
    $cacheFile = CACHE_DIR . '/' . md5($currentSite . '_' . $key) . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data !== null) return $data;
    }
    
    $data = $callback();
    file_put_contents($cacheFile, json_encode($data));
    return $data;
}

// Оптимизированная функция получения статистики по всем сайтам
function getAllSitesStats($config) {
    return getCachedData('all_sites_overview', function() use ($config) {
        $sitesStats = [];
        foreach ($config['sites'] as $siteKey => $siteConfig) {
            try {
                $siteFullConfig = array_merge($config, $siteConfig);
                $pdo = connectDB($siteFullConfig);
                if (!$pdo) {
                    $sitesStats[$siteKey] = [
                        'name' => $siteConfig['name'],
                        'stats' => ['error' => 'Ошибка подключения к БД'],
                        'url' => $siteConfig['url'] ?? '',
                        'color' => $siteConfig['color'] ?? '#dc3545'
                    ];
                    continue;
                }
                $stats = [];
                $stmt = $pdo->query("SELECT table_rows FROM information_schema.tables WHERE table_schema = '{$siteConfig['db_name']}' AND table_name = 'visits'");
                $approxRows = $stmt->fetchColumn();
                if ($approxRows < 10000) {
                     $stats['total_visits'] = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
                } else {
                     $stats['total_visits'] = $approxRows; 
                }
                $stats['today_visits'] = $pdo->query("SELECT COUNT(*) FROM visits WHERE visit_time >= CURDATE()")->fetchColumn();
                $stats['unique_visitors'] = "N/A"; 
                $stats['month_visits'] = "N/A";
                $sitesStats[$siteKey] = [
                    'name' => $siteConfig['name'],
                    'stats' => $stats,
                    'url' => $siteConfig['url'] ?? '',
                    'color' => $siteConfig['color'] ?? '#007bff'
                ];
            } catch (Exception $e) {}
        }
        return $sitesStats;
    }, 300);
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

// Функция очистки
function cleanupDatabase($pdo, $daysToKeep = 0) {
    try {
        $pdo->beginTransaction();
        if ($daysToKeep > 0) {
            $stmt = $pdo->prepare("DELETE FROM visits WHERE visit_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$daysToKeep]);
            $stmt = $pdo->prepare("DELETE FROM geo_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$daysToKeep]);
        } else {
            $pdo->exec("TRUNCATE TABLE visits");
            $pdo->exec("TRUNCATE TABLE geo_cache");
        }
        $pdo->commit();
        array_map('unlink', glob(CACHE_DIR . '/*.json'));
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Ошибка очистки БД: " . $e->getMessage());
        return false;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

checkAuth();
$pdo = connectDB($siteConfig);
if (!$pdo) {
    $pdo = connectDB($config);
    if (!$pdo) die("Critical Error: No DB connection");
}

// === ЛОГИКА ОЧИСТКИ ===
if (isset($_POST['cleanup_db']) && $_POST['cleanup_db'] === '1') {
    $daysToKeep = isset($_POST['days_to_keep']) ? (int)$_POST['days_to_keep'] : 0;
    $isFullCleanup = ($daysToKeep === 0);
    
    if (!$isFullCleanup || (isset($_POST['confirm_cleanup']) && $_POST['confirm_cleanup'] === 'confirm')) {
        $cleanupResult = cleanupDatabase($pdo, $daysToKeep);
        if ($cleanupResult) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'База данных успешно очищена!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Ошибка очистки!'];
        }
    }
    header("Location: index.php?site=" . $currentSite);
    exit;
}

if (isset($_GET['clear_cache'])) {
    array_map('unlink', glob(CACHE_DIR . '/*.json'));
    header("Location: index.php?site=" . $currentSite);
    exit;
}

// === ПОЛУЧЕНИЕ ДАННЫХ ===
function getBasicStats($pdo) {
    return getCachedData('basic_stats', function() use ($pdo) {
        $stats = [];
        $stats['total_visits'] = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
        $stats['unique_visitors'] = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits")->fetchColumn();
        $stats['today_visits'] = $pdo->query("SELECT COUNT(*) FROM visits WHERE visit_time >= CURDATE()")->fetchColumn();
        $stats['today_unique'] = $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM visits WHERE visit_time >= CURDATE()")->fetchColumn();
        $stats['month_visits'] = $pdo->query("SELECT COUNT(*) FROM visits WHERE visit_time >= DATE_FORMAT(NOW() ,'%Y-%m-01')")->fetchColumn();
        return $stats;
    }, TOTALS_CACHE_TTL);
}

function getDatabaseSize($pdo, $dbName) {
    $stmt = $pdo->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'size_mb' FROM information_schema.tables WHERE table_schema = :dbName");
    $stmt->execute([':dbName' => $dbName]);
    $res = $stmt->fetch();
    return $res ? $res['size_mb'] : 0;
}

function getDailyStats($pdo, $days = 30) {
    return getCachedData('daily_stats_' . $days, function() use ($pdo, $days) {
        $stmt = $pdo->prepare("SELECT DATE(visit_time) as date, COUNT(*) as count FROM visits WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(visit_time) ORDER BY date");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

function getHourlyStats($pdo) {
    return getCachedData('hourly_stats', function() use ($pdo) {
        $stmt = $pdo->query("SELECT HOUR(visit_time) as hour, COUNT(*) as count FROM visits WHERE visit_time >= CURDATE() GROUP BY HOUR(visit_time) ORDER BY hour");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

function getTopStats($pdo, $field, $limit = 10) {
    $allowed = ['country', 'city', 'browser', 'device', 'page_url', 'referer'];
    if (!in_array($field, $allowed)) return [];
    return getCachedData("top_{$field}_{$limit}", function() use ($pdo, $field, $limit) {
        $stmt = $pdo->query("SELECT {$field}, COUNT(*) as count FROM visits WHERE {$field} != '' AND {$field} != 'Неизвестно' GROUP BY {$field} ORDER BY count DESC LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

function getRecentVisits($pdo, $page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    $stats = getBasicStats($pdo);
    $totalItems = $stats['total_visits']; 
    $stmt = $pdo->prepare("SELECT id, page_url, ip_address, country, city, browser, device, visit_time FROM visits ORDER BY id DESC LIMIT :offset, :perPage");
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

function getMapData($pdo) {
    return getCachedData('map_data', function() use ($pdo) {
        $stmt = $pdo->query("SELECT ip_address, country, city, latitude, longitude, count(*) as visits_count FROM visits WHERE latitude != 0 AND visit_time > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY city, country ORDER BY visits_count DESC LIMIT 1000");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

if (isset($_GET['export'])) {
    set_time_limit(600);
    $format = $_GET['export'];
    header('Content-Type: ' . ($format == 'excel' ? 'application/vnd.ms-excel' : 'text/csv') . '; charset=UTF-8');
    header('Content-Disposition: attachment; filename=export_' . date('Y-m-d') . '.' . ($format == 'excel' ? 'xls' : 'csv'));
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $stmt = $pdo->query("SELECT * FROM visits ORDER BY id DESC LIMIT 50000");
    $header = ['ID', 'URL', 'IP', 'Время', 'Страна', 'Город', 'Браузер', 'Устройство'];
    if ($format == 'excel') {
        echo "<table><tr><th>" . implode("</th><th>", $header) . "</th></tr>";
    } else {
        fputcsv($out, $header);
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($format == 'excel') {
            echo "<tr><td>" . implode("</td><td>", array_map('htmlspecialchars', $row)) . "</td></tr>";
        } else {
            fputcsv($out, $row);
        }
    }
    if ($format == 'excel') echo "</table>";
    else fclose($out);
    exit;
}

$currentPage = $_GET['page'] ?? 'dashboard';
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
$allSitesStats = !empty($siteConfig['sites']) ? getAllSitesStats($siteConfig) : [];
$basicStats = getBasicStats($pdo);
$dbSize = getDatabaseSize($pdo, $siteConfig['db_name']);
$dailyStats = getDailyStats($pdo);
$hourlyStats = getHourlyStats($pdo);
$countryStats = getTopStats($pdo, 'country');
$cityStats = getTopStats($pdo, 'city');
$browserStats = getTopStats($pdo, 'browser');
$deviceStats = getTopStats($pdo, 'device');
$mapData = getMapData($pdo);
$pageRecent = isset($_GET['recent_page']) ? (int)$_GET['recent_page'] : 1;
$recentVisits = getRecentVisits($pdo, $pageRecent, $siteConfig['items_per_page'] ?? 20);
$popularPages = getCachedData('popular_pages_' . ($pagePopular = $_GET['popular_page'] ?? 1), function() use ($pdo) {
    $stmt = $pdo->query("SELECT page_url, COUNT(*) as visit_count FROM visits WHERE visit_time > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY page_url ORDER BY visit_count DESC LIMIT 20");
    return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
});

function createPagination($curr, $total, $param) {
    if ($total <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-center">';
    if ($curr > 1) $html .= '<li class="page-item"><a class="page-link" href="?'.$param.'='.($curr-1).'&site='.$GLOBALS['currentSite'].'">&laquo;</a></li>';
    $html .= '<li class="page-item active"><span class="page-link">'.$curr.'</span></li>';
    if ($curr < $total) $html .= '<li class="page-item"><a class="page-link" href="?'.$param.'='.($curr+1).'&site='.$GLOBALS['currentSite'].'">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель (Optimized)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <style>
        .stats-card { margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border:none; }
        .sidebar { min-height: 100vh; background: #212529; color: white; }
        .sidebar a { color: rgba(255,255,255,0.8); text-decoration: none; padding: 10px; display: block; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.1); color: white; border-radius: 5px;}
        .map-container { height: 400px; width: 100%; border-radius: 8px; overflow: hidden; }
        .site-badge { width: 12px; height: 12px; display: inline-block; border-radius: 50%; margin-right: 5px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-3">
                <h4 class="mb-4">📊 Counter</h4>
                <?php if (!empty($siteConfig['sites'])): ?>
                    <div class="mb-4">
                        <small class="text-muted text-uppercase">Сайты</small>
                        <?php foreach ($siteConfig['sites'] as $key => $s): ?>
                            <a href="?site=<?php echo $key; ?>" class="<?php echo $key === $currentSite ? 'active' : ''; ?>">
                                <span class="site-badge" style="background: <?php echo $s['color'] ?? '#0d6efd'; ?>"></span>
                                <?php echo htmlspecialchars($s['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mb-4">
                    <small class="text-muted text-uppercase">Меню</small>
                    <a href="?site=<?php echo $currentSite; ?>" class="active"><i class="bi bi-speedometer2"></i> Обзор</a>
                    <a href="?clear_cache=1&site=<?php echo $currentSite; ?>"><i class="bi bi-arrow-clockwise"></i> Сбросить кэш</a>
                    <a href="#settings" data-bs-toggle="modal" data-bs-target="#smartCleanupModal"><i class="bi bi-tools"></i> Обслуживание</a>
                    <a href="?logout=1" class="text-danger"><i class="bi bi-box-arrow-left"></i> Выход</a>
                </div>
            </div>

            <div class="col-md-10 p-4 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="m-0"><?php echo htmlspecialchars($siteConfig['name']); ?></h2>
                        <small class="text-muted"><a href="<?php echo htmlspecialchars($siteConfig['url']); ?>" target="_blank"><?php echo htmlspecialchars($siteConfig['url']); ?></a></small>
                    </div>
                    <div>
                        <div class="btn-group">
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#smartCleanupModal"><i class="bi bi-stars"></i> Очистка</button>
                            <a href="?export=csv&site=<?php echo $currentSite; ?>" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show">
                        <?php echo $message['text']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card p-3">
                            <small class="text-muted">Всего визитов</small>
                            <h3 class="fw-bold text-primary"><?php echo number_format($basicStats['total_visits']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3">
                            <small class="text-muted">Сегодня</small>
                            <h3 class="fw-bold text-success"><?php echo number_format($basicStats['today_visits']); ?></h3>
                            <small class="text-success">+<?php echo $basicStats['today_unique']; ?> уник.</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3">
                            <small class="text-muted">Размер БД</small>
                            <h3 class="fw-bold text-danger"><?php echo $dbSize; ?> MB</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3">
                            <small class="text-muted">В этом месяце</small>
                            <h3 class="fw-bold text-info"><?php echo number_format($basicStats['month_visits']); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-8">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Динамика (30 дней)</h5>
                                <div style="position: relative; height: 300px; width: 100%;">
                                    <canvas id="dailyChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Активность сегодня</h5>
                                <div style="position: relative; height: 300px; width: 100%;">
                                    <canvas id="hourlyChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card stats-card mb-4">
                    <div class="card-body p-0">
                         <div id="map" class="map-container"></div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card stats-card h-100">
                            <div class="card-header bg-white">ТОП Стран</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <?php foreach ($countryStats as $c): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($c['country']); ?></td>
                                        <td class="text-end fw-bold"><?php echo $c['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card h-100">
                            <div class="card-header bg-white">ТОП Браузеров</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <?php foreach ($browserStats as $b): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($b['browser']); ?></td>
                                        <td class="text-end fw-bold"><?php echo $b['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card h-100">
                            <div class="card-header bg-white">Популярные страницы (30 дн)</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <?php foreach ($popularPages['data'] as $p): ?>
                                    <tr>
                                        <td class="text-truncate" style="max-width: 200px;">
                                            <a href="<?php echo htmlspecialchars($p['page_url']); ?>" target="_blank"><?php echo htmlspecialchars($p['page_url']); ?></a>
                                        </td>
                                        <td class="text-end"><?php echo $p['visit_count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card stats-card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="m-0">Последние визиты</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Время</th>
                                    <th>IP</th>
                                    <th>Локация</th>
                                    <th>Устройство</th>
                                    <th>Страница</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentVisits['data'] as $v): ?>
                                <tr>
                                    <td><?php echo date('H:i d.m', strtotime($v['visit_time'])); ?></td>
                                    <td>
                                        <a href="https://whatismyipaddress.com/ip/<?php echo $v['ip_address']; ?>" target="_blank" class="text-decoration-none">
                                            <?php echo $v['ip_address']; ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($v['country'] . ', ' . $v['city']); ?></td>
                                    <td>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($v['browser']); ?></small>
                                        <?php echo htmlspecialchars($v['device']); ?>
                                    </td>
                                    <td class="text-truncate" style="max-width: 250px;">
                                        <a href="<?php echo htmlspecialchars($v['page_url']); ?>" target="_blank" class="text-decoration-none">
                                            <?php echo htmlspecialchars($v['page_url']); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white">
                        <?php echo createPagination($recentVisits['current'], $recentVisits['pages'], 'recent_page'); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal fade" id="smartCleanupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Обслуживание базы данных</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Умная очистка</h6>
                    <p class="text-muted small">Удалит старые записи, чтобы сайт работал быстрее. Свежие данные останутся.</p>
                    <form method="post" class="mb-4 border-bottom pb-3">
                        <input type="hidden" name="cleanup_db" value="1">
                        <div class="input-group">
                            <select class="form-select" name="days_to_keep">
                                <option value="30">Оставить 1 месяц</option>
                                <option value="90" selected>Оставить 3 месяца</option>
                                <option value="365">Оставить 1 год</option>
                            </select>
                            <button class="btn btn-success" type="submit">Очистить</button>
                        </div>
                    </form>
                    <h6 class="text-danger">2. Полное удаление</h6>
                    <p class="text-muted small">Удалит ВСЕ данные безвозвратно.</p>
                    <button class="btn btn-outline-danger w-100" data-bs-target="#fullCleanupConfirm" data-bs-toggle="modal">Удалить всё</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="fullCleanupConfirm" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Вы уверены?</h5>
                </div>
                <div class="modal-body">
                    <p>Введите <b>confirm</b> для подтверждения:</p>
                    <form method="post">
                        <input type="hidden" name="cleanup_db" value="1">
                        <input type="hidden" name="days_to_keep" value="0">
                        <input type="text" name="confirm_cleanup" class="form-control mb-3" required>
                        <button class="btn btn-danger w-100">ПОДТВЕРДИТЬ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyData = <?php echo json_encode($dailyStats); ?>;
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.map(d => d.date),
                datasets: [{
                    label: 'Визиты',
                    data: dailyData.map(d => d.count),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { 
                maintainAspectRatio: false, // Важно!
                plugins: { legend: { display: false } }, 
                scales: { y: { beginAtZero: true } }
            }
        });

        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const hourlyData = <?php echo json_encode($hourlyStats); ?>;
        const hourLabels = Array.from({length: 24}, (_, i) => i + ':00');
        const hourCounts = Array(24).fill(0);
        hourlyData.forEach(d => hourCounts[parseInt(d.hour)] = d.count);
        
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Сегодня',
                    data: hourCounts,
                    backgroundColor: '#198754'
                }]
            },
            options: { 
                maintainAspectRatio: false, // Важно!
                plugins: { legend: { display: false } }, 
                scales: { y: { beginAtZero: true } }
            }
        });

        const map = L.map('map').setView([20, 0], 2);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO'
        }).addTo(map);
        
        const mapPoints = <?php echo json_encode($mapData); ?>;
        mapPoints.forEach(p => {
            if(p.latitude && p.longitude) {
                L.circleMarker([p.latitude, p.longitude], {
                    radius: Math.min(10, Math.max(3, Math.log(p.visits_count) * 2)),
                    fillColor: "#ff0000",
                    color: "#000",
                    weight: 0,
                    opacity: 1,
                    fillOpacity: 0.6
                }).bindPopup(`<b>${p.city}</b>: ${p.visits_count}`).addTo(map);
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>