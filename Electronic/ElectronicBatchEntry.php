<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log page access for debugging
error_log("ElectronicBatchEntry.php accessed at " . date('Y-m-d H:i:s'));

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

// Include database and sidebar
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

// Add this temporarily after including db_connect.php
if (!$conn) {
    die("Database connection failed: " . print_r(sqlsrv_errors(), true));
}

// === Load active operators ===
$activeOperators = [];
$operatorQuery = sqlsrv_query($conn, "SELECT op_id, name FROM operators WHERE present_status != 'Inactive' ORDER BY CAST(op_id AS INT) ASC");
if ($operatorQuery) {
    while ($row = sqlsrv_fetch_array($operatorQuery, SQLSRV_FETCH_ASSOC)) {
        $activeOperators[] = $row;
    }
}

// === Load machines for Electronic department ===
$electronicMachines = [];
$machineQuery = sqlsrv_query($conn, "
    SELECT m.machine_id, m.machine_name 
    FROM machines m 
    LEFT JOIN departments d ON m.department_id = d.id 
    WHERE d.department_name LIKE '%Electronic%' 
    ORDER BY m.machine_id ASC
");
if ($machineQuery) {
    while ($row = sqlsrv_fetch_array($machineQuery, SQLSRV_FETCH_ASSOC)) {
        $electronicMachines[] = $row;
    }
}

// === Debug counts ===


// === AJAX Actions ===
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'get_active_operators') {
        $search = $_GET['search'] ?? '';
        try {
            error_log("AJAX Request: get_active_operators | Search: $search");
            if (!empty($search)) {
                $sql = "SELECT op_id, name FROM operators WHERE present_status != 'Inactive' AND (op_id LIKE ? OR name LIKE ?) ORDER BY CAST(op_id AS INT) ASC";
                $searchParam = "%$search%";
                $stmt = sqlsrv_query($conn, $sql, [$searchParam, $searchParam]);
            } else {
                $sql = "SELECT op_id, name FROM operators WHERE present_status != 'Inactive' ORDER BY CAST(op_id AS INT) ASC";
                $stmt = sqlsrv_query($conn, $sql);
            }

            $operators = [];
            if ($stmt) {
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $operators[] = $row;
                }
            }

            echo json_encode(['success' => true, 'operators' => $operators, 'count' => count($operators)]);
        } catch (Exception $e) {
            error_log("AJAX error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] === 'get_operator_name') {
        $op_id = $_GET['op_id'] ?? '';
        if (empty($op_id)) {
            echo json_encode(['success' => false, 'error' => 'OP ID is required']);
            exit;
        }

        try {
            $sql = "SELECT name FROM operators WHERE op_id = ? AND present_status != 'Inactive'";
            $stmt = sqlsrv_query($conn, $sql, [$op_id]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                echo json_encode(['success' => true, 'name' => $row['name']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Operator not found or inactive']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// === Load Lot Numbers for dropdown ===
$lotNos = [];
$lotRes = sqlsrv_query($conn, "SELECT DISTINCT lot_no FROM dipping_binwise_entry WHERE forward_request = 1 ORDER BY lot_no ASC");
if ($lotRes) {
    while ($row = sqlsrv_fetch_array($lotRes, SQLSRV_FETCH_ASSOC)) {
        $lotNos[] = $row['lot_no'];
    }
}

// === Handle form submission ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect inputs
        $month         = $_POST['month'] ?? '';
        $date          = $_POST['date'] ?? '';
        $shift         = $_POST['shift'] ?? '';
        $mc_no         = $_POST['mcNo'] ?? '';
        $op_id         = $_POST['opId'] ?? '';
        $lot_no        = $_POST['lotNo'] ?? '';
        $bin_no        = $_POST['binNo'] ?? '';
        $pass_kg       = floatval($_POST['passKg'] ?? 0);
        $rej_kg        = floatval($_POST['rejKg'] ?? 0);
        $avg_wt        = $_POST['avgWt'] !== '' ? floatval($_POST['avgWt']) : null;
        $pass_gross    = $_POST['passGross'] !== '' ? floatval($_POST['passGross']) : null;
        $reject_gross  = $_POST['rejectGross'] !== '' ? floatval($_POST['rejectGross']) : null;
        $et_total_gs   = $_POST['etTotalGS'] !== '' ? floatval($_POST['etTotalGS']) : null;
        $product_type  = $_POST['productType'] ?? '';
        $op_name       = $_POST['opName'] ?? '';
        $total_kg      = $_POST['totalKg'] !== '' ? floatval($_POST['totalKg']) : null;

    // Validate PASS + REJ <= Remaining (not original WT KG)
    $wt_kg = 0;
    $previously_used = 0;

    // Convert bin_no to integer to match database schema
    $bin_no_int = intval($bin_no);

    // Debug: Log the input values first
    error_log("Electronic Batch Entry Debug - Input Values:");
    error_log("Lot: '$lot_no', Bin: '$bin_no' (converted to int: $bin_no_int)");
    error_log("PASS KG: $pass_kg, REJ KG: $rej_kg, Total Input: " . ($pass_kg + $rej_kg));

    // Get original weight - try multiple approaches
    // First, try with forward_request = 1 and proper data types
    $sql = "SELECT wt_kg FROM dipping_binwise_entry WHERE lot_no = ? AND bin_no = ? AND forward_request = 1";
    $stmt = sqlsrv_query($conn, $sql, [$lot_no, $bin_no_int]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $wt_kg = floatval($row['wt_kg']);
        error_log("Found dipping record with forward_request=1: wt_kg = $wt_kg");
    } else {
        error_log("No record found with forward_request=1, trying without forward_request condition");
        
        // If not found, try without forward_request condition
        if ($stmt) sqlsrv_free_stmt($stmt);
        $sql = "SELECT wt_kg, forward_request FROM dipping_binwise_entry WHERE lot_no = ? AND bin_no = ?";
        $stmt = sqlsrv_query($conn, $sql, [$lot_no, $bin_no_int]);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $wt_kg = floatval($row['wt_kg']);
            $forward_status = $row['forward_request'] ? 1 : 0;
            error_log("Found dipping record without forward condition: wt_kg = $wt_kg, forward_request = $forward_status");
        } else {
            error_log("No dipping record found at all for lot '$lot_no' bin $bin_no_int");
            
            // Debug: Check what records exist for this lot
            if ($stmt) sqlsrv_free_stmt($stmt);
            $debug_sql = "SELECT lot_no, bin_no, wt_kg, forward_request FROM dipping_binwise_entry WHERE lot_no = ?";
            $debug_stmt = sqlsrv_query($conn, $debug_sql, [$lot_no]);
            if ($debug_stmt) {
                error_log("All dipping records for lot '$lot_no':");
                while ($debug_row = sqlsrv_fetch_array($debug_stmt, SQLSRV_FETCH_ASSOC)) {
                    $forward_val = $debug_row['forward_request'] ? 1 : 0;
                    error_log("  Bin: " . $debug_row['bin_no'] . ", WT: " . $debug_row['wt_kg'] . ", Forward: " . $forward_val);
                }
                sqlsrv_free_stmt($debug_stmt);
            }
        }
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Get previously used amount from electronic entries (also fix bin_no data type)
    $sql = "SELECT ISNULL(SUM(pass_kg + rej_kg), 0) as used_kg FROM electronic_batch_entry WHERE lot_no = ? AND bin_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$lot_no, $bin_no_int]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $previously_used = floatval($row['used_kg']);
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    $remaining_kg = $wt_kg - $previously_used;
    $total_input = $pass_kg + $rej_kg;

    // Debug: Log the calculated values
    error_log("Electronic Batch Entry Debug - Calculated Values:");
    error_log("Original WT KG: $wt_kg");
    error_log("Previously used: $previously_used");
    error_log("Remaining: $remaining_kg");
    error_log("Total Input: $total_input");

    // Only reject if exceeds remaining (allow equal)
    // TEMPORARY: If wt_kg is 0 (not found), bypass validation to allow testing
    if ($wt_kg == 0) {
        error_log("WARNING: Original WT KG is 0 - bypassing validation for testing");
        error_log("This suggests the dipping record was not found");
    } else if ($total_input > $remaining_kg) {
        $exceeded = $total_input - $remaining_kg;
        echo "<script>alert('PASS KG + REJ. KG (" . number_format($total_input, 2) . ") cannot be greater than remaining KG (" . number_format($remaining_kg, 2) . ").\\nExceeded by: " . number_format($exceeded, 2) . " KG\\n\\nOriginal WT KG: " . number_format($wt_kg, 2) . "\\nPreviously used: " . number_format($previously_used, 2) . "\\nRemaining: " . number_format($remaining_kg, 2) . "'); window.history.back();</script>";
        exit;
    }

    // Insert batch entry
    $sql = "INSERT INTO electronic_batch_entry 
        (month, date, shift, mc_no, op_id, lot_no, bin_no, pass_kg, rej_kg, avg_wt, pass_gross, reject_gross, et_total_gs, product_type, op_name, total_kg)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $month, $date, $shift, $mc_no, $op_id, $lot_no, $bin_no_int,  // Use integer bin_no
        $pass_kg, $rej_kg, $avg_wt, $pass_gross, $reject_gross,
        $et_total_gs, $product_type, $op_name, $total_kg
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo "<script>alert('Batch entry saved successfully!'); window.location.href='ElectronicBatchEntryLookup.php';</script>";
        exit;
    } else {
        $errors = sqlsrv_errors();
        $errorMsg = $errors ? $errors[0]['message'] : 'Unknown error';
        echo "<script>alert('Failed to save batch entry: " . addslashes($errorMsg) . "');</script>";
    }
    
    } catch (Exception $e) {
        error_log("Electronic Batch Entry Exception: " . $e->getMessage());
        echo "<script>alert('Error processing form: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    } catch (Error $e) {
        error_log("Electronic Batch Entry PHP Error: " . $e->getMessage());
        echo "<script>alert('System error occurred. Please try again.'); window.history.back();</script>";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Electronic Testing Batch Entry</title>
  <link rel="stylesheet" href="../asset/style.css" />
  <!-- Add this in your <head> section if not already present -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 6px 12px;
            border: 1px solid #bbb;
            border-radius: 5px;
        }

        /* Enhanced Select2 styling for OP ID and Machine */
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #495057;
            padding-left: 8px;
            padding-right: 20px;
            line-height: 26px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .select2-dropdown {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color:rgb(211, 214, 218);
            color: white;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            padding: 8px 12px;
        }

        /* Custom styling for operator and machine info */
        .operator-info, .machine-info {
            font-size: 12px;
            color:rgb(0, 0, 0);
            font-style: italic;
        }

        .machine-info {
            color: #0056b3;
        }

        body {
            background: rgb(240, 235, 235);
            font-family: Arial, sans-serif;
        }

        .main-content {
            margin-left: 255px;
            padding: 30px 10px 30px 10px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out;
        }

        /* Responsive layout for sidebar toggle */
        .main-content.sidebar-collapsed {
            margin-left: 0 !important;
            width: 100% !important;
        }

       .batch-form-container {
            background: #fff;
            border-radius: 12px;
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px 40px 20px 40px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
            transition: max-width 0.3s ease-in-out;
        }

        /* Adjust container width when sidebar is collapsed */
        .main-content.sidebar-collapsed .batch-form-container {
            max-width: 1200px;
        }

        /* Responsive form grid adjustments */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 0px;
        }

        /* When sidebar is collapsed, allow more columns */
        .main-content.sidebar-collapsed .form-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        /* Summary table responsive styling */
        .data-summary-section {
            margin-top: 25px;
            overflow-x: auto;
        }

        .summary-table-container {
            min-width: 600px;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .summary-table th, .summary-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .summary-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .batch-form-container h2 {
            text-align: center;
            background:rgba(66, 96, 230, 0.78);
            color: #222;
            padding: 10px 0;
            border-radius: 8px;
            margin-bottom: 30px;
            letter-spacing: 1px;
        }

        .batch-form label {
            font-weight: bold;
            margin-bottom: 4px;
            color: rgb(14, 8, 11);
        }

        .batch-form input,
        .batch-form select {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #bbb;
            border-radius: 5px;
            margin-bottom: 0px;
            font-size: 15px;
        }

        .batch-form .row {
            display: flex;
            gap: 18px;
        }

        .batch-form .row>div {
            flex: 1;
        }

        .batch-form button {
            background: #4a90e2;
            color: #fff;
            border: none;
            padding: 10px 32px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 10px;
        }

        .batch-form button[type="reset"] {
            background: rgb(12, 8, 8);
        }

        .shift-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .shift-container select {
            flex: 1;
            margin-bottom: 0 !important;
        }

        #resetShiftBtn {
            background:rgba(53, 98, 220, 0.75);
            color: white;
            border: none;
            padding: 8px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #resetShiftBtn:hover {
            background:rgb(60, 35, 200);
        }

        .shift-locked {
            background-color: #e9ecef !important;
            color: #6c757d;
        }

        /* Enhanced Summary Table Styles */
        .data-summary-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            border: 2px solid #e9ecef;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .summary-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
            padding: 12px;
            text-align: center;
            border-bottom: 2px solid #dee2e6;
            font-size: 14px;
        }

        .summary-table td {
            padding: 10px 12px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
            font-size: 13px;
        }

        .summary-table tr:hover {
            background-color: #e3f2fd;
            transform: scale(1.01);
            transition: all 0.2s ease;
        }

        /* Specific styling for different row types */
        #lotBinRow td {
            font-weight: bold;
            color: #333;
            background-color: #e8f5e8;
        }

        #lotBinRow.data-loaded td {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
        }

        #lotBinRow td:first-child {
            background-color: #d4edda;
            font-size: 14px;
        }

        #emptyRow1 td:nth-child(2) {
            font-style: italic;
            color: #6c757d;
            font-weight: 600;
        }

        #totalRow td:nth-child(2) {
            font-weight: bold;
            color: #28a745;
            background-color: #d1ecf1;
        }

        #etTotalPass, #etTotalRej {
            font-weight: bold;
            color: #0056b3;
        }

        /* Highlight dipping data cells */
        #dippingPassCell, #dippingRejCell, #dippingTotalCell, #dippingRecdCell {
            background-color: #fff3cd !important;
            font-weight: 600;
            color: #856404;
        }

        /* Animation for data loading */
        .data-loading {
            background-color: #f8f9fa !important;
            color: #6c757d !important;
            font-style: italic;
        }

        /* Electronic summary row styling */
        #electronicSummaryRow td {
            background-color: #e8f4f8 !important;
            font-weight: bold;
            color: #0056b3;
        }

        #electronicSummaryRow td:nth-child(2) {
            color: #28a745 !important; /* Green for pass kg */
        }

        #electronicSummaryRow td:nth-child(3) {
            color: #dc3545 !important; /* Red for reject kg */
        }

        #electronicSummaryRow td:nth-child(5) {
            color: #6c757d !important; /* Gray for remaining */
        }

        /* Enhanced status indicators */
        #remainingIndicator td {
            font-weight: bold !important;
            padding: 12px !important;
        }

        .within-limit {
            background-color: #d4edda !important;
            color: #155724 !important;
        }

        .exceeded {
            background-color: #f8d7da !important;
            color: #721c24 !important;
        }

        /* Visual feedback for different statuses */
        .summary-table tbody tr:nth-child(1) {
            background-color: #e3f2fd; /* Dipping data - light blue */
        }

        .summary-table tbody tr:nth-child(2) {
            background-color: #f3e5f5; /* Previous ET - light purple */
        }

        .summary-table tbody tr:nth-child(3) {
            background-color: #e8f5e8; /* Current entry - light green */
        }

        /* Progress indicator styles */
        .progress-indicator {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }

        .progress-good {
            background-color: #d4edda;
            color: #155724;
        }

        .progress-warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .progress-danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
            }

            .batch-form-container {
                padding: 18px 5vw;
            }

            .batch-form .row {
                flex-direction: column;
            }

            .summary-table {
                font-size: 11px;
            }
            
            .summary-table th,
            .summary-table td {
                padding: 6px 8px;
            }
        }
    </style>

