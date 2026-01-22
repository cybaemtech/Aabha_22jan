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
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

// Handle delete request
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    try {
        $delete_id = intval($_GET['delete']);
        $delete_sql = "DELETE FROM operators WHERE id = ?";
        $delete_stmt = sqlsrv_prepare($conn, $delete_sql, [$delete_id]);

        if ($delete_stmt && sqlsrv_execute($delete_stmt)) {
            if (sqlsrv_rows_affected($delete_stmt) > 0) {
                echo "<script>alert('Operator deleted successfully!'); window.location.href='operatorLookup.php';</script>";
            } else {
                echo "<script>alert('Operator not found!'); window.location.href='operatorLookup.php';</script>";
            }
        } else {
            throw new Exception("Failed to delete operator.");
        }
        exit;
    } catch (Exception $e) {
        error_log("Error deleting operator: " . $e->getMessage());
        echo "<script>alert('Error deleting operator.');</script>";
    }
}

// Search functionality
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$contractFilter = $_GET['contract'] ?? '';

// Build dynamic WHERE clause
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(op_id LIKE ? OR name LIKE ?)";
    $searchParam = "%" . $search . "%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($statusFilter)) {
    $whereConditions[] = "present_status = ?";
    $params[] = $statusFilter;
}

if (!empty($contractFilter)) {
    $whereConditions[] = "contract = ?";
    $params[] = $contractFilter;
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Final SQL Query
$query = "SELECT * FROM operators $whereClause ORDER BY op_id ASC";

// Prepare and execute
$stmt = sqlsrv_prepare($conn, $query, $params);

if ($stmt && sqlsrv_execute($stmt)) {
    // Ready to fetch rows using sqlsrv_fetch_array()
} else {
    echo "<div class='alert alert-danger'>Error fetching operator list.</div>";
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional enhancements to work with your existing style.css */
        body {
            /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            transition: all 0.3s ease;
            padding: 20px;
            min-height: 100vh;
            padding-bottom: 100px;
        }

        /* Page Header Enhancement */
        .page-header {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
            color: #fff;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.3);
            text-align: center;
        }

        .page-title {
            margin: 0;
            font-weight: 700;
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .page-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin: 8px 0 0 0;
            font-weight: 400;
        }

        /* Enhanced Filter Container */
        .filter-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #ffffff;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        /* Enhanced Button Styling */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin: 2px;
            min-width: 120px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
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

        .btn-edit {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 8px 16px;
            font-size: 0.8rem;
            min-width: auto;
        }

        .btn-delete {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            padding: 8px 16px;
            font-size: 0.8rem;
            min-width: auto;
        }

        .btn-edit:hover {
            background: linear-gradient(45deg, #218838, #1eac87);
            color: white;
        }

        .btn-delete:hover {
            background: linear-gradient(45deg, #c82333, #a71e2a);
            color: white;
        }

        /* Enhanced Table Container */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-stats {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .header-add-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .header-add-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }

        /* Enhanced DataTables Styling */
        .dataTables_wrapper {
            padding: 25px;
        }

        .dataTables_length,
        .dataTables_filter {
            margin-bottom: 20px;
        }

        .dataTables_length select,
        .dataTables_filter input {
            padding: 8px 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            margin: 0 8px;
            transition: all 0.3s ease;
        }

        .dataTables_length select:focus,
        .dataTables_filter input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            outline: none;
        }

        .dataTables_info {
            padding: 15px 0;
            color: #6c757d;
            font-weight: 500;
        }

        .dataTables_paginate {
            padding: 15px 0;
        }

        .dataTables_paginate .paginate_button {
            display: inline-block;
            padding: 10px 16px;
            margin: 0 3px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            color: #495057 !important;
            text-decoration: none;
            background: white;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .dataTables_paginate .paginate_button:hover {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white !important;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .dataTables_paginate .paginate_button.current {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white !important;
            border-color: #667eea;
        }

        .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .dataTables_paginate .paginate_button.disabled:hover {
            background: white;
            color: #495057 !important;
            border-color: #e1e5e9;
            transform: none;
            box-shadow: none;
        }

        /* Enhanced Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 0.9rem;
        }

        tr:hover {
            background: linear-gradient(45deg, #e3f2fd, #f3e5f5);
            transition: background 0.3s ease;
        }

        /* Status and Badge Enhancements */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            display: inline-block;
            min-width: 80px;
        }

        .status-active {
            background: linear-gradient(45deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .status-inactive {
            background: linear-gradient(45deg, #f8d7da, #f1b3b8);
            color: #721c24;
        }

        .status-leave {
            background: linear-gradient(45deg, #fff3cd, #ffeaa7);
            color: #856404;
        }

        .status-transferred {
            background: linear-gradient(45deg, #d1ecf1, #b8daff);
            color: #0c5460;
        }

        .contract-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .contract-permanent {
            background: linear-gradient(45deg, #e7f3ff, #cce7ff);
            color: #0066cc;
        }

        .contract-temporary {
            background: linear-gradient(45deg, #fff0e6, #ffe0cc);
            color: #cc6600;
        }

        .contract-contract {
            background: linear-gradient(45deg, #f0e6ff, #e0ccff);
            color: #6600cc;
        }

        .contract-trainee {
            background: linear-gradient(45deg, #e6ffe6, #ccffcc);
            color: #006600;
        }

        .grade-badge {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.8rem;
        }

        .grade-a { background: linear-gradient(45deg, #28a745, #20c997); color: white; }
        .grade-b { background: linear-gradient(45deg, #17a2b8, #20c997); color: white; }
        .grade-c { background: linear-gradient(45deg, #ffc107, #ffca2c); color: black; }
        .grade-d { background: linear-gradient(45deg, #fd7e14, #ff922b); color: white; }
        .grade-e { background: linear-gradient(45deg, #dc3545, #e55353); color: white; }

        /* Floating Add Button */
        .add-operator-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .add-operator-btn:hover {
            transform: scale(1.15);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
            color: white;
            text-decoration: none;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-bottom: 100px;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            table {
                min-width: 800px;
            }

            .dataTables_wrapper {
                padding: 15px;
            }

            .add-operator-btn {
                bottom: 20px;
                right: 20px;
                width: 55px;
                height: 55px;
                font-size: 20px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }

        /* Loading Animation */
        .btn:disabled {
            opacity: 0.7;
            transform: none !important;
        }

        /* Enhanced No Data State */
        .no-data {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }

        .no-data i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
            display: block;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Enhanced Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-users"></i> Operator Lookup
        </h1>
        <p class="page-subtitle">View and manage all operator records with advanced filtering</p>
    </div>

    <!-- Enhanced Filter Section -->
    <div class="filter-container">
        <form method="GET" action="operatorLookup.php">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="search">
                        <i class="fas fa-search me-2"></i>Search (OP ID or Name)
                    </label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Enter OP ID or Name..." class="form-control">
                </div>
                
                <div class="filter-group">
                    <label for="status">
                        <i class="fas fa-user-check me-2"></i>Status Filter
                    </label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="On Leave" <?= $statusFilter === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="Transferred" <?= $statusFilter === 'Transferred' ? 'selected' : '' ?>>Transferred</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="contract">
                        <i class="fas fa-file-contract me-2"></i>Contract Filter
                    </label>
                    <select id="contract" name="contract" class="form-control">
                        <option value="">All Contracts</option>
                        <option value="Permanent" <?= $contractFilter === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
                        <option value="Temporary" <?= $contractFilter === 'Temporary' ? 'selected' : '' ?>>Temporary</option>
                        <option value="Contract" <?= $contractFilter === 'Contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="Trainee" <?= $contractFilter === 'Trainee' ? 'selected' : '' ?>>Trainee</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="operatorLookup.php" class="btn btn-secondary">
                        <i class="fas fa-refresh"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Enhanced Data Table -->
    <div class="table-container">
        <div class="table-header">
            <div class="table-title">
                <i class="fas fa-list"></i> Operators List
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <?php
$totalRows = 0;
while (sqlsrv_fetch($stmt)) {
    $totalRows++;
}
sqlsrv_execute($stmt); // Re-execute if needed for later use
?>
<div class="table-stats">
    <i class="fas fa-chart-bar me-2"></i>
    Total Records: <?= $totalRows ?>
</div>

                <a href="operator.php" class="header-add-btn">
                    <i class="fas fa-plus me-2"></i>Add New
                </a>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table id="operatorsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th class="sr-no">Sr No.</th>
                        <th><i class="fas fa-id-card me-2"></i>OP ID</th>
                        <th><i class="fas fa-user me-2"></i>Name</th>
                        <th><i class="fas fa-file-contract me-2"></i>Contract</th>
                        <th><i class="fas fa-venus-mars me-2"></i>Sex</th>
                        <th><i class="fas fa-calendar me-2"></i>Month</th>
                        <th><i class="fas fa-star me-2"></i>Grade</th>
                        <th><i class="fas fa-user-check me-2"></i>Present Status</th>
                        <th><i class="fas fa-clock me-2"></i>Created</th>
                        <th class="actions-cell"><i class="fas fa-cogs me-2"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
<?php



include '../Includes/db_connect.php'; // make sure your SQLSRV connection is here

$sql = "SELECT * FROM operators"; // Change table name to your actual one
$result = sqlsrv_query($conn, $sql);
if ($result && sqlsrv_has_rows($result)) {
    $srNo = 1;
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        // Status badge class
        $statusClass = match($row['present_status']) {
            'Active' => 'status-active',
            'Inactive' => 'status-inactive',
            'On Leave' => 'status-leave',
            'Transferred' => 'status-transferred',
            default => 'status-inactive'
        };

        // Contract badge class
        $contractClass = match($row['contract']) {
            'Permanent' => 'contract-permanent',
            'Temporary' => 'contract-temporary',
            'Contract' => 'contract-contract',
            'Trainee' => 'contract-trainee',
            default => 'contract-permanent'
        };

        // Grade badge class
        $gradeClass = $row['grade'] ? 'grade-' . strtolower($row['grade']) : '';

        // Format created_at (handle null values)
        $createdAt = isset($row['created_at']) ? date('d-M-Y', strtotime($row['created_at']->format('Y-m-d'))) : '-';

        echo "<tr>
            <td class='sr-no'></td> <!-- Sr No. will be filled by DataTables -->
            <td class='op-id'><strong>{$row['op_id']}</strong></td>
            <td>{$row['name']}</td>
            <td>
                <span class='contract-badge {$contractClass}'>
                    {$row['contract']}
                </span>
            </td>
            <td>
                <i class='fas fa-" . ($row['sex'] === 'Male' ? 'mars' : 'venus') . " me-2'></i> 
                {$row['sex']}
            </td>
            <td>" . (!empty($row['month']) ? $row['month'] : '-') . "</td>
            <td>" . ($row['grade'] ? "<span class='grade-badge {$gradeClass}'>{$row['grade']}</span>" : '-') . "</td>
            <td>
                <span class='status-badge {$statusClass}'>
                    {$row['present_status']}
                </span>
            </td>
            <td>{$createdAt}</td>
            <td class='actions-cell'>
                <a href='operator.php?edit={$row['id']}' class='btn btn-edit' title='Edit Operator'>
                    <i class='fas fa-edit'></i>
                </a>
                <a href='operatorLookup.php?delete={$row['id']}' class='btn btn-delete' 
                   onclick='return confirm(\"Are you sure you want to delete operator {$row['name']} ({$row['op_id']})?\")' 
                   title='Delete Operator'>
                    <i class='fas fa-trash'></i>
                </a>
            </td>
        </tr>";
    }
} else {
    echo "<tr>
        <td colspan='10' class='no-data'>
            <i class='fas fa-users-slash'></i>
            <h5>No operators found matching your criteria</h5>
            <p>Try adjusting your search filters or add a new operator.</p>
            <a href='operator.php' class='btn btn-success mt-3'>
                <i class='fas fa-plus me-2'></i>Add First Operator
            </a>
        </td>
    </tr>";
}
?>
</tbody>

            </table>
        </div>
    </div>

    <!-- Floating Add Button -->
    <a href="operator.php" class="add-operator-btn" title="Add New Operator">
        <i class="fas fa-plus"></i>
    </a>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    var table = $('#operatorsTable').DataTable({
        "pageLength": 10,
        "lengthMenu": [[5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"]],
        "order": [[1, "asc"]], // Sort by OP ID
        "columnDefs": [
            { "orderable": false, "targets": [9] }, // Disable sorting for Actions column
            { "searchable": false, "targets": [0, 9] } // Disable search for Sr No and Actions
        ],
        "language": {
            "search": "Quick Search:",
            "lengthMenu": "Show _MENU_ records per page",
            "info": "Showing _START_ to _END_ of _TOTAL_ operators",
            "infoEmpty": "No operators available",
            "infoFiltered": "(filtered from _MAX_ total operators)",
            "paginate": {
                "first": "« First",
                "last": "Last »",
                "next": "Next »",
                "previous": "« Previous"
            },
            "emptyTable": "No operators found in the database",
            "zeroRecords": "No matching operators found"
        },
        "pagingType": "full_numbers",
        "responsive": true,
        "processing": true,
        "stateSave": true,
        "autoWidth": false,
        "drawCallback": function(settings) {
            var api = this.api();
            api.rows({ page: 'current' }).every(function(rowIdx, tableLoop, rowLoop) {
                var srNo = rowIdx + 1 + api.page.info().start;
                $(this.node()).find('td.sr-no').html('<strong>' + srNo + '</strong>');
            });
        }
    });

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

    // Enhanced keyboard shortcuts
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'operator.php';
        }
        
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            $('#search').focus();
        }
    });

    // Smooth animations for buttons
    $('.btn').hover(
        function() { $(this).addClass('animated'); },
        function() { $(this).removeClass('animated'); }
    );
});
</script>

</body>
</html>