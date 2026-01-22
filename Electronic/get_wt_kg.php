<?php
// filepath: c:\xampp\htdocs\Aabha\Electronic\get_wt_kg.php
include '../Includes/db_connect.php';

$lot_no = $_GET['lot_no'] ?? '';

if ($lot_no) {
    $sql = "SELECT wt_kg, product_type FROM dipping_binwise_entry WHERE lot_no = ? AND forward_request = 1";
    $stmt = sqlsrv_query($conn, $sql, [$lot_no]);
    
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo json_encode([
            'wt_kg' => $row['wt_kg'],
            'product_type' => $row['product_type']
        ]);
    } else {
        echo json_encode(['wt_kg' => 0, 'product_type' => '']);
    }
} else {
    echo json_encode(['wt_kg' => 0, 'product_type' => '']);
}
?>