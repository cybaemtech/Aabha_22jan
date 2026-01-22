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
ob_start();
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

// Clean up any existing temporary records
$cleanupSql = "DELETE FROM store_entry_materials WHERE material_id = 'TEMP' OR material_id IS NULL OR material_id = ''";
sqlsrv_query($conn, $cleanupSql);

$delete_id = 0;
$edit_mode = false;
$store_entry_id = 0;
$gate_entry_id = 0;

// Check for edit mode from URL
if (isset($_GET['edit']) && isset($_GET['store_entry_id'])) {
    $edit_mode = true;
    $store_entry_id = intval($_GET['store_entry_id']);
    $gate_entry_id = intval($_GET['gate_entry_id'] ?? 0);
} else {
    $gate_entry_id = intval($_GET['gate_entry_id'] ?? 0);
}

// Handle POST request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['gate_entry_id'])) {
    $gate_entry_id = intval($_POST['gate_entry_id']);
    $store_entry_id = intval($_POST['store_entry_id'] ?? 0);
    $is_edit = isset($_POST['edit_mode']) && $_POST['edit_mode'] == 1 && $store_entry_id > 0;

    // Get form arrays
    $material_ids = $_POST['material_id'] ?? [];
    $material_descriptions = $_POST['material_description'] ?? [];
    $batch_numbers = $_POST['batch_number'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $units = $_POST['unit'] ?? [];
    $packages_received = $_POST['package_received'] ?? [];
    $material_types = $_POST['material_type'] ?? [];
    $accepted_quantities = $_POST['accepted_quantity'] ?? [];
    $rejected_quantities = $_POST['rejected_quantity'] ?? [];
    $remarks = $_POST['remark'] ?? [];

    $delete_id = 0; // Set default value for delete_id

    try {
        // Begin transaction
        sqlsrv_begin_transaction($conn);

        if ($is_edit) {
            // Delete existing materials for this store entry
            $deleteSql = "DELETE FROM store_entry_materials WHERE store_entry_id = ?";
            $deleteStmt = sqlsrv_query($conn, $deleteSql, [$store_entry_id]);
            if (!$deleteStmt) {
                throw new Exception("Failed to delete existing materials");
            }
            
            // Generate new store_entry_id for the update
            $newStoreEntryId = $store_entry_id;
        } else {
            // Generate new store_entry_id
            $maxIdSql = "SELECT MAX(store_entry_id) as max_id FROM store_entry_materials";
            $maxIdStmt = sqlsrv_query($conn, $maxIdSql);
            $maxIdRow = $maxIdStmt ? sqlsrv_fetch_array($maxIdStmt, SQLSRV_FETCH_ASSOC) : null;
            $newStoreEntryId = ($maxIdRow && $maxIdRow['max_id']) ? $maxIdRow['max_id'] + 1 : 1;
        }

        // Insert materials
        $hasValidData = false;
        for ($i = 0; $i < count($material_ids); $i++) {
            $material_id = trim($material_ids[$i] ?? '');
            $material_description = trim($material_descriptions[$i] ?? '');
            $batch_number = trim($batch_numbers[$i] ?? '');
            $quantity = floatval($quantities[$i] ?? 0);
            $unit = trim($units[$i] ?? '');
            $package_received = floatval($packages_received[$i] ?? 0);
            $material_type = trim($material_types[$i] ?? '');
            $accepted_quantity = floatval($accepted_quantities[$i] ?? 0);
            $rejected_quantity = floatval($rejected_quantities[$i] ?? 0);
            $remark = trim($remarks[$i] ?? '');

            // Skip empty rows
            if (empty($material_id) || empty($material_description)) {
                continue;
            }

            $hasValidData = true;

            $insertSql = "INSERT INTO store_entry_materials (
                store_entry_id, gate_entry_id, material_id, material_description, 
                batch_number, quantity, unit, package_received, material_type, 
                accepted_quantity, rejected_quantity, remark, delete_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insertParams = [
                $newStoreEntryId, $gate_entry_id, $material_id, $material_description,
                $batch_number, $quantity, $unit, $package_received, $material_type,
                $accepted_quantity, $rejected_quantity, $remark, $delete_id
            ];

            $insertStmt = sqlsrv_query($conn, $insertSql, $insertParams);
            if (!$insertStmt) {
                $errors = sqlsrv_errors();
                $errorMsg = $errors ? $errors[0]['message'] : 'Unknown database error';
                throw new Exception("Failed to insert material: " . $errorMsg);
            }
        }

        if (!$hasValidData) {
            throw new Exception("No valid material data to save");
        }

        // Commit transaction
        sqlsrv_commit($conn);

        // Redirect with success message
        if ($is_edit) {
            header("Location: StoreEntryLookup.php?msg=updated&store_entry_id=" . $newStoreEntryId);
        } else {
            header("Location: StoreEntryLookup.php?msg=saved&store_entry_id=" . $newStoreEntryId);
        }
        exit;

    } catch (Exception $e) {
        // Rollback transaction
        sqlsrv_rollback($conn);
        
        // Set error message
        $error_message = $e->getMessage();
        echo "<script>alert('Error saving store entry: " . addslashes($error_message) . "');</script>";
    }
}

