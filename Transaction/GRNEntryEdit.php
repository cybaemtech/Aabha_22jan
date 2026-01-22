<?php
ob_start();
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

$materialsArr = [];
$sql = "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials ORDER BY material_description";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $materialsArr[] = $row;

$usersArr = [];
$sql = "SELECT id, user_name FROM users";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $usersArr[] = $row;

$checkedByList = $verifiedByList = [];
$sql = "SELECT DISTINCT grn_checked_by, grn_verified_by FROM check_by";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (!empty($row['grn_checked_by'])) $checkedByList[] = $row['grn_checked_by'];
    if (!empty($row['grn_verified_by'])) $verifiedByList[] = $row['grn_verified_by'];
}
$checkedByList = array_unique($checkedByList);
$verifiedByList = array_unique($verifiedByList);

// Fetch GRN data for edit
$grnData = [];
$weightDetails = [];
$quantityDetails = [];

if (isset($_GET['grn_header_id'])) {
    $editId = intval($_GET['grn_header_id']);

    // Fetch GRN header
    $sql = "SELECT * FROM grn_header WHERE grn_header_id = ?";
    $stmt = sqlsrv_prepare($conn, $sql, [$editId]);
    if (sqlsrv_execute($stmt)) {
        $grnData = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }

    // Fetch weight details
    $sql = "SELECT * FROM grn_weight_details WHERE grn_header_id = ?";
    $stmt = sqlsrv_prepare($conn, $sql, [$editId]);
    if (sqlsrv_execute($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $weightDetails[] = $row;
        }
    }

    // Fetch quantity details
    $sql = "SELECT * FROM grn_quantity_details WHERE grn_header_id = ?";
    $stmt = sqlsrv_prepare($conn, $sql, [$editId]);
    if (sqlsrv_execute($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $quantityDetails[] = $row;
        }
    }
}

// Handle update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grn_header_id'])) {
    $grnHeaderId = intval($_POST['grn_header_id']);
    $params = [
        $_POST['grn_date'] ?? '',
        $_POST['grn_no'] ?? '',
        $_POST['po_no'] ?? '',
        $_POST['tear_damage_leak'] ?? '',
        $_POST['damage_remark'] ?? '',
        $_POST['labeling'] ?? '',
        $_POST['labeling_remark'] ?? '',
        $_POST['packing'] ?? '',
        $_POST['packing_remark'] ?? '',
        $_POST['cert_analysis'] ?? '',
        $_POST['cert_analysis_remark'] ?? '',
        intval($_SESSION['operator_id'] ?? 0), // Always use session for prepared_by
        $grnHeaderId
    ];

    $sql = "UPDATE grn_header SET 
        grn_date=?, grn_no=?, po_no=?, tear_damage_leak=?, damage_remark=?, labeling=?, labeling_remark=?, 
        packing=?, packing_remark=?, cert_analysis=?, cert_analysis_remark=?, prepared_by=?, delete_id=0
        WHERE grn_header_id=?";
    sqlsrv_query($conn, $sql, $params);

    // Delete old details
    sqlsrv_query($conn, "DELETE FROM grn_weight_details WHERE grn_header_id = $grnHeaderId");
    sqlsrv_query($conn, "DELETE FROM grn_quantity_details WHERE grn_header_id = $grnHeaderId");

    // Insert weight details
    if (!empty($_POST['drum_number'])) {
        foreach ($_POST['drum_number'] as $i => $drumNo) {
            $gross = $_POST['gross_weight'][$i] ?? 0;
            $actual = $_POST['actual_weight'][$i] ?? 0;
            $checkedBy = $_POST['weight_checked_by'][$i] ?? '';
            $verifiedBy = $_POST['weight_verified_by'][$i] ?? '';

            $sql = "INSERT INTO grn_weight_details 
                (grn_header_id, drum_number, gross_weight, actual_weight, checked_by, verified_by)
                VALUES (?, ?, ?, ?, ?, ?)";
            $params = [$grnHeaderId, $drumNo, floatval($gross), floatval($actual), $checkedBy, $verifiedBy];
            sqlsrv_query($conn, $sql, $params);
        }
    }

    // Insert quantity details
    if (!empty($_POST['material'])) {
        foreach ($_POST['material'] as $i => $materialId) {
            if (empty($materialId)) continue;
            $params = [
                $grnHeaderId,
                $materialId,
                $_POST['material_description'][$i] ?? '',
                $_POST['unit'][$i] ?? '',
                $_POST['batch_no'][$i] ?? '',
                $_POST['box_no'][$i] ?? '',
                $_POST['packing_details'][$i] ?? '',
                $_POST['ordered_qty'][$i] ?? '',
                $_POST['actual_qty'][$i] ?? '',
                $_POST['checked_by'][$i] ?? '',
                $_POST['verified_by'][$i] ?? '',
                $_POST['material_type'][$i] ?? ''
            ];

            $sql = "INSERT INTO grn_quantity_details 
                (grn_header_id, material_id, material, unit, batch_no, box_no, packing_details, ordered_qty, actual_qty, checked_by, verified_by, material_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            sqlsrv_query($conn, $sql, $params);
        }
    }

    header("Location: GRNEntryLookup.php?updated=1");
    exit;
}

