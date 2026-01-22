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


// AJAX handler for fetching sealing entry data
if (isset($_GET['action']) && $_GET['action'] === 'get_entry' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    include '../Includes/db_connect.php';

    $id = intval($_GET['id']);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid entry ID']);
        exit;
    }

    try {
        // Fetch entry with brand name from batch_creation table
        $sql = "SELECT se.*, bc.brand_name 
                FROM sealing_entry se 
                LEFT JOIN batch_creation bc ON se.batch_no = bc.batch_number 
                WHERE se.id = ?";
        $params = [$id];
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            throw new Exception(print_r(sqlsrv_errors(), true));
        }

        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo json_encode([
                'success' => true,
                'entry' => $row
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Entry not found'
            ]);
        }

        sqlsrv_free_stmt($stmt);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }

    sqlsrv_close($conn);
    exit;
}

;
include '../Includes/sidebar.php';

$success = '';
$error = '';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM sealing_entry WHERE id = ?";
    $params = [$id];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        $success = "Entry deleted successfully!";
        sqlsrv_free_stmt($stmt);
    } else {
        $errors = sqlsrv_errors();
        $error = "Delete failed: " . ($errors[0]['message'] ?? 'Unknown error');
    }
}

// Handle success message from form submission
if (isset($_GET['success'])) {
    $success = "Entry added successfully!";
}

// Build filter conditions
$where = [];
$params = [];

if (!empty($_GET['filter_batch_no'])) {
    $where[] = "batch_no LIKE ?";
    $params[] = '%' . $_GET['filter_batch_no'] . '%';
}
if (!empty($_GET['filter_date'])) {
    $where[] = "CONVERT(date, date) = ?";
    $params[] = $_GET['filter_date'];
}

$sql = "SELECT * FROM sealing_entry";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY id DESC";

$result = sqlsrv_query($conn, $sql, $params);

