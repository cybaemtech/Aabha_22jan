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
$departments = [];
$deptStmt = sqlsrv_query($conn, "SELECT dept_id, department_name FROM departments ORDER BY department_name");
if ($deptStmt) {
    while ($row = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $row;
    }
}

// Always initialize $message to avoid undefined variable warning
$message = '';

// Handle delete for Checked By only
if (isset($_GET['delete_checked'])) {
    $id = intval($_GET['delete_checked']);
    $sql = "UPDATE check_by SET grn_checked_by = NULL WHERE id = ?";
    sqlsrv_query($conn, $sql, [$id]);
    header("Location: CheckBy.php?menu_type=" . urlencode($_GET['menu_type'] ?? '') . "&grn_option=checked_by");
    exit;
}

// Handle delete for Verified By only
if (isset($_GET['delete_verified'])) {
    $id = intval($_GET['delete_verified']);
    $sql = "UPDATE check_by SET grn_verified_by = NULL WHERE id = ?";
    sqlsrv_query($conn, $sql, [$id]);
    header("Location: CheckBy.php?menu_type=" . urlencode($_GET['menu_type'] ?? '') . "&grn_option=verified_by");
    exit;
}

// Handle deletion of entire row
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $sql = "DELETE FROM check_by WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$deleteId]);
    if ($stmt) {
        $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                      Record deleted successfully!
                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>';
    } else {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                      Error deleting record.
                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>';
    }
}

// Handle insertion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['menu_type'])) {
    $menu_type = $_POST['menu_type'];
    $checked_by = trim($_POST['checked_by'] ?? '');
    $verified_by = trim($_POST['verified_by'] ?? '');
    $department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : null;

    if ($menu_type === 'Supervisor') {
        if ($checked_by && $department_id) {
            $sql = "INSERT INTO check_by (menu, grn_checked_by, department_id) VALUES (?, ?, ?)";
            $params = [$menu_type, $checked_by, $department_id];
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt) {
                $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                              Entry added successfully!
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            } else {
                $errors = sqlsrv_errors();
                $errorMsg = $errors ? $errors[0]['message'] : 'Unknown error';
                $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                              Error adding entry: ' . htmlspecialchars($errorMsg) . '
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            }
        } else {
            $message = '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                          Please select department and enter supervisor name.
                          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>';
        }
    } else {
        if ($menu_type && ($checked_by || $verified_by)) {
            $sql = "INSERT INTO check_by (menu, grn_checked_by, grn_verified_by) VALUES (?, ?, ?)";
            $params = [$menu_type, $checked_by, $verified_by];
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt) {
                $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                              Entry added successfully!
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            } else {
                $errors = sqlsrv_errors();
                $errorMsg = $errors ? $errors[0]['message'] : 'Unknown error';
                $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                              Error adding entry: ' . htmlspecialchars($errorMsg) . '
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            }
        } else {
            $message = '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                          Please fill at least one field.
                          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>';
        }
    }
}

// Fetch unique menu types
$menuTypes = ['GRN Entry', 'Supervisor'];

