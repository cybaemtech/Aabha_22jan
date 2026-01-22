<?php
include '../Includes/db_connect.php';

$desc = $_GET['material_description'] ?? '';
$size = $_GET['size'] ?? '';
$unit = $_GET['unit_of_measurement'] ?? '';

$sql = "SELECT id, qty FROM materials WHERE material_description = ? AND size = ? AND unit_of_measurement = ?";
$params = array($desc, $size, $unit);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    echo json_encode(['exists' => true, 'qty' => $row['qty']]);
} else {
    echo json_encode(['exists' => false, 'qty' => 1]);
}