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
include '../Includes/sidebar.php';

$conditions = [];
$params = [];

// Filter: material_request_id
if (!empty($_GET['material_request_id'])) {
    $material_request_id = $_GET['material_request_id'];
    $conditions[] = "mri.material_request_id LIKE ?";
    $params[] = '%' . $material_request_id . '%';
}

// Filter: department
if (!empty($_GET['department'])) {
    $department = $_GET['department'];
    $conditions[] = "d.department_name LIKE ?";
    $params[] = '%' . $department . '%';
}

// Filter: request_by
if (!empty($_GET['request_by'])) {
    $request_by = $_GET['request_by'];
    $conditions[] = "mri.request_by LIKE ?";
    $params[] = '%' . $request_by . '%';
}

// Filter: request_date
if (!empty($_GET['request_date'])) {
    $request_date = $_GET['request_date'];
    $conditions[] = "CONVERT(date, mri.request_date) = ?";
    $params[] = $request_date;
}

// Build SQL for material_request_items
$sql = "SELECT DISTINCT 
            mri.material_request_id, 
            mri.department_id, 
            mri.request_date, 
            mri.request_by, 
            d.department_name,
            (SELECT MIN(issue_date) FROM material_issuer_items WHERE material_request_id = mri.material_request_id) as issued_date
        FROM material_request_items mri
        LEFT JOIN departments d ON mri.department_id = d.id";

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " GROUP BY mri.material_request_id, mri.department_id, mri.request_date, mri.request_by, d.department_name
          ORDER BY mri.material_request_id DESC";

// Execute the query
$result = sqlsrv_query($conn, $sql, $params);

// Check if result is valid
if ($result === false) {
    die('Query failed: ' . print_r(sqlsrv_errors(), true));
}

