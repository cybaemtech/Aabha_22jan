<?php
ob_start();
// ...existing code...
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



$gate_entry_id = '';
if (isset($_GET['gate_entry_id'])) {
    $gate_entry_id = intval($_GET['gate_entry_id']);
} elseif (isset($_POST['gate_entry_id'])) {
    $gate_entry_id = intval($_POST['gate_entry_id']);
}

// Fetch gate entry details
$gateEntry = null;
if ($gate_entry_id) {
    $sql = "SELECT g.*, s.supplier_name, s.approve_status 
            FROM gate_entries g 
            LEFT JOIN suppliers s ON g.supplier_id = s.id 
            WHERE g.id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$gate_entry_id]);
    if ($stmt) {
        $gateEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }
}

// Fetch dropdown data
$materialsArr = [];
$sql = "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials ORDER BY material_description";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $materialsArr[] = $row;
}

$usersArr = [];
$sql = "SELECT id, user_name FROM users";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $usersArr[] = $row;
}

// Fetch Checked By list only for GRN Entry menu
$checkedByList = [];
$sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'GRN Entry' AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $checkedByList[] = $row['grn_checked_by'];
}
$checkedByList = array_unique($checkedByList);

$verifiedByList = [];
$sql = "SELECT DISTINCT grn_verified_by FROM check_by WHERE menu = 'GRN Entry' AND grn_verified_by IS NOT NULL AND grn_verified_by != ''";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $verifiedByList[] = $row['grn_verified_by'];
}
$verifiedByList = array_unique($verifiedByList);

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grn_no'])) {
    // Duplicate GRN Number check
    $grn_no = trim($_POST['grn_no']);
    $checkSql = "SELECT COUNT(*) as cnt FROM grn_header WHERE grn_no = ?";
    $checkStmt = sqlsrv_query($conn, $checkSql, [$grn_no]);
    $checkRow = $checkStmt ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;
    if ($checkRow && $checkRow['cnt'] > 0) {
        echo "<script>alert('❌ GRN Number already exists. Please enter a unique GRN Number.'); window.history.back();</script>";
        exit;
    }

    // 1. Insert into grn_header
    $sql = "INSERT INTO grn_header (
        gate_entry_id, grn_date, grn_no, po_no, tear_damage_leak, damage_remark,
        labeling, labeling_remark, packing, packing_remark, cert_analysis, cert_analysis_remark,
        created_at, edit_id, delete_id, prepared_by
    ) OUTPUT INSERTED.grn_header_id
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DEFAULT, NULL, 0, ?)";
    $params = [
        intval($_POST['gate_entry_id'] ?? 0),
        $_POST['grn_date'] ?? null,
        $_POST['grn_no'] ?? '',
        $_POST['po_no'] ?? null,
        $_POST['tear_damage_leak'] ?? null,
        $_POST['damage_remark'] ?? null,
        $_POST['labeling'] ?? null,
        $_POST['labeling_remark'] ?? null,
        $_POST['packing'] ?? null,
        $_POST['packing_remark'] ?? null,
        $_POST['cert_analysis'] ?? null,
        $_POST['cert_analysis_remark'] ?? null,
        intval($_SESSION['user_id'] ?? 0) // <-- CORRECT: this is the integer id
    ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        die('Header insert failed: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $grnHeaderId = $row['grn_header_id'];

    if (!$grnHeaderId) {
        die('Failed to get GRN Header ID. Details not saved. Debug: ' . print_r($row, true));
    }

    // 2. Insert into grn_weight_details
    if (!empty($_POST['drum_number'])) {
        foreach ($_POST['drum_number'] as $i => $drumNo) {
            $sqlWeight = "INSERT INTO grn_weight_details (
                grn_header_id, drum_number, gross_weight, actual_weight, checked_by, verified_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, DEFAULT)";
            $paramsWeight = [
                $grnHeaderId,
                $drumNo,
                floatval($_POST['gross_weight'][$i] ?? 0),
                floatval($_POST['actual_weight'][$i] ?? 0),
                $_POST['weight_checked_by'][$i] ?? null,
                $_POST['weight_verified_by'][$i] ?? null
            ];
            $stmtWeight = sqlsrv_query($conn, $sqlWeight, $paramsWeight);
            if ($stmtWeight === false) {
                error_log(print_r(sqlsrv_errors(), true));
            }
        }
    }

    // 3. Insert into grn_quantity_details
    if (!empty($_POST['material_id'])) {
        foreach ($_POST['material_id'] as $i => $materialId) {
            $sqlQty = "INSERT INTO grn_quantity_details (
                grn_header_id, material_id, material, unit, material_type, batch_no, box_no,
                packing_details, ordered_qty, actual_qty, created_at, checked_by, verified_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DEFAULT, ?, ?)";

            $paramsQty = [
                $grnHeaderId,
                $materialId,
                $_POST['material_description'][$i] ?? '',
                $_POST['unit'][$i] ?? '',
                $_POST['material_type'][$i] ?? '',
                $_POST['batch_no'][$i] ?? '',
                $_POST['box_no'][$i] ?? '',
                $_POST['packing_details'][$i] ?? '',
                $_POST['ordered_qty'][$i] ?? '',
                $_POST['actual_qty'][$i] ?? '',
                $_POST['checked_by'][$i] ?? '',
                $_POST['verified_by'][$i] ?? ''
            ];
            $stmtQty = sqlsrv_query($conn, $sqlQty, $paramsQty);
            if ($stmtQty === false) {
                error_log(print_r(sqlsrv_errors(), true));
            }
        }
    }

    // Optionally store prepared_by and received_by in grn_header if you add columns
    // $preparedBy = $_POST['prepared_by'] ?? null;
    // $receivedBy = $_POST['received_by'] ?? null;

    header("Location: GRNEntryLookup.php?success=1");
    exit;
}

