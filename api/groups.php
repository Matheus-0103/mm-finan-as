<?php
// ============================================
// MM FINANÇAS - API DE GRUPOS
// ============================================

require_once __DIR__ . '/../config.php';

try {
    requireAuth();
    $db = Database::getInstance()->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    // LISTAR GRUPOS (do usuário: dono ou membro). Se ?group_id= for passado, retorna detalhes + membros
    if ($method === 'GET') {
        $userId = $_SESSION['user_id'];

        $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
        if ($groupId) {
            // verifica permissão: owner or member
            $pstmt = $db->prepare("SELECT * FROM groups WHERE id = ?");
            $pstmt->execute([$groupId]);
            $g = $pstmt->fetch();
            if (!$g) { http_response_code(404); echo json_encode(['error'=>'Grupo não encontrado']); exit; }

            $isMemberStmt = $db->prepare("SELECT COUNT(*) AS c FROM group_memberships WHERE group_id = ? AND user_id = ?");
            $isMemberStmt->execute([$groupId, $userId]);
            $isMember = (int)$isMemberStmt->fetchColumn() > 0;

            if ((int)$g['owner_id'] !== (int)$userId && !$isMember) {
                http_response_code(403); echo json_encode(['error'=>'Acesso negado']); exit;
            }

            // lista membros
            $mstmt = $db->prepare("SELECT u.id, u.name, u.email, gm.added_by, gm.created_at FROM users u INNER JOIN group_memberships gm ON gm.user_id = u.id WHERE gm.group_id = ? ORDER BY u.name ASC");
            $mstmt->execute([$groupId]);
            $members = $mstmt->fetchAll();

            echo json_encode(['group' => $g, 'members' => $members]);
            exit;
        }

        $stmt = $db->prepare("SELECT g.id, g.name, g.owner_id,
            (SELECT COUNT(*) FROM group_memberships gm WHERE gm.group_id = g.id) AS member_count
            FROM groups g
            WHERE g.owner_id = ?
            UNION
            SELECT g2.id, g2.name, g2.owner_id,
            (SELECT COUNT(*) FROM group_memberships gm2 WHERE gm2.group_id = g2.id) AS member_count
            FROM groups g2
            INNER JOIN group_memberships gm3 ON gm3.group_id = g2.id
            WHERE gm3.user_id = ?
            ORDER BY id DESC");

        $stmt->execute([$userId, $userId]);
        $groups = $stmt->fetchAll();

        echo json_encode(['groups' => $groups]);
        exit;
    }

    // CRIAR GRUPO ou ações de membros
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? null;

        // ADICIONAR MEMBRO
        if ($action === 'add_member') {
            $groupId = (int)($data['group_id'] ?? 0);
            $email = trim($data['email'] ?? '');
            if (!$groupId || !$email) { http_response_code(400); echo json_encode(['error'=>'group_id e email são obrigatórios']); exit; }

            // verificar permissão (apenas dono)
            $stmt = $db->prepare("SELECT owner_id FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $g = $stmt->fetch();
            if (!$g) { http_response_code(404); echo json_encode(['error'=>'Grupo não encontrado']); exit; }
            $userId = $_SESSION['user_id'];
            if ((int)$g['owner_id'] !== (int)$userId) { http_response_code(403); echo json_encode(['error'=>'Apenas o dono pode adicionar membros']); exit; }

            // procura usuário por email
            $ustmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ?");
            $ustmt->execute([$email]);
            $u = $ustmt->fetch();
            if (!$u) { http_response_code(404); echo json_encode(['error'=>'Usuário não encontrado']); exit; }

            // evita duplicados
            $check = $db->prepare("SELECT COUNT(*) FROM group_memberships WHERE group_id = ? AND user_id = ?");
            $check->execute([$groupId, $u['id']]);
            if ((int)$check->fetchColumn() > 0) { http_response_code(409); echo json_encode(['error'=>'Usuário já é membro']); exit; }

            $mstmt = $db->prepare("INSERT INTO group_memberships (group_id, user_id, added_by) VALUES (?, ?, ?)");
            try {
                $mstmt->execute([$groupId, $u['id'], $userId]);
                addLog('member_added', ['group_id'=>$groupId, 'user_id'=>$u['id'], 'email'=>$u['email']], $userId);
                echo json_encode(['message'=>'Membro adicionado', 'user' => $u]);
            } catch (PDOException $e) { http_response_code(500); echo json_encode(['error'=>'Erro ao adicionar membro']); }
            exit;
        }

        // REMOVER MEMBRO
        if ($action === 'remove_member') {
            $groupId = (int)($data['group_id'] ?? 0);
            $removeUserId = (int)($data['user_id'] ?? 0);
            if (!$groupId || !$removeUserId) { http_response_code(400); echo json_encode(['error'=>'group_id e user_id são obrigatórios']); exit; }

            // verificar permissão (apenas dono pode remover)
            $stmt = $db->prepare("SELECT owner_id FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $g = $stmt->fetch();
            if (!$g) { http_response_code(404); echo json_encode(['error'=>'Grupo não encontrado']); exit; }
            $userId = $_SESSION['user_id'];
            if ((int)$g['owner_id'] !== (int)$userId) { http_response_code(403); echo json_encode(['error'=>'Apenas o dono pode remover membros']); exit; }

            $dstmt = $db->prepare("DELETE FROM group_memberships WHERE group_id = ? AND user_id = ?");
            try {
                $dstmt->execute([$groupId, $removeUserId]);
                addLog('member_removed', ['group_id'=>$groupId, 'user_id'=>$removeUserId], $userId);
                echo json_encode(['message'=>'Membro removido']);
            } catch (PDOException $e) { http_response_code(500); echo json_encode(['error'=>'Erro ao remover membro']); }
            exit;
        }

        // padrão: criar grupo (sem action) - somente gestores podem criar famílias
        // garanta que apenas gestores criem grupos
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'manager') {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas gestores podem criar grupos/famílias']);
            exit;
        }

        // padrão: criar grupo (sem action)
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome do grupo é obrigatório']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        $stmt = $db->prepare("INSERT INTO groups (name, owner_id) VALUES (?, ?)");
        try {
            $stmt->execute([$name, $userId]);
            $groupId = $db->lastInsertId();

            // adiciona o dono como membro do grupo
            $mstmt = $db->prepare("INSERT INTO group_memberships (group_id, user_id, added_by) VALUES (?, ?, ?)");
            $mstmt->execute([$groupId, $userId, $userId]);

            addLog('group_created', ['group_id' => $groupId, 'name' => $name], $userId);

            http_response_code(201);
            echo json_encode(['message' => 'Grupo criado', 'group_id' => $groupId]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar grupo']);
        }
        exit;

    // REMOVER GRUPO (só dono pode)
    if ($method === 'DELETE') {
        $groupId = $_GET['id'] ?? null;
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID do grupo é obrigatório']);
            exit;
        }

        $userId = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT owner_id FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $g = $stmt->fetch();
        if (!$g) {
            http_response_code(404);
            echo json_encode(['error' => 'Grupo não encontrado']);
            exit;
        }

        if ((int)$g['owner_id'] !== (int)$userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas o dono do grupo pode removê-lo']);
            exit;
        }

        $dstmt = $db->prepare("DELETE FROM groups WHERE id = ?");
        try {
            $dstmt->execute([$groupId]);
            echo json_encode(['message' => 'Grupo removido']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao remover grupo']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
}
