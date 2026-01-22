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

// Pre-populate default departments if table is empty
$checkSql = "SELECT COUNT(*) as count FROM departments";
$checkStmt = sqlsrv_query($conn, $checkSql);
$countRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

if ($countRow['count'] == 0) {
    // Insert default departments
    $defaultDepartments = [
        ['dept_id' => 1, 'department_name' => 'Dipping'],
        ['dept_id' => 2, 'department_name' => 'Electronic'],
        ['dept_id' => 3, 'department_name' => 'Sealing']
    ];
    foreach ($defaultDepartments as $dept) {
        $insertSql = "INSERT INTO departments (dept_id, department_name) VALUES (?, ?)";
        $params = array($dept['dept_id'], $dept['department_name']);
        $insertStmt = sqlsrv_query($conn, $insertSql, $params);
    }
}

// Auto-generate next Dept ID
$result = sqlsrv_query($conn, "SELECT MAX(dept_id) AS max_id FROM departments");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$nextDeptId = $row['max_id'] + 1;

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept_id = intval($_POST['dept_id']);
    $department_name = trim($_POST['department_name']);

    // Check for duplicate department name
    $checkSql = "SELECT COUNT(*) as count FROM departments WHERE department_name = ?";
    $checkParams = array($department_name);
    $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
    $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

    if ($checkRow['count'] > 0) {
        $error_message = "Department name already exists!";
    } else {
        $insertSql = "INSERT INTO departments (dept_id, department_name) VALUES (?, ?)";
        $insertParams = array($dept_id, $department_name);
        $insertStmt = sqlsrv_query($conn, $insertSql, $insertParams);
        if ($insertStmt) {
            $success_message = "Department added successfully!";
            header("Location: department.php");
            exit;
        } else {
            $error_message = "Error adding department!";
        }
    }
}

