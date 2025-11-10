<?php
// ============================================
// MM FINANÇAS - API DE FEEDBACKS
// ============================================

require_once __DIR__ . '/../config.php';

requireAuth();

try {
    $db = Database::getInstance()->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // LISTAR FEEDBACKS
    if ($method === 'GET') {
        if ($_SESSION['user_role'] === 'user') {
            // Get feedbacks for the user
            $stmt = $db->prepare("
                SELECT f.*, u.name as manager_name
                FROM feedbacks f
                INNER JOIN users u ON f.manager_id = u.id
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$_SESSION['user_id']]);
        } else {
            // Get feedbacks sent by the manager
            $stmt = $db->prepare("
                SELECT f.*, u.name as client_name
                FROM feedbacks f
                INNER JOIN users u ON f.user_id = u.id
                WHERE f.manager_id = ?
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        
        $feedbacks = $stmt->fetchAll();
        echo json_encode(['feedbacks' => $feedbacks]);
        exit;
    }

    // ADICIONAR FEEDBACK
    if ($method === 'POST') {
        if ($_SESSION['user_role'] !== 'manager') {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas gestores podem enviar feedbacks']);
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        
        $userId = $data['user_id'] ?? null;
        $message = trim($data['message'] ?? '');
        
        if (empty($userId) || empty($message)) {
            http_response_code(400);
            echo json_encode(['error' => 'Cliente e mensagem são obrigatórios']);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO feedbacks (user_id, manager_id, message, created_at) VALUES (?, ?, ?, NOW())");
        
        try {
            $stmt->execute([$userId, $_SESSION['user_id'], $message]);
            $feedbackId = $db->lastInsertId();
            
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

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
}