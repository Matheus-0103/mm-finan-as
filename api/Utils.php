<?php
// ============================================
// MM FINANÇAS - BIBLIOTECA DE UTILITÁRIOS
// ============================================

class Utils {
    // Cache de consultas frequentes
    private static $cache = [];
    private static $cacheExpiry = [];

    /**
     * Sanitiza e valida entrada de usuário
     */
    public static function sanitizeInput($data, $type = 'string') {
        if (is_array($data)) {
            return array_map(fn($item) => self::sanitizeInput($item, $type), $data);
        }

        switch ($type) {
            case 'email':
                $data = filter_var(trim($data), FILTER_SANITIZE_EMAIL);
                return filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : false;
            
            case 'int':
                return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            case 'html':
                return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
            
            default:
                return trim(strip_tags($data));
        }
    }

    /**
     * Verifica se usuário é desenvolvedor
     */
    public static function isDeveloper() {
        global $DEV_AUTHORIZED_EMAILS;
        
        // Verifica se está em modo de desenvolvimento
        if (!defined('DEV_MODE') || !DEV_MODE) return false;
        
        // Verifica se é um email autorizado
        $userEmail = $_SESSION['user_email'] ?? '';
        if (!in_array($userEmail, $DEV_AUTHORIZED_EMAILS)) return false;
        
        // Verifica se tem a chave de desenvolvedor na sessão
        return isset($_SESSION['dev_key']) && $_SESSION['dev_key'] === DEV_KEY;
    }

    /**
     * Rate limiting aprimorado
     */
    public static function checkRateLimit($userId, $action, $limit, $window) {
        $db = Database::getInstance()->getConnection();
        $now = time();
        
        // Se for desenvolvedor, aplica limites mais permissivos
        if (self::isDeveloper()) {
            $limit = DEV_RATE_LIMIT_REQUESTS;
            $window = DEV_RATE_LIMIT_WINDOW;
        }

        // Chave única para o rate limit
        $key = "rate_limit:{$action}:{$userId}";
        
        // Verifica cache primeiro
        if (isset(self::$cache[$key]) && self::$cacheExpiry[$key] > $now) {
            $count = self::$cache[$key];
        } else {
            // Busca do banco
            $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits 
                WHERE user_id = ? AND action = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$userId, $action, $window]);
            $count = (int)$stmt->fetchColumn();
            
            // Atualiza cache
            self::$cache[$key] = $count;
            self::$cacheExpiry[$key] = $now + 60; // Cache por 1 minuto
        }

        // Se excedeu o limite
        if ($count >= $limit) {
            return false;
        }

        // Registra nova requisição
        $stmt = $db->prepare("INSERT INTO rate_limits (user_id, action, timestamp) VALUES (?, ?, NOW())");
        $stmt->execute([$userId, $action]);
        
        // Atualiza cache
        self::$cache[$key] = $count + 1;
        
        return true;
    }

    /**
     * Gera token seguro
     */
    public static function generateSecureToken($length = 32) {
        try {
            return bin2hex(random_bytes($length / 2));
        } catch (Exception $e) {
            // Fallback mais seguro que mt_rand()
            $chars = '0123456789abcdef';
            $token = '';
            for ($i = 0; $i < $length; $i++) {
                $token .= $chars[random_int(0, 15)];
            }
            return $token;
        }
    }

    /**
     * Log seguro de eventos
     */
    public static function secureLog($type, $message, $data = []) {
        // Remove dados sensíveis
        $sensitiveKeys = ['password', 'password_hash', 'token', 'code'];
        array_walk_recursive($data, function(&$value, $key) use ($sensitiveKeys) {
            if (in_array($key, $sensitiveKeys)) {
                $value = '***';
            }
        });

        // Adiciona contexto
        $context = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? 'guest',
            'user_type' => self::isDeveloper() ? 'developer' : 'user'
        ];

        // Log detalhado em desenvolvimento
        if (defined('DEV_MODE') && DEV_MODE && defined('DEV_LOG_LEVEL') && DEV_LOG_LEVEL === 'debug') {
            $context['debug'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        }

        $logEntry = json_encode([
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'context' => $context
        ], JSON_UNESCAPED_UNICODE);

        // Grava em arquivo com rotação diária
        $logFile = __DIR__ . '/logs/' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Cache inteligente
     */
    public static function cache($key, $callback, $ttl = 300) {
        $now = time();
        
        // Desativa cache em desenvolvimento se configurado
        if (defined('DEV_MODE') && DEV_MODE && defined('DEV_CACHE') && !DEV_CACHE) {
            return $callback();
        }

        // Verifica cache em memória
        if (isset(self::$cache[$key]) && self::$cacheExpiry[$key] > $now) {
            return self::$cache[$key];
        }

        // Executa callback
        $result = $callback();
        
        // Armazena em cache
        self::$cache[$key] = $result;
        self::$cacheExpiry[$key] = $now + $ttl;
        
        return $result;
    }
}