// Soft delete
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $sql = "UPDATE grn_header SET delete_id = 1 WHERE grn_header_id = ?";
    sqlsrv_query($conn, $sql, [$deleteId]);
    header("Location: GRNEntryLookup.php?deleted=1");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRN Entry - AABHA MFG</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
        }
        
        .form-container {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
        }
        
        .page-header h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .page-header .subtitle {
            margin: 5px 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .form-body {
            padding: 30px;
        }
        
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 25px 0 15px 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .section-header:first-child {
            margin-top: 0;
        }
        
        .gate-info-card {
            background: linear-gradient(135deg, #e8f4f8 0%, #f1e8f8 100%);
            border: 1px solid #d1ecf1;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            margin-bottom: 3px;
        }
        
        .info-value {
            background: rgba(255, 255, 255, 0.8);
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            color: #2c3e50;
        }
        
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #ced4da;
            padding: 8px 12px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .add-row-controls {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .add-row-input {
            width: 100px;
        }
        
        .btn-add-rows {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-add-rows:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            max-height: 400px;
            overflow: auto;
        }
        
        .table {
            margin: 0;
            font-size: 0.85rem;
        }
        
        .table thead th {
           
            color: black;
            font-weight: 600;
            text-align: center;
            padding: 10px 8px;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            font-size: 0.8rem;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
            padding: 8px;
        }
        
        .table input, .table select {
            font-size: 0.8rem;
            padding: 5px 8px;
            min-width: 80px;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        
        .btn-delete:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }
        
        /* Compact table columns */
        .col-action { width: 60px; }
        .col-id { width: 80px; }
        .col-material { width: 200px; }
        .col-unit { width: 60px; }
        .col-batch { width: 100px; }
        .col-box { width: 80px; }
        .col-qty { width: 80px; }
        .col-check { width: 120px; }
        .col-type { width: 100px; }
        
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .form-container {
                max-width: 100%;
            }
            
            .form-body {
                padding: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 1.4rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="form-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-file-alt me-2"></i>GRN Entry Form</h1>
                <p class="subtitle">Goods Receipt Note Entry & Verification</p>
            </div>

            <!-- Form Body -->
            <div class="form-body">
                <form method="post" action="">
                    <!-- Gate Entry Reference -->
                    <div class="section-header">
                        <i class="fas fa-link me-2"></i>Gate Entry Reference
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Gate Entry ID <span class="text-danger">*</span></label>
                            <input type="text" name="gate_entry_id" class="form-control" 
                                   value="<?= htmlspecialchars($gate_entry_id) ?>" required readonly>
                        </div>
                    </div>

                    <!-- Gate Entry Details -->
                    <?php if (!empty($gateEntry)): ?>
                    <div class="gate-info-card">
                        <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>Gate Entry Details</h6>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Entry Date</span>
                                <span class="info-value">
                                    <?= isset($gateEntry['entry_date']) ? htmlspecialchars($gateEntry['entry_date']->format('d-m-Y')) : '-' ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Invoice Number</span>
                                <span class="info-value"><?= htmlspecialchars($gateEntry['invoice_number'] ?? '-') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Supplier</span>
                                <span class="info-value"><?= htmlspecialchars($gateEntry['supplier_name'] ?? '-') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Vehicle Number</span>
                                <span class="info-value"><?= htmlspecialchars($gateEntry['vehicle_number'] ?? '-') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Packages</span>
                                <span class="info-value"><?= htmlspecialchars($gateEntry['no_of_package'] ?? '-') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Transporter</span>
                                <span class="info-value"><?= htmlspecialchars($gateEntry['transporter'] ?? '-') ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- GRN Header Information -->
                    <div class="section-header">
                        <i class="fas fa-clipboard me-2"></i>GRN Information
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">GRN Date <span class="text-danger">*</span></label>
                            <input type="date" name="grn_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">GRN Number <span class="text-danger">*</span></label>
                            <input type="text" name="grn_no" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PO Number</label>
                            <input type="text" name="po_no" class="form-control">
                        </div>
                    </div>

                    <!-- Visual Inspection -->
                    <div class="section-header">
                        <i class="fas fa-eye me-2"></i>Visual Inspection
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Tear/Damage/Leak</label>
                            <select class="form-select" name="tear_damage_leak">
                                <option value="">Select</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Damage Remark</label>
                            <input type="text" name="damage_remark" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Labeling</label>
                            <select class="form-select" name="labeling">
                                <option value="">Select</option>
                                <option value="Good">Good</option>
                                <option value="Average">Average</option>
                                <option value="Bad">Bad</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Labeling Remark</label>
                            <input type="text" name="labeling_remark" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Packing</label>
                            <select class="form-select" name="packing">
                                <option value="">Select</option>
                                <option value="Good">Good</option>
                                <option value="Average">Average</option>
                                <option value="Bad">Bad</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Packing Remark</label>
                            <input type="text" name="packing_remark" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Certificate Remark <span class="text-danger">*</span></label>
                            <select class="form-select" name="cert_analysis" required>
                                <option value="">Select</option>
                                <option value="Received" <?= (isset($_POST['cert_analysis']) && $_POST['cert_analysis'] == 'Received') ? 'selected' : '' ?>>Received</option>
                                <option value="Not Received" <?= (isset($_POST['cert_analysis']) && $_POST['cert_analysis'] == 'Not Received') ? 'selected' : '' ?>>Not Received</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Certificate Additional Remark</label>
                            <input type="text" name="cert_analysis_remark" class="form-control" value="<?= htmlspecialchars($_POST['cert_analysis_remark'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Item Weight Details -->
                    <div class="section-header">
                        <i class="fas fa-weight me-2"></i>Item Weight Details
                    </div>
                    
                    <div class="add-row-controls">
                        <label class="form-label mb-0"><i class="fas fa-plus me-1"></i>Add Weight Rows:</label>
                        <input type="number" id="weightRowCount" class="form-control add-row-input" value="1" min="1" max="20">
                        <button type="button" class="btn btn-add-rows" onclick="addWeightRow()">
                            <i class="fas fa-plus me-1"></i>Add Rows
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="clearWeightTable()">
                            <i class="fas fa-trash me-1"></i>Clear All
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="weightTable">
                                <thead>
                                    <tr>
                                        <th class="col-action"><i class="fas fa-trash"></i></th>
                                        <th class="col-batch"><i class="fas fa-drum me-1"></i>Drum No.</th>
                                        <th class="col-qty"><i class="fas fa-weight-hanging me-1"></i>Gross Wt. (kg)/No</th>
                                        <th class="col-qty"><i class="fas fa-balance-scale me-1"></i>Actual Wt. (kg)/No</th>
                                        <th class="col-check"><i class="fas fa-user-check me-1"></i>Checked By</th>
                                        <th class="col-check"><i class="fas fa-user-shield me-1"></i>Verified By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-delete btn-sm remove-weight-row" title="Remove Row">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <input type="text" name="drum_number[]" class="form-control form-control-sm" 
                                                   placeholder="Drum#" required>
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="gross_weight[]" 
                                                   class="form-control form-control-sm gross-weight" 
                                                   placeholder="0.000" required min="0">
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="actual_weight[]" 
                                                   class="form-control form-control-sm actual-weight" 
                                                   placeholder="0.000" required min="0">
                                        </td>
                                       
                                        <td>
                                            <select name="weight_checked_by[]" class="form-select form-select-sm" required>
                                                <option value="">Select</option>
                                                <?php foreach ($checkedByList as $checkedBy): ?>
                                                    <option value="<?= htmlspecialchars($checkedBy) ?>"><?= htmlspecialchars($checkedBy) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="weight_verified_by[]" class="form-select form-select-sm" required>
                                                <option value="">Select</option>
                                                <?php foreach ($verifiedByList as $verifiedBy): ?>
                                                    <option value="<?= htmlspecialchars($verifiedBy) ?>"><?= htmlspecialchars($verifiedBy) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Quantity Verification -->
                    <div class="section-header">
                        <i class="fas fa-calculator me-2"></i>Quantity Verification
                    </div>
                    
                    <div class="add-row-controls">
                        <label class="form-label mb-0"><i class="fas fa-plus me-1"></i>Add Material Rows:</label>
                        <input type="number" id="qtyRowCount" class="form-control add-row-input" value="1" min="1" max="50">
                        <button type="button" class="btn btn-add-rows" onclick="addQuantityRow()">
                            <i class="fas fa-plus me-1"></i>Add Rows
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="clearQuantityTable()">
                            <i class="fas fa-trash me-1"></i>Clear All
                        </button>
                    </div>
                    
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="quantityTable">
                                <thead>
                                    <tr>
                                        <th class="col-action"><i class="fas fa-trash"></i></th>
                                        <th class="col-id"><i class="fas fa-barcode me-1"></i>Mat. ID</th>
                                        <th class="col-material"><i class="fas fa-cube me-1"></i>Material Description</th>
                                        <th class="col-unit"><i class="fas fa-ruler me-1"></i>Unit</th>
                                        <th class="col-batch"><i class="fas fa-tag me-1"></i>Batch No</th>
                                        <th class="col-box"><i class="fas fa-box me-1"></i>Box No</th>
                                        <th class="col-material"><i class="fas fa-package me-1"></i>Packing Details</th>
                                        <th class="col-qty"><i class="fas fa-shopping-cart me-1"></i>Order Qty</th>
                                        <th class="col-qty"><i class="fas fa-check-circle me-1"></i>Actual Qty</th>
                                        <th class="col-qty"><i class="fas fa-minus-circle me-1"></i>Variance</th>
                                        <th class="col-check"><i class="fas fa-user-check me-1"></i>Checked By</th>
                                        <th class="col-check"><i class="fas fa-user-shield me-1"></i>Verified By</th>
                                        <th class="col-type"><i class="fas fa-cog me-1"></i>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-delete btn-sm remove-qty-row" title="Remove Row">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <input type="text" name="material_id[]" class="form-control form-control-sm material-id" readonly required style="background-color: #f8f9fa;">
                                        </td>
                                        <td>
                                            <select name="material[]" class="form-select form-select-sm material-select" required>
                                                <option value="">Select Material</option>
                                                <?php foreach ($materialsArr as $material): ?>
                                                    <option 
                                                        value="<?= htmlspecialchars($material['material_id']) ?>"
                                                        data-description="<?= htmlspecialchars($material['material_description']) ?>"
                                                        data-unit="<?= htmlspecialchars($material['unit_of_measurement']) ?>"
                                                        data-material_type="<?= htmlspecialchars($material['material_type']) ?>"
                                                    >
                                                        <?= htmlspecialchars($material['material_description']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="material_description[]" class="material-description">
                                        </td>
                                        <td>
                                            <input type="text" name="unit[]" class="form-control form-control-sm material-unit" readonly style="background-color: #f8f9fa;">
                                        </td>
                                        <td>
                                            <input type="text" name="batch_no[]" class="form-control form-control-sm" 
                                                   placeholder="Batch No">
                                        </td>
                                        <td>
                                            <input type="text" name="box_no[]" class="form-control form-control-sm" 
                                                   placeholder="Box No">
                                        </td>
                                        <td>
                                            <input type="text" name="packing_details[]" class="form-control form-control-sm" 
                                                   placeholder="Packing Details">
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="ordered_qty[]" 
                                                   class="form-control form-control-sm ordered-qty" 
                                                   placeholder="0.000" min="0">
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="actual_qty[]" 
                                                   class="form-control form-control-sm actual-qty" 
                                                   placeholder="0.000" min="0">
                                        </td>
                                        <td>
                                            <input type="text" name="qty_variance[]" 
                                                   class="form-control form-control-sm qty-variance" 
                                                   readonly style="background-color: #f8f9fa;">
                                        </td>
                                        <td>
                                            <select name="checked_by[]" class="form-select form-select-sm">
                                                <option value="">Select</option>
                                                <?php foreach ($checkedByList as $checker): ?>
                                                    <option value="<?= htmlspecialchars($checker) ?>"><?= htmlspecialchars($checker) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="verified_by[]" class="form-select form-select-sm">
                                                <option value="">Select</option>
                                                <?php foreach ($verifiedByList as $verifier): ?>
                                                    <option value="<?= htmlspecialchars($verifier) ?>"><?= htmlspecialchars($verifier) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                       
                                        <td>
                                            <input type="text" name="material_type[]" class="form-control form-control-sm material-type" 
                                                   readonly style="background-color: #f8f9fa;">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Prepared/Received By -->
                    <div class="section-header">
                        <i class="fas fa-users me-2"></i>Authorization
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Prepared By <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly>
                            <input type="hidden" name="prepared_by" value="<?= htmlspecialchars($_SESSION['operator_id'] ?? '') ?>">
                        </div>
                     
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Submit to QC
                        </button>
                        <a href="GRNEntryLookup.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                        <button type="reset" class="btn btn-outline-warning">
                            <i class="fas fa-undo me-2"></i>Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        const materials = <?= json_encode($materialsArr) ?>;
        const checkedByOptions = <?= json_encode($checkedByList) ?>;
        const verifiedByOptions = <?= json_encode($verifiedByList) ?>;
        // Initialize Select2 for material selects
        function initializeSelect2() {
            $('.material-select').select2({
                width: '100%',
                placeholder: "Select Material",
                allowClear: true
            });
        }
        
        // Add weight row function
        function addWeightRow() {
            const count = parseInt($('#weightRowCount').val()) || 1;
            const tbody = $('#weightTable tbody');
            for (let i = 0; i < count; i++) {
                const row = $(`
                    <tr>
                        <td class="text-center">
                            <button type="button" class="btn btn-delete btn-sm remove-weight-row" title="Remove Row">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                        <td>
                            <input type="text" name="drum_number[]" class="form-control form-control-sm" 
                                   placeholder="Drum#" required>
                        </td>
                        <td>
                            <input type="number" step="0.001" name="gross_weight[]" 
                                   class="form-control form-control-sm gross-weight" 
                                   placeholder="0.000" required min="0">
                        </td>
                        <td>
                            <input type="number" step="0.001" name="actual_weight[]" 
                                   class="form-control form-control-sm actual-weight" 
                                   placeholder="0.000" required min="0">
                        </td>
                        <td>
                            <select name="weight_checked_by[]" class="form-select form-select-sm" required>
                                <option value="">Select</option>
                                ${checkedByOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                            </select>
                        </td>
                        <td>
                            <select name="weight_verified_by[]" class="form-select form-select-sm" required>
                                <option value="">Select</option>
                                ${verifiedByOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                            </select>
                        </td>
                    </tr>
                `);
                tbody.append(row);
            }
        }
        
        // Add quantity row function
        function addQuantityRow() {
            const count = parseInt($('#qtyRowCount').val()) || 1;
            const tbody = $('#quantityTable tbody');
            for (let i = 0; i < count; i++) {
                const row = $(`
                    <tr>
                        <td class="text-center">
                            <button type="button" class="btn btn-delete btn-sm remove-qty-row" title="Remove Row">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                        <td>
                            <input type="text" name="material_id[]" class="form-control form-control-sm material-id" 
                                   readonly required style="background-color: #f8f9fa;">
                        </td>
                        <td>
                            <select name="material[]" class="form-select form-select-sm material-select" required>
                                <option value="">Select Material</option>
                                <?php foreach ($materialsArr as $material): ?>
                                    <option 
                                        value="<?= htmlspecialchars($material['material_id']) ?>"
                                        data-description="<?= htmlspecialchars($material['material_description']) ?>"
                                        data-unit="<?= htmlspecialchars($material['unit_of_measurement']) ?>"
                                        data-material_type="<?= htmlspecialchars($material['material_type']) ?>"
                                    >
                                        <?= htmlspecialchars($material['material_description']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="material_description[]" class="material-description">
                        </td>
                        <td>
                            <input type="text" name="unit[]" class="form-control form-control-sm material-unit" 
                                   readonly style="background-color: #f8f9fa;">
                        </td>
                        <td>
                            <input type="text" name="batch_no[]" class="form-control form-control-sm" 
                                   placeholder="Batch No">
                        </td>
                        <td>
                            <input type="text" name="box_no[]" class="form-control form-control-sm" 
                                   placeholder="Box No">
                        </td>
                        <td>
                            <input type="text" name="packing_details[]" class="form-control form-control-sm" 
                                   placeholder="Packing Details">
                        </td>
                        <td>
                            <input type="number" step="0.001" name="ordered_qty[]" 
                                   class="form-control form-control-sm ordered-qty" 
                                   placeholder="0.000" min="0">
                        </td>
                        <td>
                            <input type="number" step="0.001" name="actual_qty[]" 
                                   class="form-control form-control-sm actual-qty" 
                                   placeholder="0.000" min="0">
                        </td>
                        <td>
                            <input type="text" name="qty_variance[]" 
                                   class="form-control form-control-sm qty-variance" 
                                   readonly style="background-color: #f8f9fa;">
                        </td>
                        <td>
                            <select name="checked_by[]" class="form-select form-select-sm">
                                <option value="">Select</option>
                                ${checkedByOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                            </select>
                        </td>
                        <td>
                            <select name="verified_by[]" class="form-select form-select-sm">
                                <option value="">Select</option>
                                ${verifiedByOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                            </select>
                        </td>
                        <td>
                            <input type="text" name="material_type[]" class="form-control form-control-sm material-type" 
                                   readonly style="background-color: #f8f9fa;">
                        </td>
                    </tr>
                `);
                tbody.append(row);
                // Initialize Select2 for the new row
                row.find('.material-select').select2({
                    width: '100%',
                    placeholder: "Select Material",
                    allowClear: true
                });
            }       
        }
        
        // Clear tables
        function clearWeightTable() {
            if (confirm('Are you sure you want to clear all weight entries?')) {
                $('#weightTable tbody').empty();
                addWeightRow(); // Add one default row
            }
        }
        
        function clearQuantityTable() {
            if (confirm('Are you sure you want to clear all quantity entries?')) {
                $('#quantityTable tbody').empty();
                addQuantityRow(); // Add one default row
            }
        }
        
        $(document).ready(function() {
            // Initialize Select2
            initializeSelect2();
            
            // Material selection change handler
            $(document).on('change', '.material-select', function() {
                const selectedOption = $(this).find('option:selected');
                const row = $(this).closest('tr');
                row.find('.material-id').val(selectedOption.val() || '');
                row.find('.material-description').val(selectedOption.data('description') || '');
                row.find('.material-unit').val(selectedOption.data('unit') || '');
                row.find('.material-type').val(selectedOption.data('material_type') || '');
            });
            
            // Weight calculation
            $(document).on('input', '.gross-weight, .actual-weight', function() {
                const row = $(this).closest('tr');
                const gross = parseFloat(row.find('.gross-weight').val()) || 0;
                const actual = parseFloat(row.find('.actual-weight').val()) || 0;
                const difference = (gross - actual).toFixed(3);
                row.find('.weight-difference').val(difference);
            });
            
            // Quantity variance calculation
            $(document).on('input', '.ordered-qty, .actual-qty', function() {
                const row = $(this).closest('tr');
                const ordered = parseFloat(row.find('.ordered-qty').val()) || 0;
                const actual = parseFloat(row.find('.actual-qty').val()) || 0;
                const variance = (ordered - actual).toFixed(3);
                row.find('.qty-variance').val(variance);
            });
            
            // Remove row handlers
            $(document).on('click', '.remove-weight-row', function() {
                const tbody = $(this).closest('tbody');
                if (tbody.find('tr').length > 1) {
                    $(this).closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('At least one weight row is required.');
                }
            });
            
            $(document).on('click', '.remove-qty-row', function() {
                const tbody = $(this).closest('tbody');
                if (tbody.find('tr').length > 1) {
                    $(this).closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('At least one quantity row is required.');
                }
            });
        });
        
        // Form validation before submit
        $('form').on('submit', function(e) {
            let isValid = true;
            let errorMessage = '';

            // Check if at least one weight row has data
            let hasWeightData = false;
            $('#weightTable tbody tr').each(function() {
                const drumNo = $(this).find('input[name="drum_number[]"]').val().trim();
                if (drumNo) {
                    hasWeightData = true;
                    return false;
                }
            });

            if (!hasWeightData) {
                errorMessage += '• At least one weight entry is required.\n';
                isValid = false;
            }

            // Check if at least one quantity row has data
            let hasQtyData = false;
            $('#quantityTable tbody tr').each(function() {
                const materialId = $(this).find('.material-id').val().trim();
                if (materialId) {
                    hasQtyData = true;
                    return false;
                }
            });

            if (!hasQtyData) {
                errorMessage += '• At least one material entry is required.\n';
                isValid = false;
            }

            // Validate required dropdowns in weight table
            $('#weightTable tbody tr').each(function(index) {
                const checkedBy = $(this).find('select[name="weight_checked_by[]"]').val();
                const verifiedBy = $(this).find('select[name="weight_verified_by[]"]').val();
                if (!checkedBy) {
                    isValid = false;
                    errorMessage += `• Please select "Checked By" in weight row ${index + 1}.\n`;
                    $(this).find('select[name="weight_checked_by[]"]').focus();
                    return false;
                }
                if (!verifiedBy) {
                    isValid = false;
                    errorMessage += `• Please select "Verified By" in weight row ${index + 1}.\n`;
                    $(this).find('select[name="weight_verified_by[]"]').focus();
                    return false;
                }
            });

            // Validate required dropdowns in quantity table
            $('#quantityTable tbody tr').each(function(index) {
                const material = $(this).find('select[name="material[]"]').val();
                const checkedBy = $(this).find('select[name="checked_by[]"]').val();
                const verifiedBy = $(this).find('select[name="verified_by[]"]').val();
                if (!material) {
                    isValid = false;
                    errorMessage += `• Please select "Material Description" in material row ${index + 1}.\n`;
                    $(this).find('select[name="material[]"]').focus();
                    return false;
                }
                if (!checkedBy) {
                    isValid = false;
                    errorMessage += `• Please select "Checked By" in material row ${index + 1}.\n`;
                    $(this).find('select[name="checked_by[]"]').focus();
                    return false;
                }
                if (!verifiedBy) {
                    isValid = false;
                    errorMessage += `• Please select "Verified By" in material row ${index + 1}.\n`;
                    $(this).find('select[name="verified_by[]"]').focus();
                    return false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errorMessage);
                return false;
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
