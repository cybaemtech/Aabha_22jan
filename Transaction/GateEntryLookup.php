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

// Handle Delete (SQLSRV)
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    // Soft delete: set is_deleted=1 for the gate entry
    $sql = "UPDATE gate_entries SET is_deleted=1 WHERE id=?";
    $params = array($delete_id);
    $stmt = sqlsrv_query($conn, $sql, $params);

    // Optionally, delete related records if needed (example for store_entry_materials)
    $deleteRelatedSql = "UPDATE store_entry_materials SET delete_id=1 WHERE gate_entry_id=?";
    sqlsrv_query($conn, $deleteRelatedSql, array($delete_id));

    $_SESSION['message'] = "Gate entry deleted successfully!";
    header("Location: GateEntryLookup.php");
    exit;
}

// Handle messages
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "Gate entry updated successfully!";
}
if (isset($_GET['added']) && $_GET['added'] == 1) {
    $_SESSION['message'] = "Gate entry added successfully!";
}

// Search functionality
$search_invoice = isset($_GET['invoice_number']) ? trim($_GET['invoice_number']) : '';
$search_vehicle = isset($_GET['vehicle_number']) ? trim($_GET['vehicle_number']) : '';
$search_supplier = isset($_GET['supplier_name']) ? trim($_GET['supplier_name']) : '';
$search_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$search_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build WHERE clause (SQLSRV-safe)
$whereArr = ["g.is_deleted = 0"];
$params = [];

if (!empty($search_invoice)) {
    $whereArr[] = "g.invoice_number LIKE ?";
    $params[] = "%$search_invoice%";
}
if (!empty($search_vehicle)) {
    $whereArr[] = "g.vehicle_number LIKE ?";
    $params[] = "%$search_vehicle%";
}
if (!empty($search_supplier)) {
    $whereArr[] = "s.supplier_name LIKE ?";
    $params[] = "%$search_supplier%";
}
if (!empty($search_date_from)) {
    $whereArr[] = "g.entry_date >= ?";
    $params[] = $search_date_from;
}
if (!empty($search_date_to)) {
    $whereArr[] = "g.entry_date <= ?";
    $params[] = $search_date_to;
}

$where = "WHERE " . implode(" AND ", $whereArr);

$query = "
    SELECT g.*, s.supplier_name 
    FROM gate_entries g 
    LEFT JOIN suppliers s ON g.supplier_id = s.id 
    $where 
    ORDER BY g.id DESC
";
$entriesStmt = sqlsrv_query($conn, $query, $params);
$entries = [];
if ($entriesStmt) {
    while ($row = sqlsrv_fetch_array($entriesStmt, SQLSRV_FETCH_ASSOC)) {
        $entries[] = $row;
    }
    sqlsrv_free_stmt($entriesStmt);
}

// Get statistics (SQLSRV)
$totalEntriesStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM gate_entries WHERE is_deleted = 0");
$totalEntries = sqlsrv_fetch_array($totalEntriesStmt, SQLSRV_FETCH_ASSOC)['count'];

$todayEntriesStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM gate_entries WHERE is_deleted = 0 AND entry_date = ?", [date('Y-m-d')]);
$todayEntries = sqlsrv_fetch_array($todayEntriesStmt, SQLSRV_FETCH_ASSOC)['count'];

$totalSuppliersStmt = sqlsrv_query($conn, "SELECT COUNT(DISTINCT supplier_id) as count FROM gate_entries WHERE is_deleted = 0");
$totalSuppliers = sqlsrv_fetch_array($totalSuppliersStmt, SQLSRV_FETCH_ASSOC)['count'];

