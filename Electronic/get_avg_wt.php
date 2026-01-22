<?php
// filepath: c:\xampp\htdocs\Aabha\Electronic\get_avg_wt.php
include '../Includes/db_connect.php';

$lot_no = $_GET['lot_no'] ?? '';
$bin_no = $_GET['bin_no'] ?? '';

if ($lot_no && $bin_no) {
    $sql = "SELECT avg_wt FROM dipping_binwise_entry WHERE lot_no = ? AND bin_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$lot_no, $bin_no]);
    
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo json_encode(['avg_wt' => $row['avg_wt']]);
    } else {
        echo json_encode(['avg_wt' => null]);
    }
} else {
    echo json_encode(['avg_wt' => null]);
}
?>