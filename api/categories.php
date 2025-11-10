<?php
// ============================================
// MM FINANÇAS - API DE CATEGORIAS
// ============================================

require_once '../config.php';

try {
    $db = Database::getInstance()->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // LISTAR CATEGORIAS
    if ($method === 'GET') {
        try {
            $stmt = $db->prepare("SELECT id, name, slug, icon FROM categories ORDER BY name ASC");
            $stmt->execute();
            $categories = $stmt->fetchAll();
            
            echo json_encode(['categories' => $categories]);
            exit;
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao buscar categorias: ' . $e->getMessage()]);
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
    exit;
}
?>