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

// Get POST data and sanitize
$supplier_id    = $_POST['supplier_id'];
$supplier_name  = trim($_POST['supplier_name']);
$contact_name   = trim($_POST['contact_name']);
$address        = trim($_POST['address']);
$city           = trim($_POST['city']);
$postal_code    = trim($_POST['postal_code']);
$country        = trim($_POST['country']);
$phone          = trim($_POST['phone']);
$email          = trim($_POST['email']);
$approve_status = trim($_POST['approve_status']);
$material_ids   = isset($_POST['material_ids']) ? $_POST['material_ids'] : [];

// Insert supplier (without material_id)
$sql = "INSERT INTO suppliers (supplier_id, supplier_name, contact_name, address, city, postal_code, country, phone, email, approve_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$params = array($supplier_id, $supplier_name, $contact_name, $address, $city, $postal_code, $country, $phone, $email, $approve_status);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    // Store selected material IDs for this supplier
    foreach ($material_ids as $mat_id) {
        if (!empty($mat_id)) {
            $sqlMat = "INSERT INTO supplier_materials (supplier_id, material_id) VALUES (?, ?)";
            $paramsMat = array($supplier_id, $mat_id);
            sqlsrv_query($conn, $sqlMat, $paramsMat);
        }
    }
    header("Location: supplier.php");
    exit;
} else {
    if (($errors = sqlsrv_errors()) != null) {
        foreach ($errors as $error) {
            echo "Error: " . $error['message'] . "<br>";
        }
    } else {
        echo "Unknown error occurred.";
    }
}
?>
