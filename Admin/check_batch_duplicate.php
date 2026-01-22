<?php
// filepath: c:\xampp\htdocs\Aabha\Admin\check_batch_duplicate.php
include '../Includes/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $batch_number = $_POST['batch_number'] ?? '';
    
    if (empty($batch_number)) {
        echo json_encode(['exists' => false]);
        exit;
    }
    
    try {
        // Check if batch number exists in your batch table
        // Assuming you have a table called 'batch_creation' or similar
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM batch_creation WHERE batch_number = ?");
        $stmt->bind_param("s", $batch_number);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        echo json_encode(['exists' => $row['count'] > 0]);
        
    } catch (Exception $e) {
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['exists' => false]);
}
?>