// Count total records
$totalEntries = 0;
$entries = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $entries[] = $row;
        $totalEntries++;
    }
    sqlsrv_free_stmt($result);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sealing Entry Lookup - AABHA MFG</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    
    <!-- Include main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <!-- Page-specific CSS enhancements -->
    <style>
        /* Override and enhance existing styles */
        .main-container {
            margin-left: 250px;
            transition: margin-left 0.3s ease;
            padding: 20px;
        }
        
        .main-container.sidebar-collapsed {
            margin-left: 80px;
        }
        
        .content-wrapper {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin: 20px 0;
            backdrop-filter: blur(10px);
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
        
        .add-new-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .add-new-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .table-container {
            background: #ffffff;
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
        
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .table {
            margin: 0;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 0.85rem;
            white-space: nowrap;
            padding: 12px 8px;
        }
        
        .table td {
            vertical-align: middle;
            padding: 12px 8px;
            font-size: 0.85rem;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Actions column styling */
        .actions-column {
            min-width: 140px;
            position: sticky;
            left: 0;
            background: white;
            z-index: 20;
            border-right: 2px solid #e9ecef;
        }
        
        .actions-column-header {
            position: sticky;
            left: 0;
            background: #f8f9fa;
            z-index: 21;
            border-right: 2px solid #dee2e6;
        }
        
        .table-striped > tbody > tr:nth-of-type(odd) > td.actions-column {
            background-color: #f8f9fa;
        }
        
        .table tbody tr:hover .actions-column {
            background-color: #e3f2fd !important;
        }
        
        .btn-action {
            padding: 6px 10px;
            margin: 0 2px;
            border-radius: 6px;
            font-size: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
            color: white;
        }
        
        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }
        
        .btn-print {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            color: white;
        }
        
        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .badge {
            padding: 6px 12px;
            font-size: 0.75rem;
            font-weight: 500;
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
        
        @media (max-width: 768px) {
            .main-container {
                margin-left: 0;
                padding: 15px;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .content-wrapper {
                padding: 20px;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .add-new-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="content-wrapper">
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-list-alt me-3"></i>Sealing Entry Lookup</h1>
                <p class="subtitle">Manage and view all sealing entries</p>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="get" class="row g-3 mb-4 align-items-end">
                <div class="col-md-3">
                    <label for="filter_batch_no" class="form-label mb-1">Batch No.</label>
                    <input type="text" name="filter_batch_no" id="filter_batch_no" class="form-control"
                           value="<?= htmlspecialchars($_GET['filter_batch_no'] ?? '') ?>" placeholder="Enter batch number">
                </div>
                <div class="col-md-3">
                    <label for="filter_date" class="form-label mb-1">Date</label>
                    <input type="date" name="filter_date" id="filter_date" class="form-control"
                           value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <?php if (!empty($_GET['filter_batch_no']) || !empty($_GET['filter_date'])): ?>
                        <a href="sealing_lookup.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Table Container -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-table me-2"></i>Sealing Entries</h3>
                    <a href="sealing.php" class="add-new-btn">
                        <i class="fas fa-plus me-2"></i>Add New Entry
                    </a>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th class="actions-column-header"><i class="fas fa-tools me-1"></i>Actions</th>
                                <th><i class="fas fa-hashtag me-1"></i>Sr No.</th>
                                <th><i class="fas fa-calendar me-1"></i>Month</th>
                                <th><i class="fas fa-calendar-day me-1"></i>Date</th>
                                <th><i class="fas fa-clock me-1"></i>Shift</th>
                                <th><i class="fas fa-box me-1"></i>Bag No.</th>
                                <th><i class="fas fa-barcode me-1"></i>Batch No.</th>
                                <th><i class="fas fa-cogs me-1"></i>Machine No.</th>
                                <th><i class="fas fa-tag me-1"></i>Lot No.</th>
                                <th><i class="fas fa-archive me-1"></i>Bin No.</th>
                                <th><i class="fas fa-cookie-bite me-1"></i>Flavour</th>
                                <th><i class="fas fa-weight me-1"></i>Seal KG</th>
                                <th><i class="fas fa-balance-scale me-1"></i>Avg. WT</th>
                                <th><i class="fas fa-times-circle me-1"></i>Foil Rej. KG</th>
                                <th><i class="fas fa-exclamation-triangle me-1"></i>Product Rej.</th>
                                <th><i class="fas fa-weight-hanging me-1"></i>Rej. Avg. WT</th>
                                <th><i class="fas fa-calculator me-1"></i>Rej. Gross</th>
                                <th><i class="fas fa-calculator me-1"></i>Seal Gross</th>
                                <th><i class="fas fa-user-tie me-1"></i>Supervisor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($totalEntries > 0): ?>
                                <?php $i = 1; ?>
                                <?php foreach($entries as $row): ?>
                                    <tr>
                                        <td class="actions-column">
                                            <div class="btn-group" role="group">
                                                <a href="sealing.php?edit=<?= $row['id'] ?>" 
                                                   class="btn btn-sm btn-action btn-edit" 
                                                   title="Edit Entry">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-action btn-print" 
                                                        title="Print Label"
                                                        onclick="printSealingLabel(<?= $row['id'] ?>)">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                                <a href="?delete=<?= $row['id'] ?>" 
                                                   class="btn btn-sm btn-action btn-delete" 
                                                   onclick="return confirm('Are you sure you want to delete this entry? This action cannot be undone.')" 
                                                   title="Delete Entry">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                        <td><strong><?= $i++ ?></strong></td>
                                        <td><?= htmlspecialchars($row['month']) ?></td>
                                        <td>
                                            <?php
                                                if (!empty($row['date']) && $row['date'] !== '0000-00-00') {
                                                    // Handle SQL Server datetime format
                                                    if ($row['date'] instanceof DateTime) {
                                                        echo '<span class="badge bg-light text-dark">' . $row['date']->format('d-m-Y') . '</span>';
                                                    } else {
                                                        echo '<span class="badge bg-light text-dark">' . date('d-m-Y', strtotime($row['date'])) . '</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                            ?>
                                        </td>
                                        <td><span class="badge bg-info"><?= htmlspecialchars($row['shift']) ?></span></td>
                                        <td><?= htmlspecialchars($row['bag_no']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['batch_no']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['machine_no']) ?></td>
                                        <td><?= htmlspecialchars($row['lot_no']) ?></td>
                                        <td><?= htmlspecialchars($row['bin_no']) ?></td>
                                        <td><?= htmlspecialchars($row['flavour']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['seal_kg']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['avg_wt']) ?></td>
                                        <td class="text-danger"><strong><?= htmlspecialchars($row['foil_rej_kg']) ?></strong></td>
                                        <td class="text-warning"><strong><?= htmlspecialchars($row['product_rej']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['rej_avg_wt']) ?></td>
                                        <td><?= htmlspecialchars($row['rej_gross']) ?></td>
                                        <td><?= htmlspecialchars($row['seal_gross']) ?></td>
                                        <td><?= htmlspecialchars($row['supervisor']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="19" class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        <h4>No sealing entries found</h4>
                                        <p>No sealing entries available. Click "Add New Entry" to create your first record.</p>
                                        <a href="sealing.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Create First Entry
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Entry Count -->
            <?php if ($totalEntries > 0): ?>
                <div class="mt-3 text-end">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Showing <?= $totalEntries ?> entr<?= $totalEntries === 1 ? 'y' : 'ies' ?>
                    </small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Enhanced JavaScript -->
    <script>
        // Handle sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
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

        // Add loading animation when deleting
        document.querySelectorAll('a[href*="delete"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (confirm('Are you sure you want to delete this entry? This action cannot be undone.')) {
                    // Show loading state
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    this.classList.add('disabled');
                    return true;
                } else {
                    e.preventDefault();
                    return false;
                }
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Print sealing label function
        function printSealingLabel(entryId) {
            // Show loading state
            const printBtn = event.target.closest('button');
            const originalContent = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            printBtn.disabled = true;

            try {
                // Open print page in new window
                const printUrl = 'print_sealing_label.php?id=' + entryId + '&autoprint=1&autoclose=1';
                const printWindow = window.open(printUrl, '_blank', 'width=600,height=800,scrollbars=yes,resizable=yes');
                
                if (!printWindow) {
                    alert('Pop-up blocked! Please allow pop-ups for this site and try again.');
                    return;
                }

                // Focus the print window
                printWindow.focus();
                
            } catch (error) {
                console.error('Error opening print window:', error);
                alert('Error opening print window: ' + error.message);
            } finally {
                // Restore button state
                setTimeout(() => {
                    printBtn.innerHTML = originalContent;
                    printBtn.disabled = false;
                }, 1000);
            }
        }

        // Initialize tooltips
        document.querySelectorAll('[title]').forEach(element => {
            element.setAttribute('data-bs-toggle', 'tooltip');
        });

        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }
    </script>
</body>
</html>