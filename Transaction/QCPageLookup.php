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

// Handle delete
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $stmt = sqlsrv_prepare($conn, "UPDATE grn_header SET delete_id = 1 WHERE grn_header_id = ?", [$deleteId]);
    sqlsrv_execute($stmt);
    $_SESSION['message'] = "QC entry deleted successfully!";
    header("Location: QCPageLookup.php");
    exit;
}

// Handle messages
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "QC entry updated successfully!";
}
if (isset($_GET['added']) && $_GET['added'] == 1) {
    $_SESSION['message'] = "QC entry added successfully!";
}

// Filters
$search_grn_no = $_GET['search_grn_no'] ?? '';
$search_invoice = $_GET['search_invoice'] ?? '';
$search_supplier = $_GET['search_supplier'] ?? '';

$whereClauses = ["(gh.delete_id IS NULL OR gh.delete_id = 0)"];
$params = [];

if (!empty($search_grn_no)) {
    $whereClauses[] = "gh.grn_no LIKE ?";
    $params[] = "%$search_grn_no%";
}
if (!empty($search_invoice)) {
    $whereClauses[] = "g.invoice_number LIKE ?";
    $params[] = "%$search_invoice%";
}
if (!empty($search_supplier)) {
    $whereClauses[] = "sup.supplier_name LIKE ?";
    $params[] = "%$search_supplier%";
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

// Main QC Entries Query
$sql = "
    SELECT 
        gh.grn_header_id,
        g.id AS gate_entry_id,
        g.entry_date,
        g.entry_time,
        g.invoice_number,
        g.vehicle_number,
        g.supplier_id,
        sup.supplier_name,
        gh.grn_no,
        gh.po_no,
        gh.grn_date,
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
    $whereSql
    ORDER BY gh.grn_header_id DESC
";
$stmt = sqlsrv_prepare($conn, $sql, $params);
sqlsrv_execute($stmt);
$entries = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $entries[] = $row;
}

// Get total QC entries
$totalQC = 0;
$res = sqlsrv_query($conn, "SELECT COUNT(*) AS count FROM grn_header WHERE (delete_id IS NULL OR delete_id = 0)");
if ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $totalQC = $row['count'];
}

// Get pending QC
$pendingQC = 0;
$pendingQuery = "
    SELECT COUNT(*) AS count 
    FROM grn_header gh 
    WHERE (gh.delete_id IS NULL OR gh.delete_id = 0) 
    AND gh.grn_header_id NOT IN (
        SELECT DISTINCT gqd.grn_header_id 
        FROM grn_quantity_details gqd 
        LEFT JOIN qc_quantity_details qd ON qd.grn_quantity_id = gqd.quantity_id 
        WHERE qd.material_status IS NOT NULL
    )
";
$res = sqlsrv_query($conn, $pendingQuery);
if ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $pendingQC = $row['count'];
}

$completedQC = $totalQC - $pendingQC;

include '../Includes/sidebar.php';
?>


