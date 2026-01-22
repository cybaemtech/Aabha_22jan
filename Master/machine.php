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

// Auto-generate Machine ID (SQLSRV)
$result = sqlsrv_query($conn, "SELECT MAX(machine_id) AS max_id FROM machines");
$row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
$nextMachineId = $row['max_id'] + 1;

// Fetch departments for dropdown (SQLSRV)
$departmentsStmt = sqlsrv_query($conn, "SELECT id, department_name FROM departments ORDER BY department_name ASC");
$departments = [];
if ($departmentsStmt) {
    while ($dept = sqlsrv_fetch_array($departmentsStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $dept;
    }
    sqlsrv_free_stmt($departmentsStmt);
}

// Handle form submit with validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $machine_name = trim($_POST['machine_name']);
    $department_id = intval($_POST['department_id']);

    // Validation
    if (empty($machine_name)) {
        $_SESSION['error'] = "Machine name is required!";
    } elseif ($department_id <= 0) {
        $_SESSION['error'] = "Please select a department!";
    } else {
        // Generate department-wise machine_id
        $maxIdSql = "SELECT MAX(CAST(machine_id AS INT)) AS max_id FROM machines WHERE department_id = ?";
        $maxIdStmt = sqlsrv_query($conn, $maxIdSql, array($department_id));
        $maxIdRow = $maxIdStmt ? sqlsrv_fetch_array($maxIdStmt, SQLSRV_FETCH_ASSOC) : null;
        $nextMachineId = ($maxIdRow && $maxIdRow['max_id']) ? ($maxIdRow['max_id'] + 1) : 1;

        // Check for duplicate machine name in the same department
        $checkSql = "SELECT COUNT(*) as count FROM machines WHERE machine_name = ? AND department_id = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, array($machine_name, $department_id));
        $checkRow = $checkStmt ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;

        if ($checkRow && $checkRow['count'] > 0) {
            $_SESSION['error'] = "Machine name already exists in this department!";
        } else {
            $insertSql = "INSERT INTO machines (machine_id, machine_name, department_id) VALUES (?, ?, ?)";
            $insertStmt = sqlsrv_query($conn, $insertSql, array($nextMachineId, $machine_name, $department_id));
            if ($insertStmt) {
                $_SESSION['message'] = "Machine added successfully!";
                header("Location: machine.php");
                exit;
            } else {
                $_SESSION['error'] = "Error adding machine!";
            }
        }
    }
}

// Handle delete message
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $_SESSION['message'] = "Machine deleted successfully!";
}

// Handle update message
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "Machine updated successfully!";
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = $search ? "WHERE m.machine_name LIKE ? OR d.department_name LIKE ?" : '';
$querySql = "SELECT m.*, d.department_name FROM machines m LEFT JOIN departments d ON m.department_id = d.id $where ORDER BY m.machine_id ASC";
if ($search) {
    $machinesStmt = sqlsrv_query($conn, $querySql, array("%$search%", "%$search%"));
} else {
    $machinesStmt = sqlsrv_query($conn, $querySql);
}
$machines = [];
if ($machinesStmt) {
    while ($row = sqlsrv_fetch_array($machinesStmt, SQLSRV_FETCH_ASSOC)) {
        $machines[] = $row;
    }
    sqlsrv_free_stmt($machinesStmt);
}

// Get statistics (SQLSRV)
$totalMachinesStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM machines");
$totalMachinesRow = sqlsrv_fetch_array($totalMachinesStmt, SQLSRV_FETCH_ASSOC);
$totalMachines = $totalMachinesRow['count'];

$totalDepartmentsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM departments");
$totalDepartmentsRow = sqlsrv_fetch_array($totalDepartmentsStmt, SQLSRV_FETCH_ASSOC);
$totalDepartments = $totalDepartmentsRow['count'];

