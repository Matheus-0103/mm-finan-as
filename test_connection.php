<?php
header('Content-Type: application/json; charset=utf-8');

// Configurações do banco
define('DB_HOST', 'localhost');
define('DB_NAME', 'mmfinancas_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$response = [
    'status' => 'error',
    'message' => '',
    'details' => []
];

try {
    // Teste 1: Conexão com MySQL
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $response['details'][] = ['test' => 'MySQL Connection', 'status' => 'success'];
    
    // Teste 2: Verificar banco de dados
    $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
    $db_exists = $stmt->fetch();
    
    if (!$db_exists) {
        $response['status'] = 'error';
        $response['message'] = "Banco de dados '" . DB_NAME . "' não existe";
        $response['details'][] = ['test' => 'Database Exists', 'status' => 'error'];
        $response['solution'] = 'Acesse http://localhost/phpmyadmin e crie o banco mmfinancas_db';
        echo json_encode($response);
        exit;
    }
    
    $response['details'][] = ['test' => 'Database Exists', 'status' => 'success'];
    
    // Teste 3: Conectar ao banco
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $response['details'][] = ['test' => 'Database Connection', 'status' => 'success'];
    
    // Teste 4: Verificar tabelas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        $response['status'] = 'warning';
        $response['message'] = 'Banco existe mas não tem tabelas';
        $response['details'][] = ['test' => 'Tables Check', 'status' => 'warning'];
        $response['solution'] = 'Importe o arquivo schema.sql';
    } else {
        $response['status'] = 'success';
        $response['message'] = 'Sistema pronto para uso';
        $response['details'][] = [
            'test' => 'Tables Check', 
            'status' => 'success',
            'count' => count($tables),
            'tables' => $tables
        ];
    }
    
} catch (PDOException $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
    
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        $response['solution'] = 'Verifique usuário e senha do MySQL';
        $response['config'] = ['user' => DB_USER, 'password' => DB_PASS ? '***' : '(vazia)'];
    } elseif (strpos($e->getMessage(), 'Connection refused') !== false || 
              strpos($e->getMessage(), 'No connection could be made') !== false) {
        $response['solution'] = 'O servidor MySQL não está rodando! Inicie no XAMPP Control Panel';
    } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
        $response['solution'] = 'Crie o banco de dados mmfinancas_db no phpMyAdmin';
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
