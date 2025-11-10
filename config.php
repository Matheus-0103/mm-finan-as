<?php
// ============================================
// MM FINANÇAS - CONFIGURAÇÃO DO BANCO DE DADOS
// ============================================

// Controle de debug: altere para true apenas em desenvolvimento local
define('APP_DEBUG', true);

if (APP_DEBUG) {
    // Habilita exibição detalhada de erros para debugging local
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    // Converter warnings/notices para exceções para que sejam capturados
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    // Tratador de exceções não capturadas: retorna JSON com mensagem detalhada
    set_exception_handler(function($e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
        exit;
    });

    // Captura erros fatais no shutdown e retorna JSON com detalhe
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Erro fatal: ' . ($err['message'] ?? 'unknown')]);
            exit;
        }
    });

} else {
    // Produção/produção-similar: não expor detalhes sensíveis
    ini_set('display_errors', '0');
    error_reporting(0);

    set_exception_handler(function($e) {
        // Log interno (arquivo de log do PHP ou sistema de logs)
        error_log($e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Erro interno do servidor']);
        exit;
    });

    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log('Fatal error: ' . ($err['message'] ?? 'unknown'));
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Erro interno do servidor']);
            exit;
        }
    });
}

// Configurações do banco
define('DB_HOST', 'localhost');
define('DB_NAME', 'mmfinancas_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Configurações da aplicação
define('BASE_URL', 'http://localhost/mmfinancas');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_UPLOAD_SIZE', 10485760); // 10MB

// Carrega configurações de desenvolvimento se existirem
if (file_exists(__DIR__ . '/config.dev.php')) {
    require_once __DIR__ . '/config.dev.php';
} else {
    define('DEV_MODE', false);
    define('DEV_LOG_LEVEL', 'info');
    define('DEV_CACHE', true);
}

// Configurações de segurança
define('RATE_LIMIT_REQUESTS', 30);     // Requisições por minuto
define('RATE_LIMIT_WINDOW', 60);       // Janela de tempo em segundos
define('SESSION_LIFETIME', 7200);      // 2 horas
define('PASSWORD_MIN_LENGTH', 8);      // Mínimo de caracteres para senhas
define('BCRYPT_COST', 12);            // Custo do hash bcrypt

// Configurações de logging
define('LOG_DIR', __DIR__ . '/logs/');
if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0755, true);

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Inicia sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Classe de conexão com banco de dados
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Sugestão útil quando o banco não existe
            if (stripos($msg, 'Unknown database') !== false) {
                $msg .= ' — crie o banco de dados ou importe o arquivo schema.sql (veja c:\\xampp\\htdocs\\schema.sql)';
            }
            die(json_encode(['error' => 'Erro de conexão: ' . $msg]));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

// Função para adicionar log
function addLog($action, $meta = [], $userId = null) {
    $db = Database::getInstance()->getConnection();
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $stmt = $db->prepare("INSERT INTO logs (user_id, action, meta, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, json_encode($meta), $ip]);
}

// Função para verificar autenticação
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
}

// Função para verificar se é gestor
function requireManager() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'manager') {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado']);
        exit;
    }
}

// Headers CORS e JSON (apenas se estiver em contexto HTTP)
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
?>