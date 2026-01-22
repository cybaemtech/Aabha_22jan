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

// Fetch machine details (SQLSRV)
$sql = "SELECT * FROM machines WHERE id = ?";
$params = array($id);
$stmt = sqlsrv_query($conn, $sql, $params);
$machine = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$machine) {
    echo "Machine not found.";
    exit;
}

// Fetch departments for dropdown (SQLSRV)
$departmentsStmt = sqlsrv_query($conn, "SELECT id, department_name FROM departments");
$departments = [];
if ($departmentsStmt) {
    while ($dept = sqlsrv_fetch_array($departmentsStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $dept;
    }
    sqlsrv_free_stmt($departmentsStmt);
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $machine_name = trim($_POST['machine_name']);
    $department_id = intval($_POST['department_id']);

    $updateSql = "UPDATE machines SET machine_name = ?, department_id = ? WHERE id = ?";
    $updateParams = array($machine_name, $department_id, $id);
    $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
    if ($updateStmt) {
        header("Location: machine.php");
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error updating machine.</div>";
    }
}

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Machine</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
        <div class="card-header bg-primary text-white">Edit Machine</div>
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Machine ID</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($machine['machine_id']); ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Machine Name *</label>
                    <input type="text" class="form-control" name="machine_name" value="<?php echo htmlspecialchars($machine['machine_name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Department *</label>
                    <select class="form-select" name="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php if($machine['department_id']==$dept['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Update</button>
                    <a href="machine.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>