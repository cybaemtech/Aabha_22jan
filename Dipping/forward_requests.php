<?php
// Prevent any redirects or output before we handle the request
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Disable error output
error_reporting(0);
ini_set('display_errors', 0);

// Start clean output buffering
ob_clean();
ob_start();

try {
    // Check request method immediately
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed. Method: ' . $_SERVER['REQUEST_METHOD']);
    }

    // Set up session
    $customSessionPath = dirname(__DIR__) . '/temp';
    if (!is_dir($customSessionPath)) {
        @mkdir($customSessionPath, 0777, true);
    }
    if (is_writable($customSessionPath)) {
        ini_set('session.save_path', $customSessionPath);
    }
    session_start();

    // Check authentication
    if (!isset($_SESSION['operator_id'])) {
        throw new Exception('Authentication required');
    }

    // Get input data
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('No input data received');
    }

    // Parse JSON
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (empty($data['forward_ids']) || !is_array($data['forward_ids'])) {
        throw new Exception('Invalid request data - forward_ids array required');
    }

    // Include database connection
    include '../Includes/db_connect.php';

    // Process the forwarding request
    $ids = array_map('intval', $data['forward_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "UPDATE dipping_binwise_entry SET forward_request = 1 WHERE id IN ($placeholders)";
    $stmt = sqlsrv_query($conn, $sql, $ids);

    if ($stmt) {
        sqlsrv_free_stmt($stmt);
        
        // Success response
        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Requests forwarded successfully',
            'count' => count($ids),
            'ids' => $ids
        ]);
    } else {
        $errors = sqlsrv_errors();
        $errorMsg = 'Database update failed';
        if ($errors && isset($errors[0]['message'])) {
            $errorMsg .= ': ' . $errors[0]['message'];
        }
        throw new Exception($errorMsg);
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
    ]);
}

exit;
?>