// Handle delete operation
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    $deleteSql = "DELETE FROM departments WHERE id = ?";
    $deleteParams = array($delete_id);
    $deleteStmt = sqlsrv_query($conn, $deleteSql, $deleteParams);
    if ($deleteStmt) {
        $success_message = "Department deleted successfully!";
    } else {
        $error_message = "Error deleting department!";
    }
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = $search ? "WHERE department_name LIKE ?" : '';
$querySql = "SELECT TOP (1000) id, dept_id, department_name FROM departments $where ORDER BY dept_id ASC";
if ($search) {
    $departmentsStmt = sqlsrv_query($conn, $querySql, array("%$search%"));
} else {
    $departmentsStmt = sqlsrv_query($conn, $querySql);
}
$departments = [];
if ($departmentsStmt) {
    while ($row = sqlsrv_fetch_array($departmentsStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $row;
    }
    sqlsrv_free_stmt($departmentsStmt);
}

// For statistics
$totalDeptsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM departments");
$totalDeptsRow = sqlsrv_fetch_array($totalDeptsStmt, SQLSRV_FETCH_ASSOC);
$totalDepts = $totalDeptsRow['count'];

$defaultDeptsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM departments WHERE department_name IN ('Dipping', 'Electronic', 'Sealing')");
$defaultDeptsRow = sqlsrv_fetch_array($defaultDeptsStmt, SQLSRV_FETCH_ASSOC);
$defaultDepts = $defaultDeptsRow['count'];

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Department Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 240px;
            padding: 30px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out;
        }
        
        .sidebar.hide ~ .main-content,
        .main-content.sidebar-collapsed {
            margin-left: 0 !important;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 35px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        .card-header {
            background: linear-gradient(45deg, #667eea, #764ba2) !important;
            color: white !important;
            font-weight: 600;
            padding: 20px 25px;
            border: none;
        }
        
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table thead th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: #f8f9ff;
            transform: translateY(-1px);
        }
        
        .action-buttons a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .action-buttons a:hover {
            transform: scale(1.1);
        }
        
        .text-primary:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .text-danger:hover {
            background: rgba(220, 53, 69, 0.1);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
        }
        
        .default-dept-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
            margin-bottom: 20px;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 600px) {
            .main-content {
                padding: 15px;
            }
            
            .action-buttons {
                display: flex;
                gap: 5px;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-building"></i>
            Department Master
        </h1>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <?php 
    // SQLSRV version for department statistics
    $totalDeptsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM departments");
    $totalDeptsRow = sqlsrv_fetch_array($totalDeptsStmt, SQLSRV_FETCH_ASSOC);
    $totalDepts = $totalDeptsRow['count'];

    $defaultDeptsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM departments WHERE department_name IN ('Dipping', 'Electronic', 'Sealing')");
    $defaultDeptsRow = sqlsrv_fetch_array($defaultDeptsStmt, SQLSRV_FETCH_ASSOC);
    $defaultDepts = $defaultDeptsRow['count'];
    ?>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalDepts; ?></div>
                <div class="stats-label">Total Departments</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $defaultDepts; ?></div>
                <div class="stats-label">Default Departments</div>
            </div>
        </div>
    </div>

    <!-- Add New Department Button -->
    <?php if (!isset($_GET['add'])): ?>
        <div class="mb-4">
            <a href="?add=1" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New Department
            </a>
        </div>
    <?php endif; ?>

    <!-- Add Department Form -->
    <?php if (isset($_GET['add'])): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-plus me-2"></i>Add New Department
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-hashtag me-1"></i>Department ID
                                </label>
                                <input type="text" class="form-control" name="dept_id" value="<?php echo $nextDeptId; ?>" readonly>
                                <small class="text-muted">Auto-generated ID</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-building me-1"></i>Department Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="department_name" required placeholder="Enter department name">
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Department
                        </button>
                        <a href="department.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search and Department List -->
    <?php if (!isset($_GET['add'])): ?>
        <!-- Search Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3" method="get">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control" name="search" placeholder="Search Department..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                            <?php if ($search): ?>
                                <a href="department.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Department List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Department List
                <?php if ($search): ?>
                    <span class="badge bg-light text-dark ms-2">Search: "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 100px;">Action</th>
                                <th style="width: 80px;">Sr. No.</th>
                                <th style="width: 100px;">Dept ID</th>
                                <th>Department Name</th>
                                <th style="width: 120px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1; 
                            $defaultDeptNames = ['Dipping', 'Electronic', 'Sealing'];
                            if (count($departments) > 0):
                                foreach ($departments as $row):
                                    $isDefault = in_array($row['department_name'], $defaultDeptNames);
                            ?>
                            <tr>
                                <td class="text-center">
                                    <div class="action-buttons d-flex justify-content-center gap-1">
                                        <a href="department_edit.php?id=<?php echo $row['id']; ?>" class="text-primary" title="Edit Department">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!$isDefault): ?>
                                            <a href="?delete=1&id=<?php echo $row['id']; ?>" class="text-danger" title="Delete Department" onclick="return confirmDelete('<?php echo htmlspecialchars($row['department_name']); ?>', <?php echo $isDefault ? 'true' : 'false'; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" title="Default departments cannot be deleted">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><strong><?php echo $sr++; ?></strong></td>
                                <td><span class="badge bg-primary"><?php echo $row['dept_id']; ?></span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['department_name']); ?></strong>
                                    <?php if ($isDefault): ?>
                                        <span class="default-dept-badge">DEFAULT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isDefault): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check me-1"></i>System Default
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-user me-1"></i>User Added
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No Departments Found</h5>
                                        <p>No departments match your search criteria.</p>
                                        <?php if ($search): ?>
                                            <a href="department.php" class="btn btn-primary">
                                                <i class="fas fa-list me-2"></i>View All Departments
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Handle sidebar toggle
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        
        function adjustMainContent() {
            if (sidebar && sidebar.classList.contains('hide')) {
                mainContent.classList.add('sidebar-collapsed');
            } else {
                mainContent.classList.remove('sidebar-collapsed');
            }
        }
        
        adjustMainContent();
        
        if (sidebar) {
            const observer = new MutationObserver(adjustMainContent);
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }
        
        window.addEventListener('resize', adjustMainContent);
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Confirm delete for non-default departments
    function confirmDelete(deptName, isDefault) {
        if (isDefault) {
            alert('Cannot delete default departments (Dipping, Electronic, Sealing)');
            return false;
        }
        return confirm(`Are you sure you want to delete "${deptName}" department?`);
    }
</script>
</body>
</html>
