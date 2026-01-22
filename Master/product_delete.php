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
    
    // Check if the product exists (SQLSRV)
    $checkSql = "SELECT * FROM products WHERE id = ?";
    $checkParams = array($id);
    $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
    $product = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

    if ($product) {
        // Delete the product (SQLSRV)
        $deleteSql = "DELETE FROM products WHERE id = ?";
        $deleteParams = array($id);
        $deleteStmt = sqlsrv_query($conn, $deleteSql, $deleteParams);
    }
}

header("Location: product.php?deleted=1");
exit;
?>
