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


// --- AJAX handler must be at the VERY TOP of the file ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_ids']) && isset($_POST['batch_number'])) {
    include '../Includes/db_connect.php';
    $batchNumber = trim($_POST['batch_number']);
    $forwardIds = $_POST['forward_ids'];
    $success = true;
    $errorMsg = '';

    foreach ($forwardIds as $combo) {
        list($bin_no, $lot_no, $old_batch_no) = explode('|', $combo);
        
        $sql = "UPDATE electronic_batch_entry 
                SET batch_number = ?, forward = 1 
                WHERE bin_no = ? AND lot_no = ? AND forward = 0";
        $params = [$batchNumber, $bin_no, $lot_no];
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $success = false;
            $errorMsg .= "Error forwarding bin $bin_no, lot $lot_no: " . print_r(sqlsrv_errors(), true) . "\n";
        }
    }

    echo json_encode(['success' => $success, 'error' => $errorMsg]);
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_forwarded_total') {
    include '../Includes/db_connect.php';
    $batchNumber = trim($_POST['batch_number']);

    $sql = "
        SELECT 
            SUM(pass_gross) AS total_pass_gross,
            COUNT(*) AS total_entries,
            STRING_AGG(CONCAT(lot_no, '-', bin_no), ', ') AS forwarded_entries
        FROM electronic_batch_entry 
        WHERE forward = 1 AND batch_number = ?
    ";
    $stmt = sqlsrv_query($conn, $sql, [$batchNumber]);

    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo json_encode([
            'success' => true,
            'total' => floatval($row['total_pass_gross'] ?? 0),
            'entries' => intval($row['total_entries'] ?? 0),
            'forwarded_entries' => $row['forwarded_entries'] ?? ''
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'total' => 0,
            'entries' => 0,
            'forwarded_entries' => ''
        ]);
    }
    exit;
}

// --- Main Page Content ---

include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

// Fetch available batch numbers
$batchOptions = [];
$sql = "
    SELECT DISTINCT 
        bc.batch_number, 
        bc.brand_name, 
        bc.product_type, 
        bc.status,
        bc.created_at,
        COUNT(ebe.id) as entry_count,
        SUM(ebe.pass_gross) as total_pass_gross
    FROM batch_creation bc
    LEFT JOIN electronic_batch_entry ebe ON bc.batch_number = ebe.batch_number AND ebe.forward = 0
    WHERE bc.status IN ('Pending', 'Active') 
    GROUP BY bc.batch_number, bc.brand_name, bc.product_type, bc.status, bc.created_at
    ORDER BY bc.created_at DESC, bc.batch_number ASC
";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $batchOptions[] = $row;
    }
}

// Filters
$searchLotNo = isset($_GET['search_lot_no']) ? trim($_GET['search_lot_no']) : '';
$searchBatchNo = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
$searchForwardedBatchNo = isset($_GET['forwarded_batch_number']) ? trim($_GET['forwarded_batch_number']) : '';
$where = "forward = 0";
$params = [];

if ($searchLotNo !== '') {
    $where .= " AND lot_no = ?";
    $params[] = $searchLotNo;
}
if ($searchBatchNo !== '') {
    $where .= " AND batch_number = ?";
    $params[] = $searchBatchNo;
}

// Forwarded batch total
$forwardedBatchTotal = null;
if ($searchForwardedBatchNo !== '') {
    $sql = "SELECT SUM(pass_gross) AS total_pass_gross FROM electronic_batch_entry WHERE forward = 1 AND batch_number = ?";
    $stmt = sqlsrv_query($conn, $sql, [$searchForwardedBatchNo]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $forwardedBatchTotal = $row['total_pass_gross'];
    }
}

// Main data query
$where = "forward = 0"; // Only show non-forwarded entries by default

// If searching for forwarded batch, show forwarded entries instead
if ($searchForwardedBatchNo !== '') {
    $where = "forward = 1 AND batch_number = ?";
    $params = [$searchForwardedBatchNo];
} else {
    $params = [];
    
    if ($searchLotNo !== '') {
        $where .= " AND lot_no = ?";
        $params[] = $searchLotNo;
    }
    if ($searchBatchNo !== '') {
        $where .= " AND batch_number = ?";
        $params[] = $searchBatchNo;
    }
}

$sql = "
    SELECT 
        lot_no,
        bin_no,
        batch_number,
        MIN(date) as min_date,
        MAX(date) as max_date,
        shift,
        mc_no,
        product_type,
        op_name,
        SUM(pass_kg) as sum_pass_kg,
        SUM(rej_kg) as sum_rej_kg,
        AVG(avg_wt) as avg_avg_wt,
        SUM(pass_gross) as sum_pass_gross,
        SUM(reject_gross) as sum_reject_gross,
        SUM(et_total_gs) as sum_et_total_gs,
        SUM(total_kg) as sum_total_kg,
        COUNT(*) as entry_count
    FROM electronic_batch_entry
    WHERE $where
    GROUP BY lot_no, bin_no, batch_number, shift, mc_no, product_type, op_name
    ORDER BY lot_no, bin_no
