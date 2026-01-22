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

    // Optionally check if the ID exists before deleting (SQLSRV)
    $checkSql = "SELECT * FROM suppliers WHERE id = ?";
    $checkParams = array($id);
    $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
    $supplier = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

    if ($supplier) {
        // First, delete related records from supplier_materials (SQLSRV)
        $deleteSupplierMaterialsSql = "DELETE FROM supplier_materials WHERE supplier_id = ?";
        $deleteSupplierMaterialsParams = array($id);
        sqlsrv_query($conn, $deleteSupplierMaterialsSql, $deleteSupplierMaterialsParams);

        // Now, delete the supplier (SQLSRV)
        $deleteSupplierSql = "DELETE FROM suppliers WHERE id = ?";
        $deleteSupplierParams = array($id);
        $deleteStmt = sqlsrv_query($conn, $deleteSupplierSql, $deleteSupplierParams);

        if ($deleteStmt) {
            header("Location: supplier.php?msg=deleted");
            exit;
        } else {
            echo "Error deleting supplier.";
        }
    } else {
        echo "Supplier not found.";
    }
} else {
    echo "Invalid request.";
}
?>
