<?php
$customSessionPath = dirname(__DIR__) . '/temp';
if (!is_dir($customSessionPath)) {
    @mkdir($customSessionPath, 0777, true);
}
if (is_writable($customSessionPath)) {
    ini_set('session.save_path', $customSessionPath);
}
session_start();
if (!isset($_SESSION['operator_id'])) {
    header("Location: ../index.php");
    exit;
}
include '../Includes/db_connect.php';

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // SQLSRV version for deleting department
    $sql = "DELETE FROM departments WHERE id = ?";
    $params = array($id);
    $stmt = sqlsrv_query($conn, $sql, $params);
}

header("Location: department.php");
exit;
?>
