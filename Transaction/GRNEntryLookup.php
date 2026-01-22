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
include '../Includes/db_connect.php'; // Must use sqlsrv_connect()

// Delete GRN Entry
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $sql = "UPDATE grn_header SET delete_id = 1 WHERE grn_header_id = ?";
    $result = sqlsrv_query($conn, $sql, [$deleteId]);
    if ($result) {
        $_SESSION['message'] = "GRN entry deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete GRN entry.";
    }
    header("Location: GRNEntryLookup.php");
    exit;
}

// Handle messages
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "GRN entry updated successfully!";
}
if (isset($_GET['added']) && $_GET['added'] == 1) {
    $_SESSION['message'] = "GRN entry added successfully!";
}

// Search filters
$search_grn_no = isset($_GET['search_grn_no']) ? trim($_GET['search_grn_no']) : '';
$search_invoice = isset($_GET['search_invoice']) ? trim($_GET['search_invoice']) : '';
$search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';

$whereArr = ["(gh.delete_id IS NULL OR gh.delete_id = 0)"];
$params = [];

if ($search_grn_no !== '') {
    $whereArr[] = "gh.grn_no LIKE ?";
    $params[] = "%$search_grn_no%";
}
if ($search_invoice !== '') {
    $whereArr[] = "g.invoice_number LIKE ?";
    $params[] = "%$search_invoice%";
}
if ($search_supplier !== '') {
    $whereArr[] = "sup.supplier_name LIKE ?";
    $params[] = "%$search_supplier%";
}

$where = "WHERE " . implode(' AND ', $whereArr);

// Final Query
$sql = "
    SELECT 
        gh.grn_header_id,
        g.id AS gate_entry_id,
        g.invoice_number,
        g.vehicle_number,
        g.supplier_id,
        sup.supplier_name,
        gh.grn_no,
        gh.po_no,
        g.entry_date,
        g.entry_time,
        gh.tear_damage_leak,
        gh.damage_remark,
        gh.labeling,
        gh.labeling_remark,
        gh.packing,
        gh.packing_remark,
        gh.cert_analysis,
        gh.cert_analysis_remark,
        gw.checked_by,
        gw.verified_by
    FROM grn_header gh
    LEFT JOIN gate_entries g ON gh.gate_entry_id = g.id 
    LEFT JOIN suppliers sup ON g.supplier_id = sup.id
    LEFT JOIN grn_weight_details gw ON gw.grn_header_id = gh.grn_header_id
    $where
    ORDER BY gh.grn_header_id DESC
";

$stmt = sqlsrv_query($conn, $sql, $params);
$entries = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to strings for display
        if (isset($row['entry_date']) && $row['entry_date'] instanceof DateTime) {
            $row['entry_date'] = $row['entry_date']->format('Y-m-d');
        }
        if (isset($row['entry_time']) && $row['entry_time'] instanceof DateTime) {
            $row['entry_time'] = $row['entry_time']->format('H:i:s');
        }
        $entries[] = $row;
    }
}

// Statistics

// Total GRNs
$stmtTotal = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM grn_header WHERE (delete_id IS NULL OR delete_id = 0)");
$totalGRN = 0;
if ($stmtTotal) {
    $row = sqlsrv_fetch_array($stmtTotal, SQLSRV_FETCH_ASSOC);
    $totalGRN = $row['count'];
}

