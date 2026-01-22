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

// Fetch material details (SQLSRV)
$sql = "SELECT * FROM materials WHERE id = ?";
$params = array($id);
$stmt = sqlsrv_query($conn, $sql, $params);
$material = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$material) {
    echo "Material not found.";
    exit;
}

// Fetch suppliers for dropdown (SQLSRV)
$suppliersStmt = sqlsrv_query($conn, "SELECT id, supplier_name FROM suppliers");
$suppliers = [];
if ($suppliersStmt) {
    while ($row = sqlsrv_fetch_array($suppliersStmt, SQLSRV_FETCH_ASSOC)) {
        $suppliers[] = $row;
    }
    sqlsrv_free_stmt($suppliersStmt);
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $material_description = trim($_POST['material_description']);
    $unit_of_measurement = trim($_POST['unit_of_measurement']);
    $material_type = trim($_POST['material_type']);
    $status_remark = trim($_POST['status_remark']);

    $updateSql = "UPDATE materials SET material_description = ?, unit_of_measurement = ?, material_type = ?, status_remark = ? WHERE id = ?";
    $updateParams = array($material_description, $unit_of_measurement, $material_type, $status_remark, $id);
    $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
    if ($updateStmt) {
        header("Location: material.php");
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error updating material.</div>";
    }
}

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Material</title>
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
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">Edit Material</div>
        <div class="card-body">
            <form method="post">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Material ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($material['material_id']); ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Material Description *</label>
                        <input type="text" class="form-control" name="material_description" value="<?php echo htmlspecialchars($material['material_description']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Unit Of Measurement</label>
                        <input type="text" class="form-control" name="unit_of_measurement" value="<?php echo htmlspecialchars($material['unit_of_measurement']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Material Type *</label>
                        <select class="form-select" name="material_type" required>
                            <option value="">Select Type</option>
                            <option value="Raw material" <?php if($material['material_type']=='Raw material') echo 'selected'; ?>>Raw material</option>
                            <option value="Packing material" <?php if($material['material_type']=='Packing material') echo 'selected'; ?>>Packing material</option>
                            <option value="Miscellaneous" <?php if($material['material_type']=='Miscellaneous') echo 'selected'; ?>>Miscellaneous</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Status Remark</label>
                        <input type="text" class="form-control" name="status_remark" value="<?php echo htmlspecialchars($material['status_remark'] ?? ''); ?>">
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Update</button>
                    <a href="material.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>