// Fetch data for edit mode or new entry
if ($edit_mode && $store_entry_id > 0) {
    $sql = "SELECT se.*, g.entry_date, g.entry_time, g.invoice_number, g.invoice_date, g.vehicle_number, 
                   g.supplier_id, g.transporter, g.no_of_package as gate_no_of_package, s.supplier_name 
            FROM store_entry_materials se 
            JOIN gate_entries g ON se.gate_entry_id = g.id 
            LEFT JOIN suppliers s ON g.supplier_id = s.id 
            WHERE se.store_entry_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$store_entry_id]);
    $gateEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    $materialsInEntry = [];
    $stmt = sqlsrv_query($conn, "SELECT * FROM store_entry_materials WHERE store_entry_id = ?", [$store_entry_id]);
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $materialsInEntry[] = $row;
    }
} else {
    $gateEntry = [];
    if ($gate_entry_id) {
        $sql = "SELECT g.*, g.no_of_package AS gate_no_of_package, s.supplier_name, s.approve_status 
                FROM gate_entries g 
                LEFT JOIN suppliers s ON g.supplier_id = s.id 
                WHERE g.id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$gate_entry_id]);
        $gateEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }
    $materialsInEntry = [];
}

// Dropdown list for materials
$materialsArr = [];
$materialsResult = sqlsrv_query($conn, "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials");
while ($row = sqlsrv_fetch_array($materialsResult, SQLSRV_FETCH_ASSOC)) {
    $materialsArr[$row['material_id']] = $row;
}

