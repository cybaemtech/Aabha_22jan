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

// Connect to SQL Server
include '../Includes/db_connect.php'; // this should use sqlsrv_connect()
include '../Includes/sidebar.php';

// Initialize request_date_value
$request_date_value = '';

// Use request_no from query string
if (isset($_GET['request_no']) && $_GET['request_no'] !== '') {
    $req_no = $_GET['request_no'];

    $query = "SELECT TOP 1 request_date FROM material_request_items WHERE request_no = ? ORDER BY id DESC";
    $params = [$req_no];
    $dateRes = sqlsrv_query($conn, $query, $params);

    if ($dateRes && $dateRow = sqlsrv_fetch_array($dateRes, SQLSRV_FETCH_ASSOC)) {
        $request_date_value = $dateRow['request_date'];
    }
}

// Base SQL with join
$sql = "SELECT mri.*, d.department_name 
        FROM material_request_items mri
        LEFT JOIN departments d ON mri.department_id = d.id";

$conditions = [];
$params = [];

// Add filters
if (!empty($_GET['request_no'])) {
    $conditions[] = "mri.request_no LIKE ?";
    $params[] = '%' . $_GET['request_no'] . '%';
}
if (!empty($_GET['department'])) {
    $conditions[] = "d.department_name LIKE ?";
    $params[] = '%' . $_GET['department'] . '%';
}
if (!empty($_GET['request_by'])) {
    $conditions[] = "mri.request_by LIKE ?";
    $params[] = '%' . $_GET['request_by'] . '%';
}
if (!empty($_GET['request_date'])) {
    $conditions[] = "CAST(mri.request_date AS DATE) = ?";
    $params[] = $_GET['request_date'];
}

// Add WHERE clause
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY mri.id DESC";

// Execute main query
$result = sqlsrv_query($conn, $sql, $params);

// Statistics queries
// 1. Total
$totalQuery = "SELECT COUNT(*) as count FROM material_request_items";
$totalResult = sqlsrv_query($conn, $totalQuery);
$totalRequests = ($row = sqlsrv_fetch_array($totalResult, SQLSRV_FETCH_ASSOC)) ? $row['count'] : 0;

// 2. Today
$todayQuery = "SELECT COUNT(*) as count FROM material_request_items WHERE CAST(request_date AS DATE) = CAST(GETDATE() AS DATE)";
$todayResult = sqlsrv_query($conn, $todayQuery);
$todayRequests = ($row = sqlsrv_fetch_array($todayResult, SQLSRV_FETCH_ASSOC)) ? $row['count'] : 0;

