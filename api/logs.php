<?php
// ============================================
// MM FINANÇAS - API DE LOGS / NOTIFICAÇÕES
// ============================================

require_once '../config.php';

requireAuth();

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Apenas listagem (GET) por enquanto
if ($method === 'GET') {
    try {
        $logs = [];

        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'manager') {
            // Para gestores, retornar logs seus e dos seus clientes
            $mgrId = $_SESSION['user_id'];
            $stmt = $db->prepare("SELECT id FROM users WHERE manager_id = ?");
            $stmt->execute([$mgrId]);
            $clients = $stmt->fetchAll();
            $ids = array_map(function($r){ return (int)$r['id']; }, $clients);
            
            // Adiciona o próprio gestor à lista
            $ids[] = (int)$mgrId;

            if (count($ids) > 0) {
                // Construir cláusula IN segura
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT * FROM logs WHERE user_id IN ($placeholders) ORDER BY created_at DESC LIMIT 200";
                $stmt = $db->prepare($sql);
                $stmt->execute($ids);
                $logs = $stmt->fetchAll();
            }
        } else {
            // Usuário normal: retorna todos os seus logs
            $uid = $_SESSION['user_id'];
            $stmt = $db->prepare("SELECT * FROM logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
            $stmt->execute([$uid]);
            $logs = $stmt->fetchAll();
        }

        echo json_encode(['logs' => $logs]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar logs']);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);

?>
