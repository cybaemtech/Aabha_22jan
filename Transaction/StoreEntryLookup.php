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

// --- FIX: Use SQLSRV instead of MySQLi for all queries ---

if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $deleteStmt = sqlsrv_query($conn, "UPDATE store_entry_materials SET delete_id = 1 WHERE store_entry_id = ?", array($delete_id));
    $_SESSION['message'] = "Store entry deleted successfully!";
    header("Location: StoreEntryLookup.php");
    exit;
}

// Search logic
$search_gate = isset($_GET['search_gate']) ? trim($_GET['search_gate']) : '';
$search_store = isset($_GET['search_store']) ? trim($_GET['search_store']) : '';

$whereArr = ["sem.delete_id = 0"];
$params = [];

if ($search_gate !== '') {
    $whereArr[] = "sem.gate_entry_id LIKE ?";
    $params[] = "%$search_gate%";
}
if ($search_store !== '') {
    $whereArr[] = "sem.store_entry_id LIKE ?";
    $params[] = "%$search_store%";
}
$where = "WHERE " . implode(" AND ", $whereArr);

$query = "
SELECT 
    sem.*,
    g.invoice_number,
    g.entry_date
FROM store_entry_materials sem
LEFT JOIN gate_entries g ON sem.gate_entry_id = g.id
$where
ORDER BY sem.id DESC
";
$entriesStmt = sqlsrv_query($conn, $query, $params);

// Get statistics
$totalEntriesStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM store_entry_materials WHERE delete_id = 0");
$totalEntries = sqlsrv_fetch_array($totalEntriesStmt, SQLSRV_FETCH_ASSOC)['count'];