// 3. Recent (last 7 days)
$recentQuery = "SELECT COUNT(*) as count FROM material_request_items WHERE request_date >= DATEADD(DAY, -7, GETDATE())";
$recentResult = sqlsrv_query($conn, $recentQuery);
$recentRequests = ($row = sqlsrv_fetch_array($recentResult, SQLSRV_FETCH_ASSOC)) ? $row['count'] : 0;
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Issue Note Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to material issue note lookup */
        .request-id-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .material-id-badge {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .batch-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .quantity-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .qty-requested {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .qty-available {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .department-badge {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .search-title {
            color: #495057;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .unit-display {
            color: #6c757d;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .request-by {
            color: #495057;
            font-weight: 600;
        }
        
        .no-data-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .no-data-icon {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 15px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .export-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-clipboard-list"></i>
            Material Issue Note Lookup
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
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalRequests; ?></div>
                <div class="stats-label">Total Requests</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $todayRequests; ?></div>
                <div class="stats-label">Today's Requests</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $recentRequests; ?></div>
                <div class="stats-label">Recent Requests (7 days)</div>
            </div>
        </div>
    </div>

    <!-- Add New Button -->
    <div class="mb-4">
        <a href="MaterialIssueNotePage.php" class="btn btn-success">
            <i class="fas fa-plus me-2"></i>Add New Material Request
        </a>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <div class="search-title">
            <i class="fas fa-search"></i>
            Search Material Requests
        </div>
        
        <form method="GET" id="searchForm">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="fas fa-hashtag input-icon"></i>
                        Request Number
                    </label>
                    <input type="text" class="form-control" name="request_no" placeholder="Enter request number" value="<?= isset($_GET['request_no']) ? htmlspecialchars($_GET['request_no']) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="fas fa-building input-icon"></i>
                        Department
                    </label>
                    <input type="text" class="form-control" name="department" placeholder="Enter department name" value="<?= isset($_GET['department']) ? htmlspecialchars($_GET['department']) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="fas fa-user input-icon"></i>
                        Requested By
                    </label>
                    <input type="text" class="form-control" name="request_by" placeholder="Enter requester name" value="<?= isset($_GET['request_by']) ? htmlspecialchars($_GET['request_by']) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="fas fa-calendar input-icon"></i>
                        Request Date
                    </label>
                    <input type="date" class="form-control" name="request_date" value="<?= $request_date_value ? htmlspecialchars($request_date_value) : (isset($_GET['request_date']) ? htmlspecialchars($_GET['request_date']) : '') ?>">
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-danger">
                    <i class="fas fa-times me-1"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Export Buttons -->
    <div class="export-buttons">
        <button class="btn export-btn" onclick="exportToExcel()">
            <i class="fas fa-file-excel me-2"></i>Export to Excel
        </button>
        <button class="btn export-btn" onclick="printTable()">
            <i class="fas fa-print me-2"></i>Print Report
        </button>
    </div>

    <!-- Material Request Table -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i>Material Request List
            <?php if (!empty($_GET['request_no']) || !empty($_GET['department']) || !empty($_GET['request_by']) || !empty($_GET['request_date'])): ?>
    <?php
        // Count number of rows manually
        $rowCount = 0;
        if ($result) {
            while (sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                $rowCount++;
            }

            // Re-run the query to reset result set for later looping
            $result = sqlsrv_query($conn, $sql);
        }
    ?>
    <span class="badge bg-light text-dark ms-2">
        Search Results: <?php echo $rowCount; ?> entries found
    </span>
<?php endif; ?>

        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0" id="materialRequestTable">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 120px;">Actions</th>
                            <th style="width: 80px;">Sr. No.</th>
                            <th>Material Request ID</th>
                            <th>Material ID</th>
                            <th>Description</th>
                            <th>Unit</th>
                            <th>Batch No</th>
                            <th>Request Qty</th>
                            <th>Available Qty</th>
                            <th>Department</th>
                            <th>Request Date</th>
                            <th>Request By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && sqlsrv_has_rows($result)): ?>
    <?php $sr = 1; while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)): ?>
        <tr>
            <td class="text-center">
                <div class="action-buttons d-flex justify-content-center">
                    <a href="MaterialIssueNotePage.php?id=<?= urlencode($row['material_request_id']) ?>" class="text-primary" title="Edit Request">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="MaterialIssueNoteDelete.php?id=<?= urlencode($row['material_request_id']) ?>" class="text-danger" title="Delete Request" onclick="return confirmDelete('<?= htmlspecialchars($row['material_request_id']) ?>')">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                </div>
            </td>
            <td><strong><?= $sr++ ?></strong></td>
            <td>
                <span class="request-id-badge">
                    <?= htmlspecialchars($row['material_request_id']) ?>
                </span>
            </td>
            <td>
                <span class="material-id-badge">
                    <?= htmlspecialchars($row['material_id']) ?>
                </span>
            </td>
            <td><strong><?= htmlspecialchars($row['description']) ?></strong></td>
            <td>
                <span class="unit-display">
                    <?= htmlspecialchars($row['unit']) ?>
                </span>
            </td>
            <td>
                <?php if (!empty($row['batch_no'])): ?>
                    <span class="batch-number"><?= htmlspecialchars($row['batch_no']) ?></span>
                <?php else: ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="quantity-badge qty-requested">
                    <?= htmlspecialchars($row['request_qty']) ?>
                </span>
            </td>
            <td>
                <span class="quantity-badge qty-available">
                    <?= htmlspecialchars($row['available_qty']) ?>
                </span>
            </td>
            <td>
                <span class="department-badge">
                    <?= htmlspecialchars($row['department_name']) ?>
                </span>
            </td>
            <td><?= htmlspecialchars(is_object($row['request_date']) ? $row['request_date']->format('Y-m-d') : $row['request_date']) ?></td>
            <td>
                <span class="request-by">
                    <?= htmlspecialchars($row['request_by']) ?>
                </span>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr>
        <td colspan="12" class="no-data-message">
            <div>
                <i class="fas fa-clipboard-list no-data-icon"></i>
                <h5>No Material Requests Found</h5>
                <p>No material requests match your search criteria.</p>
                <?php if (!empty($_GET['request_no']) || !empty($_GET['department']) || !empty($_GET['request_by']) || !empty($_GET['request_date'])): ?>
                    <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-primary">
                        <i class="fas fa-list me-2"></i>View All Requests
                    </a>
                <?php else: ?>
                    <a href="MaterialIssueNotePage.php" class="btn btn-success">
                        <i class="fas fa-plus me-2"></i>Add First Material Request
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
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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

    // Enhanced delete confirmation
    function confirmDelete(requestId) {
        return confirm(`⚠️ Are you sure you want to delete material request "${requestId}"?\n\nThis action cannot be undone.`);
    }

    // Search form enhancement
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        const requestNo = document.querySelector('input[name="request_no"]').value.trim();
        const department = document.querySelector('input[name="department"]').value.trim();
        const requestBy = document.querySelector('input[name="request_by"]').value.trim();
        const requestDate = document.querySelector('input[name="request_date"]').value.trim();
        
        if (!requestNo && !department && !requestBy && !requestDate) {
            e.preventDefault();
            alert('Please enter at least one search criterion.');
            return false;
        }
    });

    // Export to Excel function
    function exportToExcel() {
        const table = document.getElementById('materialRequestTable');
        let csvContent = '';
        
        // Get headers
        const headers = table.querySelectorAll('thead th');
        const headerRow = Array.from(headers).map(header => header.textContent.trim()).join(',');
        csvContent += headerRow + '\n';
        
        // Get data rows
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            if (row.cells.length > 1) { // Skip no-data row
                const cells = row.querySelectorAll('td');
                const rowData = Array.from(cells).slice(1).map(cell => { // Skip action column
                    return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                }).join(',');
                csvContent += rowData + '\n';
            }
        });
        
        // Download file
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'material_requests_' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    // Print function
    function printTable() {
        const printContent = document.getElementById('materialRequestTable').outerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Material Requests Report</title>
                    <style>
                        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; font-weight: bold; }
                        .action-buttons { display: none; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <h2>Material Issue Note Report</h2>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
</script>

</body>
</html>
