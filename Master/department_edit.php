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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// SQLSRV version for fetching department
$sql = "SELECT * FROM departments WHERE id = ?";
$params = array($id);
$stmt = sqlsrv_query($conn, $sql, $params);
$department = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$department) {
    echo "Department not found.";
    exit;
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $department_name = trim($_POST['department_name']);
    $updateSql = "UPDATE departments SET department_name = ? WHERE id = ?";
    $updateParams = array($department_name, $id);
    $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
    if ($updateStmt) {
        header("Location: department.php");
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error updating department.</div>";
    }
}

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Department</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        .main-content {
            margin-left: 240px; /* Same as sidebar width */
            padding: 30px;
            min-height: 100vh;
            background: #f4f6f8;
        }
        @media (max-width: 900px) {
            .main-content { padding: 10px; }
        }
        @media (max-width: 600px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
<div class="main-content">
    <div class="card shadow-sm mb-4 centered-form-container">
        <div class="card-header bg-primary text-white">Edit Department</div>
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Department ID</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($department['dept_id']); ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Department Name *</label>
                    <input type="text" class="form-control" name="department_name" value="<?php echo htmlspecialchars($department['department_name']); ?>" required>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Update</button>
                    <a href="department.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>