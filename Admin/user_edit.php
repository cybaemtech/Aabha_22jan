<?php
include '../Includes/db_connect.php';
session_start();
if (!isset($_SESSION['operator_id'])) {
     header("Location: ../index.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: user_registration.php");
    exit;
}
$operator_id = $_GET['id'];

// Fetch user data
$sql = "SELECT * FROM users WHERE operator_id = ?";
$params = [$operator_id];
$stmt = sqlsrv_query($conn, $sql, $params);
$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$user) {
    echo "User not found.";
    exit;
}

// Fetch departments
$departments = [];
$deptSql = "SELECT id, department_name FROM departments";
$deptRes = sqlsrv_query($conn, $deptSql);
while ($row = sqlsrv_fetch_array($deptRes, SQLSRV_FETCH_ASSOC)) {
    $departments[] = $row;
}
sqlsrv_free_stmt($deptRes);

// Menu list
$menuList = [
    'Dashboard',
    'Supplier Master',
    'Material Master',
    'Department Master',
    'Machine Master',
    'Product Master',
    'User Registration',
    'Department Wise Material Stock',
    'Batch Creation'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_name = trim($_POST['user_name']);
    $email = trim($_POST['email']);
    $department_id = intval($_POST['department_id']);
    $menu_permission = isset($_POST['menu_permission']) ? $_POST['menu_permission'] : [];

    if (in_array('admin_all', $menu_permission)) {
        $menu_permission = $menuList;
    }

    $menu_permission_str = implode(',', $menu_permission);

    $updateSql = "UPDATE users SET user_name = ?, email = ?, department_id = ?, menu_permission = ? WHERE operator_id = ?";
    $params = [$user_name, $email, $department_id, $menu_permission_str, $operator_id];
    $stmt = sqlsrv_prepare($conn, $updateSql, $params);
    if ($stmt && sqlsrv_execute($stmt)) {
        header("Location: user_registration.php");
        exit;
    } else {
        die(print_r(sqlsrv_errors(), true));
    }
}

include '../Includes/sidebar.php';
?>
