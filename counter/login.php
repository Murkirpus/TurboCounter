<?php
session_start();
// Увеличиваем время жизни сессии до 30 дней (в секундах)
ini_set('session.gc_maxlifetime', 2592000); // 30 дней
session_set_cookie_params(2592000); // Также устанавливаем время жизни cookie сессии

// Конфигурация базы данных
$config = [
    'db_host' => 'localhost',
    'db_name' => 'site_counter',
    'db_user' => 'site_counter',
    'db_pass' => 'site_counter'
];

// Загружаем сохраненные настройки, если они есть
$configFile = __DIR__ . '/counter_config.php';
if (file_exists($configFile)) {
    include $configFile;
}

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
        die("Ошибка подключения к базе данных: " . $e->getMessage());
    }
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

// Проверяем, авторизован ли уже пользователь
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Пожалуйста, введите имя пользователя и пароль';
    } else {
        $pdo = connectDB($config);
        $user = authenticateUser($pdo, $username, $password);
        
        if ($user) {
            // Успешная авторизация
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit;
        } else {
            $error = 'Неверное имя пользователя или пароль';
        }
    }
}
?> 

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в панель управления</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-form {
            max-width: 400px;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            background-color: white;
        }
        .form-control {
            margin-bottom: 15px;
        }
        .alert {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-form">
        <h2 class="text-center mb-4">Вход в систему</h2>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Имя пользователя</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Войти</button>
            </div>
        </form>
    </div>
</body>
</html>
