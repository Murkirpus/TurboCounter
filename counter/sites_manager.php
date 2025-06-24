<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Загружаем текущую конфигурацию
$configFile = __DIR__ . '/counter_config.php';
$config = [];

if (file_exists($configFile)) {
    include $configFile;
} else {
    // Если файла конфигурации нет, создаем базовую структуру
    $config = [
        'db_host' => 'localhost',
        'db_name' => 'site_counter',
        'db_user' => 'site_counter',
        'db_pass' => 'site_counter',
        'count_unique_ip' => true,
        'count_interval' => 86400,
        'excluded_ips' => ['127.0.0.1'],
        'mmdb_path' => __DIR__ . '/GeoLite2-City.mmdb',
        'sxgeo_path' => __DIR__ . '/SxGeoCity.dat',
        'use_external_api' => true,
        'api_url' => 'https://ipinfo.io/{ip}/json',
        'api_token' => '',
        'max_queue_size' => 1000,
        'queue_batch_size' => 50,
        'auto_process_chance' => 5,
        'cache_ttl' => 604800,
        'cleanup_chance' => 2,
        'counter_style' => 'simple',
        'items_per_page' => 25,
        'default_site' => 'main',
        'sites' => []
    ];
}

// Функция для сохранения конфигурации в правильном формате
function saveConfig($config) {
    global $configFile;
    
    // Извлекаем массив сайтов
    $sites = $config['sites'] ?? [];
    
    // Создаем содержимое файла
    $content = "<?php\n";
    $content .= "// Конфигурация для нескольких сайтов\n";
    $content .= "\$sites_config = " . var_export($sites, true) . ";\n\n";
    
    // Убираем sites из основного конфига для записи
    $mainConfig = $config;
    unset($mainConfig['sites']);
    
    $content .= "// Общие настройки (применяются ко всем сайтам)\n";
    $content .= "\$config = " . var_export($mainConfig, true) . ";\n\n";
    $content .= "// Добавляем сайты обратно\n";
    $content .= "\$config['sites'] = \$sites_config;\n";
    $content .= "?>";
    
    return file_put_contents($configFile, $content);
}

// Функция проверки подключения к базе данных
function testDatabaseConnection($dbConfig) {
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['db_host']};dbname={$dbConfig['db_name']};charset=utf8mb4",
            $dbConfig['db_user'],
            $dbConfig['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        
        // Проверяем наличие нужных таблиц
        $stmt = $pdo->query("SHOW TABLES LIKE 'visits'");
        $hasVisitsTable = ($stmt->rowCount() > 0);
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $hasUsersTable = ($stmt->rowCount() > 0);
        
        return [
            'success' => true,
            'message' => 'Подключение успешно',
            'has_visits_table' => $hasVisitsTable,
            'has_users_table' => $hasUsersTable
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage(),
            'has_visits_table' => false,
            'has_users_table' => false
        ];
    }
}

// Функция создания таблиц в базе данных
function createTables($dbConfig) {
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['db_host']};dbname={$dbConfig['db_name']};charset=utf8mb4",
            $dbConfig['db_user'],
            $dbConfig['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Создаем таблицу visits
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `visits` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `page_url` varchar(500) NOT NULL,
                `ip_address` varchar(45) NOT NULL,
                `user_agent` text DEFAULT NULL,
                `visit_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `referer` varchar(500) DEFAULT NULL,
                `country` varchar(100) DEFAULT 'Неизвестно',
                `city` varchar(100) DEFAULT 'Неизвестно',
                `latitude` float DEFAULT 0,
                `longitude` float DEFAULT 0,
                `region` varchar(100) DEFAULT '',
                `timezone` varchar(50) DEFAULT '',
                `browser` varchar(50) DEFAULT 'Other',
                `device` varchar(50) DEFAULT 'Desktop',
                PRIMARY KEY (`id`),
                KEY `ip_address` (`ip_address`),
                KEY `visit_time` (`visit_time`),
                KEY `page_url` (`page_url`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        // Создаем таблицу geo_cache
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `geo_cache` (
                `ip_address` varchar(45) NOT NULL,
                `country` varchar(100) NOT NULL DEFAULT 'Неизвестно',
                `city` varchar(100) NOT NULL DEFAULT 'Неизвестно',
                `latitude` float DEFAULT 0,
                `longitude` float DEFAULT 0,
                `region` varchar(100) DEFAULT '',
                `timezone` varchar(50) DEFAULT '',
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ip_address`),
                KEY `updated_at` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        // Создаем таблицу users (только если её нет)
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL,
                    `password` varchar(255) NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            
            // Добавляем админа по умолчанию (пароль: admin)
            $hashedPassword = password_hash('admin', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute(['admin', $hashedPassword]);
        }
        
        return ['success' => true, 'message' => 'Таблицы успешно созданы'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка создания таблиц: ' . $e->getMessage()];
    }
}

