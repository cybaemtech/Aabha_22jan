<?php
include '../Includes/db_connect.php';
// if (!isset($_SESSION['operator_id'])) {
//     header("Location: ../includes/login.php");
//     exit;
// }
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    // SQLSRV version for deleting material
    $sql = "DELETE FROM materials WHERE id = ?";
    $params = array($id);
    $stmt = sqlsrv_query($conn, $sql, $params);
}
header("Location: material.php");
exit;
?>