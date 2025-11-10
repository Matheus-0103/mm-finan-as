<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/config.php';
    $db = Database::getInstance()->getConnection();
    echo 'Conexão com o banco OK!<br>';
    $stmt = $db->query('SHOW TABLES');
    $tables = $stmt->fetchAll();
    echo 'Tabelas encontradas:<br>';
    foreach ($tables as $t) {
        echo implode(', ', $t) . '<br>';
    }
    echo 'Sessão: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'não autenticado') . '<br>';
} catch (Exception $e) {
    echo 'Erro: ' . $e->getMessage();
}
