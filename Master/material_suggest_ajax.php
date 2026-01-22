<?php
include '../Includes/db_connect.php';

$field = $_GET['field'] ?? '';
$query = $_GET['query'] ?? '';

if (!in_array($field, ['material_description', 'size', 'unit_of_measurement'])) {
    echo json_encode([]);
    exit;
}

// SQLSRV version for suggestion query
$sql = "SELECT DISTINCT $field FROM materials WHERE $field LIKE ? ORDER BY $field OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY";
$params = array('%' . $query . '%');
$stmt = sqlsrv_query($conn, $sql, $params);

$suggestions = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suggestions[] = $row[$field];
    }
    sqlsrv_free_stmt($stmt);
}
echo json_encode($suggestions);