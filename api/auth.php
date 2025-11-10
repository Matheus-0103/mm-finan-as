<?php
// ============================================
// MM FINANÇAS - API DE AUTENTICAÇÃO
// ============================================

require_once __DIR__ . '/../config.php';

$db = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? '';

try {
    // REGISTRO DE USUÁRIO
    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? 'user';
        
        // Validação
        if (empty($name) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Todos os campos são obrigatórios']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido']);
            exit;
        }
        
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'A senha deve ter no mínimo 6 caracteres']);
            exit;
        }
        
        if (!in_array($role, ['user', 'manager'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tipo de conta inválido']);
            exit;
        }
        
        // Verifica se email já existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Este email já está cadastrado']);
            exit;
        }
        
        // Cria usuário
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        
        if ($stmt->execute([$name, $email, $password_hash, $role])) {
            $userId = $db->lastInsertId();
            
            // Log da ação
            addLog('user_registered', ['user_id' => $userId, 'email' => $email, 'role' => $role], $userId);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Usuário cadastrado com sucesso',
                'user_id' => $userId
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar usuário']);
        }
        exit;
    }
    
    // LOGIN
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email e senha são obrigatórios']);
            exit;
        }
        
        // Busca usuário
        $stmt = $db->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Email ou senha incorretos']);
            exit;
        }
        
        // Cria sessão
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        
        // Log da ação
        addLog('user_login', ['email' => $email], $user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login realizado com sucesso',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    }
    
    // LOGOUT
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            addLog('user_logout', [], $userId);
        }
        
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
        exit;
    }
    
    // VERIFICAR SESSÃO
    if ($action === 'check') {
        if (isset($_SESSION['user_id'])) {
            $stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                echo json_encode([
                    'authenticated' => true,
                    'user' => [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role']
                    ]
                ]);
            } else {
                session_destroy();
                echo json_encode(['authenticated' => false]);
            }
        } else {
            echo json_encode(['authenticated' => false]);
        }
        exit;
    }
    
    // ATUALIZAR CONTA
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAuth();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = $_SESSION['user_id'];
        
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $verifyToken = $data['verify_token'] ?? '';
        $managerEmail = isset($data['manager_email']) ? trim($data['manager_email']) : null;
        
        // Validação básica
        if (empty($name) || empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome e email são obrigatórios']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido']);
            exit;
        }
        
        // Busca dados atuais do usuário
        $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();
        
        // Verifica se email foi alterado
        $emailChanged = ($email !== $currentUser['email']);
        
        // Se email mudou ou senha foi fornecida, requer verificação
        if (($emailChanged || !empty($password)) && empty($verifyToken)) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Verificação necessária',
                'requires_verification' => true,
                'type' => $emailChanged ? 'email' : 'password'
            ]);
            exit;
        }
        
        // Se há token de verificação, valida
        if (!empty($verifyToken)) {
            if (!isset($_SESSION['verify_token']) || 
                $_SESSION['verify_token'] !== $verifyToken ||
                $_SESSION['verify_expires'] < time()) {
                http_response_code(401);
                echo json_encode(['error' => 'Token de verificação inválido ou expirado']);
                exit;
            }
            // Limpa token após uso
            unset($_SESSION['verify_token']);
            unset($_SESSION['verify_type']);
            unset($_SESSION['verify_expires']);
        }
        
        // Verifica se novo email já está em uso por outro usuário
        if ($emailChanged) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Este email já está em uso']);
                exit;
            }
        }
        
        // Processa vínculo com gestor (apenas para usuários normais)
        $managerId = null;
        if ($_SESSION['user_role'] === 'user' && $managerEmail !== null) {
            if (!empty($managerEmail)) {
                // Busca gestor pelo email
                $stmt = $db->prepare("SELECT id, role FROM users WHERE email = ?");
                $stmt->execute([$managerEmail]);
                $manager = $stmt->fetch();
                
                if (!$manager) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Gestor não encontrado com este email']);
                    exit;
                }
                
                if ($manager['role'] !== 'manager') {
                    http_response_code(400);
                    echo json_encode(['error' => 'O email informado não pertence a um gestor']);
                    exit;
                }
                
                $managerId = $manager['id'];
            }
            // Se managerEmail estiver vazio, $managerId fica null (remove vínculo)
        }
        
        // Atualiza usuário
        if (!empty($password)) {
            if (strlen($password) < 6) {
                http_response_code(400);
                echo json_encode(['error' => 'A senha deve ter no mínimo 6 caracteres']);
                exit;
            }
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            if ($_SESSION['user_role'] === 'user' && $managerEmail !== null) {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = ?, manager_id = ? WHERE id = ?");
                $stmt->execute([$name, $email, $password_hash, $managerId, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$name, $email, $password_hash, $userId]);
            }
        } else {
            if ($_SESSION['user_role'] === 'user' && $managerEmail !== null) {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, manager_id = ? WHERE id = ?");
                $stmt->execute([$name, $email, $managerId, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([$name, $email, $userId]);
            }
        }
        
        // Log de vinculação/desvinculação
        if ($_SESSION['user_role'] === 'user' && $managerEmail !== null) {
            if ($managerId) {
                addLog('manager_linked', ['manager_email' => $managerEmail], $userId);
            } else {
                addLog('manager_unlinked', [], $userId);
            }
        }
        
        // Atualiza sessão
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        
        // Log da ação
        addLog('user_updated', ['email' => $email], $userId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Conta atualizada com sucesso',
            'user' => [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $_SESSION['user_role']
            ]
        ]);
        exit;
    }
    
    // OBTER GESTOR ATUAL
    if ($action === 'get-manager' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        
        $userId = $_SESSION['user_id'];
        
        // Busca manager_id do usuário atual
        $stmt = $db->prepare("SELECT manager_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user && $user['manager_id']) {
            // Busca dados do gestor
            $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$user['manager_id']]);
            $manager = $stmt->fetch();
            
            if ($manager) {
                echo json_encode([
                    'success' => true,
                    'manager' => [
                        'id' => $manager['id'],
                        'name' => $manager['name'],
                        'email' => $manager['email']
                    ]
                ]);
            } else {
                echo json_encode(['success' => true, 'manager' => null]);
            }
        } else {
            echo json_encode(['success' => true, 'manager' => null]);
        }
        exit;
    }
    
    // Endpoint não encontrado
    http_response_code(404);
    echo json_encode(['error' => 'Ação não encontrada']);
    
} catch (Exception $e) {
    error_log('Erro na API de autenticação: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>