// Fetch the latest request_date for date input pre-fill
$request_date_value = '';
if (!empty($_GET['material_request_id'])) {
    $latestDateSql = "SELECT TOP 1 request_date FROM material_request_items WHERE material_request_id = ? ORDER BY id DESC";
    $latestStmt = sqlsrv_query($conn, $latestDateSql, [$_GET['material_request_id']]);
    if ($latestStmt && $row = sqlsrv_fetch_array($latestStmt, SQLSRV_FETCH_ASSOC)) {
        $request_date_value = $row['request_date'] instanceof DateTime ? $row['request_date']->format('Y-m-d') : $row['request_date'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Issue Note</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional form-specific styles to enhance your existing CSS */
        body {
        
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            transition: all 0.3s ease;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 40px;
        }

        .card.shadow-sm {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border: none;
            background: #ffffff;
            overflow: hidden;
        }

        .section-header {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            padding: 20px;
            text-align: center;
            background: linear-gradient(45deg, #667eea, #764ba2) !important;
            color: white !important;
            border-radius: 0;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .card-body {
            padding: 30px;
            background: #ffffff;
        }

        /* Enhanced Search Form */
        .search-form-container {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .search-controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
            margin-bottom: 0;
        }

        .form-group {
            flex: 1;
            min-width: 180px;
        }

        .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #ffffff;
            min-width: 180px;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: #ffffff;
            outline: none;
        }

        .form-control::placeholder {
            color: #6c757d;
            font-style: italic;
        }

        /* Enhanced Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            min-width: 120px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #5a6fd8, #6a4190);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(45deg, #545b62, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
            color: white;
        }

        .btn-success {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(45deg, #218838, #1eac87);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
            color: white;
        }

        /* Enhanced Table */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .table {
            margin-bottom: 0;
            border: none;
        }

    
        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .table tbody tr:hover {
            background: linear-gradient(45deg, #e3f2fd, #f3e5f5);
            transition: background 0.3s ease;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Action Icon */
        .action-icon {
            color: #667eea;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            padding: 8px;
            border-radius: 50%;
            background: rgba(102, 126, 234, 0.1);
        }

        .action-icon:hover {
            color: #ffffff;
            background: linear-gradient(45deg, #667eea, #764ba2);
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .search-controls {
                flex-direction: column;
                gap: 10px;
            }

            .form-group {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                margin-bottom: 10px;
            }

            .card-body {
                padding: 20px;
            }

            .table-responsive {
                font-size: 0.85rem;
            }

            .section-header {
                font-size: 1.4rem;
                padding: 15px;
            }
        }

        /* Loading Animation */
        .btn:disabled {
            opacity: 0.7;
            transform: none !important;
        }

        /* Form Labels */
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        /* Search form styling */
        .search-form-wrapper {
            background: linear-gradient(135deg, rgba(255,255,255,0.9), rgba(248,249,250,0.9));
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* Button group styling */
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        @media (max-width: 576px) {
            .button-group {
                flex-direction: column;
                width: 100%;
            }
            
            .button-group .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="card shadow-sm">
            <div class="section-header">
                <i class="fas fa-clipboard-list me-3"></i>
                Material Issue Note
            </div>
            
            <div class="card-body">
                <!-- Enhanced Search Form -->
                <div class="search-form-wrapper">
                    <form method="GET" id="searchForm">
                        <div class="search-controls">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-hashtag me-1"></i>Request Number
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       placeholder="Enter request number" 
                                       name="material_request_id" 
                                       value="<?= isset($_GET['material_request_id']) ? htmlspecialchars($_GET['material_request_id']) : '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building me-1"></i>Department
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       placeholder="Enter department" 
                                       name="department" 
                                       value="<?= isset($_GET['department']) ? htmlspecialchars($_GET['department']) : '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i>Request By
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       placeholder="Enter requester name" 
                                       name="request_by" 
                                       value="<?= isset($_GET['request_by']) ? htmlspecialchars($_GET['request_by']) : '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calendar me-1"></i>Request Date
                                </label>
                                <input type="date" 
                                       class="form-control" 
                                       name="request_date"
                                       value="<?= $request_date_value ? htmlspecialchars($request_date_value) : (isset($_GET['request_date']) ? htmlspecialchars($_GET['request_date']) : '') ?>">
                            </div>
                            
                            <div class="button-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-2"></i>Search
                                </button>
                                <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-secondary">
                                    <i class="fas fa-undo me-2"></i>Reset
                                </a>
                               
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Enhanced Table -->
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>
                                        <i class="fas fa-cogs me-2"></i>Action
                                    </th>
                                    <th>
                                        <i class="fas fa-list-ol me-2"></i>Sr. No.
                                    </th>
                                    <th>
                                        <i class="fas fa-hashtag me-2"></i>Request Number
                                    </th>
                                    <th>
                                        <i class="fas fa-building me-2"></i>Department
                                    </th>
                                    <th>
                                        <i class="fas fa-user me-2"></i>Request By
                                    </th>
                                    <th>
                                        <i class="fas fa-calendar-plus me-2"></i>Request Date
                                    </th>
                                    <th>
                                        <i class="fas fa-calendar-check me-2"></i>Issued Date
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sr = 1;
                                // Use $result instead of $stmt
                                if ($result && sqlsrv_has_rows($result)) {
                                    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                                ?>
                                <tr>
                                    <td>
                                        <a href="issuerMaterial.php?id=<?= $row['material_request_id'] ?>" title="Edit Request">
                                            <i class="fas fa-edit action-icon"></i>
                                        </a>
                                    </td>
                                    <td><strong><?= $sr++ ?></strong></td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?= htmlspecialchars($row['material_request_id']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['department_name']) ?></td>
                                    <td><?= htmlspecialchars($row['request_by']) ?></td>
                                    <td>
                                        <span class="text-info">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php 
                                            if ($row['request_date'] instanceof DateTime) {
                                                echo $row['request_date']->format('d M Y');
                                            } else {
                                                echo date('d M Y', strtotime($row['request_date']));
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['issued_date'])): ?>
                                            <span class="text-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <?php 
                                                if ($row['issued_date'] instanceof DateTime) {
                                                    echo $row['issued_date']->format('d M Y');
                                                } else {
                                                    echo date('d M Y', strtotime($row['issued_date']));
                                                }
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-warning">
                                                <i class="fas fa-clock me-1"></i>
                                                Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    }
                                } else {
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        <h5 class="text-muted">No records found</h5>
                                        <p class="text-muted mb-0">Try adjusting your search criteria</p>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

        // Form submission with loading state
        document.getElementById('searchForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Searching...';
        });

        // Auto-focus first input
        document.querySelector('input[name="material_request_id"]').focus();

        // Add enter key submission for all inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('searchForm').submit();
                }
            });
        });
    </script>
</body>
</html>
