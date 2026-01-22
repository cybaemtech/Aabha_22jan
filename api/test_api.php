<?php
// Simple test API to check if the endpoint is reachable
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];

error_log("Test API called with method: " . $method);

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'GET') {
    echo json_encode([
        'success' => true, 
        'message' => 'Test API is working - GET method',
        'method' => $method,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Test API is working - POST method',
        'method' => $method,
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

echo json_encode([
    'success' => false, 
    'message' => 'Method not allowed: ' . $method,
    'allowed_methods' => ['GET', 'POST', 'OPTIONS']
]);
?>