<!DOCTYPE html>
<html>
<head>
    <title>Quality Control Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to QC lookup page */
        .qc-status-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin: 2px;
        }
        
        .badge-pending {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        
        .badge-accept {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-hold {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .badge-reject {
            background: linear-gradient(45deg, #6c757d, #495057);
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
        
        .vehicle-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .qc-action-btn {
            background: linear-gradient(45deg, #17a2b8, #138496);
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .qc-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }
        
        .status-column {
            min-width: 120px;
        }
        
        .multi-status {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-clipboard-check"></i>
            Quality Control Lookup
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
                <div class="stats-number"><?php echo $totalQC; ?></div>
                <div class="stats-label">Total QC Entries</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $pendingQC; ?></div>
                <div class="stats-label">Pending QC</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $completedQC; ?></div>
                <div class="stats-label">Completed QC</div>
            </div>
        </div>
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
                <a href="QCPageLookup.php" class="btn btn-outline-danger">
                    <i class="fas fa-times me-1"></i>Clear All
                </a>
            </div>
        </form>
    </div>

    <!-- QC Entry List -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i>Quality Control Entry List
        <?php if (!empty($search_grn_no) || !empty($search_invoice) || !empty($search_supplier)): ?>
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
                            <th class="status-column">QC Status</th>
                            <th class="text-center" style="width: 100px;">Actions</th>
                            <th style="width: 80px;">Sr. No.</th>
                            <th>Entry Date</th>
                            <th>GRN Number</th>
                            <th>Invoice Number</th>
                            <th>Supplier</th>
                            <th>PO Number</th>
                            <th>GRN Date</th>
                            <th>Vehicle Number</th>
                            <th>Damage Condition</th>
                            <th>Labeling</th>
                            <th>Packing</th>
                            <th>Certificate Analysis</th>
                        </tr>
                    </thead>
                    <tbody>
<?php if (count($entries) > 0): ?>
    <?php $sr = 1; foreach ($entries as $row): ?>
        <?php
        // Check for QC status for this GRN
        $grnHeaderId = $row['grn_header_id'];
        $qcStatus = 'Pending';
        
        $qcRes = sqlsrv_query($conn, "
            SELECT qd.material_status
            FROM grn_quantity_details gqd
            LEFT JOIN qc_quantity_details qd ON qd.grn_quantity_id = gqd.quantity_id
            WHERE gqd.grn_header_id = $grnHeaderId
            AND qd.qc_quantity_id = (
                SELECT MAX(qc_quantity_id) FROM qc_quantity_details WHERE grn_quantity_id = gqd.quantity_id
            )
        ");
        
        $statuses = [];
        while ($qcRow = sqlsrv_fetch_array($qcRes, SQLSRV_FETCH_ASSOC)) {
            if (!empty($qcRow['material_status'])) {
                $statuses[] = $qcRow['material_status'];
            }
        }
        
        if (count($statuses) > 0) {
            $qcStatus = implode(', ', array_unique($statuses));
        }
        ?>
        <tr>
            <td class="status-column">
                <div class="multi-status">
                    <?php
                    $statusArr = array_map('trim', explode(',', $qcStatus));
                    foreach ($statusArr as $status) {
                        $badgeClass = 'badge-pending';
                        if (strcasecmp($status, 'Accept') === 0) $badgeClass = 'badge-accept';
                        elseif (strcasecmp($status, 'Hold') === 0) $badgeClass = 'badge-hold';
                        elseif (strcasecmp($status, 'Reject') === 0) $badgeClass = 'badge-reject';
                        
                        echo '<span class="qc-status-badge ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>';
                    }
                    ?>
                </div>
            </td>
            <td class="text-center">
                <div class="action-buttons d-flex justify-content-center">
                    <a href="QualityCheck.php?edit=1&grn_header_id=<?php echo $row['grn_header_id']; ?>&gate_entry_id=<?php echo $row['gate_entry_id']; ?>" class="qc-action-btn" title="Quality Check">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </td>
            <td><strong><?php echo $sr++; ?></strong></td>
            <td>
    <?php
    $entryDate = $row['entry_date'] ?? '';
    if ($entryDate instanceof DateTime) {
        echo htmlspecialchars($entryDate->format('Y-m-d'));
    } else {
        echo htmlspecialchars($entryDate);
    }
    ?>
</td>
<td>
    <span class="grn-number-badge">
        <?php echo htmlspecialchars($row['grn_no']); ?>
    </span>
</td>
<td><strong><?php echo htmlspecialchars($row['invoice_number']); ?></strong></td>
<td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
<td><?php echo htmlspecialchars($row['po_no']); ?></td>
<td>
    <?php
    $grnDate = $row['grn_date'] ?? '';
    if ($grnDate instanceof DateTime) {
        echo htmlspecialchars($grnDate->format('Y-m-d'));
    } else {
        echo htmlspecialchars($grnDate);
    }
    ?>
</td>
<td>
                <?php if ($row['vehicle_number']): ?>
                    <span class="vehicle-number"><?php echo htmlspecialchars($row['vehicle_number']); ?></span>
                <?php else: ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="condition-status">
                    <span class="condition-label"><?php echo htmlspecialchars($row['tear_damage_leak']); ?></span>
                    <?php if ($row['damage_remark']): ?>
                        <span class="condition-remark"><?php echo htmlspecialchars($row['damage_remark']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="condition-status">
                    <span class="condition-label"><?php echo htmlspecialchars($row['labeling']); ?></span>
                    <?php if ($row['labeling_remark']): ?>
                        <span class="condition-remark"><?php echo htmlspecialchars($row['labeling_remark']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="condition-status">
                    <span class="condition-label"><?php echo htmlspecialchars($row['packing']); ?></span>
                    <?php if ($row['packing_remark']): ?>
                        <span class="condition-remark"><?php echo htmlspecialchars($row['packing_remark']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="condition-status">
                    <span class="condition-label"><?php echo htmlspecialchars($row['cert_analysis']); ?></span>
                    <?php if ($row['cert_analysis_remark']): ?>
                        <span class="condition-remark"><?php echo htmlspecialchars($row['cert_analysis_remark']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="14" class="text-center py-4">
            <div class="text-muted">
                <i class="fas fa-clipboard-check fa-3x mb-3"></i>
                <h5>No QC Entries Found</h5>
                <p>No quality control entries match your search criteria.</p>
                <?php if (!empty($search_grn_no) || !empty($search_invoice) || !empty($search_supplier)): ?>
                    <a href="QCPageLookup.php" class="btn btn-primary">
                        <i class="fas fa-list me-2"></i>View All Entries
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

    // Click on pending QC stat to filter pending entries
    document.querySelector('.stats-card:nth-child(2)').addEventListener('click', function() {
        // This could be enhanced to filter by pending status
        window.location.href = 'QCPageLookup.php';
    });
</script>

</body>
</html>


