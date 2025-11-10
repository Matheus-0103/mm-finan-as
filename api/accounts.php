<?php
// ============================================
// MM FINANÇAS - API DE CONTAS/DESPESAS
// ============================================

require_once '../config.php';

requireAuth();

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// CRIAR CONTA
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $categoryId = (int)($data['category_id'] ?? 0);
    $value = (float)($data['value'] ?? 0);
    $date = $data['date'] ?? '';
    $description = trim($data['description'] ?? '');
    $groupId = !empty($data['group_id']) ? (int)$data['group_id'] : null;
    
    if ($categoryId <= 0 || $value <= 0 || empty($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO accounts (user_id, group_id, category_id, value, date, description) VALUES (?, ?, ?, ?, ?, ?)");
    
    try {
        $stmt->execute([$_SESSION['user_id'], $groupId, $categoryId, $value, $date, $description]);
        $accountId = $db->lastInsertId();
        
        addLog('account_created', ['account_id' => $accountId, 'value' => $value]);
        
        http_response_code(201);
        echo json_encode([
            'message' => 'Despesa criada com sucesso',
            'account_id' => $accountId
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao criar despesa']);
    }
    exit;
}

// LISTAR CONTAS
if ($method === 'GET') {
    $userId = $_GET['user_id'] ?? $_SESSION['user_id'];
    $groupId = $_GET['group_id'] ?? null;
    $categoryId = $_GET['category_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $month = $_GET['month'] ?? null; // formato: YYYY-MM
    
    // Para gestores, podem ver contas de seus clientes
    if ($_SESSION['user_role'] === 'manager') {
        // Gestor pode ver todas as contas de seus clientes
        $sql = "SELECT a.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon,
                u.name as user_name, g.name as group_name
                FROM accounts a
                INNER JOIN categories c ON a.category_id = c.id
                INNER JOIN users u ON a.user_id = u.id
                LEFT JOIN groups g ON a.group_id = g.id
                WHERE u.manager_id = ? OR a.user_id = ?";
        $params = [$_SESSION['user_id'], $_SESSION['user_id']];
    } else {
        // Usuário vê apenas suas contas
        $sql = "SELECT a.*, c.name as category_name, c.slug as category_slug, c.icon as category_icon,
                g.name as group_name
                FROM accounts a
                INNER JOIN categories c ON a.category_id = c.id
                LEFT JOIN groups g ON a.group_id = g.id
                WHERE a.user_id = ?";
        $params = [$_SESSION['user_id']];
    }
    
    if ($groupId) {
        $sql .= " AND a.group_id = ?";
        $params[] = $groupId;
    }
    
    if ($categoryId) {
        $sql .= " AND a.category_id = ?";
        $params[] = $categoryId;
    }
    
    if ($dateFrom) {
        $sql .= " AND a.date >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND a.date <= ?";
        $params[] = $dateTo;
    }
    
    if ($month) {
        $sql .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
        $params[] = $month;
    }
    
    $sql .= " ORDER BY a.date DESC, a.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();
    
    echo json_encode(['accounts' => $accounts]);
    exit;
}

// DELETAR CONTA
if ($method === 'DELETE') {
    $accountId = $_GET['id'] ?? 0;
    
    if ($accountId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    
    // Verifica se a conta pertence ao usuário
    $stmt = $db->prepare("SELECT id FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Despesa não encontrada']);
        exit;
    }
    
    $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
    $stmt->execute([$accountId]);
    
    addLog('account_deleted', ['account_id' => $accountId]);
    
    echo json_encode(['message' => 'Despesa excluída com sucesso']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
?>