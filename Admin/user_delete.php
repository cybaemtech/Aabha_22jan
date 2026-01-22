<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Get the ID and validate it
$id = isset($_GET['id']) ? $_GET['id'] : 0;

// Additional security check - ensure it's a numeric value
if (!is_numeric($id) || $id <= 0) {
    header("Location: user_registrationLookup.php?error=invalid_id");
    exit;
}

// Convert to integer after validation
$id = intval($id);

// Debug: Check if we have a valid database connection
if (!$conn) {
    error_log("Database connection failed: " . print_r(sqlsrv_errors(), true));
    header("Location: user_registrationLookup.php?error=db_connection");
    exit;
}

try {
    // First, check if the user exists
    $checkSql = "SELECT id FROM users WHERE id = ? AND is_deleted = 0";
    $checkParams = array($id);
    $checkStmt = sqlsrv_prepare($conn, $checkSql, $checkParams);
    if (!$checkStmt) {
        error_log("Check statement preparation failed: " . print_r(sqlsrv_errors(), true));
        header("Location: user_registrationLookup.php?error=prepare_failed");
        exit;
    }
    if (!sqlsrv_execute($checkStmt)) {
        error_log("Check statement execution failed: " . print_r(sqlsrv_errors(), true));
        header("Location: user_registrationLookup.php?error=execute_failed");
        exit;
    }
    $userExists = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    if (!$userExists) {
        header("Location: user_registrationLookup.php?error=user_not_found");
        exit;
    }

    // Soft delete: set is_deleted = 1
    $softDeleteSql = "UPDATE users SET is_deleted = 1 WHERE id = ?";
    $softDeleteParams = array($id);
    $softDeleteStmt = sqlsrv_prepare($conn, $softDeleteSql, $softDeleteParams);
    if (!$softDeleteStmt) {
        error_log("Soft delete statement preparation failed: " . print_r(sqlsrv_errors(), true));
        header("Location: user_registrationLookup.php?error=prepare_failed");
        exit;
    }
    if (!sqlsrv_execute($softDeleteStmt)) {
        $errors = sqlsrv_errors();
        $errorMsg = "Soft delete statement execution failed.";
        if ($errors) {
            foreach ($errors as $error) {
                $errorMsg .= " SQLSTATE: " . $error['SQLSTATE'] . " - " . $error['message'];
            }
        }
        die($errorMsg);
    }
    $rowsAffected = sqlsrv_rows_affected($softDeleteStmt);
    sqlsrv_free_stmt($softDeleteStmt);

    if ($rowsAffected === false) {
        error_log("Error getting affected rows: " . print_r(sqlsrv_errors(), true));
        header("Location: user_registrationLookup.php?error=rows_affected");
        exit;
    }
    if ($rowsAffected > 0) {
        header("Location: user_registrationLookup.php?deleted=1");
        exit;
    } else {
        header("Location: user_registrationLookup.php?error=no_rows_affected");
        exit;
    }
} catch (Exception $e) {
    error_log("Delete error: " . $e->getMessage());
    header("Location: user_registrationLookup.php?error=exception");
    exit;
}
?>