include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Machine Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
            animation: slideInUp 0.6s ease-out;
        }
        
        .card-header {
            background: linear-gradient(45deg, #667eea, #764ba2) !important;
            color: white !important;
            font-weight: 600;
            padding: 20px 25px;
            border: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #667eea;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 20px;
        }
        
        .form-group {
            position: relative;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-control, .form-select {
            padding: 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            width: 100%;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control:read-only {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .required {
            color: #dc3545;
            font-weight: bold;
        }
        
        .input-icon {
            color: #667eea;
            font-size: 1rem;
        }
        
        .btn {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #495057);
            border: none;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
        }
        
        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
        }
        
        .btn-outline-danger:hover {
            background: #dc3545;
            border-color: #dc3545;
            transform: translateY(-2px);
        }
        
        .form-actions {
            background: #f8f9fa;
            padding: 25px;
            margin: 25px -25px -25px -25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
            gap: 15px;
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
            padding: 15px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: #f8f9ff;
            transform: translateY(-1px);
        }
        
        .table tbody td {
            padding: 15px;
            vertical-align: middle;
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
            margin: 0 3px;
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
            margin-bottom: 25px;
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
        
        .search-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .search-input-group {
            position: relative;
            max-width: 500px;
        }
        
        .search-input-group .form-control {
            padding-left: 50px;
        }
        
        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 10;
        }
        
        .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
            font-style: italic;
        }
        
        /* Animations */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
            <i class="fas fa-cogs"></i>
            Machine Master
        </h1>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalMachines; ?></div>
                <div class="stats-label">Total Machines</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalDepartments; ?></div>
                <div class="stats-label">Departments Available</div>
            </div>
        </div>
    </div>

    <!-- Add New Machine Button -->
    <?php if (!isset($_GET['add'])): ?>
        <div class="mb-4">
            <a href="?add=1" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New Machine
            </a>
        </div>
    <?php endif; ?>

    <!-- Add Machine Form -->
    <?php if (isset($_GET['add'])): ?>
        <div class="form-container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    Add New Machine
                </div>
                <div class="card-body">
                    <form method="post" id="machineForm">
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Machine Information
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-hashtag input-icon"></i>
                                        Machine ID
                                    </label>
                                    <input type="text" class="form-control" name="machine_id" value="<?php echo $nextMachineId; ?>" readonly>
                                    <div class="help-text">Auto-generated unique identifier</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-cog input-icon"></i>
                                        Machine Name <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="machine_name" required placeholder="Enter machine name">
                                    <div class="help-text">Enter a unique machine name</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-building input-icon"></i>
                                        Department <span class="required">*</span>
                                    </label>
                                    <select class="form-select" name="department_id" required>
                                        <option value="">Select Department</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">Choose the department this machine belongs to</div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='machine.php'">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Machine
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search and Machine List -->
    <?php if (!isset($_GET['add'])): ?>
        <!-- Search Form -->
        <div class="search-container">
            <form method="get" class="d-flex align-items-center gap-3">
                <div class="search-input-group flex-grow-1">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search machines or departments..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <?php if ($search): ?>
                    <a href="machine.php" class="btn btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Machine List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Machine List
                <?php if ($search): ?>
                    <span class="badge bg-light text-dark ms-2">Search: "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 120px;">Actions</th>
                                <th style="width: 80px;">Sr. No.</th>
                                <th style="width: 100px;">Machine ID</th>
                                <th>Machine Name</th>
                                <th>Department</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($machines) > 0): ?>
                                <?php $sr = 1; foreach($machines as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <div class="action-buttons d-flex justify-content-center">
                                            <a href="machine_edit.php?id=<?php echo $row['id']; ?>" class="text-primary" title="Edit Machine">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="machine_delete.php?id=<?php echo $row['id']; ?>" class="text-danger" title="Delete Machine" onclick="return confirmDelete('<?php echo htmlspecialchars($row['machine_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $sr++; ?></strong></td>
                                    <td><span class="badge bg-primary"><?php echo $row['machine_id']; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['machine_name']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($row['department_name']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i>
                                            <h5>No Machines Found</h5>
                                            <p>No machines match your search criteria.</p>
                                            <?php if ($search): ?>
                                                <a href="machine.php" class="btn btn-primary">
                                                    <i class="fas fa-list me-2"></i>View All Machines
                                                </a>
                                            <?php else: ?>
                                                <a href="?add=1" class="btn btn-success">
                                                    <i class="fas fa-plus me-2"></i>Add First Machine
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

    // Form validation
    document.getElementById('machineForm')?.addEventListener('submit', function(e) {
        const machineName = document.querySelector('input[name="machine_name"]').value.trim();
        const departmentId = document.querySelector('select[name="department_id"]').value;
        
        if (!machineName) {
            alert('❌ Please enter a machine name!');
            e.preventDefault();
            return false;
        }
        
        if (!departmentId) {
            alert('❌ Please select a department!');
            e.preventDefault();
            return false;
        }
        
        return confirm(`✅ Are you sure you want to add machine "${machineName}"?`);
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Enhanced delete confirmation
    function confirmDelete(machineName) {
        return confirm(`⚠️ Are you sure you want to delete machine "${machineName}"?\n\nThis action cannot be undone.`);
    }
</script>
</body>
</html>