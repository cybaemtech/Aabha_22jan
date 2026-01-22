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

// Connect DB
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

$success = '';
$error = '';

// Handle add new entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_menu'])) {
    $menu_type = $_POST['menu_type'] ?? '';
    $menu_value = trim($_POST['menu_value'] ?? '');

    if ($menu_type === 'flavour' && $menu_value !== '') {
        // Check if the value already exists to prevent duplicates
        $checkSql = "SELECT COUNT(*) as count FROM flavour_supervisor WHERE flavour = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$menu_value]);
        $checkResult = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        if ($checkResult['count'] > 0) {
            $error = "Flavour already exists!";
        } else {
            $sql = "INSERT INTO flavour_supervisor (flavour, supervisor) VALUES (?, NULL)";
            $stmt = sqlsrv_query($conn, $sql, [$menu_value]);
            if ($stmt) {
                $success = "Flavour added successfully!";
            } else {
                $error = "Database error: " . print_r(sqlsrv_errors(), true);
            }
        }
    } else {
        $error = "Please enter a flavour name.";
    }
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $type = $_GET['type'] === 'flavour' ? 'flavour' : '';
    $id = intval($_GET['id']);

    if ($type === 'flavour' && $id > 0) {
        $sql = "DELETE FROM flavour_supervisor WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        if ($stmt) {
            $success = "Flavour deleted successfully!";
        } else {
            $error = "Delete failed: " . print_r(sqlsrv_errors(), true);
        }
    }
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_menu'])) {
    $id = intval($_POST['edit_id']);
    $menu_type = $_POST['edit_type'];
    $menu_value = trim($_POST['edit_value']);

    if ($menu_type === 'flavour' && $menu_value && $id) {
        // Check if the new value already exists (excluding current record)
        $checkSql = "SELECT COUNT(*) as count FROM flavour_supervisor WHERE flavour = ? AND id != ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$menu_value, $id]);
        $checkResult = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        if ($checkResult['count'] > 0) {
            $error = "Flavour already exists!";
        } else {
            $sql = "UPDATE flavour_supervisor SET flavour = ? WHERE id = ?";
            $stmt = sqlsrv_query($conn, $sql, [$menu_value, $id]);
            if ($stmt) {
                $success = "Flavour updated successfully!";
            } else {
                $error = "Update failed: " . print_r(sqlsrv_errors(), true);
            }
        }
    } else {
        $error = "Invalid edit request.";
    }
}

// Fetch all unique flavours from flavour_supervisor table
$flavours = [];
$flavourRes = sqlsrv_query($conn, "SELECT DISTINCT flavour FROM flavour_supervisor WHERE flavour IS NOT NULL AND flavour != '' ORDER BY flavour");
if ($flavourRes) {
    while ($row = sqlsrv_fetch_array($flavourRes, SQLSRV_FETCH_ASSOC)) {
        $flavours[] = $row['flavour'];
    }
}

// Fetch all entries for listing with their IDs
$allEntries = [];
$allRes = sqlsrv_query($conn, "SELECT id, flavour FROM flavour_supervisor WHERE flavour IS NOT NULL AND flavour != '' ORDER BY id DESC");
if ($allRes) {
    while ($row = sqlsrv_fetch_array($allRes, SQLSRV_FETCH_ASSOC)) {
        $allEntries[] = $row;
    }
}

