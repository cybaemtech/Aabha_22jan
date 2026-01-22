<?php
// filepath: c:\xampp\htdocs\Aabha\Electronic\get_dipping_summary.php
include '../Includes/db_connect.php';

header('Content-Type: application/json');

$lot_no = $_GET['lot_no'] ?? '';
$bin_no = $_GET['bin_no'] ?? '';

if ($lot_no && $bin_no) {
    // Get dipping data
    $dippingSql = "SELECT wt_kg, product_type FROM dipping_binwise_entry WHERE lot_no = ? AND bin_no = ?";
    $dippingStmt = sqlsrv_query($conn, $dippingSql, [$lot_no, $bin_no]);
    $dippingData = null;
    
    if ($dippingStmt && $row = sqlsrv_fetch_array($dippingStmt, SQLSRV_FETCH_ASSOC)) {
        $dippingData = $row;
    }
    
    // Get electronic data
    $electronicSql = "SELECT COUNT(*) as entry_count, SUM(pass_kg) as pass_kg, SUM(rej_kg) as rej_kg, SUM(total_kg) as total_kg 
                      FROM electronic_batch_entry WHERE lot_no = ? AND bin_no = ?";
    $electronicStmt = sqlsrv_query($conn, $electronicSql, [$lot_no, $bin_no]);
    $electronicData = null;
    
    if ($electronicStmt && $row = sqlsrv_fetch_array($electronicStmt, SQLSRV_FETCH_ASSOC)) {
        $electronicData = $row;
    }
    
    // Calculate remaining
    $originalWt = $dippingData ? floatval($dippingData['wt_kg']) : 0;
    $usedWt = $electronicData ? floatval($electronicData['total_kg']) : 0;
    $actualRemaining = $originalWt - $usedWt;
    
    $remainingData = [
        'actual_remaining' => number_format($actualRemaining, 2),
        'usage_percentage' => $originalWt > 0 ? number_format(($usedWt / $originalWt) * 100, 1) : '0.0',
        'can_add_more' => $actualRemaining > 0
    ];
    
    echo json_encode([
        'success' => true,
        'dipping_data' => $dippingData,
        'electronic_data' => $electronicData,
        'remaining_data' => $remainingData
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Missing lot_no or bin_no']);
}
?>