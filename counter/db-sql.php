<?php
/**
 * Скрипт для экспорта структуры базы данных MySQL
 * 
 * Выводит SQL-команды для создания всех таблиц, индексов, ключей, триггеров и представлений
 * в текущей базе данных.
 */

// Параметры подключения к базе данных
$config = [
    'host'     => 'localhost',  // Хост базы данных
    'username' => 'site_counter',       // Имя пользователя
    'password' => 'site_counter',           // Пароль
    'database' => 'site_counter',    // Имя базы данных
];

// Функция для безопасного вывода HTML
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Обработка формы с параметрами подключения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['host'] = $_POST['host'] ?? $config['host'];
    $config['username'] = $_POST['username'] ?? $config['username'];
    $config['password'] = $_POST['password'] ?? $config['password'];
    $config['database'] = $_POST['database'] ?? $config['database'];
}

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $connected = true;
} catch (PDOException $e) {
    $connected = false;
    $error = $e->getMessage();
}

// Функция для получения структуры базы данных
function getDatabaseStructure($pdo, $database) {
    $output = [];
    
    // Команду создания базы данных не добавляем, т.к. предполагается
    // что скрипт будет выполняться в уже существующей базе данных
    
    // Получаем список таблиц
    $tables = [];
    $tables = [];
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $stmt->fetch()) {
        $tables[] = $row["Tables_in_$database"];
    }

    // Для каждой таблицы получаем команду CREATE TABLE
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $createTable = $stmt->fetch();
        $output[] = $createTable['Create Table'] . ";\n";
    }

    // Получаем список представлений (VIEW)
    $views = [];
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    while ($row = $stmt->fetch()) {
        $views[] = $row["Tables_in_$database"];
    }

    // Для каждого представления получаем команду CREATE VIEW
    foreach ($views as $view) {
        $stmt = $pdo->query("SHOW CREATE VIEW `$view`");
        $createView = $stmt->fetch();
        $output[] = $createView['Create View'] . ";\n";
    }

    // Получаем список триггеров
    $triggers = [];
    $stmt = $pdo->query("SHOW TRIGGERS");
    while ($row = $stmt->fetch()) {
        $triggers[] = $row;
    }

    // Для каждого триггера получаем команду CREATE TRIGGER
    foreach ($triggers as $trigger) {
        $triggerName = $trigger['Trigger'];
        $stmt = $pdo->query("SHOW CREATE TRIGGER `$triggerName`");
        $createTrigger = $stmt->fetch();
        $output[] = "DELIMITER //\n" . $createTrigger['SQL Original Statement'] . " //\nDELIMITER ;\n";
    }

    // Получаем список процедур
    $procedures = [];
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = '$database'");
    while ($row = $stmt->fetch()) {
        $procedures[] = $row['Name'];
    }

    // Для каждой процедуры получаем команду CREATE PROCEDURE
    foreach ($procedures as $procedure) {
        $stmt = $pdo->query("SHOW CREATE PROCEDURE `$procedure`");
        $createProcedure = $stmt->fetch();
        $output[] = "DELIMITER //\n" . $createProcedure['Create Procedure'] . " //\nDELIMITER ;\n";
    }

    // Получаем список функций
    $functions = [];
    $stmt = $pdo->query("SHOW FUNCTION STATUS WHERE Db = '$database'");
    while ($row = $stmt->fetch()) {
        $functions[] = $row['Name'];
    }

    // Для каждой функции получаем команду CREATE FUNCTION
    foreach ($functions as $function) {
        $stmt = $pdo->query("SHOW CREATE FUNCTION `$function`");
        $createFunction = $stmt->fetch();
        $output[] = "DELIMITER //\n" . $createFunction['Create Function'] . " //\nDELIMITER ;\n";
    }

    return $output;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Экспорт структуры базы данных</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: red;
            margin-bottom: 15px;
        }
        pre {
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .buttons {
            margin-top: 15px;
        }
        .copy-btn {
            background-color: #2196F3;
        }
        .copy-btn:hover {
            background-color: #0b7dda;
        }
        .download-btn {
            background-color: #ff9800;
        }
        .download-btn:hover {
            background-color: #e68a00;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Экспорт структуры базы данных</h1>
        
        <?php if (!$connected && isset($error)): ?>
            <div class="error">
                <strong>Ошибка подключения:</strong> <?= h($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="host">Хост базы данных:</label>
                <input type="text" id="host" name="host" value="<?= h($config['host']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="username">Имя пользователя:</label>
                <input type="text" id="username" name="username" value="<?= h($config['username']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" value="<?= h($config['password']) ?>">
            </div>
            
            <div class="form-group">
                <label for="database">База данных:</label>
                <input type="text" id="database" name="database" value="<?= h($config['database']) ?>" required>
            </div>
            
            <button type="submit">Подключиться и экспортировать структуру</button>
        </form>
        
        <?php if ($connected): ?>
            <?php $structure = getDatabaseStructure($pdo, $config['database']); ?>
            
            <h2>SQL-команды для создания структуры таблиц базы данных "<?= h($config['database']) ?>"</h2>
            <p>Эти команды можно выполнить в любой базе данных. Команда создания самой базы данных не включена.</p>
            
            <div class="buttons">
                <button class="copy-btn" onclick="copyToClipboard()">Копировать в буфер обмена</button>
                <button class="download-btn" onclick="downloadSQL()">Скачать как SQL-файл</button>
            </div>
            
            <pre id="sql-output"><?php
                foreach ($structure as $sql) {
                    echo h($sql);
                }
            ?></pre>
            
            <script>
                // Функция копирования в буфер обмена
                function copyToClipboard() {
                    const sqlOutput = document.getElementById('sql-output');
                    const textArea = document.createElement('textarea');
                    textArea.value = sqlOutput.textContent;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    alert('SQL-команды скопированы в буфер обмена!');
                }
                
                // Функция для скачивания SQL-файла
                function downloadSQL() {
                    const sqlContent = document.getElementById('sql-output').textContent;
                    const blob = new Blob([sqlContent], { type: 'text/plain' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = '<?= h($config['database']) ?>_tables_structure.sql';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>