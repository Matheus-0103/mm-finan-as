<?php
// Teste básico da API sem autenticação
echo json_encode([
    'status' => 'success',
    'message' => 'API funcionando',
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => [
        'php_version' => PHP_VERSION,
        'sapi' => php_sapi_name(),
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'N/A',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    ]
]);
?>
