<?php
// ============================================
// MM FINANÇAS - API DO GESTOR
// ============================================

require_once '../config.php';

requireManager();

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// CRIAR NOVO CLIENTE
if ($method === 'POST' && $action === 'create-client') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    // Validações
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
        echo json_encode(['error' => 'Senha deve ter no mínimo 6 caracteres']);
        exit;
    }
    
    // Verifica se email já existe
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Este email já está cadastrado']);
        exit;
    }
    
    // Cria cliente
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, manager_id) VALUES (?, ?, ?, 'user', ?)");
    
    try {
        $stmt->execute([$name, $email, $passwordHash, $_SESSION['user_id']]);
        $userId = $db->lastInsertId();
        
        addLog('client_created_by_manager', ['user_id' => $userId, 'email' => $email]);
        
        http_response_code(201);
        echo json_encode([
            'message' => 'Cliente criado com sucesso',
            'user_id' => $userId
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar cliente']);
    }
    exit;
}

// LISTAR CLIENTES DO GESTOR
if ($method === 'GET' && $action === 'clients') {
    try {
        // Verify that the current user is a manager
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || $user['role'] !== 'manager') {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso não autorizado. Apenas gestores podem acessar esta função.']);
            exit;
        }
        
        // Get all clients for this manager
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.created_at,
                   COUNT(DISTINCT a.id) as total_accounts,
                   COALESCE(SUM(a.value), 0) as total_value
            FROM users u
            LEFT JOIN accounts a ON u.id = a.user_id
            WHERE u.manager_id = ? AND u.role = 'user'
            GROUP BY u.id
            ORDER BY u.name ASC
        ");
        
        $stmt->execute([$_SESSION['user_id']]);
        $clients = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'clients' => $clients,
            'total_clients' => count($clients)
        ]);
        exit;
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar clientes. Por favor, tente novamente.']);
        exit;
    } catch (Exception $e) {
        error_log('Server error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno do servidor']);
        exit;
    }
}