include '../Includes/sidebar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Gate Entry Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to gate entry lookup page */
        .entry-status-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-today {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-past {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }
        
        .invoice-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .package-count {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .advanced-search-toggle {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .advanced-search-toggle:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .date-range-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-separator {
            color: #6c757d;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-sign-in-alt"></i>
            Gate Entry Lookup
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
                <div class="stats-number"><?php echo $totalEntries; ?></div>
                <div class="stats-label">Total Gate Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $todayEntries; ?></div>
                <div class="stats-label">Today's Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalSuppliers; ?></div>
                <div class="stats-label">Active Suppliers</div>
            </div>
        </div>
    </div>

    <!-- Add New Button -->
    <div class="mb-4">
        <a href="GateEntry.php?add=1" class="btn btn-success">
            <i class="fas fa-plus me-2"></i>Add New Gate Entry
        </a>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <form method="get" id="searchForm">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-file-invoice input-icon"></i>
                        Invoice Number
                    </label>
                    <input type="text" class="form-control" name="invoice_number" placeholder="Search by invoice number" value="<?php echo htmlspecialchars($search_invoice); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-truck input-icon"></i>
                        Vehicle Number
                    </label>
                    <input type="text" class="form-control" name="vehicle_number" placeholder="Search by vehicle number" value="<?php echo htmlspecialchars($search_vehicle); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-building input-icon"></i>
                        Supplier Name
                    </label>
                    <input type="text" class="form-control" name="supplier_name" placeholder="Search by supplier name" value="<?php echo htmlspecialchars($search_supplier); ?>">
                </div>
            </div>

            <!-- Advanced Search -->
            <div class="mt-3">
                <a href="#" class="advanced-search-toggle" data-bs-toggle="collapse" data-bs-target="#advancedSearch">
                    <i class="fas fa-sliders-h me-1"></i>Advanced Search
                </a>
            </div>

            <div class="collapse mt-3" id="advancedSearch">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">
                            <i class="fas fa-calendar input-icon"></i>
                            Date Range
                        </label>
                        <div class="date-range-container">
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($search_date_from); ?>" placeholder="From">
                            <span class="date-separator">to</span>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($search_date_to); ?>" placeholder="To">
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <a href="GateEntryLookup.php" class="btn btn-outline-danger">
                    <i class="fas fa-times me-1"></i>Clear All
                </a>
            </div>
        </form>
    </div>

    <!-- Gate Entry List -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i>Gate Entry List
            <?php if (!empty($search_invoice) || !empty($search_vehicle) || !empty($search_supplier) || !empty($search_date_from) || !empty($search_date_to)): ?>
                <span class="badge bg-light text-dark ms-2">
                    Search Results: <?php echo count($entries); ?> entries found
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 120px;">Actions</th>
                            <th style="width: 80px;">Sr. No.</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Invoice Number</th>
                            <th>Invoice Date</th>
                            <th>Vehicle Number</th>
                            <th>Transporter</th>
                            <th>Supplier</th>
                            <th>Packages</th>
                            <th>Remark</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($entries) > 0): ?>
                            <?php $sr = 1; foreach($entries as $row): ?>
                            <tr>
                                <td class="text-center">
                                    <div class="action-buttons d-flex justify-content-center">
                                        <a href="GateEntry.php?edit=<?php echo $row['id']; ?>" class="text-primary" title="Edit Gate Entry">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="GateEntryLookup.php?delete=<?php echo $row['id']; ?>" class="text-danger" title="Delete Gate Entry" onclick="return confirmDelete('<?php echo htmlspecialchars($row['invoice_number']); ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                                <td><strong><?php echo $sr++; ?></strong></td>
                                <td>
                                    <?php
                                    // Ensure entry_date is a string before using htmlspecialchars
                                    $entryDate = $row['entry_date'];
                                    if ($entryDate instanceof DateTime) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    } elseif (is_object($entryDate) && method_exists($entryDate, 'format')) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    }
                                    // If it's not a string, try to cast or fallback
                                    if (!is_string($entryDate)) {
                                        $entryDate = (string)$entryDate;
                                    }
                                    echo htmlspecialchars($entryDate);
                                    ?>
                                    <?php if ($entryDate == date('Y-m-d')): ?>
                                        <span class="entry-status-badge badge-today">Today</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // Ensure entry_time is a string before using htmlspecialchars
                                    $entryTime = $row['entry_time'];
                                    if ($entryTime instanceof DateTime) {
                                        $entryTime = $entryTime->format('H:i:s');
                                    } elseif (is_object($entryTime) && method_exists($entryTime, 'format')) {
                                        $entryTime = $entryTime->format('H:i:s');
                                    }
                                    if (!is_string($entryTime)) {
                                        $entryTime = (string)$entryTime;
                                    }
                                    echo htmlspecialchars($entryTime);
                                    ?>
                                </td>
                                <td>
                                    <span class="invoice-badge">
                                        <?php
                                        $invoiceNumber = $row['invoice_number'];
                                        if (!is_string($invoiceNumber)) {
                                            $invoiceNumber = (string)$invoiceNumber;
                                        }
                                        echo htmlspecialchars($invoiceNumber);
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $invoiceDate = $row['invoice_date'];
                                    if ($invoiceDate instanceof DateTime) {
                                        $invoiceDate = $invoiceDate->format('Y-m-d');
                                    } elseif (is_object($invoiceDate) && method_exists($invoiceDate, 'format')) {
                                        $invoiceDate = $invoiceDate->format('Y-m-d');
                                    }
                                    if (!is_string($invoiceDate)) {
                                        $invoiceDate = (string)$invoiceDate;
                                    }
                                    echo htmlspecialchars($invoiceDate);
                                    ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars((string)$row['vehicle_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars((string)$row['transporter']); ?></td>
                                <td><strong><?php echo htmlspecialchars((string)$row['supplier_name']); ?></strong></td>
                                <td>
                                    <span class="package-count">
                                        <i class="fas fa-boxes"></i>
                                        <?php echo htmlspecialchars((string)$row['no_of_package']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['remark']): ?>
                                        <span class="remark-text"><?php echo htmlspecialchars((string)$row['remark']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // Check if store entry exists for this gate entry (SQLSRV)
                                    $storeEntryCheckStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM store_entry_materials WHERE gate_entry_id = ? AND delete_id = 0", array($row['id']));
                                    $storeEntryRow = sqlsrv_fetch_array($storeEntryCheckStmt, SQLSRV_FETCH_ASSOC);
                                    $hasStoreEntry = $storeEntryRow['count'] > 0;
                                    ?>
                                    <?php if ($hasStoreEntry): ?>
                                        <span class="badge bg-success">Processed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No Gate Entries Found</h5>
                                        <p>No gate entries match your search criteria.</p>
                                        <?php if (!empty($search_invoice) || !empty($search_vehicle) || !empty($search_supplier) || !empty($search_date_from) || !empty($search_date_to)): ?>
                                            <a href="GateEntryLookup.php" class="btn btn-primary">
                                                <i class="fas fa-list me-2"></i>View All Entries
                                            </a>
                                        <?php else: ?>
                                            <a href="GateEntry.php?add=1" class="btn btn-success">
                                                <i class="fas fa-plus me-2"></i>Add First Gate Entry
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
    function confirmDelete(invoiceNumber) {
        return confirm(`⚠️ Are you sure you want to delete gate entry with invoice number "${invoiceNumber}"?\n\nThis action cannot be undone.`);
    }

    // Search form enhancement
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        const invoice = document.querySelector('input[name="invoice_number"]').value.trim();
        const vehicle = document.querySelector('input[name="vehicle_number"]').value.trim();
        const supplier = document.querySelector('input[name="supplier_name"]').value.trim();
        const dateFrom = document.querySelector('input[name="date_from"]').value.trim();
        const dateTo = document.querySelector('input[name="date_to"]').value.trim();
        
        if (!invoice && !vehicle && !supplier && !dateFrom && !dateTo) {
            e.preventDefault();
            alert('Please enter at least one search criterion.');
            return false;
        }
        
        // Validate date range
        if (dateFrom && dateTo && dateFrom > dateTo) {
            e.preventDefault();
            alert('From date cannot be greater than To date.');
            return false;
        }
    });

    // Advanced search toggle enhancement
    document.querySelector('.advanced-search-toggle').addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector('#advancedSearch');
        const icon = this.querySelector('i');
        
        if (target.classList.contains('show')) {
            icon.className = 'fas fa-sliders-h me-1';
        } else {
            icon.className = 'fas fa-minus me-1';
        }
    });

    // Auto-fill today's date range when clicking "Today's Entries" stat
    document.querySelector('.stats-card:nth-child(2)').addEventListener('click', function() {
        const today = new Date().toISOString().split('T')[0];
        document.querySelector('input[name="date_from"]').value = today;
        document.querySelector('input[name="date_to"]').value = today;
        document.getElementById('searchForm').submit();
    });
</script>

</body>
</html>