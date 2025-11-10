<?php
require_once __DIR__ . '/config.php';

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT * FROM users");
    $users = $stmt->fetchAll();
    
    echo "Usuários encontrados:\n";
    print_r($users);
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}