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
include '../Includes/db_connect.php'; // Must return $conn (SQLSRV connection)
include '../Includes/sidebar.php';

// Handle delete operation
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $delete_stmt = sqlsrv_prepare($conn, "DELETE FROM batch_creation WHERE id = ?", [$delete_id]);

    if (sqlsrv_execute($delete_stmt)) {
        echo "<script>alert('Batch entry deleted successfully!'); window.location.href='BatchCreationEntryLookup.php';</script>";
    } else {
        echo "<script>alert('Failed to delete batch entry.');</script>";
    }
}

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_clause = '';
$params = [];

if (!empty($search_term)) {
    $where_clause = " WHERE batch_number LIKE ? 
                      OR brand_name LIKE ? 
                      OR product_type LIKE ? 
                      OR strip_cutting LIKE ? 
                      OR created_by LIKE ?";
    $like_term = '%' . $search_term . '%';
    $params = array_fill(0, 5, $like_term);
}

// Get total records for pagination
$total_sql = "SELECT COUNT(*) as total FROM batch_creation $where_clause";
$total_stmt = sqlsrv_prepare($conn, $total_sql, $params);
sqlsrv_execute($total_stmt);
$total_row = sqlsrv_fetch_array($total_stmt, SQLSRV_FETCH_ASSOC);
$total_records = $total_row['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch paginated batch creation entries (OFFSET FETCH used for SQL Server)
$query = "SELECT * FROM batch_creation $where_clause ORDER BY id DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
$params[] = $offset;
$params[] = $records_per_page;

$stmt = sqlsrv_prepare($conn, $query, $params);
sqlsrv_execute($stmt);

// Collect result rows
$result = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $result[] = $row;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Creation Entry Lookup - AABHA MFG</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .main-container {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .main-container.sidebar-collapsed {
            margin-left: 80px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        .page-header h1 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .page-header .subtitle {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .search-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            margin: 0;
            font-weight: 600;
        }
        
        .btn-add-new {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-add-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .table-responsive {
            max-height: 600px;
            overflow-x: auto;
            overflow-y: auto;
        }
        
        .table {
            margin: 0;
            min-width: 1800px;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            font-size: 0.875rem;
            padding: 12px 8px;
        }
        
        .table td {
            vertical-align: middle;
            padding: 12px 8px;
            font-size: 0.875rem;
            white-space: nowrap;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            padding: 6px 10px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge.bg-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
        }
        
        .badge.bg-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%) !important;
        }
        
        .badge.bg-danger {
            background: linear-gradient(135deg, #dc3545 0%, #fd1d1d 100%) !important;
        }
        
        .badge.bg-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%) !important;
        }
        
        .btn-action {
            padding: 8px 12px;
            margin: 0 2px;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
        }
        
        .btn-action i {
            font-size: 14px;
            line-height: 1;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }
        
        .btn-edit:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.4);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
        }
        
        .btn-action.disabled,
        .btn-action:disabled {
            opacity: 0.6;
            transform: none !important;
            cursor: not-allowed;
        }
        
        .pagination-container {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .pagination .page-link {
            border: none;
            padding: 10px 15px;
            margin: 0 3px;
            border-radius: 8px;
            color: #667eea;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination .page-link:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        
        .search-input {
            border: 2px solid #e9ecef;
            border-radius: 25px;
            padding: 12px 20px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .no-data {
            text-align: center;
            padding: 60px;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #dee2e6;
        }
        
        .text-truncate {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .qty-badge {
            background: linear-gradient(135deg, #6f42c1 0%, #563d7c 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .main-container {
                margin-left: 0;
                padding: 15px;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .btn-add-new {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
                gap: 5px !important;
            }
            
            .btn-action {
                width: 100%;
                margin: 2px 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-clipboard-list me-3"></i>Batch Creation Lookup</h1>
            <p class="subtitle">Manage and view all batch creation entries</p>
        </div>
        
        <!-- Search Container -->
        <div class="search-container">
            <form method="GET" action="">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input 
                                type="text" 
                                name="search" 
                                class="form-control search-input border-start-0" 
                                placeholder="Search by batch number, brand name, product type, strip cutting, or creator..."
                                value="<?= htmlspecialchars($search_term) ?>"
                            >
                        </div>
                    </div>
                    <div class="col-md-4 d-flex">
                        <button type="submit" class="btn btn-primary search-btn w-100 me-2">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                        <?php if (!empty($search_term)): ?>
                            <a href="BatchCreationEntryLookup.php" class="btn btn-outline-danger w-100">
                                <i class="fas fa-times me-2"></i>Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Table Container -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-table me-2"></i>Batch Creation Entries (<?= $total_records ?> total)</h3>
                <a href="BatchCreationEntry.php" class="btn btn-success btn-add-new">
                    <i class="fas fa-plus me-2"></i>Add New Entry
                </a>
            </div>
            
            <div class="table-responsive">
                <?php if (!empty($result)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th class="text-center" style="min-width: 120px;">
                                    <i class="fas fa-cogs me-1"></i>Actions
                                </th>
                                <th><i class="fas fa-hashtag me-1"></i>ID</th>
                                <th><i class="fas fa-barcode me-1"></i>Batch Number</th>
                                <th><i class="fas fa-tag me-1"></i>Brand Name</th>
                                <th><i class="fas fa-calendar-alt me-1"></i>MFG Date</th>
                                <th><i class="fas fa-calendar-times me-1"></i>EXP Date</th>
                                <th><i class="fas fa-cube me-1"></i>Product Type</th>
                                <th><i class="fas fa-cut me-1"></i>Strip Cutting</th>
                                <th><i class="fas fa-tint me-1"></i>Silicone Oil</th>
                                <th><i class="fas fa-pills me-1"></i>Benzocaine</th>
                                <th><i class="fas fa-flask me-1"></i>Benzocaine Qty</th>
                                <th><i class="fas fa-shopping-cart me-1"></i>Order Qty</th>
                                <th><i class="fas fa-sticky-note me-1"></i>Special Req</th>
                                <th><i class="fas fa-info-circle me-1"></i>Status</th>
                                <th><i class="fas fa-user me-1"></i>Created By</th>
                                <th><i class="fas fa-clock me-1"></i>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <div class="action-buttons d-flex justify-content-center gap-1">
                                            <a href="BatchCreationEntry.php?edit_id=<?= $row['id'] ?>" 
                                               class="btn btn-edit btn-action" 
                                               title="Edit Entry"
                                               data-bs-toggle="tooltip">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?delete_id=<?= $row['id'] ?>" 
                                               class="btn btn-delete btn-action" 
                                               title="Delete Entry"
                                               data-bs-toggle="tooltip"
                                               onclick="return confirmDelete(this, '<?= htmlspecialchars($row['batch_number']) ?>')">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <td><strong><?= htmlspecialchars($row['id']) ?></strong></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($row['batch_number']) ?></span></td>
                                    <td><?= htmlspecialchars($row['brand_name']) ?></td>
                                    <td>
                                        <?php
                                        $mfg = $row['mfg_date'];
                                        if ($mfg instanceof DateTime) {
                                            echo $mfg->format('m/Y');
                                        } elseif (!empty($mfg)) {
                                            echo date('m/Y', strtotime($mfg));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $exp = $row['exp_date'];
                                        if ($exp instanceof DateTime) {
                                            echo $exp->format('m/Y');
                                        } elseif (!empty($exp)) {
                                            echo date('m/Y', strtotime($exp));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['product_type']) ?></td>
                                    <td><?= htmlspecialchars($row['strip_cutting'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if ($row['silicone_oil_qty']): ?>
                                            <span class="qty-badge"><?= number_format($row['silicone_oil_qty'], 3) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['benzocaine_used'] == 'Yes'): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['benzocaine_qty']): ?>
                                            <span class="qty-badge"><?= number_format($row['benzocaine_qty'], 3) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['order_qty']): ?>
                                            <span class="badge bg-info"><?= number_format($row['order_qty']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['special_requirement']): ?>
                                            <span class="text-truncate" title="<?= htmlspecialchars($row['special_requirement']) ?>">
                                                <?= htmlspecialchars($row['special_requirement']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status = $row['status'] ?? 'Pending';
                                        $badge_class = '';
                                        switch($status) {
                                            case 'Completed':
                                                $badge_class = 'bg-success';
                                                break;
                                            case 'In Progress':
                                                $badge_class = 'bg-info';
                                                break;
                                            case 'Pending':
                                                $badge_class = 'bg-warning';
                                                break;
                                            case 'Cancelled':
                                                $badge_class = 'bg-danger';
                                                break;
                                            default:
                                                $badge_class = 'bg-secondary';
                                        }
                                        ?>
                                        <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($status) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['created_by'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php 
                                        $created_at = $row['created_at'];
                                        if ($created_at instanceof DateTime) {
                                            echo $created_at->format('d/m/Y H:i');
                                        } else {
                                            echo date('d/m/Y H:i', strtotime($created_at));
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <h4>No batch entries found</h4>
                        <p>
                            <?php if (!empty($search_term)): ?>
                                No batch creation entries match your search criteria for "<?= htmlspecialchars($search_term) ?>".
                            <?php else: ?>
                                No batch creation entries found.
                            <?php endif; ?>
                        </p>
                        <a href="BatchCreationEntry.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Create First Entry
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?><?= !empty($search_term) ? '&search=' . urlencode($search_term) : '' ?>">
                                <i class="fas fa-chevron-left me-1"></i>Previous
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?><?= !empty($search_term) ? '&search=' . urlencode($search_term) : '' ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?><?= !empty($search_term) ? '&search=' . urlencode($search_term) : '' ?>">
                                Next<i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                    </small>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Handle sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const mainContainer = document.querySelector('.main-container');
    
    function adjustMainContent() {
        if (sidebar && sidebar.classList.contains('hide')) {
            mainContainer.classList.add('sidebar-collapsed');
        } else {
            mainContainer.classList.remove('sidebar-collapsed');
        }
    }
    
    adjustMainContent();
    window.addEventListener('resize', adjustMainContent);
    
    // Watch for sidebar changes
    const observer = new MutationObserver(adjustMainContent);
    if (sidebar) {
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }
});

// Enhanced delete confirmation
function confirmDelete(element, batchNumber) {
    const result = confirm(`Are you sure you want to delete batch "${batchNumber}"?\n\nThis action cannot be undone.`);
    
    if (result) {
        // Show loading state
        element.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        element.classList.add('disabled');
        
        // Add loading text for better UX
        setTimeout(() => {
            element.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
        }, 500);
        
        return true;
    }
    
    return false;
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);

// Search input focus effect
const searchInput = document.querySelector('.search-input');
if (searchInput) {
    searchInput.addEventListener('focus', function() {
        this.closest('.input-group').classList.add('focused');
    });
    
    searchInput.addEventListener('blur', function() {
        this.closest('.input-group').classList.remove('focused');
    });
}

// Enhanced action button hover effects
document.querySelectorAll('.btn-action').forEach(button => {
    button.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-2px) scale(1.05)';
    });
    
    button.addEventListener('mouseleave', function() {
        if (!this.classList.contains('disabled')) {
            this.style.transform = 'translateY(0) scale(1)';
        }
    });
});

// Keyboard shortcuts for actions
document.addEventListener('keydown', function(e) {
    // Ctrl + N for new entry
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        window.location.href = 'BatchCreationEntry.php';
    }
    
    // Ctrl + F for search focus
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.querySelector('.search-input').focus();
    }
});
</script>
</body>
</html>