// Fetch gate entry details
$gateEntry = null;
if (!empty($grnData['gate_entry_id'])) {
    $sql = "SELECT g.*, s.supplier_name, s.approve_status 
            FROM gate_entries g 
            LEFT JOIN suppliers s ON g.supplier_id = s.id 
            WHERE g.id = ?";
    $stmt = sqlsrv_prepare($conn, $sql, [$grnData['gate_entry_id']]);
    if (sqlsrv_execute($stmt)) {
        $gateEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit GRN Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body, html { height: 100%; margin: 0; padding: 0; }
        .main-content { margin-left: 240px; padding: 30px; min-height: 100vh; background: #f4f6f8; }
        .section-title { background: #888; color: #fff; font-weight: bold; padding: 6px 10px; margin-bottom: 0; }
        .table-responsive { max-height: 350px; overflow-x:  auto; }
        .add-rows-container { display: flex; gap: 10px; align-items: center; margin-bottom: 1rem; }
        .add-rows-container input { max-width: 120px; }
        .table-bordered th, .table-bordered td { vertical-align: middle; }
        .table-striped tbody tr:nth-of-type(odd) { background-color: #f9f9f9; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; }
        .section-title { background: #555; color: #fff; font-weight: bold; padding: 8px 14px; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        .select2-dropdown { min-width: 300px !important; max-width: 600px; font-size: 1rem; }
        .quality-table-responsive { overflow-x: auto; width: 100%; }

        /* Add this to your existing styles */
        .debug-panel {
            position: fixed;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 9999;
            max-width: 300px;
        }
        .debug-panel h6 {
            color: #ffc107;
            margin-bottom: 5px;
        }

        @media print {
            body, html {
                background: #fff !important;
                color: #000 !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                box-shadow: none !important;
                width: 100vw !important;
                min-width: 100vw !important;
            }
            .card, .card-header, .card-body {
                background: #f8f9fa !important;
                color: #000 !important;
                border: 1px solid #bbb !important;
                box-shadow: none !important;
                margin-bottom: 10px !important;
                border-radius: 6px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .card-header {
                font-size: 1.1rem !important;
                font-weight: bold !important;
                background: #e9ecef !important;
                color: #222 !important;
                border-bottom: 1px solid #bbb !important;
                padding: 10px 16px !important;
            }
            .section-title {
                background: #444 !important;
                color: #fff !important;
                font-size: 1.1rem !important;
                font-weight: bold !important;
                padding: 8px 14px !important;
                margin-bottom: 0 !important;
                border-radius: 4px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .row, .mb-2, .mb-3, .mt-4 {
                margin: 0 !important;
                padding: 0 !important;
                page-break-inside: avoid !important;
            }
            .form-control, .form-select {
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
                color: #000 !important;
                font-weight: 600;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                min-width: 0 !important;
            }
            .btn, .add-rows-container, .mb-3.text-end, .btn-outline-primary, .btn-primary, .btn-danger, .btn-secondary, .remove-row, .select2, .select2-container {
                display: none !important;
            }
            table {
                width: 100% !important;
                page-break-inside: auto;
                border-collapse: collapse !important;
                margin-bottom: 10px !important;
            }
            th, td {
                border: 1px solid #000 !important;
                padding: 4px 8px !important;
                text-align: left !important;
                vertical-align: middle !important;
                font-size: 0.95rem !important;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            /* Hide scrollbars */
            .table-responsive, .quality-table-responsive {
                overflow: visible !important;
                max-height: none !important;
            }
        }
    </style>
</head>
<body>

<!-- Add this debug panel right after the body tag -->


<div class="main-content">
    <form method="post" action="">
        <input type="hidden" name="grn_header_id" value="<?= htmlspecialchars($grnData['grn_header_id']) ?>">

        <!-- Add this debug section after the form opening tag -->


        <!-- Gate Entry Details -->
        <?php if (!empty($gateEntry)): ?>
        <div class="card mb-3">
            <div class="card-header fw-bold">
                Gate Entry Details (ID: <?= htmlspecialchars($gateEntry['id']) ?>)
            </div>
            <div class="card-body">
               <div class="row mb-2">
    <div class="col-md-3"><b>Gate Entry Date:</b>
        <?= htmlspecialchars($gateEntry['entry_date'] instanceof DateTime ? $gateEntry['entry_date']->format('Y-m-d') : $gateEntry['entry_date']) ?>
    </div>
    <div class="col-md-3"><b>Gate Entry Time:</b>
        <?= htmlspecialchars($gateEntry['entry_time'] instanceof DateTime ? $gateEntry['entry_time']->format('H:i:s') : $gateEntry['entry_time']) ?>
    </div>
    <div class="col-md-3"><b>Invoice Number:</b>
        <?= htmlspecialchars($gateEntry['invoice_number']) ?>
    </div>
    <div class="col-md-3"><b>Invoice Date:</b>
        <?= htmlspecialchars($gateEntry['invoice_date'] instanceof DateTime ? $gateEntry['invoice_date']->format('Y-m-d') : $gateEntry['invoice_date']) ?>
    </div>
</div>

                <div class="row mb-2">
                    <div class="col-md-3"><b>Vehicle Number:</b> <?= htmlspecialchars($gateEntry['vehicle_number']) ?></div>
                    <div class="col-md-3"><b>Transporter:</b> <?= htmlspecialchars($gateEntry['transporter']) ?></div>
                    <div class="col-md-3"><b>Supplier:</b> <?= htmlspecialchars($gateEntry['supplier_name']) ?></div>
                    <div class="col-md-3"><b>Supplier ID:</b> <?= htmlspecialchars($gateEntry['supplier_id']) ?></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-3"><b>No. of Packages:</b> <?= htmlspecialchars($gateEntry['no_of_package']) ?></div>
                    <div class="col-md-3"><b>Approve Status:</b> <?= htmlspecialchars($gateEntry['approve_status'] ?? '') ?></div>
                    <div class="col-md-6"><b>Remark:</b> <?= htmlspecialchars($gateEntry['remark']) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- GRN Date, GRN Number, PO No -->
        <div class="row mb-2">
            <div class="col-md-4 mb-2">
                <label class="form-label">GRN Date</label>
                <input type="date" name="grn_date" class="form-control" required
                    value="<?= htmlspecialchars(isset($grnData['grn_date']) && $grnData['grn_date'] instanceof DateTime ? $grnData['grn_date']->format('Y-m-d') : $grnData['grn_date'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-2">
                <label class="form-label">GRN Number</label>
                <input type="text" name="grn_no" class="form-control" required
                    value="<?= htmlspecialchars($grnData['grn_no'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-2">
                <label class="form-label">PO No</label>
                <input type="text" name="po_no" class="form-control"
                    value="<?= htmlspecialchars($grnData['po_no'] ?? '') ?>">
            </div>
        </div>

        <!-- Visual Inspection -->
        <div class="mb-2 section-title">Visual Inspection</div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Tear/Damage/Leak</label>
                <select class="form-select" name="tear_damage_leak">
                    <option value="">Select</option>
                    <option value="Yes" <?= (isset($grnData['tear_damage_leak']) && $grnData['tear_damage_leak'] == 'Yes') ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= (isset($grnData['tear_damage_leak']) && $grnData['tear_damage_leak'] == 'No') ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Damage Remark</label>
                <input type="text" name="damage_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['damage_remark'] ?? '') ?>">
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Labeling</label>
                <select class="form-select" name="labeling">
                    <option value="">Select</option>
                    <option value="Good" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Good') ? 'selected' : '' ?>>Good</option>
                    <option value="Average" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Average') ? 'selected' : '' ?>>Average</option>
                    <option value="Bad" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Bad') ? 'selected' : '' ?>>Bad</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Labeling Remark</label>
                <input type="text" name="labeling_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['labeling_remark'] ?? '') ?>">
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Packing</label>
                <select class="form-select" name="packing">
                    <option value="">Select</option>
                    <option value="Good" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Good') ? 'selected' : '' ?>>Good</option>
                    <option value="Average" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Average') ? 'selected' : '' ?>>Average</option>
                    <option value="Bad" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Bad') ? 'selected' : '' ?>>Bad</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Packing Remark</label>
                <input type="text" name="packing_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['packing_remark'] ?? '') ?>">
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Certificate of Analysis</label>
                <select class="form-select" name="cert_analysis">
                    <option value="">Select</option>
                    <option value="Received" <?= (isset($grnData['cert_analysis']) && $grnData['cert_analysis'] == 'Received') ? 'selected' : '' ?>>Received</option>
                    <option value="Not Received" <?= (isset($grnData['cert_analysis']) && $grnData['cert_analysis'] == 'Not Received') ? 'selected' : '' ?>>Not Received</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Certificate of Analysis Remark</label>
                <input type="text" name="cert_analysis_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['cert_analysis_remark'] ?? '') ?>">
            </div>
        </div>

        <!-- Item Weight Details -->
        <div class="mb-2 section-title">Item Weight Details</div>
        <div class="add-rows-container mb-2">
            <input type="number" id="itemWeightRowCount" class="form-control d-inline-block" style="width:120px;" placeholder="No. of rows" min="1" value="1">
            <button type="button" class="btn btn-primary btn-sm" id="addWeightRowBtn">
                <i class="fas fa-plus"></i> Add Row
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered" id="weightDetailsTable">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Drum No.</th>
                        <th>Gross Weight</th>
                        <th>Actual Weight</th>
                        <th>Checked By</th>
                        <th>Verified By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weightDetails as $w): ?>
                    <tr>
                        <td>
                            <button type="button" class="btn btn-danger btn-sm remove-row"
                                <?php if (!empty($w['weight_id'])) echo 'disabled style="pointer-events:none;opacity:0.6;"'; ?>>
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                        <td><input type="text" name="drum_number[]" class="form-control" value="<?= htmlspecialchars($w['drum_number']) ?>" required></td>
                        <td><input type="number" step="0.01" name="gross_weight[]" class="form-control" value="<?= htmlspecialchars($w['gross_weight']) ?>" required></td>
                        <td><input type="number" step="0.01" name="actual_weight[]" class="form-control" value="<?= htmlspecialchars($w['actual_weight']) ?>" required></td>
                        <td>
                            <select name="weight_checked_by[]" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($checkedByList as $checkedBy): ?>
                                    <option value="<?= htmlspecialchars($checkedBy) ?>" <?= $w['checked_by'] == $checkedBy ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($checkedBy) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="weight_verified_by[]" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($verifiedByList as $verifiedBy): ?>
                                    <option value="<?= htmlspecialchars($verifiedBy) ?>" <?= $w['verified_by'] == $verifiedBy ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($verifiedBy) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Quantity Verification -->
        <div class="mb-2 section-title">Quantity Verification</div>
        <div class="add-rows-container mb-2">
            <input type="number" id="quantityRowCount" class="form-control d-inline-block" style="width:120px;" placeholder="No. of rows" min="1" value="1">
            <button type="button" class="btn btn-primary btn-sm" id="addQuantityRowBtn">
                <i class="fas fa-plus"></i> Add Row
            </button>
        </div>
        <div class="table-responsive quality-table-responsive" style="overflow-x:auto;">
            <table class="table table-bordered table-striped table-hover" style="min-width:1500px;">
                <thead class="table-dark">
                    <tr>
                        <th>Delete</th>
                        <th>Material ID</th>
                        <th>Material</th>
                        <th>Unit</th>
                        <th>Batch No</th>
                        <th>Drum/Bag/Box No</th>
                        <th>Packing Details</th>
                        <th>Ordered Qty</th>
                        <th>Actual Qty</th>
                        <th>Checked By</th>
                        <th>Verified By</th>
                        <th>Material Type</th>
                    </tr>
                </thead>
                <tbody id="quantityVerificationTableBody">
                    <?php foreach ($quantityDetails as $q): ?>
                    <tr>
                        <td>
                            <button type="button" class="btn btn-danger btn-sm remove-row"
                                <?php if (!empty($q['quantity_id'])) echo 'disabled style="pointer-events:none;opacity:0.6;"'; ?>>
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                        <td><input type="text" name="material_id[]" class="form-control material-id-input" value="<?= htmlspecialchars($q['material_id']) ?>" readonly required></td>
                        <td>
                            <select name="material[]" class="form-select material-select" required>
                                <option value="">Select Material</option>
                                <?php
                                $found = false;
                                foreach ($materialsArr as $material):
                                    $isSelected = (strval($q['material_id']) === strval($material['material_id']));
                                    if ($isSelected) $found = true;
                                ?>
                                    <option value="<?= htmlspecialchars($material['material_id']) ?>"
                                        data-description="<?= htmlspecialchars($material['material_description']) ?>"
                                        data-unit="<?= htmlspecialchars($material['unit_of_measurement']) ?>"
                                        data-material_type="<?= htmlspecialchars($material['material_type']) ?>"
                                        <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($material['material_description']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$found && !empty($q['material_id'])): ?>
                                    <option value="<?= htmlspecialchars($q['material_id']) ?>" selected
                                        data-description="<?= htmlspecialchars($q['material']) ?>"
                                        data-unit="<?= htmlspecialchars($q['unit']) ?>"
                                        data-material_type="<?= htmlspecialchars($q['material_type']) ?>">
                                        <?= htmlspecialchars($q['material']) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="material_description[]" class="material-description-hidden" value="<?= htmlspecialchars($q['material']) ?>">
                        </td>
                        <td><input type="text" name="unit[]" class="form-control" value="<?= htmlspecialchars($q['unit']) ?>" readonly></td>
                        <td><input type="text" name="batch_no[]" class="form-control" value="<?= htmlspecialchars($q['batch_no']) ?>"></td>
                        <td><input type="text" name="box_no[]" class="form-control" value="<?= htmlspecialchars($q['box_no']) ?>"></td>
                        <td><input type="text" name="packing_details[]" class="form-control" value="<?= htmlspecialchars($q['packing_details'] ?? '') ?>"></td>
                        <td><input type="number" step="0.01" name="ordered_qty[]" class="form-control" value="<?= htmlspecialchars($q['ordered_qty'] ?? '') ?>"></td>
                        <td><input type="number" step="0.01" name="actual_qty[]" class="form-control" value="<?= htmlspecialchars($q['actual_qty'] ?? '') ?>"></td>
                        <td>
                            <select name="checked_by[]" class="form-control">
                                <option value="">Select</option>
                                <?php foreach ($checkedByList as $checker): ?>
                                    <option value="<?= htmlspecialchars($checker); ?>" <?= $q['checked_by'] == $checker ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($checker); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="verified_by[]" class="form-control">
                                <option value="">Select</option>
                                <?php foreach ($verifiedByList as $verifier): ?>
                                    <option value="<?= htmlspecialchars($verifier); ?>" <?= $q['verified_by'] == $verifier ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($verifier); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="material_type[]" class="form-control" value="<?= htmlspecialchars($q['material_type'] ?? '') ?>" readonly></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Prepared/Received By -->
        <div class="row mt-4">
            <div class="col-md-6">
                <label for="prepared_by">Prepared By :</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly>
                <input type="hidden" name="prepared_by" value="<?= htmlspecialchars($_SESSION['operator_id'] ?? '') ?>">
                <small class="form-text text-muted">
                    Session ID: <?= htmlspecialchars($_SESSION['operator_id'] ?? 'Not set') ?>
                </small>
            </div>
        </div>
        <br>
        <div class="mb-3 text-end">
            <button type="submit" class="btn btn-primary">Update</button>
            <a href="GRNEntryLookup.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
    // Add Weight Row(s)
    $('#addWeightRowBtn').on('click', function () {
        let rowCount = parseInt($('#itemWeightRowCount').val()) || 1;
        for (let i = 0; i < rowCount; i++) {
            let newRow = `
                <tr>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm remove-row">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                    <td><input type="text" name="drum_number[]" class="form-control" required></td>
                    <td><input type="number" step="0.01" name="gross_weight[]" class="form-control" required></td>
                    <td><input type="number" step="0.01" name="actual_weight[]" class="form-control" required></td>
                    <td>
                        <select name="weight_checked_by[]" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($checkedByList as $checkedBy): ?>
                                <option value="<?= htmlspecialchars($checkedBy) ?>"><?= htmlspecialchars($checkedBy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="weight_verified_by[]" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($verifiedByList as $verifiedBy): ?>
                                <option value="<?= htmlspecialchars($verifiedBy) ?>"><?= htmlspecialchars($verifiedBy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            `;
            $('#weightDetailsTable tbody').append(newRow);
        }
    });

    // Add Quantity Row(s)
    $('#addQuantityRowBtn').on('click', function () {
        let rowCount = parseInt($('#quantityRowCount').val()) || 1;
        for (let i = 0; i < rowCount; i++) {
            let newRow = `
                <tr>
                    <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-trash-alt"></i></button></td>
                    <td><input type="text" name="material_id[]" class="form-control material-id-input" readonly required></td>
                    <td>
                        <select name="material[]" class="form-select material-select" required>
                            <option value="">Select Material</option>
                            <?php foreach ($materialsArr as $material): ?>
                                <option value="<?= htmlspecialchars($material['material_id']) ?>"
                                    data-description="<?= htmlspecialchars($material['material_description']) ?>"
                                    data-unit="<?= htmlspecialchars($material['unit_of_measurement']) ?>"
                                    data-material_type="<?= htmlspecialchars($material['material_type']) ?>">
                                    <?= htmlspecialchars($material['material_description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="material_description[]" class="material-description-hidden">
                    </td>
                    <td><input type="text" name="unit[]" class="form-control" readonly></td>
                    <td><input type="text" name="batch_no[]" class="form-control"></td>
                    <td><input type="text" name="box_no[]" class="form-control"></td>
                    <td><input type="text" name="packing_details[]" class="form-control"></td>
                    <td><input type="number" step="0.01" name="ordered_qty[]" class="form-control"></td>
                    <td><input type="number" step="0.01" name="actual_qty[]" class="form-control"></td>
                    <td>
                        <select name="checked_by[]" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($checkedByList as $checker): ?>
                                <option value="<?= htmlspecialchars($checker); ?>"><?= htmlspecialchars($checker); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="verified_by[]" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($verifiedByList as $verifier): ?>
                                <option value="<?= htmlspecialchars($verifier); ?>"><?= htmlspecialchars($verifier); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="material_type[]" class="form-control" readonly></td>
                </tr>
            `;
            $('#quantityVerificationTableBody').append(newRow);
            $('#quantityVerificationTableBody tr:last .material-select').select2({
                width: 'resolve',
                placeholder: "Select Material",
                allowClear: true
            });
        }
    });

    // Remove row handler
    $(document).on('click', '.remove-row', function () {
        $(this).closest('tr').remove();
    });

    // Auto-fill fields when material changes
    $(document).on('change', '.material-select', function () {
        let selected = $(this).find('option:selected');
        let $row = $(this).closest('tr');
        $row.find('.material-description-hidden').val(selected.data('description') || '');
        $row.find('input[name="unit[]"]').val(selected.data('unit') || '');
        $row.find('input[name="material_type[]"]').val(selected.data('material_type') || '');
        $row.find('input[name="material_id[]"]').val(selected.val() || '');
    });

    // Initialize Select2 for all material-select dropdowns on page load
    $('.material-select').select2({
        placeholder: "Select Material",
        allowClear: true,
        width: 'resolve'
    });
});

function printGRN() {
    var printContents = document.querySelector('.main-content').innerHTML;
    var originalContents = document.body.innerHTML;
    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>