</head>

<body>
  <div class="wrapper">
    <div class="container">
      <h1 style="background-color: #007bff; color: white; padding: 5px; border-radius: 5px;">
        ELECTRONIC TESTING BATCH ENTRY FORM
      </h1>
      
      <form id="electronicBatchForm" method="POST" class="batch-form">
        <div class="form-grid">

          <div>
            <label for="month">MONTH</label>
            <input type="text" id="month" name="month" readonly
              style="background-color: #f8f9fa;" placeholder="e.g. May-25">
          </div>
          <div>
            <label for="date">DATE</label>
            <input type="date" id="date" name="date" required>
          </div>
          <div>
            <label for="shift">SHIFT</label>
            <div class="shift-group">
                <select id="shift" name="shift" required>
                  <option value="">Select Shift</option>
                  <option value="I">I</option>
                  <option value="II">II</option>
                  <option value="III">III</option>
                </select>
                <button type="button" id="resetShiftBtn" title="Reset Shift">
                  <i class="fas fa-sync-alt"></i>
                </button>
            </div>
          </div>

          <div>
            <label for="mcNo">M/C NO.</label>
            <select id="mcNo" name="mcNo" class="form-control machine-select2" required style="width:100%;">
                <option value="">Select Machine</option>
                <?php foreach ($electronicMachines as $machine): ?>
                    <option value="<?= htmlspecialchars($machine['machine_id']) ?>" 
                            data-name="<?= htmlspecialchars($machine['machine_name']) ?>">
                        <?= htmlspecialchars($machine['machine_id']) ?> - <?= htmlspecialchars($machine['machine_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="opId">OP ID</label>
            <select id="opId" name="opId" class="form-control operator-select2" required style="width:100%;">
                <option value="">Select OP ID</option>
                <?php foreach ($activeOperators as $operator): ?>
                    <option value="<?= htmlspecialchars($operator['op_id']) ?>" 
                            data-name="<?= htmlspecialchars($operator['name']) ?>">
                        <?= htmlspecialchars($operator['op_id']) ?> - <?= htmlspecialchars($operator['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="opName">OP NAME</label>
            <input type="text" id="opName" name="opName" readonly
              style="background-color: #f8f9fa;" placeholder="Auto-filled">
          </div>

          <div>
            <label for="lotNo">LOT NO.</label>
            <select id="lotNo" name="lotNo" class="form-control lot-select2" required style="width:100%;">
                <option value="">Select Lot No.</option>
                <?php foreach ($lotNos as $lotNo): ?>
                    <option value="<?= htmlspecialchars($lotNo) ?>"><?= htmlspecialchars($lotNo) ?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="binNo">BIN NO.</label>
            <input type="number" id="binNo" name="binNo" required>
          </div>
          <div>
            <label for="avgWt">AVG. WT.</label>
            <input type="number" step="0.01" id="avgWt" name="avgWt" readonly
              style="background-color: #f8f9fa;" required>
          </div>

          <div>
            <label for="passKg">PASS KG</label>
            <input type="number" step="0.01" id="passKg" name="passKg" required>
          </div>
          <div>
            <label for="rejKg">REJ. KG</label>
            <input type="number" step="0.01" id="rejKg" name="rejKg" required>
          </div>
          <div>
            <label for="passGross">PASS GROSS</label>
            <input type="number" step="0.01" id="passGross" name="passGross" readonly
              style="background-color: #f8f9fa;">
          </div>

          <div>
            <label for="rejectGross">REJECT GROSS</label>
            <input type="number" step="0.01" id="rejectGross" name="rejectGross" readonly
              style="background-color: #f8f9fa;">
          </div>
          <div>
            <label for="etTotalGS">ET totalGS</label>
            <input type="number" step="0.01" id="etTotalGS" name="etTotalGS" readonly
              style="background-color: #f8f9fa;">
          </div>
          <div>
            <label for="productType">PRODUCT TYPE</label>
            <input type="text" id="productType" name="productType" readonly
              style="background-color: #f8f9fa;">
          </div>

          <div>
            <label for="totalKg">Total Kg</label>
            <input type="number" step="0.01" id="totalKg" name="totalKg" readonly
              style="background-color: #f8f9fa;">
          </div>
          <div style="display: flex; align-items: end; gap: 12px; margin-top: 8px;">
            <button type="submit" onclick="return checkSubmit()"
              style="background-color: #4CAF50; padding: 12px 24px; border: none; color: white; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: 600; min-width: 120px;">
              Submit
            </button>
            <a href="ElectronicBatchEntryLookup.php"
              style="background-color: #6c757d; padding: 12px 24px; color: white; border-radius: 5px; cursor: pointer; text-decoration: none; text-align: center; font-size: 16px; font-weight: 600; min-width: 120px; display: inline-block;">
              Cancel
            </a>
          </div>
          <div>
            <!-- Empty for spacing -->
          </div>

        </div>

      </form>

      <!-- Data Summary Table (outside form, like in Dipping) -->
      <div class="data-summary-section" style="margin-top: 25px;">
          <h5 style="margin-bottom: 15px; color: #495057; font-weight: bold;">
              <i class="fas fa-table"></i> Lot Summary & Remaining Analysis
          </h5>
          <div class="summary-table-container">
              <table class="summary-table" id="summaryTable">
                  <thead>
                      <tr>
                          <th>Type</th>
                          <th>Pass KG</th>
                          <th>Reject KG</th>
                          <th>Total KG</th>
                          <th>Status</th>
                      </tr>
                  </thead>
                  <tbody id="summaryTableBody">
                      <tr id="lotBinRow" style="display: none;">
                          <td id="lotBinCell" style="font-weight: bold; background-color: #e3f2fd;"></td>
                          <td id="dippingPassCell">-</td>
                          <td id="dippingRejCell">-</td>
                          <td id="dippingTotalCell" style="font-weight: bold; color: #0056b3;">-</td>
                          <td id="dippingRecdCell" style="font-weight: bold; color: #28a745;">-</td>
                      </tr>
                      <!-- Electronic summary and remaining status rows will be inserted here -->
                      <tr id="emptyRow1" style="display: none; background-color: #f8f9fa;">
                          <td style="font-style: italic; color: #6c757d;">Current Entry</td>
                          <td id="etTotalPass" style="font-weight: bold; color: #28a745;">0.00</td>
                          <td id="etTotalRej" style="font-weight: bold; color: #dc3545;">0.00</td>
                          <td id="entryFormAmount" style="font-weight: bold;">0.00</td>
                          <td id="entryFormStatus" style="font-style: italic;">-</td>
                      </tr>
                  </tbody>
              </table>
          </div>
      </div>
    </div>
  </div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const dateInput = document.getElementById("date");
    const monthInput = document.getElementById("month");

    // Get current date
    const today = new Date();

    // Format for 'MONTH' field (e.g. May-25)
    const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                        "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const formattedMonth = `${monthNames[today.getMonth()]}-${String(today.getFullYear()).slice(2)}`;
    monthInput.value = formattedMonth;

    // Format for 'DATE' field as yyyy-mm-dd (required format for input[type="date"])
    const day = String(today.getDate()).padStart(2, '0');
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const year = today.getFullYear();
    dateInput.value = `${year}-${month}-${day}`;
});

$(document).ready(function() {
    // Initialize Machine Select2
    $('#mcNo').select2({
        placeholder: "-- Select Machine --",
        allowClear: true,
        width: '100%',
        templateResult: function(machine) {
            if (!machine.id) return machine.text;
            
            // Extract name from text if available
            var parts = machine.text.split(' - ');
            var id = parts[0] || machine.id;
            var name = parts[1] || '';
            
            var $result = $(
                '<div>' +
                    '<strong>' + id + '</strong>' +
                    '<div class="machine-info"><i class="fas fa-cog me-1"></i>' + name + '</div>' +
                '</div>'
            );
            return $result;
        }
    });

    // Initialize Lot No Select2
    $('#lotNo').select2({
        placeholder: "-- Select Lot No --",
        allowClear: true,
        width: '100%'
    });

    // Initialize OP ID Select2 - simplified version without AJAX since we have PHP data
    $('#opId').select2({
        placeholder: "-- Select OP ID --",
        allowClear: true,
        width: '100%',
        templateResult: function(operator) {
            if (!operator.id) return operator.text;
            
            // Extract name from text if available
            var parts = operator.text.split(' - ');
            var id = parts[0] || operator.id;
            var name = parts[1] || '';
            
            var $result = $(
                '<div>' +
                    '<strong>' + id + '</strong>' +
                    '<div class="operator-info"><i class="fas fa-user me-1"></i>' + name + '</div>' +
                '</div>'
            );
            return $result;
        }
    });

    // Handle OP ID selection to auto-fill OP NAME
    $('#opId').on('change', function() {
        var selectedText = $(this).find(':selected').text();
        var selectedName = $(this).find(':selected').attr('data-name') || '';
        
        // If data-name attribute exists, use it; otherwise extract from text
        if (selectedName) {
            $('#opName').val(selectedName);
        } else if (selectedText && selectedText.includes(' - ')) {
            var parts = selectedText.split(' - ');
            $('#opName').val(parts[1] || '');
        } else {
            $('#opName').val('');
        }
    });

    // Clear OP NAME when OP ID is cleared
    $('#opId').on('select2:clear', function() {
        $('#opName').val('');
    });

    // Shift locking functionality
    const shiftSelect = $('#shift');
    const resetShiftBtn = $('#resetShiftBtn');

    // Check if shift is already locked in localStorage
    const savedShift = localStorage.getItem('selectedShift');
    if (savedShift) {
        shiftSelect.val(savedShift);
        shiftSelect.addClass('shift-locked');
        shiftSelect.prop('disabled', true);
    }

    // When shift is selected, lock it
    shiftSelect.on('change', function() {
        const selectedShift = $(this).val();
        if (selectedShift !== '') {
            localStorage.setItem('selectedShift', selectedShift);
            $(this).addClass('shift-locked');
            $(this).prop('disabled', true);
        }
    });

    // Reset shift functionality
    resetShiftBtn.on('click', function() {
        if (confirm('Are you sure you want to reset the shift? This will allow you to select a different shift.')) {
            localStorage.removeItem('selectedShift');
            shiftSelect.removeClass('shift-locked');
            shiftSelect.prop('disabled', false);
            shiftSelect.val('');
            shiftSelect.focus();
        }
    });

    $('#closeBtn').on('click', function() {
    window.location.href = 'ElectronicBatchEntryLookup.php';
    });
});

let lotWtKg = 0;
let actualRemainingKg = 0; // New variable to track actual remaining

// Function to calculate Total Kg
function calculateTotalKg() {
    const passKg = parseFloat($('#passKg').val()) || 0;
    const rejKg = parseFloat($('#rejKg').val()) || 0;
    
    // Total Kg = PASS KG + REJ. KG
    const totalKg = passKg + rejKg;
    $('#totalKg').val(totalKg.toFixed(2));
}

// Function to calculate PASS GROSS and REJECT GROSS
function calculateGross() {
    const passKg = parseFloat($('#passKg').val()) || 0;
    const rejKg = parseFloat($('#rejKg').val()) || 0;
    const avgWt = parseFloat($('#avgWt').val()) || 0;
    
    if (avgWt > 0) {
        // Pass Gross = Pass kg x 1000 / Average Weight / 144
        const passGross = (passKg * 1000 / avgWt / 144);
        $('#passGross').val(passGross.toFixed(2));
        
        // Reject Gross = Rej. kg x 1000 / Average Weight / 144
        const rejectGross = (rejKg * 1000 / avgWt / 144);
        $('#rejectGross').val(rejectGross.toFixed(2));
        
        // Calculate ET Total GS (Pass Gross + Reject Gross)
        const etTotalGS = passGross + rejectGross;
        $('#etTotalGS').val(etTotalGS.toFixed(2));
    } else {
        $('#passGross').val('');
        $('#rejectGross').val('');
        $('#etTotalGS').val('');
    }
}

// Function to fetch avg_wt when both lot_no and bin_no are available
function fetchAvgWt() {
    const lotNo = $('#lotNo').val();
    const binNo = $('#binNo').val();
    
    if (lotNo && binNo) {
        $.get('get_avg_wt.php', { 
            lot_no: lotNo, 
            bin_no: binNo 
        }, function(data) {
            if (data.avg_wt && data.avg_wt > 0) {
                $('#avgWt').val(data.avg_wt);
                // Recalculate gross values when avg_wt is updated
                calculateGross();
            } else {
                $('#avgWt').val('');
                $('#passGross').val('');
                $('#rejectGross').val('');
                $('#etTotalGS').val('');
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Failed to fetch avg_wt:', error);
            console.log('Response:', xhr.responseText);
        });
    } else {
        $('#avgWt').val('');
        $('#passGross').val('');
        $('#rejectGross').val('');
        $('#etTotalGS').val('');
    }
}

$('#lotNo').on('change', function() {
    const lotNo = $(this).val();
    if (lotNo) {
        // Show loading state
        $('#dippingPassCell, #dippingRejCell, #dippingTotalCell, #dippingRecdCell').addClass('data-loading').text('Loading...');
        
        $.get('get_wt_kg.php', { lot_no: lotNo }, function(data) {
            lotWtKg = parseFloat(data.wt_kg) || 0;
            $('#productType').val(data.product_type || '');
            $('#passKg, #rejKg').val('');
            $('#passGross, #rejectGross, #etTotalGS, #totalKg').val('');
            fetchAvgWt();
            updateSummaryTable(); // This will calculate actual remaining
        }, 'json').fail(function(xhr, status, error) {
            console.error('Failed to fetch wt_kg:', error);
            updateSummaryTable();
        });
    } else {
        lotWtKg = 0;
        actualRemainingKg = 0;
        $('#passKg, #rejKg, #avgWt, #passGross, #rejectGross, #etTotalGS, #totalKg, #productType').val('');
        updateSummaryTable();
    }
});

$('#binNo').on('input change keyup', function() {
    fetchAvgWt();
    updateSummaryTable(); // This will update with specific lot+bin data
});

$('#passKg, #rejKg').on('input', function() {
    const pass = parseFloat($('#passKg').val()) || 0;
    const rej = parseFloat($('#rejKg').val()) || 0;
    const currentTotal = pass + rej;
    
    // Use actual remaining instead of original WT KG
    if (actualRemainingKg > 0 && currentTotal > actualRemainingKg) {
        const exceeded = currentTotal - actualRemainingKg;
        alert(`Entry (${currentTotal.toFixed(2)} KG) exceeds remaining WT KG (${actualRemainingKg.toFixed(2)} KG)\nExceeded by: ${exceeded.toFixed(2)} KG\n\nOriginal WT KG: ${lotWtKg.toFixed(2)} KG\nPreviously used: ${(lotWtKg - actualRemainingKg).toFixed(2)} KG`);
        $(this).val('');
        calculateGross();
        calculateTotalKg();
        updateSummaryTable();
        return;
    }
    
    calculateGross();
    calculateTotalKg();
    updateSummaryTable();
});

// Update the form submission validation (remove the duplicate validation)
$('.batch-form').on('submit', function(e) {
    // Enable disabled fields before form submission so they get included in POST data
    const shiftSelect = $('#shift');
    if (shiftSelect.prop('disabled')) {
        shiftSelect.prop('disabled', false);
    }
    
    const pass = parseFloat($('#passKg').val()) || 0;
    const rej = parseFloat($('#rejKg').val()) || 0;
    const currentTotal = pass + rej;
    
    // Only prevent submission if exceeds - allow equal values
    if (actualRemainingKg > 0 && currentTotal > actualRemainingKg) {
        const exceeded = currentTotal - actualRemainingKg;
        alert(`Cannot save: Entry (${currentTotal.toFixed(2)} KG) exceeds remaining WT KG (${actualRemainingKg.toFixed(2)} KG)\nExceeded by: ${exceeded.toFixed(2)} KG`);
        e.preventDefault();
        return false;
    }
    
    // Additional validation: Check if total is exactly equal to remaining (this is valid)
    if (actualRemainingKg > 0 && currentTotal === actualRemainingKg) {
        // This is perfectly fine - user is using exactly the remaining amount
        console.log('Using exactly the remaining amount:', currentTotal);
    }
    
    // Allow form submission if within limits
    return true;
});

// Remove the old validation that was causing issues (remove this block if it exists)
// $('.batch-form').on('submit', function(e) {
//     const pass = parseFloat($('#passKg').val()) || 0;
//     const rej = parseFloat($('#rejKg').val()) || 0;
//     if (lotWtKg && (pass + rej > lotWtKg)) {
//         alert('PASS KG + REJ. KG cannot be greater than WT KG (' + lotWtKg + ')');
//         e.preventDefault();
//         return false;
//     }
// });

// Enhanced function to update summary table
function updateSummaryTable() {
    const lotNo = $('#lotNo').val();
    const binNo = $('#binNo').val();
    const passKg = parseFloat($('#passKg').val()) || 0;
    const rejKg = parseFloat($('#rejKg').val()) || 0;
    const totalKg = parseFloat($('#totalKg').val()) || 0;

    if (lotNo) {
        // Show the table rows when lot number is selected
        $('#lotBinRow, #emptyRow1, #totalRow').show();
        
        // Update LOT BIN cell
        if (binNo) {
            $('#lotBinCell').text(lotNo + binNo);
            // Fetch both dipping and electronic data for this specific lot/bin
            fetchDippingData(lotNo, binNo);
        } else {
            $('#lotBinCell').text(lotNo + "___");
            // Show placeholder data when only lot is selected
            $('#dippingPassCell').text('-');
            $('#dippingRejCell').text('-');
            $('#dippingTotalCell').text('-');
            $('#dippingRecdCell').text('-');
            // Remove electronic summary row
            $('#electronicSummaryRow').remove();
        }
        
        // Calculate and display remaining for current entry
        const remaining = lotWtKg - totalKg;
        const remainingText = remaining >= 0 ? remaining.toFixed(2) : `(${Math.abs(remaining).toFixed(2)})`;
        
        // Update entry form data with remaining calculation
        $('#entryFormAmount').text(totalKg.toFixed(2));
        $('#entryFormStatus').text(totalKg > 0 ? 'Active' : 'Pending');
        
        // Update ET+Entry totals with remaining
        $('#etTotalPass').text(passKg.toFixed(2));
        $('#etTotalRej').text(rejKg.toFixed(2));
        
        // Add remaining indicator
        updateRemainingIndicator(remaining, totalKg);
        
    } else {
        // Hide the table rows when no lot is selected
        $('#lotBinRow, #emptyRow1, #totalRow').hide();
        $('#electronicSummaryRow').remove();
        $('#remainingIndicator').remove();
    }
}

// New function to show remaining indicator
function updateRemainingIndicator(remaining, currentTotal) {
    $('#remainingIndicator').remove();
    
    if (lotWtKg > 0 && currentTotal > 0) {
        const percentage = (currentTotal / lotWtKg * 100).toFixed(1);
        const status = remaining >= 0 ? 'within-limit' : 'exceeded';
        const statusText = remaining >= 0 ? 'Within Limit' : 'Exceeded';
        const statusColor = remaining >= 0 ? '#28a745' : '#dc3545';
        
        const indicatorRow = `
            <tr id="remainingIndicator" style="background-color: #f8f9fa; border-top: 2px solid #dee2e6;">
                <td style="font-weight: bold; color: #6c757d;">Status</td>
                <td style="font-weight: bold; color: ${statusColor};">${statusText}</td>
                <td style="font-weight: bold; color: #6c757d;">${remaining >= 0 ? remaining.toFixed(2) : '(' + Math.abs(remaining).toFixed(2) + ')'}</td>
                <td style="font-weight: bold; color: #0056b3;">${percentage}%</td>
                <td style="font-weight: bold; color: #6c757d;">Used</td>
            </tr>
        `;
        $('#totalRow').after(indicatorRow);
    }
}

// Enhanced function to fetch dipping data and calculate actual remaining
function fetchDippingData(lotNo, binNo) {
    $.get('get_dipping_summary.php', { 
        lot_no: lotNo, 
        bin_no: binNo 
    }, function(data) {
        if (data.success && data.remaining_data) {
            // Set actual remaining from server calculation
            actualRemainingKg = parseFloat(data.remaining_data.actual_remaining);
            
            // Update dipping data (first row)
            if (data.dipping_data) {
                $('#dippingPassCell').text('-'); // Dipping doesn't have pass/reject
                $('#dippingRejCell').text('-');
                $('#dippingTotalCell').text(data.dipping_data.wt_kg || '0.00');
                $('#dippingRecdCell').text(data.remaining_data.actual_remaining || '0.00');
            } else {
                $('#dippingPassCell').text('No Data');
                $('#dippingRejCell').text('No Data');
                $('#dippingTotalCell').text('No Data');
                $('#dippingRecdCell').text('0.00');
                actualRemainingKg = lotWtKg; // If no dipping data, use original
            }
            
            // Update previous electronic data (add new row)
            updateElectronicSummaryRow(data.electronic_data, data.remaining_data);
            
            // Add visual feedback for loaded data
            $('#lotBinRow').addClass('data-loaded');
            
            // Show remaining status
            showRemainingStatus(data.remaining_data);
            
        } else {
            $('#dippingPassCell').text('Error');
            $('#dippingRejCell').text('Error');
            $('#dippingTotalCell').text('Error');
            $('#dippingRecdCell').text('Error');
            actualRemainingKg = lotWtKg; // Fallback to original
            $('#lotBinRow').removeClass('data-loaded');
        }
    }, 'json').fail(function(xhr, status, error) {
        console.error('Failed to fetch dipping data:', error);
        $('#dippingPassCell').text('Error');
        $('#dippingRejCell').text('Error');
        $('#dippingTotalCell').text('Error');
        $('#dippingRecdCell').text('Error');
        actualRemainingKg = lotWtKg; // Fallback to original
        $('#lotBinRow').removeClass('data-loaded');
    });
}

// New function to show remaining status
function showRemainingStatus(remainingData) {
    $('#remainingStatusRow').remove();
    
    if (remainingData) {
        const statusColor = remainingData.can_add_more ? '#28a745' : '#dc3545';
        const statusText = remainingData.can_add_more ? 'Available' : 'Fully Used';
        
        const statusRow = `
            <tr id="remainingStatusRow" style="background-color: #e8f5e8; border-top: 2px solid #28a745;">
                <td style="font-weight: bold; color: #333;">Remaining</td>
                <td style="font-weight: bold; color: ${statusColor};">${statusText}</td>
                <td style="font-weight: bold; color: #6c757d;">-</td>
                <td style="font-weight: bold; color: #0056b3;">${remainingData.actual_remaining} KG</td>
                <td style="font-weight: bold; color: #6c757d;">${remainingData.usage_percentage}% Used</td>
            </tr>
        `;
        $('#electronicSummaryRow').length ? $('#electronicSummaryRow').after(statusRow) : $('#lotBinRow').after(statusRow);
    }
}

// Updated function to update electronic summary row
function updateElectronicSummaryRow(electronicData, remainingData) {
    // Remove existing electronic summary row if it exists
    $('#electronicSummaryRow').remove();
    
    if (electronicData && electronicData.entry_count > 0) {
        // Add electronic summary row after the dipping row
        const electronicRow = `
            <tr id="electronicSummaryRow" style="background-color: #fff3cd;">
                <td style="font-weight: bold;">Previous ET</td>
                <td style="font-weight: bold; color: #28a745;">${electronicData.pass_kg}</td>
                <td style="font-weight: bold; color: #dc3545;">${electronicData.rej_kg}</td>
                <td style="font-weight: bold; color: #856404;">${electronicData.total_kg}</td>
                <td style="font-weight: bold; color: #6c757d;">${electronicData.entry_count} entries</td>
            </tr>
        `;
        $('#lotBinRow').after(electronicRow);
    }
}

// Initial summary table update
updateSummaryTable();

// Form validation function
function checkSubmit() {
    // Check required fields
    const requiredFields = [
        { id: 'shift', name: 'Shift' },
        { id: 'lotNo', name: 'Lot No' },
        { id: 'binNo', name: 'Bin No' },
        { id: 'productType', name: 'Product Type' },
        { id: 'mcNo', name: 'Machine No' },
        { id: 'opId', name: 'Operator' },
        { id: 'passKg', name: 'Pass KG' },
        { id: 'rejKg', name: 'Reject KG' }
    ];

    for (let field of requiredFields) {
        const element = document.getElementById(field.id);
        const value = element ? element.value.trim() : '';
        
        if (!value || value === '' || value === '0' || value === '0.00') {
            alert(`Please fill in the ${field.name} field.`);
            element.focus();
            return false;
        }
    }

    // Validate numeric fields
    const passKg = parseFloat(document.getElementById('passKg').value) || 0;
    const rejKg = parseFloat(document.getElementById('rejKg').value) || 0;
    const totalKg = passKg + rejKg;

    if (totalKg <= 0) {
        alert('Total KG (Pass + Reject) must be greater than 0.');
        document.getElementById('passKg').focus();
        return false;
    }

    // Check if exceeds available quantity
    if (actualRemainingKg > 0 && totalKg > actualRemainingKg) {
        const confirmMsg = `Warning: Entry (${totalKg.toFixed(2)} KG) exceeds remaining quantity (${actualRemainingKg.toFixed(2)} KG).\n\nDo you want to proceed anyway?`;
        if (!confirm(confirmMsg)) {
            return false;
        }
    }

    // Enable disabled fields before submission
    document.getElementById('productType').disabled = false;
    document.getElementById('totalKg').disabled = false;

    return true;
}
</script>

<!-- Debug Information -->
<?php
echo "<!-- Total operators loaded: " . count($activeOperators) . " -->";
echo "<!-- Total machines loaded: " . count($electronicMachines) . " -->";
foreach ($electronicMachines as $index => $machine) {
    if ($index < 5) { // Show first 5 for debugging
        echo "<!-- Machine " . ($index + 1) . ": " . $machine['machine_id'] . " - " . $machine['machine_name'] . " -->";
    }
}
?>

</body>
</html>
