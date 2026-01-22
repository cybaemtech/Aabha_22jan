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

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);

    // Check if machine exists (SQLSRV)
    $checkSql = "SELECT * FROM machines WHERE id = ?";
    $checkParams = array($id);
    $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
    $machine = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

    if ($machine) {
        // Proceed to delete (SQLSRV)
        $deleteSql = "DELETE FROM machines WHERE id = ?";
        $deleteParams = array($id);
        $deleteStmt = sqlsrv_query($conn, $deleteSql, $deleteParams);
        if ($deleteStmt) {
            $_SESSION['message'] = "Machine deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete machine.";
        }
    } else {
        $_SESSION['error'] = "Machine not found.";
    }
} else {
    $_SESSION['error'] = "Invalid machine ID.";
}

header("Location: machine.php");
exit;