";
$stmt = sqlsrv_query($conn, $sql, $params);
$summaryData = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $summaryData[] = $row;
    }
}

// Batch-wise total pass_gross
$batchPassGross = [];
$sql = "
    SELECT batch_number, SUM(pass_gross) AS total_pass_gross
    FROM electronic_batch_entry
    WHERE $where
    GROUP BY batch_number
";
$grossStmt = sqlsrv_query($conn, $sql, $params);
if ($grossStmt) {
    while ($row = sqlsrv_fetch_array($grossStmt, SQLSRV_FETCH_ASSOC)) {
        $batchPassGross[$row['batch_number']] = $row['total_pass_gross'];
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Electronic Batch Entry Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional enhancements to work with your existing style.css */
        body {
           
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            transition: all 0.3s ease;
            padding: 20px;
            min-height: 100vh;
            margin-left: 255px;
        }

        /* Enhanced Container */
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            padding: 40px;
            margin: 20px auto;
            max-width: 1400px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Enhanced Page Header */
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
            letter-spacing: 1px;
        }

        /* Enhanced Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .search-controls {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
            margin-bottom: 0;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 180px;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #ffffff;
            min-width: 0; /* Allow inputs to shrink */
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: #ffffff;
            outline: none;
        }

        /* Enhanced Dropdown Styling */
        .batch-dropdown {
            position: relative;
        }

        .batch-select {
            appearance: menulist;
            background-image: none;
            padding-right: 15px;
        }

        /* Keep only basic dropdown styling */
        .form-control select {
            cursor: pointer;
        }

        .form-control select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .batch-option {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
        }

        .batch-info {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
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
            background: linear-gradient(45deg, #2196f3, #1976d2);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #1976d2, #1565c0);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.4);
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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Enhanced Reset Icon */
        .reset-icon {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            font-size: 1.2em;
            transition: all 0.3s ease;
            z-index: 5;
        }

        .reset-icon:hover {
            color: #2196f3;
            transform: translateY(-50%) scale(1.1);
        }

        /* Forward Section Enhancement */
        .forward-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-top: 20px;
        }

        .forward-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .batch-selection-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 250px;
        }

        .batch-selection-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 0.9rem;
        }

        /* Enhanced Searchable Dropdown Styling */
        .batch-dropdown-wrapper {
            position: relative;
            width: 100%;
        }

        .batch-search-input {
            width: 100%;
            border-radius: 8px;
            border: 2px solid #e1e5e9;
            padding: 12px 40px 12px 15px;
            font-size: 0.95rem;
            background: #ffffff;
            z-index: 3;
            position: relative;
        }

        .batch-search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            outline: none;
        }

        .batch-search-input.has-selection {
            border-color: #28a745;
            background: #f8fff9;
        }

        .batch-search-input.has-selection:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }

        .dropdown-list-container {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1000;
            margin-top: 2px;
            display: none;
        }

        .dropdown-list-container.show {
            display: block;
        }

        .batch-dropdown-list {
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            appearance: none;
            padding: 0;
        }

        .batch-dropdown-list option {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            cursor: pointer;
            background: #ffffff;
            color: #495057;
        }

        .batch-dropdown-list option:hover {
            background: #e3f2fd;
            color: #1976d2;
        }

        .batch-dropdown-list option:last-child {
            border-bottom: none;
        }

        .batch-dropdown-list option[style*="display: none"] {
            display: none !important;
        }

        /* Dropdown arrow indicator */
        .batch-dropdown-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            pointer-events: none;
            transition: transform 0.3s ease;
            z-index: 4;
        }

        .batch-dropdown-wrapper.open::after {
            transform: translateY(-50%) rotate(180deg);
        }

        /* No results message */
        .no-results-message {
            padding: 15px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 2px;
            border: 2px solid #e1e5e9;
        }

        /* Remove conflicting old styles */
        .searchable-dropdown {
            display: none !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .batch-dropdown-list {
                max-height: 150px;
            }
            
            .batch-dropdown-list option {
                padding: 10px 12px;
                font-size: 0.85rem;
            }
        }

        /* Table and other existing styles remain the same... */
        /* Enhanced Alert Styling */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
        }

        .alert-primary {
            background: linear-gradient(45deg, rgba(33, 150, 243, 0.1), rgba(25, 118, 210, 0.1));
            color: #1565c0;
            border-left: 4px solid #2196f3;
        }

        .alert-info {
            background: linear-gradient(45deg, rgba(23, 162, 184, 0.1), rgba(32, 201, 151, 0.1));
            color: #0c5460;
            border-left: 4px solid #17a2b8;
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

        .table-responsive {
            max-height: 70vh;
            overflow: auto;
            border-radius: 15px;
        }

        /* Enhanced Table Styling */
        .table {
            margin-bottom: 0;
            border: none;
        }

        .table thead th {
            background: linear-gradient(45deg, #495057, #343a40);
            color: #fff;
            font-weight: 600;
            padding: 15px 12px;
            border: none;
            text-align: center;
            font-size: 0.9rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
            font-size: 0.9rem;
        }

        .table tbody tr:hover {
            background: linear-gradient(45deg, #e3f2fd, #f3e5f5);
            transition: background 0.3s ease;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Enhanced Action Icons */
        .action-icons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .print-icon {
            background: linear-gradient(45deg, #6f42c1, #e83e8c);
            color: white;
            border: none;
        }

        .print-icon:hover {
            background: linear-gradient(45deg, #5a2d91, #c4196b);
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(111, 66, 193, 0.3);
            color: white;
        }

        .checkbox-icon {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .checkbox-icon:hover {
            background: linear-gradient(45deg, #5a6fd8, #6a4190);
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        /* Enhanced Checkbox Styling */
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
            cursor: pointer;
        }

        #selectAll {
            width: 20px;
            height: 20px;
        }

        /* Enhanced Header Actions */
        .header-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .batch-input-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Animation Enhancements */
        .summary-animate {
            animation: fadeInDown 0.7s ease;
        }

        @keyframes fadeInDown {
            0% { 
                opacity: 0; 
                transform: translateY(-30px);
            }
            100% { 
                opacity: 1; 
                transform: translateY(0);
            }
        }

        /* No Data State */
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

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Enhanced Status Indicators */
        .status-indicator {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-success {
            background: linear-gradient(45deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .status-warning {
            background: linear-gradient(45deg, #fff3cd, #ffeaa7);
            color: #856404;
        }

        .status-info {
            background: linear-gradient(45deg, #d1ecf1, #b8daff);
            color: #0c5460;
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="main-container animate__animated animate__fadeInDown">
        <!-- Enhanced Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-database"></i> 
                Electronic Batch Entry Lookup
            </h1>
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-section">
            <form id="batchForm" method="get" autocomplete="off">
                <div class="search-controls">
                    <div class="form-group">
                        <label for="search_lot_no">
                            <i class="fas fa-search me-1"></i>Lot No.
                        </label>
                        <div style="position:relative;">
                            <input type="text" id="search_lot_no" name="search_lot_no" class="form-control"
                                   value="<?= htmlspecialchars($searchLotNo) ?>"
                                   placeholder="Enter Lot No."
                                   style="padding-right:35px;">
                            <?php if($searchLotNo !== ''): ?>
                                <span class="reset-icon" onclick="clearField('search_lot_no')" title="Clear">
                                    <i class="fas fa-times-circle"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="forwarded_batch_number">
                            <i class="fas fa-share me-1"></i>Forwarded Batch No.
                        </label>
                        <div style="position:relative;">
                            <input type="text" id="forwarded_batch_number" name="forwarded_batch_number" class="form-control"
                                   placeholder="Enter Forwarded Batch No."
                                   value="<?= isset($_GET['forwarded_batch_number']) ? htmlspecialchars($_GET['forwarded_batch_number']) : '' ?>"
                                   style="padding-right:35px;">
                            <?php if(!empty($_GET['forwarded_batch_number'])): ?>
                                <span class="reset-icon" onclick="clearField('forwarded_batch_number')" title="Clear">
                                    <i class="fas fa-times-circle"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="batch_forward_select">
                            <i class="fas fa-arrow-right me-1"></i>Select Batch to Forward To
                        </label>
                        <div class="batch-dropdown-wrapper">
                            <input type="text" 
                                   id="batch_search_input" 
                                   class="form-control batch-search-input"
                                   placeholder="Click to search batch number..."
                                   autocomplete="off"
                                   readonly>
        
                            <div class="dropdown-list-container">
                                <select id="batch_forward_select" 
                                        name="batch_forward_select" 
                                        class="form-control batch-dropdown-list" 
                                        size="6">
                                    <option value="" data-brand="" data-product="" data-status="">
                                        -- Select Batch Number --
                                    </option>
                                    <?php foreach ($batchOptions as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_number']) ?>" 
                                                data-brand="<?= htmlspecialchars($batch['brand_name']) ?>"
                                                data-product="<?= htmlspecialchars($batch['product_type']) ?>"
                                                data-status="<?= htmlspecialchars($batch['status']) ?>"
                                                data-entries="<?= intval($batch['entry_count']) ?>"
                                                data-total="<?= floatval($batch['total_pass_gross'] ?? 0) ?>"
                                                data-search="<?= htmlspecialchars(strtolower($batch['batch_number'] . ' ' . $batch['brand_name'] . ' ' . $batch['product_type'])) ?>">
                                            <?= htmlspecialchars($batch['batch_number']) ?> - <?= htmlspecialchars($batch['brand_name']) ?> 
                                            (<?= htmlspecialchars($batch['product_type']) ?>) 
                                            [<?= htmlspecialchars($batch['status']) ?>]
                                            <?php if ($batch['entry_count'] > 0): ?>
                                                - Entries: <?= intval($batch['entry_count']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
        
                            <!-- No results message container -->
                            <div id="no-results-message" class="no-results-message" style="display: none;">
                                <i class="fas fa-search me-2"></i>
                                No matching batch numbers found. Try a different search term.
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="ElectronicBatchEntry.php" class="btn btn-success">
                                <i class="fas fa-plus"></i> Add New
                            </a>
                            <?php if ($searchBatchNo !== '' || $searchLotNo !== '' || $searchForwardedBatchNo !== ''): ?>
                                <a href="?" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear All
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Summary Information -->
        <?php if ($searchBatchNo !== '' && isset($batchPassGross[$searchBatchNo])): ?>
            <div class="alert alert-info summary-animate">
                <i class="fas fa-calculator me-2"></i>
                <strong>Batch Number:</strong> <span style="color:#1769aa"><?= htmlspecialchars($searchBatchNo) ?></span>
                <br>
                <strong>Issue to Sealing:</strong> <span style="color:#388e3c"><?= number_format($batchPassGross[$searchBatchNo], 2) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($searchForwardedBatchNo !== ''): ?>
            <div class="alert alert-info summary-animate">
                <i class="fas fa-calculator me-2"></i>
                <strong>Forwarded Batch Number:</strong> <span style="color:#1769aa"><?= htmlspecialchars($searchForwardedBatchNo) ?></span>
                <br>
                <strong>Total Pass Gross:</strong>
                <span style="color:#388e3c">
                    <?= $forwardedBatchTotal !== null ? number_format($forwardedBatchTotal, 2) : '0.00' ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Enhanced Data Table -->
        <div class="table-container">
            <div class="table-responsive animate__animated animate__fadeIn">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 120px;">
                                <div class="action-icons">
                                    <input type="checkbox" id="selectAll" title="Select All"/>
                                    <span style="margin-left: 8px; font-size: 0.85rem;">Action</span>
                                </div>
                            </th>
                            <th>Sr. No.</th>
                            <th>Batch No.</th>
                            <th>Lot No.</th>
                            <th>Bin No.</th>
                            <th>Shift</th>
                            <th>Machine No</th>
                            <th>Product Type</th>
                            <th>Operator Name</th>
                            <th>Pass KG</th>
                            <th>Rej KG</th>
                            <th>Avg WT</th>
                            <th>Pass Gross</th>
                            <th>Reject Gross</th>
                            <th>Total GS</th>
                            <th>Total KG</th>
                            <th>Entries</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sr = 1;
                        if(!empty($summaryData)):
                            foreach($summaryData as $row):
                        ?>
                            <tr>
                                <td>
                                    <div class="action-icons">
                                        <input type="checkbox" class="row-checkbox" 
                                               value="<?= htmlspecialchars($row['bin_no']) ?>|<?= htmlspecialchars($row['lot_no']) ?>|<?= htmlspecialchars($row['batch_number']) ?>" 
                                               title="Select to forward"/>
                                        <button class="action-icon print-icon" 
                                                onclick="printBatchEntry('<?= htmlspecialchars($row['lot_no']) ?>', '<?= htmlspecialchars($row['bin_no']) ?>', '<?= htmlspecialchars($row['batch_number']) ?>')"
                                                title="Print Batch Entry">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </div>
                                </td>
                                <td><strong><?= $sr++; ?></strong></td>
                                <td>
                                    <span class="status-indicator status-info">
                                        <?= htmlspecialchars($row['batch_number']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['lot_no']) ?></td>
                                <td><?= htmlspecialchars($row['bin_no']) ?></td>
                                <td><?= htmlspecialchars($row['shift']) ?></td>
                                <td><?= htmlspecialchars($row['mc_no']) ?></td>
                                <td><?= htmlspecialchars($row['product_type']) ?></td>
                                <td><?= htmlspecialchars($row['op_name']) ?></td>
                                <td>
                                    <span class="status-indicator status-success">
                                        <?= number_format($row['sum_pass_kg'],2) ?>
                                    </span>
                                </td>
                                <td><?= number_format($row['sum_rej_kg'],2) ?></td>
                                <td><?= number_format($row['avg_avg_wt'],2) ?></td>
                                <td>
                                    <strong style="color: #28a745;">
                                        <?= number_format($row['sum_pass_gross'],2) ?>
                                    </strong>
                                </td>
                                <td><?= number_format($row['sum_reject_gross'],2) ?></td>
                                <td><?= number_format($row['sum_et_total_gs'],2) ?></td>
                                <td><?= number_format($row['sum_total_kg'],2) ?></td>
                                <td>
                                    <span class="status-indicator status-warning">
                                        <?= $row['entry_count'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="17" class="no-data">
                                    <i class="fas fa-inbox"></i>
                                    <h5>No records found</h5>
                                    <p>
                                        <?php
                                        if ($searchBatchNo !== '' && $searchLotNo !== '') {
                                            echo "No records found for Batch Number <strong>" . htmlspecialchars($searchBatchNo) . "</strong> and Lot No. <strong>" . htmlspecialchars($searchLotNo) . "</strong>.";
                                        } elseif ($searchBatchNo !== '') {
                                            echo "No records found for Batch Number <strong>" . htmlspecialchars($searchBatchNo) . "</strong>.";
                                        } elseif ($searchLotNo !== '') {
                                            echo "No records found for Lot No. <strong>" . htmlspecialchars($searchLotNo) . "</strong>.";
                                        } elseif ($searchForwardedBatchNo !== '') {
                                            echo "No records found for Forwarded Batch Number <strong>" . htmlspecialchars($searchForwardedBatchNo) . "</strong>.";
                                        } else {
                                            echo "No records found. Try adjusting your search criteria.";
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Enhanced Forward Section -->
        <div class="forward-section">
            <div class="forward-controls">
                <div class="batch-selection-info" style="margin-bottom: 15px;">
                    <div id="forward-batch-info" style="display: none;">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Will forward to:</strong> 
                        <span id="forward-batch-display" style="color: #1976d2; font-weight: 600;"></span>
                        <br>
                        <small id="forward-batch-details" class="text-muted"></small>
                    </div>
                    <div id="no-batch-info">
                        <i class="fas fa-exclamation-triangle me-2" style="color: #ff9800;"></i>
                        <span style="color: #666;">Please select a batch from the dropdown above to enable forwarding.</span>
                    </div>
                </div>
                
                <button type="button" class="btn btn-primary" id="forwardSelectedBtn" disabled>
                    <i class="fas fa-arrow-right"></i> Forward Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const forwardBtn = document.getElementById('forwardSelectedBtn');
    const selectAll = document.getElementById('selectAll');
    const batchForwardSelect = document.getElementById('batch_forward_select');
    const batchSearchInput = document.getElementById('batch_search_input');
    const dropdownContainer = document.querySelector('.dropdown-list-container');
    const dropdownWrapper = document.querySelector('.batch-dropdown-wrapper');
    let selectedForwardBatch = '';
    
    // Function to get already forwarded total for a batch
    function getAlreadyForwardedTotal(batchNumber) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('action', 'get_forwarded_total');
            formData.append('batch_number', batchNumber);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    resolve(data.total || 0);
                } else {
                    resolve(0);
                }
            })
            .catch(error => {
                console.error('Error fetching forwarded total:', error);
                resolve(0);
            });
        });
    }
    
    // Function to calculate selected entries total
    function calculateSelectedTotal() {
        let total = 0;
        const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
        
        checkedBoxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            if (row) {
                const passGrossCell = row.cells[12]; // Pass Gross column
                if (passGrossCell) {
                    const passGrossText = passGrossCell.textContent.trim();
                    const passGrossValue = parseFloat(passGrossText.replace(/,/g, '')) || 0;
                    total += passGrossValue;
                }
            }
        });
        
        return total;
    }
    
    // Enhanced dropdown search functionality
    function setupDropdownSearch() {
        if (!batchSearchInput || !batchForwardSelect || !dropdownContainer) {
            console.log('Dropdown elements not found');
            return;
        }
        
        const originalOptions = Array.from(batchForwardSelect.options);
        
        function filterOptions(searchTerm) {
            const term = searchTerm.toLowerCase().trim();
            let visibleCount = 0;
            
            originalOptions.forEach((option, index) => {
                if (index === 0) {
                    option.style.display = term === '' ? 'block' : 'none';
                    return;
                }
                
                const batchNumber = option.value.toLowerCase();
                
                if (term === '' || batchNumber.includes(term)) {
                    option.style.display = 'block';
                    visibleCount++;
                } else {
                    option.style.display = 'none';
                }
            });
            
            if (visibleCount > 0 || term !== '') {
                dropdownContainer.classList.add('show');
                dropdownWrapper.classList.add('open');
                batchForwardSelect.size = Math.min(6, Math.max(3, visibleCount + 1));
            } else {
                dropdownContainer.classList.remove('show');
                dropdownWrapper.classList.remove('open');
            }
        }
        
        batchSearchInput.addEventListener('input', function() {
            filterOptions(this.value);
        });
        
        // Show all options function
        function showAllBatchOptions() {
            const originalOptions = Array.from(batchForwardSelect.options);
            originalOptions.forEach((option, index) => {
                option.style.display = 'block';
            });
            dropdownContainer.classList.add('show');
            dropdownWrapper.classList.add('open');
            batchForwardSelect.size = Math.min(6, Math.max(3, originalOptions.length));
        }
        
        // Update event listeners for batchSearchInput
        batchSearchInput.addEventListener('focus', function() {
            // Always show all options on focus
            showAllBatchOptions();
        });
        
        batchSearchInput.addEventListener('click', function() {
            // Always show all options on click
            showAllBatchOptions();
        });
        
        batchForwardSelect.addEventListener('change', function() {
            if (this.value) {
                selectedForwardBatch = this.value;
                batchSearchInput.value = this.value;
                batchSearchInput.classList.add('has-selection');
                
                dropdownContainer.classList.remove('show');
                dropdownWrapper.classList.remove('open');
            } else {
                selectedForwardBatch = '';
                batchSearchInput.classList.remove('has-selection');
            }
            updateForwardDisplay();
            updateForwardBtn();
        });
        
        // Handle keyboard navigation
        batchSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!dropdownContainer.classList.contains('show')) {
                    filterOptions(this.value);
                }
                batchForwardSelect.focus();
                if (batchForwardSelect.selectedIndex < 1) {
                    for (let i = 1; i < batchForwardSelect.options.length; i++) {
                        if (batchForwardSelect.options[i].style.display !== 'none') {
                            batchForwardSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const visibleOptions = Array.from(batchForwardSelect.options).filter(opt => 
                    opt.style.display !== 'none' && !opt.disabled && opt.value !== ''
                );
                if (visibleOptions.length > 0) {
                    batchForwardSelect.value = visibleOptions[0].value;
                    batchForwardSelect.dispatchEvent(new Event('change'));
                }
            } else if (e.key === 'Escape') {
                dropdownContainer.classList.remove('show');
                dropdownWrapper.classList.remove('open');
                this.blur();
            }
        });
        
        batchForwardSelect.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdownContainer.classList.remove('show');
                dropdownWrapper.classList.remove('open');
                batchSearchInput.focus();
            } else if (e.key === 'Enter') {
                this.dispatchEvent(new Event('change'));
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.batch-dropdown-wrapper')) {
                dropdownContainer.classList.remove('show');
                dropdownWrapper.classList.remove('open');
            }
        });
        
        dropdownContainer.classList.remove('show');
        dropdownWrapper.classList.remove('open');
    }
    
    // Enhanced update forward display function
    async function updateForwardDisplay() {
        const forwardInfo = document.getElementById('forward-batch-info');
        const noBatchInfo = document.getElementById('no-batch-info');
        const forwardDisplay = document.getElementById('forward-batch-display');
        const forwardDetails = document.getElementById('forward-batch-details');
        
        if (selectedForwardBatch && forwardInfo && noBatchInfo && forwardDisplay && forwardDetails) {
            const selectedOption = Array.from(batchForwardSelect.options).find(opt => opt.value === selectedForwardBatch);
            if (selectedOption) {
                const brand = selectedOption.getAttribute('data-brand');
                const product = selectedOption.getAttribute('data-product');
                const status = selectedOption.getAttribute('data-status');
                
                // Show loading state
                forwardDisplay.textContent = selectedForwardBatch;
                forwardDetails.innerHTML = `
                    <strong>Brand:</strong> ${brand} | 
                    <strong>Product:</strong> ${product} | 
                    <strong>Status:</strong> <span class="status-badge status-${status.toLowerCase()}">${status}</span>
                    <br><i class="fas fa-spinner fa-spin"></i> Loading totals...
                `;
                
                forwardInfo.style.display = 'block';
                noBatchInfo.style.display = 'none';
                
                try {
                    // Get already forwarded total
                    const alreadyForwardedTotal = await getAlreadyForwardedTotal(selectedForwardBatch);
                    
                    // Calculate selected total
                    const selectedTotal = calculateSelectedTotal();
                    
                    // Calculate new total
                    const newTotal = alreadyForwardedTotal + selectedTotal;
                    
                    // Update display with totals
                    forwardDetails.innerHTML = `
                        <strong>Brand:</strong> ${brand} | 
                        <strong>Product:</strong> ${product} | 
                        <strong>Status:</strong> <span class="status-badge status-${status.toLowerCase()}">${status}</span>
                        <br>
                        <div style="margin-top: 8px; padding: 8px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #28a745;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-check-circle" style="color: #28a745;"></i> Already Forwarded:</span>
                                <span style="font-weight: 600; color: #28a745;">${alreadyForwardedTotal.toFixed(2)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-plus-circle" style="color: #2196f3;"></i> Selected to Forward:</span>
                                <span style="font-weight: 600; color: #2196f3;">${selectedTotal.toFixed(2)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; border-top: 1px solid #dee2e6; padding-top: 4px;">
                                <span><i class="fas fa-calculator" style="color: #6f42c1;"></i> <strong>New Total:</strong></span>
                                <span style="font-weight: 700; color: #6f42c1; font-size: 1.1em;">${newTotal.toFixed(2)}</span>
                            </div>
                        </div>
                    `;
                } catch (error) {
                    console.error('Error loading totals:', error);
                    forwardDetails.innerHTML = `
                        <strong>Brand:</strong> ${brand} | 
                        <strong>Product:</strong> ${product} | 
                        <strong>Status:</strong> <span class="status-badge status-${status.toLowerCase()}">${status}</span>
                        <br><span style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Error loading totals</span>
                    `;
                }
            }
        } else if (forwardInfo && noBatchInfo) {
            forwardInfo.style.display = 'none';
            noBatchInfo.style.display = 'block';
        }
    }
    
    function updateForwardBtn() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        const checked = document.querySelectorAll('.row-checkbox:checked').length;
        const batchSelected = selectedForwardBatch !== '';
        
        if (forwardBtn) {
            forwardBtn.disabled = checked === 0 || !batchSelected;
            
            if (batchSelected && checked > 0) {
                const selectedTotal = calculateSelectedTotal();
                forwardBtn.innerHTML = `<i class="fas fa-arrow-right"></i> Forward Selected (${checked}) - Total: ${selectedTotal.toFixed(2)}`;
            } else if (batchSelected) {
                forwardBtn.innerHTML = `<i class="fas fa-arrow-right"></i> Forward Selected to ${selectedForwardBatch}`;
            } else {
                forwardBtn.innerHTML = `<i class="fas fa-arrow-right"></i> Forward Selected`;
            }
        }
        
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked === checkboxes.length;
        }
        
        // Update display when selection changes
        if (selectedForwardBatch) {
            updateForwardDisplay();
        }
    }
    
    // Initialize dropdown search
    setupDropdownSearch();
    
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('row-checkbox')) {
            updateForwardBtn();
        }
        if (e.target === selectAll) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = selectAll.checked);
            updateForwardBtn();
        }
    });
    
    updateForwardBtn();
    
    // Enhanced clear field function
    function clearField(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = '';
            document.getElementById('batchForm').submit();
        }
    }
    
    window.clearField = clearField;
    
    // Forward button click handler
    if (forwardBtn) {
        forwardBtn.addEventListener('click', function () {
            const batchNumber = selectedForwardBatch;
            if (!batchNumber) {
                alert('Please select a Batch Number from the dropdown to forward to.');
                if (batchSearchInput) batchSearchInput.focus();
                return;
            }
            
            const selected = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                alert('Please select at least one entry to forward.');
                return;
            }
            
            const batchOptions = <?= json_encode($batchOptions) ?>;
            const selectedBatch = batchOptions.find(batch => batch.batch_number === batchNumber);
            const brand = selectedBatch ? selectedBatch.brand_name : 'Unknown';
            const selectedTotal = calculateSelectedTotal();
            
            if (!confirm(`Are you sure you want to forward ${selected.length} selected request(s) to:\n\nBatch Number: "${batchNumber}"\nBrand: "${brand}"\nTotal Pass Gross: ${selectedTotal.toFixed(2)}\n\nThis action cannot be undone.`)) return;
            
            forwardBtn.disabled = true;
            forwardBtn.innerHTML = '<i class="fas fa-spinner spinner"></i> Forwarding...';
            
            const formData = new FormData();
            formData.append('batch_number', batchNumber);
            selected.forEach(id => formData.append('forward_ids[]', id));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then((data) => {
                if (data.success) {
                    alert(`${selected.length} request(s) forwarded successfully to Batch Number "${batchNumber}" (${brand})!\nTotal Pass Gross: ${selectedTotal.toFixed(2)}`);
                    window.location.reload();
                } else {
                    alert('Failed to forward requests: ' + (data.error || 'Unknown error'));
                    forwardBtn.disabled = false;
                    updateForwardBtn();
                }
            })
            .catch(error => {
                alert('An error occurred while forwarding requests: ' + error.message);
                forwardBtn.disabled = false;
                updateForwardBtn();
            });
        });
    }
    
    // Enhanced keyboard event handlers
    ['search_lot_no', 'forwarded_batch_number'].forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('batchForm').submit();
                }
            });
        }
    });
    
    // Smart field clearing
    const searchLotNo = document.getElementById('search_lot_no');
    const forwardedBatchNumber = document.getElementById('forwarded_batch_number');
    
    if (searchLotNo) {
        searchLotNo.addEventListener('input', function() {
            if (this.value.trim() !== '' && forwardedBatchNumber) {
                forwardedBatchNumber.value = '';
            }
        });
    }
    
    if (forwardedBatchNumber) {
        forwardedBatchNumber.addEventListener('input', function() {
            if (this.value.trim() !== '' && searchLotNo) {
                searchLotNo.value = '';
            }
        });
    }

    // Handle sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    
    function adjustMainContent() {
        if (sidebar && sidebar.classList.contains('hide')) {
            if (mainContent) mainContent.classList.add('sidebar-collapsed');
        } else {
            if (mainContent) mainContent.classList.remove('sidebar-collapsed');
        }
    }
    
    adjustMainContent();
    
    if (sidebar) {
        const observer = new MutationObserver(adjustMainContent);
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }
    
    window.addEventListener('resize', adjustMainContent);
});