// Add this after your existing PHP code and before the HTML
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'saved') {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                alert('Store Entry saved successfully!');
            });
        </script>";
    } elseif ($_GET['msg'] == 'updated') {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                alert('Store Entry updated successfully!');
            });
        </script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $edit_mode ? 'Edit' : 'Add' ?> Store Entry - AABHA MFG</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 250px;
            transition: margin-left 0.3s ease;
            padding: 20px;
            min-height: 100vh;
        }
        
        .main-content.sidebar-collapsed {
            margin-left: 80px;
        }
        
        .content-wrapper {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 0;
            overflow: hidden;
        }
        
        .page-header {
            color: black;
            padding: 25px 30px;
            margin: 0;
            border-radius: 15px 15px 0 0;
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
        
        .form-container {
            padding: 30px;
        }
        
        .gate-entry-card {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .gate-entry-card .card-header {
            background: linear-gradient(135deg, #1976d2 0%, #7b1fa2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            border: none;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .gate-entry-card .card-body {
            padding: 20px;
        }
        
        .info-row {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .info-value {
            color: #34495e;
            background: rgba(255,255,255,0.8);
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            min-width: 80px;
        }
        
        .controls-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        
        .add-row-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .add-row-input {
            width: 80px;
            border-radius: 6px;
            border: 1px solid #ced4da;
        }
        
        .btn-add-row {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            color: white;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-add-row:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 10px;
        }
        
        .table {
            margin: 0;
            font-size: 0.9rem;
        }
        
        .table thead th {
            color: black;
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            border: none;
            padding: 12px 8px;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        
        .table tbody tr {
            transition: background-color 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
            padding: 10px 8px;
            border-color: #e9ecef;
        }
        
        .form-control {
            border-radius: 6px;
            border: 1px solid #ced4da;
            padding: 8px 12px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            border-radius: 6px;
            padding: 6px 10px;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
            color: white;
        }
        
        .material-suggestions {
            min-width: 300px;
            max-width: 400px;
            border-radius: 6px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            border: 1px solid #dee2e6;
            background: white;
        }
        
        .material-suggestions .list-group-item {
            border: none;
            border-bottom: 1px solid #f1f3f4;
            padding: 10px 15px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .material-suggestions .list-group-item:hover {
            background-color: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            padding: 20px 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 12px 30px;
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
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }
        
        @keyframes fadeInRow {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .new-row {
            animation: fadeInRow 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .table {
                font-size: 0.8rem;
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
        <div class="content-wrapper">
            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="fas fa-<?= $edit_mode ? 'edit' : 'plus-circle' ?> me-2"></i>
                    <?= $edit_mode ? 'Edit' : 'Add' ?> Store Entry
                </h1>
                <p class="subtitle">
                    <?= $edit_mode ? 'Modify existing store entry details' : 'Create new store entry from gate entry' ?>
                </p>
            </div>

            <!-- Form Container -->
            <div class="form-container">
                <?php if ($edit_mode && $store_entry_id > 0): ?>
                    <form method="post" action="StoreEntry.php?edit=1&store_entry_id=<?= $store_entry_id ?>&gate_entry_id=<?= $gate_entry_id ?>">
                    <input type="hidden" name="edit_mode" value="1">
                    <input type="hidden" name="store_entry_id" value="<?= $store_entry_id ?>">
                <?php else: ?>
                    <form method="post" action="StoreEntry.php?gate_entry_id=<?= $gate_entry_id ?>">
                    <input type="hidden" name="edit_mode" value="0">
                    <input type="hidden" name="store_entry_id" value="0">
                <?php endif; ?>

                <input type="hidden" name="gate_entry_id" value="<?= htmlspecialchars($gate_entry_id) ?>">

                <!-- Gate Entry Details Card -->
                <?php if (!empty($gateEntry)): ?>
                <div class="card gate-entry-card">
                    <div class="card-header">
                        <i class="fas fa-info-circle me-2"></i>
                        Gate Entry Details (ID: <?= htmlspecialchars($gateEntry['id']) ?>)
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-calendar me-1"></i>Entry Date
                                    </div>
                                    <div class="info-value">
                                        <?= isset($gateEntry['entry_date']) ? htmlspecialchars($gateEntry['entry_date']->format('d-m-Y')) : '-' ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-clock me-1"></i>Entry Time
                                    </div>
                                    <div class="info-value">
                                        <?= isset($gateEntry['entry_time']) ? htmlspecialchars($gateEntry['entry_time']->format('H:i:s')) : '-' ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-file-invoice me-1"></i>Invoice Number
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($gateEntry['invoice_number'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-calendar-alt me-1"></i>Invoice Date
                                    </div>
                                    <div class="info-value">
                                        <?= isset($gateEntry['invoice_date']) ? htmlspecialchars($gateEntry['invoice_date']->format('d-m-Y')) : '-' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-truck me-1"></i>Vehicle Number
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($gateEntry['vehicle_number'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-shipping-fast me-1"></i>Transporter
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($gateEntry['transporter'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-building me-1"></i>Supplier
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($gateEntry['supplier_name'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-boxes me-1"></i>No. of Packages
                                    </div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($gateEntry['gate_no_of_package'] ?? '0') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($gateEntry['remark'])): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="info-row">
                                    <div class="info-label">
                                        <i class="fas fa-comment me-1"></i>Remark
                                    </div>
                                    <div class="info-value" style="width: 100%; margin-top: 5px;">
                                        <?= htmlspecialchars($gateEntry['remark']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Controls Section -->
                <div class="controls-section">
                    <div class="add-row-controls">
                        <label for="addRowCount" class="form-label mb-0">
                            <i class="fas fa-plus me-1"></i>Add Rows:
                        </label>
                        <input type="number" id="addRowCount" class="form-control add-row-input" min="1" value="1" placeholder="1">
                        <button type="button" class="btn btn-add-row" id="addRowBtn">
                            <i class="fas fa-plus me-2"></i>Add Rows
                        </button>
                    </div>
                </div>

                <!-- Materials Table -->
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="materialTable">
                            <thead>
                                <tr>
                                    <th style="width: 80px;">
                                        <i class="fas fa-trash me-1"></i>Action
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-barcode me-1"></i>Material ID
                                    </th>
                                    <th style="width: 200px;">
                                        <i class="fas fa-cube me-1"></i>Material Description
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-tag me-1"></i>Batch Number
                                    </th>
                                    <th style="width: 100px;">
                                        <i class="fas fa-calculator me-1"></i>Quantity
                                    </th>
                                    <th style="width: 80px;">
                                        <i class="fas fa-ruler me-1"></i>Unit
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-box me-1"></i>Packages Received
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-cog me-1"></i>Material Type
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-check-circle me-1"></i>Accepted Qty
                                    </th>
                                    <th style="width: 120px;">
                                        <i class="fas fa-times-circle me-1"></i>Rejected Qty
                                    </th>
                                    <th style="width: 150px;">
                                        <i class="fas fa-comment me-1"></i>Remark
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="materialRows">
                                <?php if (!empty($materialsInEntry)): ?>
                                    <?php foreach ($materialsInEntry as $entry): ?>
                                        <tr>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-delete btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                            <td>
                                                <input type="text" name="material_id_display[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['material_id'] ?? '') ?>" readonly>
                                                <input type="hidden" name="material_id[]" class="material-id-hidden" 
                                                       value="<?= $entry['material_id'] ?? '' ?>">
                                            </td>
                                            <td>
                                                <select name="material_select[]" class="form-control material-select" required style="width: 100%;">
                                                    <option value="">Select material...</option>
                                                    <?php foreach ($materialsArr as $mid => $mat): ?>
                                                        <option value="<?= htmlspecialchars($mid) ?>"
                                                            <?= ($entry['material_id'] ?? '') == $mid ? 'selected' : '' ?>
                                                            data-description="<?= htmlspecialchars($mat['material_description']) ?>"
                                                            data-unit="<?= htmlspecialchars($mat['unit_of_measurement']) ?>"
                                                            data-type="<?= htmlspecialchars($mat['material_type']) ?>">
                                                            <?= htmlspecialchars($mat['material_description']) ?> (<?= htmlspecialchars($mid) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="hidden" name="material_description[]" class="material-description-hidden"
                                                       value="<?= htmlspecialchars($entry['material_description'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <input type="text" name="batch_number[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['batch_number'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <input type="number" name="quantity[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['quantity'] ?? '') ?>" min="0" step="0.01">
                                            </td>
                                            <td>
                                                <input type="text" name="unit_display[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['unit'] ?? '') ?>" readonly>
                                                <input type="hidden" name="unit[]" value="<?= htmlspecialchars($entry['unit'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <input type="number" name="package_received[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['package_received'] ?? '') ?>" min="0" step="0.01">
                                            </td>
                                            <td>
                                                <input type="text" name="material_type_display[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['material_type'] ?? '') ?>" readonly>
                                                <input type="hidden" name="material_type[]" class="material-type-hidden" 
                                                       value="<?= htmlspecialchars($entry['material_type'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <input type="number" name="accepted_quantity[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['accepted_quantity'] ?? '') ?>" min="0" step="0.01">
                                            </td>
                                            <td>
                                                <input type="number" name="rejected_quantity[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['rejected_quantity'] ?? '') ?>" min="0" step="0.01" readonly>
                                            </td>
                                            <td>
                                                <input type="text" name="remark[]" class="form-control" 
                                                       value="<?= htmlspecialchars($entry['remark'] ?? '') ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Default empty row for new entry -->
                                    <tr>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-delete btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <input type="text" name="material_id_display[]" class="form-control" readonly>
                                            <input type="hidden" name="material_id[]" class="material-id-hidden">
                                        </td>
                                        <td>
                                            <select name="material_id[]" class="form-control material-select" required style="width: 100%;">
                                                <option value="">Select material...</option>
                                                <?php foreach ($materialsArr as $mid => $mat): ?>
                                                    <option value="<?= htmlspecialchars($mid) ?>"
                                                        data-description="<?= htmlspecialchars($mat['material_description']) ?>"
                                                        data-unit="<?= htmlspecialchars($mat['unit_of_measurement']) ?>"
                                                        data-type="<?= htmlspecialchars($mat['material_type']) ?>">
                                                        <?= htmlspecialchars($mat['material_description']) ?> (<?= htmlspecialchars($mid) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="material_description[]" class="material-description-hidden">
                                        </td>
                                        <td>
                                            <input type="text" name="batch_number[]" class="form-control" placeholder="Batch number">
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" class="form-control" min="0" step="0.01" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="text" name="unit_display[]" class="form-control" readonly>
                                            <input type="hidden" name="unit[]">
                                        </td>
                                        <td>
                                            <input type="number" name="package_received[]" class="form-control" min="0" step="0.01" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="text" name="material_type_display[]" class="form-control" readonly>
                                            <input type="hidden" name="material_type[]" class="material-type-hidden">
                                        </td>
                                        <td>
                                            <input type="number" name="accepted_quantity[]" class="form-control" min="0" step="0.01" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="number" name="rejected_quantity[]" class="form-control" min="0" step="0.01" readonly>
                                        </td>
                                        <td>
                                            <input type="text" name="remark[]" class="form-control" placeholder="Remark">
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button type="submit" name="update" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>
                        <?= $edit_mode ? 'Update' : 'Save' ?> Store Entry
                    </button>
                    <a href="StoreEntryLookup.php" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>

                <?php endif; ?>
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
        let rowCount = 0;

        // Add material row
        function addMaterialRow(materialData = {}) {
            rowCount++;
            const row = document.createElement('tr');
            row.classList.add('new-row');
            
            // Build options for the select dropdown
            let optionsHtml = '<option value="">Select material...</option>';
            Object.entries(materials).forEach(([materialId, material]) => {
                optionsHtml += `<option value="${materialId}" 
                    data-description="${material.material_description}" 
                    data-unit="${material.unit_of_measurement || ''}" 
                    data-type="${material.material_type || ''}">
                    ${material.material_description} (${materialId})
                </option>`;
            });
            
            row.innerHTML = `
                <td class="text-center">
                    <button type="button" class="btn btn-delete btn-sm">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <td>
                    <input type="text" name="material_id_display[]" class="form-control" readonly>
                    <input type="hidden" name="material_id[]" class="material-id-hidden">
                </td>
                <td>
                    <select name="material_select[]" class="form-control material-select" required style="width: 100%;">
                        ${optionsHtml}
                    </select>
                    <input type="hidden" name="material_description[]" class="material-description-hidden">
                </td>
                <td>
                    <input type="text" name="batch_number[]" class="form-control" placeholder="Batch number">
                </td>
                <td>
                    <input type="number" name="quantity[]" class="form-control" min="0" step="0.01" placeholder="0">
                </td>
                <td>
                    <input type="text" name="unit_display[]" class="form-control" readonly>
                    <input type="hidden" name="unit[]">
                </td>
                <td>
                    <input type="number" name="package_received[]" class="form-control" min="0" step="0.01" placeholder="0">
                </td>
                <td>
                    <input type="text" name="material_type_display[]" class="form-control" readonly>
                    <input type="hidden" name="material_type[]" class="material-type-hidden">
                </td>
                <td>
                    <input type="number" name="accepted_quantity[]" class="form-control" min="0" step="0.01" placeholder="0">
                </td>
                <td>
                    <input type="number" name="rejected_quantity[]" class="form-control" min="0" step="0.01" readonly>
                </td>
                <td>
                    <input type="text" name="remark[]" class="form-control" placeholder="Remark">
                </td>
            `;
            
            document.getElementById('materialRows').appendChild(row);
            
            // Initialize Select2 for the new row
            $(row).find('.material-select').select2({
                placeholder: "Select material...",
                allowClear: true,
                width: 'resolve'
            });
        }

        // Replace your existing jQuery ready function with this:
        $(document).ready(function() {
            // Initialize Select2 for existing material selects
            $('.material-select').select2({
                placeholder: "Select material...",
                allowClear: true,
                width: 'resolve'
            });

            // On material select, fill other fields
            $(document).on('change', '.material-select', function() {
                const $row = $(this).closest('tr');
                const selected = $(this).find('option:selected');
                const materialId = $(this).val();
                
                // Fill display and hidden fields
                $row.find('input[name="material_id_display[]"]').val(materialId);
                $row.find('.material-id-hidden').val(materialId);
                $row.find('.material-description-hidden').val(selected.data('description') || '');
                $row.find('input[name="unit_display[]"]').val(selected.data('unit') || '');
                $row.find('input[name="unit[]"]').val(selected.data('unit') || '');
                $row.find('input[name="material_type_display[]"]').val(selected.data('type') || '');
                $row.find('.material-type-hidden').val(selected.data('type') || '');
            });

            // Add row button event
            $('#addRowBtn').on('click', function() {
                const countInput = document.getElementById('addRowCount');
                let rowsToAdd = parseInt(countInput.value, 10) || 1;
                for (let i = 0; i < rowsToAdd; i++) {
                    addMaterialRow();
                }
            });
        });

        // Remove the old attachMaterialInputEvents function since we're using Select2 now

        // Auto-calculate rejected quantity
        document.addEventListener('input', function(e) {
            if (e.target && (e.target.name === 'package_received[]' || e.target.name === 'accepted_quantity[]')) {
                const tr = e.target.closest('tr');
                if (!tr) return;
                
                const pkgInput = tr.querySelector('input[name="package_received[]"]');
                const accInput = tr.querySelector('input[name="accepted_quantity[]"]');
                const rejInput = tr.querySelector('input[name="rejected_quantity[]"]');
                
                const pkg = parseFloat(pkgInput?.value) || 0;
                const acc = parseFloat(accInput?.value) || 0;
                
                if (rejInput) {
                    rejInput.value = Math.max(0, pkg - acc).toFixed(2);
                }
            }
        });

        // Event delegation for delete buttons
        $(document).on('click', '.btn-delete', function() {
            const tbody = document.getElementById('materialRows');
            if (tbody.children.length > 1) {
                $(this).closest('tr').fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert('At least one row is required.');
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            let hasValidMaterial = false;
            
            document.querySelectorAll('input[name="material_id[]"]').forEach(function(input, index) {
                const materialId = input.value.trim();
                const materialDesc = document.querySelectorAll('input[name="material_description[]"]')[index]?.value.trim();
                
                if (materialId && materialDesc) {
                    hasValidMaterial = true;
                }
            });
            
            if (!hasValidMaterial) {
                e.preventDefault();
                alert('Please add at least one valid material before saving.');
                return false;
            }
        });
    </script>
</body>
</html>