// Обработка форм
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_site':
                $siteKey = trim($_POST['site_key']);
                $siteName = trim($_POST['site_name']);
                $dbHost = trim($_POST['db_host']);
                $dbName = trim($_POST['db_name']);
                $dbUser = trim($_POST['db_user']);
                $dbPass = trim($_POST['db_pass']);
                $siteUrl = trim($_POST['site_url']);
                $siteColor = trim($_POST['site_color']);
                
                if (empty($siteKey) || empty($siteName) || empty($dbHost) || empty($dbName) || empty($dbUser)) {
                    $message = '<div class="alert alert-danger">Заполните все обязательные поля!</div>';
                } elseif (!preg_match('/^[a-z0-9_]+$/', $siteKey)) {
                    $message = '<div class="alert alert-danger">Ключ сайта может содержать только латинские буквы, цифры и подчеркивания!</div>';
                } elseif (isset($config['sites'][$siteKey])) {
                    $message = '<div class="alert alert-danger">Сайт с таким ключом уже существует!</div>';
                } else {
                    // Тестируем подключение к базе данных
                    $dbConfig = [
                        'db_host' => $dbHost,
                        'db_name' => $dbName,
                        'db_user' => $dbUser,
                        'db_pass' => $dbPass
                    ];
                    
                    $testResult = testDatabaseConnection($dbConfig);
                    
                    if ($testResult['success']) {
                        // Если таблиц нет, предлагаем их создать
                        if (!$testResult['has_visits_table']) {
                            $createResult = createTables($dbConfig);
                            if (!$createResult['success']) {
                                $message = '<div class="alert alert-warning">Подключение к БД успешно, но не удалось создать таблицы: ' . $createResult['message'] . '</div>';
                            }
                        }
                        
                        // Добавляем сайт в конфигурацию
                        if (!isset($config['sites'])) {
                            $config['sites'] = [];
                        }
                        
                        $config['sites'][$siteKey] = [
                            'name' => $siteName,
                            'db_host' => $dbHost,
                            'db_name' => $dbName,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'url' => $siteUrl,
                            'color' => $siteColor ?: '#007bff'
                        ];
                        
                        // Если это первый сайт, делаем его сайтом по умолчанию
                        if (count($config['sites']) == 1) {
                            $config['default_site'] = $siteKey;
                        }
                        
                        if (saveConfig($config)) {
                            $message = '<div class="alert alert-success">Сайт успешно добавлен!</div>';
                        } else {
                            $message = '<div class="alert alert-danger">Ошибка при сохранении конфигурации!</div>';
                        }
                    } else {
                        $message = '<div class="alert alert-danger">Не удалось подключиться к базе данных: ' . $testResult['message'] . '</div>';
                    }
                }
                break;
                
            case 'edit_site':
                $siteKey = $_POST['site_key'];
                if (isset($config['sites'][$siteKey])) {
                    $config['sites'][$siteKey]['name'] = trim($_POST['site_name']);
                    $config['sites'][$siteKey]['url'] = trim($_POST['site_url']);
                    $config['sites'][$siteKey]['color'] = trim($_POST['site_color']) ?: '#007bff';
                    
                    // Обновляем параметры БД, если они переданы
                    if (!empty($_POST['db_host'])) {
                        $config['sites'][$siteKey]['db_host'] = trim($_POST['db_host']);
                    }
                    if (!empty($_POST['db_name'])) {
                        $config['sites'][$siteKey]['db_name'] = trim($_POST['db_name']);
                    }
                    if (!empty($_POST['db_user'])) {
                        $config['sites'][$siteKey]['db_user'] = trim($_POST['db_user']);
                    }
                    if (!empty($_POST['db_pass'])) {
                        $config['sites'][$siteKey]['db_pass'] = trim($_POST['db_pass']);
                    }
                    
                    if (saveConfig($config)) {
                        $message = '<div class="alert alert-success">Настройки сайта обновлены!</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Ошибка при сохранении!</div>';
                    }
                }
                break;
                
            case 'delete_site':
                $siteKey = $_POST['site_key'];
                if (isset($config['sites'][$siteKey])) {
                    unset($config['sites'][$siteKey]);
                    
                    // Если удалили сайт по умолчанию, выбираем новый
                    if ($config['default_site'] == $siteKey) {
                        $remaining = array_keys($config['sites']);
                        $config['default_site'] = !empty($remaining) ? $remaining[0] : 'main';
                    }
                    
                    if (saveConfig($config)) {
                        $message = '<div class="alert alert-success">Сайт удален!</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Ошибка при удалении!</div>';
                    }
                }
                break;
                
            case 'test_connection':
                $dbConfig = [
                    'db_host' => trim($_POST['db_host']),
                    'db_name' => trim($_POST['db_name']),
                    'db_user' => trim($_POST['db_user']),
                    'db_pass' => trim($_POST['db_pass'])
                ];
                
                $testResult = testDatabaseConnection($dbConfig);
                
                if ($testResult['success']) {
                    $status = '<span class="text-success">✓ Подключение успешно</span>';
                    if (!$testResult['has_visits_table']) {
                        $status .= '<br><span class="text-warning">⚠ Таблица visits не найдена</span>';
                    }
                    if (!$testResult['has_users_table']) {
                        $status .= '<br><span class="text-warning">⚠ Таблица users не найдена</span>';
                    }
                } else {
                    $status = '<span class="text-danger">✗ ' . $testResult['message'] . '</span>';
                }
                
                echo json_encode(['status' => $status]);
                exit;
                
            case 'set_default':
                $siteKey = $_POST['site_key'];
                if (isset($config['sites'][$siteKey])) {
                    $config['default_site'] = $siteKey;
                    
                    if (saveConfig($config)) {
                        $message = '<div class="alert alert-success">Сайт по умолчанию изменен!</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Ошибка при сохранении!</div>';
                    }
                }
                break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление сайтами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .site-color-preview {
            width: 20px;
            height: 20px;
            border-radius: 3px;
            display: inline-block;
            margin-right: 8px;
            border: 1px solid #ddd;
        }
        .connection-status {
            min-height: 20px;
        }
        .card-header .badge {
            font-size: 0.75em;
        }
        .form-floating .form-control:focus ~ label {
            color: #0d6efd;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-collection"></i> Управление сайтами</h2>
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Назад к панели
            </a>
        </div>
        
        <?php echo $message; ?>
        
        <!-- Форма добавления нового сайта -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Добавить новый сайт</h5>
            </div>
            <div class="card-body">
                <form method="post" id="addSiteForm">
                    <input type="hidden" name="action" value="add_site">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3">Основные настройки</h6>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="site_key" id="site_key" 
                                       placeholder="Ключ сайта" required pattern="[a-z0-9_]+" 
                                       title="Только латинские буквы, цифры и подчеркивания">
                                <label for="site_key">Ключ сайта *</label>
                                <div class="form-text">Только латинские буквы, цифры и _ (например: main, blog, shop)</div>
                            </div>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="site_name" id="site_name" 
                                       placeholder="Название сайта" required>
                                <label for="site_name">Название сайта *</label>
                            </div>
                            
                            <div class="form-floating mb-3">
                                <input type="url" class="form-control" name="site_url" id="site_url" 
                                       placeholder="URL сайта">
                                <label for="site_url">URL сайта</label>
                                <div class="form-text">Например: https://example.com</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="site_color" class="form-label">Цвет для идентификации</label>
                                <div class="d-flex align-items-center">
                                    <input type="color" class="form-control form-control-color me-2" 
                                           name="site_color" id="site_color" value="#007bff" 
                                           style="width: 50px; height: 38px;">
                                    <span class="form-text">Цвет для визуального различения сайта</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3">Настройки базы данных</h6>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="db_host" id="db_host" 
                                       placeholder="Хост БД" value="localhost" required>
                                <label for="db_host">Хост базы данных *</label>
                            </div>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="db_name" id="db_name" 
                                       placeholder="Имя БД" required>
                                <label for="db_name">Имя базы данных *</label>
                            </div>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="db_user" id="db_user" 
                                       placeholder="Пользователь БД" required>
                                <label for="db_user">Пользователь БД *</label>
                            </div>
                            
                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" name="db_pass" id="db_pass" 
                                       placeholder="Пароль БД">
                                <label for="db_pass">Пароль БД</label>
                            </div>
                            
                            <button type="button" class="btn btn-outline-info mb-3" onclick="testConnection()">
                                <i class="bi bi-wifi"></i> Проверить подключение
                            </button>
                            <div id="connectionStatus" class="connection-status mb-3"></div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus"></i> Добавить сайт
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Список существующих сайтов -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-list"></i> Существующие сайты (<?php echo count($config['sites'] ?? []); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($config['sites'])): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Сайты не добавлены. Добавьте первый сайт выше.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($config['sites'] as $siteKey => $siteInfo): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100 <?php echo ($config['default_site'] ?? '') == $siteKey ? 'border-success' : ''; ?>">
                                <div class="card-header d-flex justify-content-between align-items-center" 
                                     style="background-color: <?php echo $siteInfo['color'] ?? '#007bff'; ?>; color: white;">
                                    <div class="d-flex align-items-center">
                                        <span class="site-color-preview" style="background-color: <?php echo $siteInfo['color'] ?? '#007bff'; ?>; border-color: white;"></span>
                                        <strong><?php echo htmlspecialchars($siteInfo['name']); ?></strong>
                                    </div>
                                    <div>
                                        <?php if (($config['default_site'] ?? '') == $siteKey): ?>
                                            <span class="badge bg-light text-dark">По умолчанию</span>
                                        <?php endif; ?>
                                        <code class="text-white-50"><?php echo htmlspecialchars($siteKey); ?></code>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <strong>База данных:</strong><br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($siteInfo['db_host'] ?? 'localhost'); ?> / 
                                            <?php echo htmlspecialchars($siteInfo['db_name']); ?>
                                            <br>Пользователь: <?php echo htmlspecialchars($siteInfo['db_user']); ?>
                                        </small>
                                    </div>
                                    
                                    <?php if (!empty($siteInfo['url'])): ?>
                                    <div class="mb-2">
                                        <strong>URL:</strong><br>
                                        <a href="<?php echo htmlspecialchars($siteInfo['url']); ?>" target="_blank" class="small">
                                            <?php echo htmlspecialchars($siteInfo['url']); ?>
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Проверка состояния БД -->
                                    <div class="mb-3">
                                        <?php 
                                        $dbConfig = [
                                            'db_host' => $siteInfo['db_host'] ?? 'localhost',
                                            'db_name' => $siteInfo['db_name'],
                                            'db_user' => $siteInfo['db_user'],
                                            'db_pass' => $siteInfo['db_pass']
                                        ];
                                        $testResult = testDatabaseConnection($dbConfig);
                                        ?>
                                        
                                        <?php if ($testResult['success']): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> БД доступна
                                            </span>
                                            <?php if (!$testResult['has_visits_table']): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-exclamation-triangle"></i> Нет таблиц
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-x-circle"></i> БД недоступна
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="btn-group w-100" role="group">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                data-bs-target="#editModal<?php echo $siteKey; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        
                                        <?php if (($config['default_site'] ?? '') != $siteKey): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="set_default">
                                            <input type="hidden" name="site_key" value="<?php echo $siteKey; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" 
                                                    title="Сделать сайтом по умолчанию">
                                                <i class="bi bi-star"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <a href="index.php?site=<?php echo $siteKey; ?>" class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        
                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                                data-bs-target="#deleteModal<?php echo $siteKey; ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Модальное окно редактирования -->
                        <div class="modal fade" id="editModal<?php echo $siteKey; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Редактировать сайт: <?php echo htmlspecialchars($siteInfo['name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="post">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="edit_site">
                                            <input type="hidden" name="site_key" value="<?php echo $siteKey; ?>">
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6>Основные настройки</h6>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="text" class="form-control" name="site_name" 
                                                               value="<?php echo htmlspecialchars($siteInfo['name']); ?>" required>
                                                        <label>Название сайта</label>
                                                    </div>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="url" class="form-control" name="site_url" 
                                                               value="<?php echo htmlspecialchars($siteInfo['url'] ?? ''); ?>">
                                                        <label>URL сайта</label>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Цвет</label>
                                                        <input type="color" class="form-control form-control-color" name="site_color" 
                                                               value="<?php echo $siteInfo['color'] ?? '#007bff'; ?>">
                                                    </div>
                                                </div>
                                                
                                                <div class="col-md-6">
                                                    <h6>Настройки базы данных</h6>
                                                    <p class="text-muted small">Оставьте пустым, чтобы не изменять</p>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="text" class="form-control" name="db_host" 
                                                               placeholder="<?php echo htmlspecialchars($siteInfo['db_host'] ?? 'localhost'); ?>">
                                                        <label>Хост БД</label>
                                                        <div class="form-text">Текущий: <?php echo htmlspecialchars($siteInfo['db_host'] ?? 'localhost'); ?></div>
                                                    </div>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="text" class="form-control" name="db_name" 
                                                               placeholder="<?php echo htmlspecialchars($siteInfo['db_name']); ?>">
                                                        <label>Имя БД</label>
                                                        <div class="form-text">Текущая: <?php echo htmlspecialchars($siteInfo['db_name']); ?></div>
                                                    </div>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="text" class="form-control" name="db_user" 
                                                               placeholder="<?php echo htmlspecialchars($siteInfo['db_user']); ?>">
                                                        <label>Пользователь БД</label>
                                                        <div class="form-text">Текущий: <?php echo htmlspecialchars($siteInfo['db_user']); ?></div>
                                                    </div>
                                                    
                                                    <div class="form-floating mb-3">
                                                        <input type="password" class="form-control" name="db_pass" 
                                                               placeholder="Новый пароль">
                                                        <label>Пароль БД</label>
                                                        <div class="form-text">Оставьте пустым, чтобы не менять</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Модальное окно удаления -->
                        <div class="modal fade" id="deleteModal<?php echo $siteKey; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title">Удалить сайт</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>Внимание!</strong> Это действие нельзя отменить.
                                        </div>
                                        
                                        <p>Вы уверены, что хотите удалить сайт <strong><?php echo htmlspecialchars($siteInfo['name']); ?></strong>?</p>
                                        
                                        <div class="card bg-light">
                                            <div class="card-body small">
                                                <strong>Будет удалено:</strong>
                                                <ul class="mb-0">
                                                    <li>Конфигурация сайта из панели управления</li>
                                                    <li>Настройки подключения к базе данных</li>
                                                </ul>
                                                <strong class="text-success">НЕ будет удалено:</strong>
                                                <ul class="mb-0">
                                                    <li>Сама база данных и данные в ней</li>
                                                    <li>Файлы счетчика на сайте</li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <?php if (($config['default_site'] ?? '') == $siteKey): ?>
                                        <div class="alert alert-info mt-3">
                                            <i class="bi bi-info-circle"></i>
                                            Этот сайт установлен как сайт по умолчанию. После удаления автоматически будет выбран другой сайт.
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="delete_site">
                                            <input type="hidden" name="site_key" value="<?php echo $siteKey; ?>">
                                            <button type="submit" class="btn btn-danger">
                                                <i class="bi bi-trash"></i> Да, удалить сайт
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Инструкции и информация -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-question-circle"></i> Инструкции по добавлению сайта</h5>
                    </div>
                    <div class="card-body">
                        <ol class="mb-3">
                            <li>Создайте новую базу данных MySQL для счетчика</li>
                            <li>Создайте пользователя с правами на эту базу данных</li>
                            <li>Заполните форму выше и нажмите "Проверить подключение"</li>
                            <li>Если подключение успешно, нажмите "Добавить сайт"</li>
                            <li>Таблицы создадутся автоматически</li>
                            <li>Установите счетчик на ваш сайт</li>
                        </ol>
                        
                        <div class="alert alert-light">
                            <strong>Совет:</strong> Используйте осмысленные ключи сайтов (main, blog, shop) и разные цвета для удобства.
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-code-square"></i> SQL для создания таблиц</h5>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">Таблицы создаются автоматически, но если нужно создать их вручную:</p>
                        
                        <div class="accordion" id="sqlAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sqlCollapse">
                                        Показать SQL код
                                    </button>
                                </h2>
                                <div id="sqlCollapse" class="accordion-collapse collapse" data-bs-parent="#sqlAccordion">
                                    <div class="accordion-body">
                                        <pre class="bg-light p-3 rounded small"><code>-- Таблица посещений
CREATE TABLE visits (
  id int(11) NOT NULL AUTO_INCREMENT,
  page_url varchar(500) NOT NULL,
  ip_address varchar(45) NOT NULL,
  user_agent text DEFAULT NULL,
  visit_time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  referer varchar(500) DEFAULT NULL,
  country varchar(100) DEFAULT 'Неизвестно',
  city varchar(100) DEFAULT 'Неизвестно',
  latitude float DEFAULT 0,
  longitude float DEFAULT 0,
  region varchar(100) DEFAULT '',
  timezone varchar(50) DEFAULT '',
  browser varchar(50) DEFAULT 'Other',
  device varchar(50) DEFAULT 'Desktop',
  PRIMARY KEY (id),
  KEY ip_address (ip_address),
  KEY visit_time (visit_time),
  KEY page_url (page_url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица кэша геоданных
CREATE TABLE geo_cache (
  ip_address varchar(45) NOT NULL,
  country varchar(100) NOT NULL DEFAULT 'Неизвестно',
  city varchar(100) NOT NULL DEFAULT 'Неизвестно',
  latitude float DEFAULT 0,
  longitude float DEFAULT 0,
  region varchar(100) DEFAULT '',
  timezone varchar(50) DEFAULT '',
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ip_address),
  KEY updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица пользователей (только для новых установок)
CREATE TABLE users (
  id int(11) NOT NULL AUTO_INCREMENT,
  username varchar(50) NOT NULL,
  password varchar(255) NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Информация о текущей конфигурации -->
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-gear"></i> Текущая конфигурация</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Файл конфигурации:</strong><br>
                        <code class="small"><?php echo htmlspecialchars($configFile); ?></code>
                        <br><br>
                        <strong>Сайт по умолчанию:</strong><br>
                        <span class="badge bg-primary"><?php echo htmlspecialchars($config['default_site'] ?? 'main'); ?></span>
                    </div>
                    <div class="col-md-4">
                        <strong>Общие настройки:</strong><br>
                        <small class="text-muted">
                            Интервал уникальности: <?php echo $config['count_interval'] ?? 86400; ?> сек<br>
                            Стиль счетчика: <?php echo $config['counter_style'] ?? 'simple'; ?><br>
                            Записей на странице: <?php echo $config['items_per_page'] ?? 25; ?>
                        </small>
                    </div>
                    <div class="col-md-4">
                        <strong>Статистика:</strong><br>
                        <small class="text-muted">
                            Всего сайтов: <?php echo count($config['sites'] ?? []); ?><br>
                            Активных подключений: 
                            <?php 
                            $activeConnections = 0;
                            foreach ($config['sites'] ?? [] as $site) {
                                $testResult = testDatabaseConnection([
                                    'db_host' => $site['db_host'] ?? 'localhost',
                                    'db_name' => $site['db_name'],
                                    'db_user' => $site['db_user'],
                                    'db_pass' => $site['db_pass']
                                ]);
                                if ($testResult['success']) $activeConnections++;
                            }
                            echo $activeConnections;
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Функция тестирования подключения к БД
        function testConnection() {
            const formData = new FormData();
            formData.append('action', 'test_connection');
            formData.append('db_host', document.getElementById('db_host').value);
            formData.append('db_name', document.getElementById('db_name').value);
            formData.append('db_user', document.getElementById('db_user').value);
            formData.append('db_pass', document.getElementById('db_pass').value);
            
            const statusDiv = document.getElementById('connectionStatus');
            statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Проверка подключения...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                statusDiv.innerHTML = data.status;
            })
            .catch(error => {
                statusDiv.innerHTML = '<span class="text-danger">✗ Ошибка при проверке</span>';
                console.error('Error:', error);
            });
        }
        
        // Автоматическое заполнение полей БД при вводе ключа сайта
        document.getElementById('site_key').addEventListener('input', function() {
            const siteKey = this.value;
            if (siteKey && siteKey.match(/^[a-z0-9_]+$/)) {
                const dbNameField = document.getElementById('db_name');
                const dbUserField = document.getElementById('db_user');
                
                if (!dbNameField.value) {
                    dbNameField.value = siteKey + '_counter';
                }
                if (!dbUserField.value) {
                    dbUserField.value = siteKey + '_counter';
                }
            }
        });
        
        // Подтверждение удаления
        document.querySelectorAll('[data-bs-target^="#deleteModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const siteName = this.closest('.card').querySelector('.card-header strong').textContent;
                console.log('Preparing to delete site:', siteName);
            });
        });
        
        // Валидация формы
        document.getElementById('addSiteForm').addEventListener('submit', function(e) {
            const siteKey = document.getElementById('site_key').value;
            const siteName = document.getElementById('site_name').value;
            const dbName = document.getElementById('db_name').value;
            const dbUser = document.getElementById('db_user').value;
            
            if (!siteKey || !siteName || !dbName || !dbUser) {
                e.preventDefault();
                alert('Пожалуйста, заполните все обязательные поля!');
                return;
            }
            
            if (!siteKey.match(/^[a-z0-9_]+$/)) {
                e.preventDefault();
                alert('Ключ сайта может содержать только латинские буквы, цифры и подчеркивания!');
                return;
            }
        });
        
        // Tooltip для кнопок
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>