// Print function remains the same...
function printBatchEntry(lotNo, binNo, batchNumber) {
    const printWindow = window.open('', '_blank');
    
    // Get the specific row data
    const rows = document.querySelectorAll('tbody tr');
    let rowData = null;
    
    rows.forEach(row => {
        const cells = row.cells;
        if (cells.length > 3 && 
            cells[3].textContent.trim() === lotNo && 
            cells[4].textContent.trim() === binNo) {
            rowData = {
                batchNo: cells[2].textContent.trim(),
                lotNo: cells[3].textContent.trim(),
                binNo: cells[4].textContent.trim(),
                shift: cells[5].textContent.trim(),
                mcNo: cells[6].textContent.trim(),
                productType: cells[7].textContent.trim(),
                opName: cells[8].textContent.trim(),
                passKg: cells[9].textContent.trim(),
                rejKg: cells[10].textContent.trim(),
                totalKg: cells[15].textContent.trim()
            };
        }
    });
    
    const currentDate = new Date().toLocaleDateString('en-GB');
    
    const printContent = `
        <html>
        <head>
            <title>ET Print Label</title>
            <style>
                @page {
                    size: 58mm 80mm;
                    margin: 1mm;
                }
                
                body { 
                    font-family: 'Courier New', monospace;
                    font-size: 9px;
                    line-height: 1.2;
                    margin: 0;
                    padding: 2mm;
                    color: #000;
                    background: #fff;
                    width: 54mm;
                }
                
                .ticket {
                    width: 100%;
                    text-align: left;
                    border: 1px solid #000;
                    padding: 3mm;
                }
                
                .header {
                    text-align: center;
                    font-weight: bold;
                    font-size: 11px;
                    margin-bottom: 3mm;
                    text-transform: uppercase;
                    border-bottom: 1px solid #000;
                    padding-bottom: 2mm;
                }
                
                .field-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 1.5mm;
                    font-size: 8px;
                    border-bottom: 1px dotted #ccc;
                    padding-bottom: 1mm;
                }
                
                .field-label {
                    font-weight: bold;
                    min-width: 40%;
                }
                
                .field-value {
                    font-weight: normal;
                    text-align: right;
                    max-width: 55%;
                }
                
                @media print {
                    body { 
                        margin: 0; 
                        padding: 1mm;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                    
                    .ticket {
                        page-break-inside: avoid;
                        border: 1px solid #000 !important;
                    }
                    
                    .field-row {
                        border-bottom: 1px dotted #000 !important;
                    }
                    
                    .header {
                        border-bottom: 1px solid #000 !important;
                    }
                }
            </style>
        </head>
        <body>
            <div class="ticket">
                <div class="header">
                    ET PRINT LABEL
                </div>
                
                ${rowData ? `
                    <div class="field-row">
                        <span class="field-label">Date:</span>
                        <span class="field-value">${currentDate}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Shift:</span>
                        <span class="field-value">${rowData.shift}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Product:</span>
                        <span class="field-value">${rowData.productType}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Lot No.:</span>
                        <span class="field-value">${rowData.lotNo}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Bin No.:</span>
                        <span class="field-value">${rowData.binNo}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">M/c No:</span>
                        <span class="field-value">${rowData.mcNo}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Pass Kg:</span>
                        <span class="field-value">${rowData.passKg}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Rej. Kg:</span>
                        <span class="field-value">${rowData.rejKg}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Total Kg:</span>
                        <span class="field-value">${rowData.totalKg}</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Op ID:</span>
                        <span class="field-value">-</span>
                    </div>
                    
                    <div class="field-row">
                        <span class="field-label">Sup.:</span>
                        <span class="field-value">${rowData.opName}</span>
                    </div>
                ` : `
                    <div style="text-align:center; padding: 5mm;">
                        <div style="font-weight: bold; font-size: 9px;">NO DATA FOUND</div>
                        <div style="font-size: 7px;">Please check entry details</div>
                    </div>
                `}
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Wait for content to load then print
    printWindow.onload = function() {
        setTimeout(() => {
            printWindow.print();
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        }, 100);
    };
}
</script>

</body>
</html>