// Determine selected menu
$selected_menu = $_POST['menu_type'] ?? $_GET['menu_type'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Flavour & Supervisor - AABHA MFG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <style>
      
        
        .main-container { 
            max-width: 800px; 
            margin: 60px auto; 
            background: #fff; 
            border-radius: 15px; 
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); 
            padding: 40px;
            backdrop-filter: blur(10px);
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .page-header h3 {
            margin: 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .form-label { 
            font-weight: 600; 
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .form-select, .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        .btn-primary, .btn-success { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover, .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff8f00 100%);
            border: none;
            color: #212529;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }
        
        .alert { 
            margin-top: 20px;
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .action-btn { 
            margin-right: 5px;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .stats-label {
            color: #6c757d;
            font-weight: 500;
        }
        
        hr {
            border: none;
            height: 2px;
            background: linear-gradient(90deg, transparent, #667eea, transparent);
            margin: 30px 0;
        }
    </style>
</head>
<body>
<div class="main-container">
    <div class="page-header">
        <h3><i class="fas fa-plus-circle me-2"></i> Add Flavour & Supervisor</h3>
        <p class="mb-0">Manage flavours and supervisors for sealing operations</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?= count($flavours) ?></div>
                <div class="stats-label">Total Flavours</div>
            </div>
        </div>
       
    </div>

    <!-- Add New Entry Form -->
    <form method="post" autocomplete="off" class="mb-4">
        <div class="mb-3">
            <label for="menu_type" class="form-label">
                <i class="fas fa-list me-2"></i>Select Menu Type
            </label>
            <select class="form-select" id="menu_type" name="menu_type" required onchange="this.form.submit()">
                <option value="">-- Select Menu Type --</option>
                <option value="flavour" <?= $selected_menu === 'flavour' ? 'selected' : '' ?>>
                    <i class="fas fa-cookie-bite"></i> Flavour
                </option>
            </select>
        </div>
        
        <?php if ($selected_menu): ?>
        <div class="mb-3">
            <label for="menu_value" class="form-label">
                <i class="fas fa-<?= $selected_menu === 'flavour' ? 'cookie-bite' : 'user-tie' ?> me-2"></i>
                Add New <?= ucfirst($selected_menu) ?>
            </label>
            <input type="text" 
                   class="form-control" 
                   id="menu_value" 
                   name="menu_value" 
                   placeholder="Enter <?= ucfirst($selected_menu) ?> name..." 
                   required
                   maxlength="255">
        </div>
        <button type="submit" name="add_menu" class="btn btn-success w-100">
            <i class="fas fa-plus me-2"></i>Add <?= ucfirst($selected_menu) ?>
        </button>
        <?php endif; ?>
    </form>

    <hr>

    <!-- List Section -->
 <?php
 if ($selected_menu === 'flavour'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fas fa-cookie-bite me-2"></i>
        Flavour List
    </h5>
    <span class="badge bg-info">
        <?= count($allEntries) ?> items
    </span>
</div>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th style="width: 60px;">#</th>
                <th>Flavour Name</th>
                <th style="width: 150px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $i = 1;
        if (!empty($allEntries)):
            foreach ($allEntries as $entry): ?>
                <tr>
                    <td><strong><?= $i++ ?></strong></td>
                    <td>
                        <i class="fas fa-cookie-bite me-2 text-muted"></i>
                        <?= htmlspecialchars($entry['flavour']) ?>
                    </td>
                    <td>
                        <button type="button" 
                                class="btn btn-sm btn-warning action-btn"
                                onclick="editItem(<?= $entry['id'] ?>, '<?= htmlspecialchars($entry['flavour']) ?>', 'flavour')"
                                title="Edit Flavour">
                            <i class="fas fa-edit"></i>
                        </button>
                        <a href="?delete=1&type=flavour&id=<?= $entry['id'] ?>&menu_type=flavour" 
                           class="btn btn-sm btn-danger action-btn" 
                           onclick="return confirm('Are you sure you want to delete this flavour?\n\nFlavour: <?= htmlspecialchars($entry['flavour']) ?>\n\nThis action cannot be undone.')"
                           title="Delete Flavour">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach;
        else: ?>
            <tr>
                <td colspan="3" class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                    <h6>No flavours found</h6>
                    <p class="mb-0">Add your first flavour using the form above.</p>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

    <!-- Back to Sealing Button -->
    <div class="text-center mt-4">
        <a href="sealing.php" class="btn btn-primary">
            <i class="fas fa-arrow-left me-2"></i>Back to Sealing Entry
        </a>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit <span id="editType"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="editId">
                    <input type="hidden" name="edit_type" id="editTypeInput">
                    <div class="mb-3">
                        <label for="editValue" class="form-label">Name</label>
                        <input type="text" class="form-control" name="edit_value" id="editValue" required maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_menu" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editItem(id, value, type) {
    // Set modal title
    document.getElementById('editType').textContent = type.charAt(0).toUpperCase() + type.slice(1);
    
    // Set form values
    document.getElementById('editId').value = id;
    document.getElementById('editTypeInput').value = type;
    document.getElementById('editValue').value = value;
    
    // Show modal
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>
</body>
</html>