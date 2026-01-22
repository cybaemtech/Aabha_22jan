<?php
include '../Includes/db_connect.php';

$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$results = [];

if ($query === '') {
    // Return all materials if no search query
    $sql = "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials ORDER BY material_description ASC";
    $params = [];
} else {
    // Search by description or ID
    $sql = "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials WHERE material_description LIKE ? OR material_id LIKE ? ORDER BY material_description ASC";
    $params = ["%$query%", "%$query%"];
}
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'material_id' => $row['material_id'],
            'material_description' => $row['material_description'],
            'unit_of_measurement' => $row['unit_of_measurement'],
            'material_type' => $row['material_type']
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($results);
exit;
?>