// Fetch all entries for listing
$selected_menu = $_POST['menu_type'] ?? $_GET['menu_type'] ?? '';
$selected_grn_option = $_POST['grn_option'] ?? $_GET['grn_option'] ?? '';
$records = [];
if ($selected_menu) {
    $sql = "SELECT * FROM check_by WHERE menu = ? ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql, [$selected_menu]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Filter for GRN Entry based on grn_option
            if ($selected_menu === 'GRN Entry' && $selected_grn_option) {
                if (
                    ($selected_grn_option === 'checked_by' && !empty($row['grn_checked_by'])) ||
                    ($selected_grn_option === 'verified_by' && !empty($row['grn_verified_by']))
                ) {
                    $records[] = $row;
                }
            } else {
                $records[] = $row;
            }
        }
    }
}

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Check By Lookup</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        .main-container { max-width: 800px; margin: 60px auto; background: #fff; border-radius: 15px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); padding: 40px; }
        .page-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 12px; margin-bottom: 30px; text-align: center; }
        .page-header h3 { margin: 0; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .form-label { font-weight: 600; color: #2c3e50; margin-bottom: 8px; }
        .form-select, .form-control { border: 2px solid #e1e5e9; border-radius: 8px; padding: 12px 15px; transition: all 0.3s ease; }
        .form-select:focus, .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2); }
        .btn-primary, .btn-success { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s ease; }
        .btn-primary:hover, .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3); }
        .btn-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); border: none; border-radius: 6px; transition: all 0.3s ease; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3); }
        .alert { margin-top: 20px; border-radius: 10px; border: none; padding: 15px 20px; }
        .alert-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .alert-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .alert-warning { background: linear-gradient(135deg, #ffc107 0%, #ff8f00 100%); color: #212529; }
        .table { border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .table thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .table tbody tr:hover { background-color: #f8f9fa; }
        .action-btn { margin-right: 5px; }
        hr { border: none; height: 2px; background: linear-gradient(90deg, transparent, #667eea, transparent); margin: 30px 0; }
    </style>
</head>
<body>
<div class="main-container">
    <div class="page-header">
        <h3><i class="fas fa-user-check me-2"></i>Check By Lookup</h3>
        <p class="mb-0">Manage checked/verified by entries for different menus</p>
    </div>

    <?= $message ?>

    <!-- Add New Entry Form -->
    <form method="post" autocomplete="off" class="mb-4">
        <input type="hidden" name="grn_option" value="<?= htmlspecialchars($selected_grn_option) ?>">
        <div class="mb-3">
            <label for="menu_type" class="form-label">
                <i class="fas fa-list me-2"></i>Select Menu Type
            </label>
            <select class="form-select" id="menu_type" name="menu_type" required onchange="this.form.submit()">
                <option value="">-- Select Menu Type --</option>
                <?php foreach ($menuTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $selected_menu === $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($selected_menu): ?>
            <?php if ($selected_menu === 'GRN Entry'): ?>
                <div class="mb-3">
                    <label for="grn_option" class="form-label">
                        <i class="fas fa-toggle-on me-2"></i>Select Type to Add
                    </label>
                    <select class="form-select" id="grn_option" name="grn_option" required onchange="this.form.submit();toggleGrnInput()">
                        <option value="">-- Select --</option>
                        <option value="checked_by" <?= (isset($_POST['grn_option']) && $_POST['grn_option'] == 'checked_by') ? 'selected' : '' ?>>Checked By</option>
                        <option value="verified_by" <?= (isset($_POST['grn_option']) && $_POST['grn_option'] == 'verified_by') ? 'selected' : '' ?>>Verified By</option>
                    </select>
                </div>
                <div class="mb-3" id="checked_by_div" style="display:none;">
                    <label for="checked_by" class="form-label">
                        <i class="fas fa-user-check me-2"></i>
                        Checked By
                    </label>
                    <input type="text" class="form-control" id="checked_by" name="checked_by" placeholder="Enter Checked By name...">
                </div>
                <div class="mb-3" id="verified_by_div" style="display:none;">
                    <label for="verified_by" class="form-label">
                        <i class="fas fa-user-shield me-2"></i>
                        Verified By
                    </label>
                    <input type="text" class="form-control" id="verified_by" name="verified_by" placeholder="Enter Verified By name...">
                </div>
                <script>
                    function toggleGrnInput() {
                        var opt = document.getElementById('grn_option').value;
                        document.getElementById('checked_by_div').style.display = (opt === 'checked_by') ? 'block' : 'none';
                        document.getElementById('verified_by_div').style.display = (opt === 'verified_by') ? 'block' : 'none';
                    }
                    // On page load, show correct field if form was submitted
                    document.addEventListener('DOMContentLoaded', function() {
                        toggleGrnInput();
                    });
                </script>
            <?php else: ?>
                <div class="mb-3">
                    <label for="checked_by" class="form-label">
                        <i class="fas fa-user-check me-2"></i>
                        Checked By
                    </label>
                    <input type="text" class="form-control" id="checked_by" name="checked_by" placeholder="Enter Checked By name...">
                </div>
            <?php endif; ?>
            <?php if ($selected_menu === 'Supervisor'): ?>
                <div class="mb-3">
                    <label for="department_id" class="form-label">
                        <i class="fas fa-building me-2"></i>
                        Select Department
                    </label>
                    <select class="form-select" id="department_id" name="department_id" required>
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['dept_id'] ?>" <?= (isset($_POST['department_id']) && $_POST['department_id'] == $dept['dept_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-success w-100">
                <i class="fas fa-plus me-2"></i>Add Entry
            </button>
            <script>
            // Only allow one field to be filled at a time for non-GRN
            document.querySelector('form').addEventListener('submit', function(e) {
                var menu = document.getElementById('menu_type').value;
                if(menu === 'GRN Entry') {
                    var opt = document.getElementById('grn_option').value;
                    var checked = document.getElementById('checked_by').value.trim();
                    var verified = document.getElementById('verified_by').value.trim();
                    if(!opt) {
                        alert('Please select Checked By or Verified By.');
                        e.preventDefault();
                    }
                    if(opt === 'checked_by' && !checked) {
                        alert('Please enter Checked By.');
                        e.preventDefault();
                    }
                    if(opt === 'verified_by' && !verified) {
                        alert('Please enter Verified By.');
                        e.preventDefault();
                    }
                } else {
                    var checked = document.getElementById('checked_by').value.trim();
                    if(!checked) {
                        alert('Please enter Checked By.');
                        e.preventDefault();
                    }
                }
            });
            </script>
        <?php endif; ?>
    </form>

    <hr>

    <!-- List Section -->
    <?php if ($selected_menu): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>
            <?= htmlspecialchars($selected_menu) ?> List
        </h5>
        <span class="badge bg-info">
            <?= count($records) ?> items
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead>
    <tr>
        <th style="width: 60px;">#</th>
        <?php if ($selected_menu === 'GRN Entry' && $selected_grn_option === 'checked_by'): ?>
            <th>Checked By</th>
        <?php elseif ($selected_menu === 'GRN Entry' && $selected_grn_option === 'verified_by'): ?>
            <th>Verified By</th>
        <?php else: ?>
            <th>Checked By</th>
        <?php endif; ?>
        <?php if ($selected_menu === 'Supervisor'): ?>
            <th>Department</th>
        <?php endif; ?>
        <th style="width: 180px;">Actions</th>
    </tr>
</thead>
<tbody>
<?php
$i = 1;
if (!empty($records)):
    foreach ($records as $row): ?>
        <tr>
            <td><strong><?= $i++ ?></strong></td>
            <?php if ($selected_menu === 'GRN Entry' && $selected_grn_option === 'checked_by'): ?>
                <td>
                    <?= htmlspecialchars($row['grn_checked_by']) ?>
                </td>
                <td>
                    <?php if ($row['grn_checked_by']): ?>
                        <a href="?delete_checked=<?= $row['id'] ?>&menu_type=<?= urlencode($selected_menu) ?>&grn_option=checked_by"
                           class="btn btn-sm btn-danger action-btn ms-2"
                           onclick="return confirm('Delete Checked By?')"
                           title="Delete Checked By">
                            <i class="fas fa-trash"></i>
                        </a>
                    <?php endif; ?>
                </td>
            <?php elseif ($selected_menu === 'GRN Entry' && $selected_grn_option === 'verified_by'): ?>
                <td>
                    <?= htmlspecialchars($row['grn_verified_by']) ?>
                </td>
                <td>
                    <?php if ($row['grn_verified_by']): ?>
                        <a href="?delete_verified=<?= $row['id'] ?>&menu_type=<?= urlencode($selected_menu) ?>&grn_option=verified_by"
                           class="btn btn-sm btn-danger action-btn ms-2"
                           onclick="return confirm('Delete Verified By?')"
                           title="Delete Verified By">
                            <i class="fas fa-trash"></i>
                        </a>
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td>
                    <?= htmlspecialchars($row['grn_checked_by']) ?>
                </td>
                <?php if ($selected_menu === 'Supervisor'): ?>
                    <td>
                        <?php
                        $deptName = '';
                        foreach ($departments as $dept) {
                            if ($dept['dept_id'] == $row['department_id']) {
                                $deptName = $dept['department_name'];
                                break;
                            }
                        }
                        echo htmlspecialchars($deptName);
                        ?>
                    </td>
                <?php endif; ?>
                <td>
                    <a href="?delete_id=<?= $row['id'] ?>&menu_type=<?= urlencode($selected_menu) ?><?= $selected_grn_option ? '&grn_option=' . urlencode($selected_grn_option) : '' ?>"
                       class="btn btn-sm btn-danger action-btn"
                       onclick="return confirm('Delete entire entry?')"
                       title="Delete Entire Entry">
                        <i class="fas fa-trash-alt"></i> All
                    </a>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach;
else: ?>
    <tr>
        <td colspan="3" class="text-center text-muted py-4">
            <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
            <h6>No entries found</h6>
            <p class="mb-0">Add your first entry using the form above.</p>
        </td>
    </tr>
<?php endif; ?>
</tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

<?php
// Handle update for Verified By
if (isset($_POST['update_verified_id'])) {
    $id = intval($_POST['update_verified_id']);
    $verified_by = trim($_POST['verified_by']);
    if ($verified_by) {
        $sql = "UPDATE check_by SET grn_verified_by = ? WHERE id = ?";
        sqlsrv_query($conn, $sql, [$verified_by, $id]);
    }
    header("Location: CheckBy.php?menu_type=" . urlencode($_POST['menu_type'] ?? '') . "&grn_option=verified_by");
    exit;
}

// Handle update for Checked By
if (isset($_POST['update_checked_id'])) {
    $id = intval($_POST['update_checked_id']);
    $checked_by = trim($_POST['checked_by']);
    if ($checked_by) {
        $sql = "UPDATE check_by SET grn_checked_by = ? WHERE id = ?";
        sqlsrv_query($conn, $sql, [$checked_by, $id]);
    }
    header("Location: CheckBy.php?menu_type=" . urlencode($_POST['menu_type'] ?? '') . "&grn_option=checked_by");
    exit;
}

// Show edit forms if needed
$edit_verified_id = $_GET['edit_verified'] ?? null;
$edit_checked_id = $_GET['edit_checked'] ?? null;

if ($edit_verified_id) {
    // Fetch row for this ID
    $sql = "SELECT * FROM check_by WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$edit_verified_id]);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    ?>
    <form method="post" class="mb-4">
        <input type="hidden" name="update_verified_id" value="<?= $edit_verified_id ?>">
        <div class="mb-3">
            <label class="form-label"><i class="fas fa-user-shield me-2"></i>Verified By</label>
            <input type="text" class="form-control" name="verified_by" value="" placeholder="Enter Verified By name..." required>
        </div>
        <button type="submit" class="btn btn-success w-100">
            <i class="fas fa-save me-2"></i>Update Verified By
        </button>
    </form>
    <?php
} elseif ($edit_checked_id) {
    $sql = "SELECT * FROM check_by WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$edit_checked_id]);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    ?>
    <form method="post" class="mb-4">
        <input type="hidden" name="update_checked_id" value="<?= $edit_checked_id ?>">
        <div class="mb-3">
            <label class="form-label"><i class="fas fa-user-check me-2"></i>Checked By</label>
            <input type="text" class="form-control" name="checked_by" value="" placeholder="Enter Checked By name..." required>
        </div>
        <button type="submit" class="btn btn-success w-100">
            <i class="fas fa-save me-2"></i>Update Checked By
        </button>
    </form>
    <?php
}
?>

