<?php
// ============================================
// MM FINANÇAS - API DE VERIFICAÇÃO
// ============================================

require_once __DIR__ . '/../config.php';

try {
    requireAuth(); // usuário deve estar logado
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'POST') {
        $db = Database::getInstance()->getConnection();
        $userId = $_SESSION['user_id'];

        // ENVIAR CÓDIGO
        if ($action === 'send_code') {
            $data = json_decode(file_get_contents('php://input'), true);
            $type = $data['type'] ?? '';
            
            if (!in_array($type, ['email', 'password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Tipo de verificação inválido']);
                exit;
            }

            // Verifica rate limit (máximo 3 códigos não usados por tipo em 15 minutos)
            $stmt = $db->prepare("SELECT COUNT(*) FROM verification_codes 
                WHERE user_id = ? AND type = ? AND used = 0 
                AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $stmt->execute([$userId, $type]);
            if ((int)$stmt->fetchColumn() >= 3) {
                http_response_code(429);
                echo json_encode(['error' => 'Aguarde alguns minutos antes de solicitar outro código']);
                exit;
            }

            // Gera código aleatório de 6 dígitos
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Busca email do usuário
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userEmail = $stmt->fetchColumn();
            
            if (!$userEmail) {
                http_response_code(400);
                echo json_encode(['error' => 'Email do usuário não encontrado']);
                exit;
            }
            
            // Salva com expiração de 15 minutos
            $stmt = $db->prepare("INSERT INTO verification_codes 
                (user_id, code, type, expires_at) 
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
            
            try {
                $stmt->execute([$userId, $code, $type]);
                
                // TODO: Em produção, enviar por email
                // Por enquanto, retorna o código apenas em desenvolvimento e apenas para o próprio usuário
                $isOwnUser = isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId;
                echo json_encode([
                    'message' => 'Código de verificação enviado para ' . substr($userEmail, 0, 3) . '***' . substr($userEmail, strpos($userEmail, '@')),
                    'debug_code' => (APP_DEBUG && $isOwnUser) ? $code : null
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao gerar código']);
            }
            exit;
        }

        // VERIFICAR CÓDIGO
        if ($action === 'confirm_code') {
            $data = json_decode(file_get_contents('php://input'), true);
            $code = trim($data['code'] ?? '');
            $type = $data['type'] ?? '';
            
            if (empty($code) || !in_array($type, ['email', 'password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Código e tipo são obrigatórios']);
                exit;
            }

            // Busca código válido mais recente
            $stmt = $db->prepare("SELECT id FROM verification_codes 
                WHERE user_id = ? AND code = ? AND type = ? 
                AND used = 0 AND expires_at > NOW()
                ORDER BY created_at DESC LIMIT 1");
            
            $stmt->execute([$userId, $code, $type]);
            $verificationId = $stmt->fetchColumn();

            if (!$verificationId) {
                http_response_code(400);
                echo json_encode(['error' => 'Código inválido ou expirado']);
                exit;
            }

            // Marca código como usado
            $ustmt = $db->prepare("UPDATE verification_codes SET used = 1 WHERE id = ?");
            $ustmt->execute([$verificationId]);

            // Gera token temporário para a operação
            $token = bin2hex(random_bytes(32));
            $_SESSION['verify_token'] = $token;
            $_SESSION['verify_type'] = $type;
            $_SESSION['verify_expires'] = time() + 300; // 5 minutos

            echo json_encode([
                'message' => 'Código verificado com sucesso',
                'token' => $token
            ]);
            exit;
        }
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpoint não encontrado']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}