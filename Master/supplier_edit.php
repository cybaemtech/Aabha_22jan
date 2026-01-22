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

// Get supplier ID from URL
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch supplier data (SQLSRV)
$sql = "SELECT * FROM suppliers WHERE id = ?";
$params = array($id);
$stmt = sqlsrv_query($conn, $sql, $params);
$supplier = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$supplier) {
    echo "Supplier not found.";
    exit;
}

// Fetch materials for checkboxes (SQLSRV)
$materialsStmt = sqlsrv_query($conn, "SELECT material_id, material_description FROM materials");
$materials = [];
if ($materialsStmt) {
    while ($mat = sqlsrv_fetch_array($materialsStmt, SQLSRV_FETCH_ASSOC)) {
        $materials[] = $mat;
    }
    sqlsrv_free_stmt($materialsStmt);
}

// Fetch already selected materials (comma-separated in material_id)
$selectedMaterials = [];
if (!empty($supplier['material_id'])) {
    $selectedMaterials = explode(',', $supplier['material_id']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Convert array to comma-separated string for storage
    $material_id_str = implode(',', $material_ids);

    // Basic validation
    if ($supplier_name == "" || $address == "" || $city == "" || $postal_code == "" || $country == "") {
        $error = "Required fields are missing.";
    } else {
        $updateSql = "UPDATE suppliers SET supplier_name=?, contact_name=?, address=?, city=?, postal_code=?, country=?, phone=?, email=?, material_id=?, approve_status=? WHERE id=?";
        $updateParams = array($supplier_name, $contact_name, $address, $city, $postal_code, $country, $phone, $email, $material_id_str, $approve_status, $id);
        $updateStmt = sqlsrv_query($conn, $updateSql, $updateParams);
        if ($updateStmt) {
            header("Location: supplier.php");
            exit;
        } else {
            $error = "Error updating supplier.";
        }
    }
}

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Supplier</title>
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script>
$(document).ready(function() {
    $('.material-select2').select2({
        placeholder: "Select Materials",
        allowClear: true,
        width: '100%'
    });
});
</script>
</head>
<body>
 
    <div class="main-content">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                Edit Supplier
            </div>
            <div class="card-body">
                <?php if (!empty($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
                <form method="post">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier ID</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($supplier['supplier_id']); ?>" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier Name *</label>
                            <input type="text" class="form-control" name="supplier_name" value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Name</label>
                            <input type="text" class="form-control" name="contact_name" value="<?php echo htmlspecialchars($supplier['contact_name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Address *</label>
                            <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($supplier['address']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">City *</label>
                            <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($supplier['city']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Postal Code *</label>
                            <input type="text" class="form-control" name="postal_code" value="<?php echo htmlspecialchars($supplier['postal_code']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Country *</label>
                            <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($supplier['country']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($supplier['phone']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($supplier['email']); ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Materials *</label>
                            <select class="form-control material-select2" name="material_ids[]" id="material_id" multiple required style="width:100%;">
                                <?php foreach ($materials as $mat): ?>
                                    <option value="<?= htmlspecialchars($mat['material_id']) ?>"
                                        <?= in_array($mat['material_id'], $selectedMaterials) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mat['material_id']) ?> - <?= htmlspecialchars($mat['material_description']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Approval Status *</label>
                            <select class="form-control" name="approve_status" id="approve_status" required>
                                <option value="Conditional" <?= ($supplier['approve_status'] == 'Conditional') ? 'selected' : '' ?>>Conditional</option>
                                <option value="Approval" <?= ($supplier['approve_status'] == 'Approval') ? 'selected' : '' ?>>Approval</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                       <button type="submit" class="btn btn-primary">Update</button>
                        <a href="supplier.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>