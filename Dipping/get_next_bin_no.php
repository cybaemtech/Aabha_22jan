<?php
// filepath: c:\xampp\htdocs\Aabha\Dipping\get_next_bin_no.php
include '../Includes/db_connect.php';

header('Content-Type: application/json');

$lot_no = $_GET['lot_no'] ?? '';
$next_bin_no = 1;

if ($lot_no) {
    // Get next bin number for specific lot
    $sql = "SELECT MAX(bin_no) AS max_bin_no FROM dipping_binwise_entry WHERE lot_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$lot_no]);
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $max_bin_no = intval($row['max_bin_no'] ?? 0);
        $next_bin_no = $max_bin_no + 1;
    }
    sqlsrv_free_stmt($stmt);
} else {
    // Get overall next bin number (for display before lot selection)
    $sql = "SELECT MAX(bin_no) AS max_bin_no FROM dipping_binwise_entry";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $max_bin_no = intval($row['max_bin_no'] ?? 0);
        $next_bin_no = $max_bin_no + 1;
    }
    sqlsrv_free_stmt($stmt);
}

echo json_encode(['next_bin_no' => $next_bin_no]);

sqlsrv_close($conn);
?>