// DETALHES DE UM CLIENTE
if ($method === 'GET' && $action === 'client-details') {
    $clientId = $_GET['client_id'] ?? 0;
    
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de cliente inválido']);
        exit;
    }
    
    // Verifica se o cliente pertence ao gestor
    $stmt = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = ? AND manager_id = ?");
    $stmt->execute([$clientId, $_SESSION['user_id']]);
    $client = $stmt->fetch();
    
    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'Cliente não encontrado']);
        exit;
    }
    
    // Busca contas do cliente
    $stmt = $db->prepare("
        SELECT a.*, c.name as category_name, c.icon as category_icon
        FROM accounts a
        INNER JOIN categories c ON a.category_id = c.id
        WHERE a.user_id = ?
        ORDER BY a.date DESC
        LIMIT 50
    ");
    $stmt->execute([$clientId]);
    $accounts = $stmt->fetchAll();
    
    // Estatísticas
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_accounts,
            SUM(value) as total_value,
            AVG(value) as avg_value,
            MAX(value) as max_value
        FROM accounts
        WHERE user_id = ?
    ");
    $stmt->execute([$clientId]);
    $stats = $stmt->fetch();
    
    // Gastos por categoria
    $stmt = $db->prepare("
        SELECT c.name, c.icon, SUM(a.value) as total
        FROM accounts a
        INNER JOIN categories c ON a.category_id = c.id
        WHERE a.user_id = ?
        GROUP BY c.id
        ORDER BY total DESC
    ");
    $stmt->execute([$clientId]);
    $byCategory = $stmt->fetchAll();
    
    // Gastos mensais (últimos 6 meses)
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(value) as total
        FROM accounts
        WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute([$clientId]);
    $byMonth = $stmt->fetchAll();
    
    echo json_encode([
        'client' => $client,
        'accounts' => $accounts,
        'stats' => $stats,
        'by_category' => $byCategory,
        'by_month' => $byMonth
    ]);
    exit;
}

// ENVIAR FEEDBACK
if ($method === 'POST' && $action === 'send-feedback') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $userId = (int)($data['user_id'] ?? 0);
    $message = trim($data['message'] ?? '');
    
    if ($userId <= 0 || empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }
    
    // Verifica se o usuário é cliente do gestor
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND manager_id = ?");
    $stmt->execute([$userId, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Cliente não encontrado']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO feedbacks (user_id, manager_id, message) VALUES (?, ?, ?)");
    
    try {
        $stmt->execute([$userId, $_SESSION['user_id'], $message]);
        $feedbackId = $db->lastInsertId();
        
        addLog('feedback_sent', ['feedback_id' => $feedbackId, 'user_id' => $userId]);
        
        http_response_code(201);
        echo json_encode([
            'message' => 'Feedback enviado com sucesso',
            'feedback_id' => $feedbackId
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao enviar feedback']);
    }
    exit;
}

// LISTAR FEEDBACKS ENVIADOS
if ($method === 'GET' && $action === 'feedbacks') {
    $clientId = $_GET['client_id'] ?? null;
    
    $sql = "SELECT f.*, u.name as user_name, u.email as user_email
            FROM feedbacks f
            INNER JOIN users u ON f.user_id = u.id
            WHERE f.manager_id = ?";
    $params = [$_SESSION['user_id']];
    
    if ($clientId) {
        $sql .= " AND f.user_id = ?";
        $params[] = $clientId;
    }
    
    $sql .= " ORDER BY f.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $feedbacks = $stmt->fetchAll();
    
    echo json_encode(['feedbacks' => $feedbacks]);
    exit;
}

// ESTATÍSTICAS GERAIS
if ($method === 'GET' && $action === 'stats') {
    // Total de clientes
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE manager_id = ? AND role = 'user'");
    $stmt->execute([$_SESSION['user_id']]);
    $totalClients = $stmt->fetch()['total'];
    
    // Total de despesas dos clientes
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_accounts, SUM(a.value) as total_value
        FROM accounts a
        INNER JOIN users u ON a.user_id = u.id
        WHERE u.manager_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $accountsStats = $stmt->fetch();
    
    // Total de feedbacks enviados
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM feedbacks WHERE manager_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $totalFeedbacks = $stmt->fetch()['total'];
    
    echo json_encode([
        'total_clients' => $totalClients,
        'total_accounts' => $accountsStats['total_accounts'] ?? 0,
        'total_value' => $accountsStats['total_value'] ?? 0,
        'total_feedbacks' => $totalFeedbacks
    ]);
    exit;
}

// EXPORTAR RELATÓRIO CSV
if ($method === 'GET' && $action === 'export-csv') {
    $clientId = $_GET['client_id'] ?? null;
    $month = $_GET['month'] ?? null;
    
    $sql = "SELECT a.date, u.name as user_name, c.name as category, a.value, a.description
            FROM accounts a
            INNER JOIN users u ON a.user_id = u.id
            INNER JOIN categories c ON a.category_id = c.id
            WHERE u.manager_id = ?";
    $params = [$_SESSION['user_id']];
    
    if ($clientId) {
        $sql .= " AND a.user_id = ?";
        $params[] = $clientId;
    }
    
    if ($month) {
        $sql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
        $params[] = $month;
    }
    
    $sql .= " ORDER BY a.date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();
    
    // Gera CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_mmfinancas_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($output, ['Data', 'Cliente', 'Categoria', 'Valor', 'Descrição']);
    
    foreach ($accounts as $account) {
        fputcsv($output, [
            $account['date'],
            $account['user_name'],
            $account['category'],
            'R$ ' . number_format($account['value'], 2, ',', '.'),
            $account['description']
        ]);
    }
    
    fclose($output);
    addLog('report_exported', ['records' => count($accounts)]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint não encontrado']);
?>