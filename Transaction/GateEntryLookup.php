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
        .main-content {
            animation: fadeIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Updated Stats Layout */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stats-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
            text-align: left;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
            border-color: #4f42c1;
        }

        .stats-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .icon-total { background: rgba(79, 66, 193, 0.1); color: #4f42c1; }
        .icon-today { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .icon-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .icon-suppliers { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }

        .stats-info {
            display: flex;
            flex-direction: column;
        }

        .stats-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 2px;
            line-height: 1;
        }

        .stats-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .search-section {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            margin-bottom: 32px;
        }

        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            overflow: hidden;
            background: #ffffff;
        }

        .card-header {
            background: #ffffff;
            color: #1e293b;
            padding: 24px;
            font-size: 1.1rem;
            font-weight: 700;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            color: #4f42c1;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 20px;
            -webkit-overflow-scrolling: touch;
        }

        /* Modern Scrollbar Styling */
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        .table-responsive::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
            border: 2px solid #f1f5f9;
        }
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .table {
            margin-bottom: 0;
            min-width: 1400px; /* Force horizontal scroll for better overview */
        }

        .table thead th {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 16px 20px;
            border-bottom: 2px solid #f1f5f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            color: #1e293b;
            font-size: 0.9rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .table tbody tr:hover {
            background-color: #f1f5f9;
        }

        .action-buttons a {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s;
            margin: 0 4px;
            text-decoration: none;
        }

        .action-buttons a:hover {
            transform: scale(1.1);
        }

        .text-primary { color: #4f42c1 !important; background: rgba(79, 66, 193, 0.1); }
        .text-danger { color: #ef4444 !important; background: rgba(239, 68, 68, 0.1); }

        .entry-status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-today { background: #dcfce7; color: #15803d; }
        .badge-past { background: #f1f5f9; color: #475569; }

        .invoice-badge {
            background: #eef2ff;
            color: #4338ca;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .package-count {
            background: #f0fdf4;
            color: #166534;
            padding: 6px 12px;
            border-radius: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .bg-success { background: #dcfce7 !important; color: #15803d !important; }
        .bg-warning { background: #fef9c3 !important; color: #a16207 !important; }

        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-success {
            background: #10b981;
            border: none;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }

        .form-label {
            font-weight: 700;
            color: #475569;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .form-control {
            border-radius: 10px;
            padding: 10px 16px;
            border: 1.5px solid #e2e8f0;
        }

        .form-control:focus {
            border-color: #4f42c1;
            box-shadow: 0 0 0 4px rgba(79, 66, 193, 0.1);
        }

        .advanced-search-toggle {
            color: #4f42c1;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .advanced-search-toggle:hover {
            color: #4338ca;
            text-decoration: none;
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
    <div class="stats-overview">
        <div class="stats-card">
            <div class="stats-icon icon-total">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stats-info">
                <div class="stats-number"><?php echo number_format($totalEntries); ?></div>
                <div class="stats-label">Total Entries</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon icon-today">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stats-info">
                <div class="stats-number"><?php echo number_format($todayEntries); ?></div>
                <div class="stats-label">Today's Entries</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon icon-pending">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <?php
                $pendingCountQuery = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM gate_entries WHERE is_deleted = 0 AND id NOT IN (SELECT gate_entry_id FROM store_entry_materials WHERE delete_id = 0)");
                $pendingCountRow = sqlsrv_fetch_array($pendingCountQuery, SQLSRV_FETCH_ASSOC);
                $pendingCount = $pendingCountRow['count'];
                ?>
                <div class="stats-number"><?php echo number_format($pendingCount); ?></div>
                <div class="stats-label">Pending Process</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-icon icon-suppliers">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stats-info">
                <div class="stats-number"><?php echo number_format($totalSuppliers); ?></div>
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
    <div class="card" style="margin-bottom: 60px;"> <!-- Space for bottom scroller -->
        <div class="card-header">
            <i class="fas fa-list me-2"></i>Gate Entry List
            <?php if (!empty($search_invoice) || !empty($search_vehicle) || !empty($search_supplier) || !empty($search_date_from) || !empty($search_date_to)): ?>
                <span class="badge bg-light text-dark ms-2">
                    Search Results: <?php echo count($entries); ?> entries found
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" id="mainTableResponsive">
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
                                        <span class="status-badge bg-success">Processed</span>
                                    <?php else: ?>
                                        <span class="status-badge bg-warning">Pending</span>
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

    <!-- Bottom Fixed Scroller -->
    <div id="bottomScrollerContainer" style="overflow-x: auto; position: fixed; bottom: 0; left: 0; right: 0; background: #ffffff; border-top: 1px solid #f1f5f9; height: 14px; z-index: 1000; box-shadow: 0 -4px 12px rgba(0,0,0,0.05);">
        <div id="bottomScrollerContent" style="height: 1px;"></div>
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

    // Synchronize bottom scrollers
    const bottomScroller = document.getElementById('bottomScrollerContainer');
    const bottomContent = document.getElementById('bottomScrollerContent');
    const mainTable = document.getElementById('mainTableResponsive');
    const table = mainTable ? mainTable.querySelector('table') : null;

    if (bottomScroller && bottomContent && mainTable && table) {
        const updateWidth = () => {
            bottomContent.style.width = table.offsetWidth + 'px';
        };
        updateWidth();
        window.addEventListener('resize', updateWidth);

        bottomScroller.onscroll = function() {
            mainTable.scrollLeft = bottomScroller.scrollLeft;
        };
        mainTable.onscroll = function() {
            bottomScroller.scrollLeft = mainTable.scrollLeft;
        };
    }
</script>

<style>
    /* Custom styling for the fixed bottom scroller */
    #bottomScrollerContainer::-webkit-scrollbar {
        height: 8px;
    }
    #bottomScrollerContainer::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }
    #bottomScrollerContainer::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
        border: 2px solid #f1f5f9;
    }
    #bottomScrollerContainer::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
</style>

</body>
</html>