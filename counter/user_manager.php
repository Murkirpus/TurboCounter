<?php
/**
 * Менеджер пользователей для счетчика посещений
 * Позволяет создавать, изменять и удалять пользователей административной панели
 */

// Настройки подключения к базе данных
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

// Функция для получения списка пользователей
function getUsers($pdo) {
    $stmt = $pdo->query("SELECT id, username, email, created_at FROM users ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функция для получения информации о пользователе
function getUser($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Функция для добавления пользователя
function addUser($pdo, $username, $password, $email) {
    // Проверяем, не существует ли уже пользователь с таким именем
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() > 0) {
        return [
            'success' => false,
            'message' => 'Пользователь с таким именем уже существует'
        ];
    }
    
    // Хешируем пароль
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // Добавляем пользователя
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
    $result = $stmt->execute([$username, $passwordHash, $email]);
    
    if ($result) {
        return [
            'success' => true,
            'message' => 'Пользователь успешно добавлен',
            'user_id' => $pdo->lastInsertId()
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Ошибка при добавлении пользователя'
        ];
    }
}

// Функция для изменения пароля пользователя
function changePassword($pdo, $userId, $newPassword) {
    // Проверяем, существует ли пользователь
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() == 0) {
        return [
            'success' => false,
            'message' => 'Пользователь не найден'
        ];
    }
    
    // Хешируем новый пароль
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    
    // Обновляем пароль
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $result = $stmt->execute([$passwordHash, $userId]);
    
    if ($result) {
        return [
            'success' => true,
            'message' => 'Пароль успешно изменен'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Ошибка при изменении пароля'
        ];
    }
}

// Функция для обновления данных пользователя
function updateUser($pdo, $userId, $username, $email) {
    // Проверяем, существует ли пользователь
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() == 0) {
        return [
            'success' => false,
            'message' => 'Пользователь не найден'
        ];
    }
    
    // Проверяем, не занято ли имя пользователя другим пользователем
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $userId]);
    if ($stmt->fetchColumn() > 0) {
        return [
            'success' => false,
            'message' => 'Пользователь с таким именем уже существует'
        ];
    }
    
    // Обновляем данные пользователя
    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
    $result = $stmt->execute([$username, $email, $userId]);
    
    if ($result) {
        return [
            'success' => true,
            'message' => 'Данные пользователя успешно обновлены'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Ошибка при обновлении данных пользователя'
        ];
    }
}

// Функция для удаления пользователя
function deleteUser($pdo, $userId) {
    // Проверяем, существует ли пользователь
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() == 0) {
        return [
            'success' => false,
            'message' => 'Пользователь не найден'
        ];
    }
    
    // Проверяем, не удаляем ли последнего администратора
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() <= 1) {
        return [
            'success' => false,
            'message' => 'Нельзя удалить единственного пользователя системы'
        ];
    }
    
    // Удаляем пользователя
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $result = $stmt->execute([$userId]);
    
    if ($result) {
        return [
            'success' => true,
            'message' => 'Пользователь успешно удален'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Ошибка при удалении пользователя'
        ];
    }
}

// Подключаемся к базе данных
$pdo = connectDB($config);

// Обрабатываем форму добавления пользователя
$addUserResult = null;
if (isset($_POST['add_user'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (empty($username) || empty($password)) {
        $addUserResult = [
            'success' => false,
            'message' => 'Имя пользователя и пароль обязательны'
        ];
    } else {
        $addUserResult = addUser($pdo, $username, $password, $email);
    }
}

// Обрабатываем форму изменения пароля
$changePasswordResult = null;
if (isset($_POST['change_password'])) {
    $userId = $_POST['user_id'] ?? 0;
    $newPassword = $_POST['new_password'] ?? '';
    
    if (empty($newPassword)) {
        $changePasswordResult = [
            'success' => false,
            'message' => 'Новый пароль не может быть пустым'
        ];
    } else {
        $changePasswordResult = changePassword($pdo, $userId, $newPassword);
    }
}

// Обрабатываем форму обновления данных пользователя
$updateUserResult = null;
if (isset($_POST['update_user'])) {
    $userId = $_POST['user_id'] ?? 0;
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (empty($username)) {
        $updateUserResult = [
            'success' => false,
            'message' => 'Имя пользователя не может быть пустым'
        ];
    } else {
        $updateUserResult = updateUser($pdo, $userId, $username, $email);
    }
}

// Обрабатываем запрос на удаление пользователя
$deleteUserResult = null;
if (isset($_GET['delete_user'])) {
    $userId = $_GET['delete_user'] ?? 0;
    $deleteUserResult = deleteUser($pdo, $userId);
}

// Получаем список пользователей
$users = getUsers($pdo);

// Получаем информацию о пользователе для редактирования
$editUser = null;
if (isset($_GET['edit_user'])) {
    $userId = $_GET['edit_user'] ?? 0;
    $editUser = getUser($pdo, $userId);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление пользователями</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            margin-top: 20px;
        }
        .user-card {
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
        }
        .card-header {
            background-color: #343a40;
            color: white;
        }
        .action-buttons {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Управление пользователями счетчика</h1>
        
        <!-- Сообщения о результате операций -->
        <?php if ($addUserResult): ?>
            <div class="alert alert-<?php echo $addUserResult['success'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $addUserResult['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($changePasswordResult): ?>
            <div class="alert alert-<?php echo $changePasswordResult['success'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $changePasswordResult['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($updateUserResult): ?>
            <div class="alert alert-<?php echo $updateUserResult['success'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $updateUserResult['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($deleteUserResult): ?>
            <div class="alert alert-<?php echo $deleteUserResult['success'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $deleteUserResult['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Список пользователей -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Список пользователей</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Имя пользователя</th>
                                        <th>Email</th>
                                        <th>Создан</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                        <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                                        <td class="action-buttons">
                                            <a href="?edit_user=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">Изменить</a>
                                            <a href="?delete_user=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены, что хотите удалить этого пользователя?')">Удалить</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($users) == 0): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">Пользователи не найдены</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Форма для добавления/редактирования пользователя -->
            <div class="col-md-6">
                <?php if ($editUser): ?>
                <!-- Редактирование пользователя -->
                <div class="card user-card">
                    <div class="card-header">
                        <h5>Редактирование пользователя</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Имя пользователя</label>
                                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($editUser['username']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="update_user" class="btn btn-primary">Обновить данные</button>
                                <a href="user_manager.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card user-card">
                    <div class="card-header">
                        <h5>Изменение пароля</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Новый пароль</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="change_password" class="btn btn-warning">Изменить пароль</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <!-- Добавление нового пользователя -->
                <div class="card user-card">
                    <div class="card-header">
                        <h5>Добавление нового пользователя</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Имя пользователя</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Пароль</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="add_user" class="btn btn-success">Добавить пользователя</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-4">
            <a href="/counter/index.php" class="btn btn-primary">Вернуться в админ-панель</a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>