$pendingGateEntriesStmt = sqlsrv_query($conn, "
    SELECT COUNT(*) as count 
    FROM gate_entries g 
    WHERE g.id NOT IN (SELECT DISTINCT gate_entry_id FROM store_entry_materials WHERE delete_id = 0)
");
$pendingGateEntriesCount = sqlsrv_fetch_array($pendingGateEntriesStmt, SQLSRV_FETCH_ASSOC)['count'];

$totalMaterialsStmt = sqlsrv_query($conn, "SELECT COUNT(DISTINCT material_id) as count FROM store_entry_materials WHERE delete_id = 0");
$totalMaterials = sqlsrv_fetch_array($totalMaterialsStmt, SQLSRV_FETCH_ASSOC)['count'];

include '../Includes/sidebar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Store Entry Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to store entry lookup page */
        .batch-number-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .material-type-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-raw {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
        }
        
        .badge-packing {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .badge-misc {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }
        
        .quantity-cell {
            font-weight: 600;
            color: #667eea;
        }
        
        .accepted-qty {
            color: #28a745;
            font-weight: 600;
        }
        
        .rejected-qty {
            color: #dc3545;
            font-weight: 600;
        }
        
        .remark-text {
            font-style: italic;
            color: #6c757d;
            font-size: 0.9rem;
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
        
        .pending-entries-table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .pending-entries-table thead th {
            background: #f8f9fa;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
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
        
        .search-form {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .search-input-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-input-container .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .search-input-container .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Make sure the close icon is always visible */
        .btn-close,
        .btn-close-white {
            filter: invert(1) brightness(2);
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-warehouse"></i>
            Store Entry Lookup
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
                <div class="stats-label">Total Store Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $pendingGateEntriesCount; ?></div>
                <div class="stats-label">Pending Gate Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalMaterials; ?></div>
                <div class="stats-label">Unique Materials</div>
            </div>
        </div>
    </div>

    <!-- Add New Button -->
    <div class="mb-4">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pendingGateEntryModal">
            <i class="fas fa-plus me-2"></i>Add New Store Entry
        </button>
    </div>

    <!-- Search Form -->
    <div class="search-form">
        <form method="get" id="searchForm">
            <div class="search-input-container">
                <div class="flex-grow-1">
                    <input type="text" class="form-control" name="search_gate" placeholder="Search by Gate Entry ID" value="<?= htmlspecialchars($_GET['search_gate'] ?? '') ?>">
                </div>
                <div class="flex-grow-1">
                    <input type="text" class="form-control" name="search_store" placeholder="Search by Store Entry ID" value="<?= htmlspecialchars($_GET['search_store'] ?? '') ?>">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <?php if (!empty($_GET['search_gate']) || !empty($_GET['search_store'])): ?>
                    <a href="StoreEntryLookup.php" class="btn btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Store Entry List -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i>Store Entry List
            <?php if (!empty($search_gate) || !empty($search_store)): ?>
                <span class="badge bg-light text-dark ms-2">
                    Search Results: <?php echo ($entriesStmt) ? sqlsrv_num_rows($entriesStmt) : 0; ?> entries found
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
                            <th>Gate Entry ID</th>
                            <th>Store Entry ID</th>
                            <th>Batch Number</th>
                            <th>Material ID</th>
                            <th>Material Description</th>
                            <th>Unit</th>
                            <th>Quantity</th>
                            <th>Package Received</th>
                            <th>Material Type</th>
                            <th>Accepted Qty</th>
                            <th>Rejected Qty</th>
                            <th>Remark</th>
                            <th>Invoice Number</th>
                            <th>Entry Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($entriesStmt && sqlsrv_has_rows($entriesStmt)): ?>
                            <?php $sr = 1; while($row = sqlsrv_fetch_array($entriesStmt, SQLSRV_FETCH_ASSOC)): ?>
                            <tr>
                                <td class="text-center">
                                    <div class="action-buttons d-flex justify-content-center">
                                        <a href="StoreEntry.php?edit=1&store_entry_id=<?= htmlspecialchars($row['store_entry_id']); ?>&gate_entry_id=<?= htmlspecialchars($row['gate_entry_id']); ?>" class="text-primary" title="Edit Store Entry">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="StoreEntryLookup.php?delete_id=<?= htmlspecialchars($row['store_entry_id']); ?>" class="text-danger" title="Delete Store Entry" onclick="return confirm('Are you sure you want to delete this store entry?')">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                                <td><strong><?= $sr++; ?></strong></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($row['gate_entry_id']); ?></span></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($row['store_entry_id']); ?></span></td>
                                <td>
                                    <?php if ($row['batch_number']): ?>
                                        <span class="batch-number-badge"><?= htmlspecialchars($row['batch_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($row['material_id']); ?></strong></td>
                                <td><?= htmlspecialchars($row['material_description']); ?></td>
                                <td><?= htmlspecialchars($row['unit']); ?></td>
                                <td class="quantity-cell"><?= htmlspecialchars($row['quantity']); ?></td>
                                <td><?= htmlspecialchars($row['package_received']); ?></td>
                                <td>
                                    <?php 
                                    $type = $row['material_type'];
                                    $badgeClass = 'badge-misc';
                                    if (strpos(strtolower($type), 'raw') !== false) $badgeClass = 'badge-raw';
                                    elseif (strpos(strtolower($type), 'packing') !== false) $badgeClass = 'badge-packing';
                                    ?>
                                    <span class="material-type-badge <?php echo $badgeClass; ?>">
                                        <?= htmlspecialchars($type); ?>
                                    </span>
                                </td>
                                <td class="accepted-qty"><?= htmlspecialchars($row['accepted_quantity']); ?></td>
                                <td class="rejected-qty"><?= htmlspecialchars($row['rejected_quantity']); ?></td>
                                <td>
                                    <?php if ($row['remark']): ?>
                                        <span class="remark-text"><?= htmlspecialchars($row['remark']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['invoice_number']); ?></td>
                                <td>
                                    <?php
                                    $entryDate = $row['entry_date'];
                                    if ($entryDate instanceof DateTime) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    } elseif (is_object($entryDate) && method_exists($entryDate, 'format')) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    }
                                    echo htmlspecialchars((string)$entryDate);
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="16" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No Store Entries Found</h5>
                                        <p>No store entries match your search criteria.</p>
                                        <?php if (!empty($search_gate) || !empty($search_store)): ?>
                                            <a href="StoreEntryLookup.php" class="btn btn-primary">
                                                <i class="fas fa-list me-2"></i>View All Entries
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pendingGateEntryModal">
                                                <i class="fas fa-plus me-2"></i>Add First Store Entry
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
                <!-- FIX: Show close icon (always visible, styled for contrast) -->
                <button type="button" class="btn" data-bs-dismiss="modal" aria-label="Close" style="background: none; border: none; font-size: 1.5rem; color: #fff;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table pending-entries-table table-hover">
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
                            // Fetch pending gate entries (not yet in store_entries)
                            $pendingGateEntriesStmt = sqlsrv_query($conn, "
                            SELECT g.*, s.supplier_name 
                            FROM gate_entries g 
                            LEFT JOIN suppliers s ON g.supplier_id = s.id
                            WHERE g.id NOT IN (SELECT DISTINCT gate_entry_id FROM store_entry_materials WHERE delete_id = 0)
                            ORDER BY g.id DESC
                            ");
                            if ($pendingGateEntriesStmt && sqlsrv_has_rows($pendingGateEntriesStmt)):
                                while($g = sqlsrv_fetch_array($pendingGateEntriesStmt, SQLSRV_FETCH_ASSOC)):
                            ?>
                            <tr>
                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['id']); ?></span></td>
                                <td>
                                    <?php
                                    $entryDate = $g['entry_date'];
                                    if ($entryDate instanceof DateTime) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    } elseif (is_object($entryDate) && method_exists($entryDate, 'format')) {
                                        $entryDate = $entryDate->format('Y-m-d');
                                    }
                                    echo htmlspecialchars((string)$entryDate);
                                    ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($g['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($g['supplier_name']); ?></td>
                                <td class="text-center">
                                    <a href="StoreEntry.php?add=1&gate_entry_id=<?php echo htmlspecialchars($g['id']); ?>" class="btn select-btn">
                                        <i class="fas fa-check me-1"></i>Select
                                    </a>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
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
    function confirmDelete(entryId) {
        return confirm(`⚠️ Are you sure you want to delete store entry "${entryId}"?\n\nThis action cannot be undone.`);
    }

    // Search form enhancement
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        const gateSearch = document.querySelector('input[name="search_gate"]').value.trim();
        const storeSearch = document.querySelector('input[name="search_store"]').value.trim();
        
        if (!gateSearch && !storeSearch) {
            e.preventDefault();
            alert('Please enter at least one search term.');
            return false;
        }
    });

    // Modal enhancement
    document.getElementById('pendingGateEntryModal').addEventListener('shown.bs.modal', function () {
        // Focus on the modal when shown
        this.focus();
    });
</script>

</body>
</html>