// Today's GRNs
$stmtToday = sqlsrv_query($conn, "
    SELECT COUNT(*) as count 
    FROM grn_header gh 
    LEFT JOIN gate_entries g ON gh.gate_entry_id = g.id 
    WHERE (gh.delete_id IS NULL OR gh.delete_id = 0) 
      AND CAST(g.entry_date AS DATE) = CAST(GETDATE() AS DATE)
");
$todayGRN = 0;
if ($stmtToday) {
    $row = sqlsrv_fetch_array($stmtToday, SQLSRV_FETCH_ASSOC);
    $todayGRN = $row['count'];
}

// Pending GRNs
$stmtPending = sqlsrv_query($conn, "
    SELECT COUNT(*) as count 
    FROM gate_entries g 
    WHERE g.id NOT IN (
        SELECT gate_entry_id 
        FROM grn_header 
        WHERE delete_id IS NULL OR delete_id = 0
    )
");
$pendingGRN = 0;
if ($stmtPending) {
    $row = sqlsrv_fetch_array($stmtPending, SQLSRV_FETCH_ASSOC);
    $pendingGRN = $row['count'];
}

include '../Includes/sidebar.php';
?>


<!DOCTYPE html>
<html>
<head>
    <title>GRN Entry Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to GRN lookup page */
        .grn-status-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-approved {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-rejected {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        
        .badge-pending {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .grn-number-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .condition-status {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .condition-label {
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .condition-remark {
            font-style: italic;
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .pending-modal .modal-content {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .pending-modal .modal-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        .select-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 6px 15px;
            border-radius: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .select-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .vehicle-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }

    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-clipboard-list"></i>
            GRN Entry Lookup
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
                <div class="stats-number"><?php echo $totalGRN; ?></div>
                <div class="stats-label">Total GRN Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $todayGRN; ?></div>
                <div class="stats-label">Today's GRN</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $pendingGRN; ?></div>
                <div class="stats-label">Pending Gate Entries</div>
            </div>
        </div>
    </div>

    <!-- Add New Button -->
    <div class="mb-4">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pendingGateEntryModal">
            <i class="fas fa-plus me-2"></i>Add New GRN Entry
        </button>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <form method="get" id="searchForm">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-hashtag input-icon"></i>
                        GRN Number
                    </label>
                    <input type="text" class="form-control" name="search_grn_no" placeholder="Search by GRN number" value="<?php echo htmlspecialchars($search_grn_no); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-file-invoice input-icon"></i>
                        Invoice Number
                    </label>
                    <input type="text" class="form-control" name="search_invoice" placeholder="Search by invoice number" value="<?php echo htmlspecialchars($search_invoice); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fas fa-building input-icon"></i>
                        Supplier Name
                    </label>
                    <input type="text" class="form-control" name="search_supplier" placeholder="Search by supplier name" value="<?php echo htmlspecialchars($search_supplier); ?>">
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <a href="GRNEntryLookup.php" class="btn btn-outline-danger">
                    <i class="fas fa-times me-1"></i>Clear All
                </a>
            </div>
        </form>
    </div>

    <!-- GRN Entry List -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i>GRN Entry List
            <?php if (!empty($search_grn_no) || !empty($search_invoice) || !empty($search_supplier)): ?>
                <span class="badge bg-light text-dark ms-2">
                    Search Results: <?= count($entries); ?> entries found
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 100px;">Actions</th>
                            <th style="width: 80px;">Sr. No.</th>
                            <th>GRN Number</th>
                            <th>Invoice Number</th>
                            <th>Supplier</th>
                            <th>PO Number</th>
                            <th>Received Date</th>
                            <th>Vehicle Number</th>
                            <th>Damage Condition</th>
                            <th>Labeling</th>
                            <th>Packing</th>
                            <th>Certificate Analysis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($entries) > 0): ?>
                            <?php $sr = 1; foreach($entries as $row): ?>
                            <tr>
                                <td class="text-center">
                                    <div class="action-buttons d-flex justify-content-center gap-1">
                                        <a href="GRNEntryEdit.php?edit=1&grn_header_id=<?php echo $row['grn_header_id']; ?>&gate_entry_id=<?php echo $row['gate_entry_id']; ?>" class="text-primary" title="Edit GRN Entry">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-info text-white" onclick="printGRN(<?php echo $row['grn_header_id']; ?>)" title="Print GRN Details">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <a href="GRNEntryLookup.php?delete_id=<?php echo $row['grn_header_id']; ?>" class="text-danger" title="Delete GRN Entry" onclick="return confirmDelete('<?php echo htmlspecialchars($row['grn_no']); ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                                <td><strong><?php echo $sr++; ?></strong></td>
                                <td>
                                    <span class="grn-number-badge">
                                        <?php echo htmlspecialchars($row['grn_no']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($row['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['po_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['entry_date']); ?></td>
                                <td>
                                    <?php if ($row['vehicle_number']): ?>
                                        <span class="vehicle-number"><?php echo htmlspecialchars($row['vehicle_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="condition-status">
                                        <span class="condition-label">
                                            <?php 
                                            $status = $row['tear_damage_leak'];
                                            $badgeClass = '';
                                            if (strtolower($status) == 'good') $badgeClass = 'badge-approved';
                                            elseif (strtolower($status) == 'damaged') $badgeClass = 'badge-rejected';
                                            else $badgeClass = 'badge-pending';
                                            ?>
                                            <span class="grn-status-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </span>
                                        <?php if ($row['damage_remark']): ?>
                                            <span class="condition-remark"><?php echo htmlspecialchars($row['damage_remark']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="condition-status">
                                        <span class="condition-label">
                                            <?php 
                                            $status = $row['labeling'];
                                            $badgeClass = '';
                                            if (strtolower($status) == 'good') $badgeClass = 'badge-approved';
                                            elseif (strtolower($status) == 'poor') $badgeClass = 'badge-rejected';
                                            else $badgeClass = 'badge-pending';
                                            ?>
                                            <span class="grn-status-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </span>
                                        <?php if ($row['labeling_remark']): ?>
                                            <span class="condition-remark"><?php echo htmlspecialchars($row['labeling_remark']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="condition-status">
                                        <span class="condition-label">
                                            <?php 
                                            $status = $row['packing'];
                                            $badgeClass = '';
                                            if (strtolower($status) == 'good') $badgeClass = 'badge-approved';
                                            elseif (strtolower($status) == 'poor') $badgeClass = 'badge-rejected';
                                            else $badgeClass = 'badge-pending';
                                            ?>
                                            <span class="grn-status-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </span>
                                        <?php if ($row['packing_remark']): ?>
                                            <span class="condition-remark"><?php echo htmlspecialchars($row['packing_remark']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="condition-status">
                                        <span class="condition-label">
                                            <?php 
                                            $status = $row['cert_analysis'];
                                            $badgeClass = '';
                                            if (strtolower($status) == 'available') $badgeClass = 'badge-approved';
                                            elseif (strtolower($status) == 'not available') $badgeClass = 'badge-rejected';
                                            else $badgeClass = 'badge-pending';
                                            ?>
                                            <span class="grn-status-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </span>
                                        <?php if ($row['cert_analysis_remark']): ?>
                                            <span class="condition-remark"><?php echo htmlspecialchars($row['cert_analysis_remark']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No GRN Entries Found</h5>
                                        <p>No GRN entries match your search criteria.</p>
                                        <?php if (!empty($search_grn_no) || !empty($search_invoice) || !empty($search_supplier)): ?>
                                            <a href="GRNEntryLookup.php" class="btn btn-primary">
                                                <i class="fas fa-list me-2"></i>View All Entries
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pendingGateEntryModal">
                                                <i class="fas fa-plus me-2"></i>Add First GRN Entry
                                            </button>
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

<!-- Pending Gate Entry Modal -->
<div class="modal fade pending-modal" id="pendingGateEntryModal" tabindex="-1" aria-labelledby="pendingGateEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pendingGateEntryModalLabel">
                    <i class="fas fa-clipboard-list me-2"></i>Select Pending Gate Entry
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Gate Entry ID</th>
                                <th>Date</th>
                                <th>Invoice Number</th>
                                <th>Supplier</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch pending gate entries (not yet in grn_header) using SQLSRV
                            $pendingSQL = "
                                SELECT g.*, s.supplier_name 
                                FROM gate_entries g 
                                LEFT JOIN suppliers s ON g.supplier_id = s.id
                                WHERE g.id NOT IN (SELECT gate_entry_id FROM grn_header WHERE delete_id IS NULL OR delete_id = 0)
                                ORDER BY g.id DESC
                            ";
                            $pendingStmt = sqlsrv_query($conn, $pendingSQL);
                            $pendingEntries = [];
                            
                            if ($pendingStmt) {
                                while ($g = sqlsrv_fetch_array($pendingStmt, SQLSRV_FETCH_ASSOC)) {
                                    // Convert DateTime objects to strings
                                    if (isset($g['entry_date']) && $g['entry_date'] instanceof DateTime) {
                                        $g['entry_date'] = $g['entry_date']->format('Y-m-d');
                                    }
                                    $pendingEntries[] = $g;
                                }
                            }
                            
                            if (count($pendingEntries) > 0):
                                foreach($pendingEntries as $g): 
                            ?>
                            <tr>
                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['id']); ?></span></td>
                                <td><?php echo htmlspecialchars($g['entry_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($g['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($g['supplier_name']); ?></td>
                                <td class="text-center">
                                    <a href="GRNEntry.php?add=1&gate_entry_id=<?php echo $g['id']; ?>" class="btn select-btn">
                                        <i class="fas fa-check me-1"></i>Select
                                    </a>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                                        <h6>No Pending Gate Entries</h6>
                                        <p class="mb-0">All gate entries have been processed.</p>
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
    function confirmDelete(grnNumber) {
        return confirm(`⚠️ Are you sure you want to delete GRN entry "${grnNumber}"?\n\nThis action cannot be undone.`);
    }

    // Search form enhancement
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        const grnNo = document.querySelector('input[name="search_grn_no"]').value.trim();
        const invoice = document.querySelector('input[name="search_invoice"]').value.trim();
        const supplier = document.querySelector('input[name="search_supplier"]').value.trim();
        
        if (!grnNo && !invoice && !supplier) {
            e.preventDefault();
            alert('Please enter at least one search criterion.');
            return false;
        }
    });

    // Modal enhancement
    document.getElementById('pendingGateEntryModal').addEventListener('shown.bs.modal', function () {
        this.focus();
    });

    // Direct Print Function - Opens new window and triggers print
    function printGRN(grnHeaderId) {
        // Open print page in new window
        const printWindow = window.open(
            'print_grn.php?grn_id=' + grnHeaderId, 
            'printGRN', 
            'width=800,height=600,scrollbars=yes,resizable=yes'
        );
        
        // Focus on the new window
        if (printWindow) {
            printWindow.focus();
        } else {
            alert('Please allow pop-ups for this site to enable printing.');
        }
    }
</script>
</body>
</html>