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

// --- Fetch Dropdowns ---
$materialsArr = [];
$sql = "SELECT material_id, material_description, unit_of_measurement, material_type FROM materials ORDER BY material_description";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $materialsArr[] = $row;

$usersArr = [];
$sql = "SELECT id, user_name FROM users";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $usersArr[] = $row;

// --- Fetch Checked / Verified By ---
$checkedByList = [];
$verifiedByList = [];
$sql = "SELECT DISTINCT grn_checked_by, grn_verified_by FROM check_by";
$stmt = sqlsrv_query($conn, $sql);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (!empty($row['grn_checked_by'])) $checkedByList[] = $row['grn_checked_by'];
    if (!empty($row['grn_verified_by'])) $verifiedByList[] = $row['grn_verified_by'];
}
$checkedByList = array_unique($checkedByList);
$verifiedByList = array_unique($verifiedByList);

// --- Fetch GRN Data ---
$grnData = [];
$weightDetails = [];
$quantityDetails = [];
$editId = isset($_GET['grn_header_id']) ? intval($_GET['grn_header_id']) : 0;

if ($editId) {
    $stmt = sqlsrv_prepare($conn, "SELECT * FROM grn_header WHERE grn_header_id = ?", [$editId]);
    if (sqlsrv_execute($stmt)) {
        $grnData = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }

    $res = sqlsrv_query($conn, "SELECT * FROM grn_weight_details WHERE grn_header_id = $editId");
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $weightDetails[] = $row;

    $res = sqlsrv_query($conn, "SELECT * FROM grn_quantity_details WHERE grn_header_id = $editId");
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $quantityDetails[] = $row;
}

// --- POST: Save QC Data ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grn_header_id'])) {
    $success = true;
    $errors = [];
    $grnHeaderId = intval($_POST['grn_header_id']);
    $receivedBy = intval($_POST['received_by'] ?? 0);

    // Update received_by in grn_header
    $stmt = sqlsrv_prepare($conn, "UPDATE grn_header SET received_by = ? WHERE grn_header_id = ?", [$receivedBy, $grnHeaderId]);
    if (!sqlsrv_execute($stmt)) {
        throw new Exception("Failed to update Received By: " . print_r(sqlsrv_errors(), true));
    }

    $qcDate = date('Y-m-d');
    $qcDoneBy = $_SESSION['user_name'] ?? 'Unknown';
    $qcStatus = 'Completed';

    // Start transaction
    if (sqlsrv_begin_transaction($conn) === false) {
        die("Transaction could not be started: " . print_r(sqlsrv_errors(), true));
    }

    try {
        // Check if QC Header already exists
        $checkQCHeader = sqlsrv_prepare($conn, "SELECT COUNT(*) as count FROM qc_header WHERE grn_header_id = ?", [$grnHeaderId]);
        if (!sqlsrv_execute($checkQCHeader)) {
            throw new Exception("Failed to check QC header: " . print_r(sqlsrv_errors(), true));
        }
        $qcHeaderExists = sqlsrv_fetch_array($checkQCHeader, SQLSRV_FETCH_ASSOC)['count'] > 0;

        // Insert QC Header only if it doesn't exist
        if (!$qcHeaderExists) {
            $stmtQCHeader = sqlsrv_prepare($conn,
                "INSERT INTO qc_header (grn_header_id, qc_date, qc_done_by, qc_status, received_by) VALUES (?, ?, ?, ?, ?)",
                [$grnHeaderId, $qcDate, $qcDoneBy, $qcStatus, intval($_POST['received_by'] ?? 0)]
            );
            if (!sqlsrv_execute($stmtQCHeader)) {
                throw new Exception("QC Header insert failed: " . print_r(sqlsrv_errors(), true));
            }
        }

        // Handle QC Weight Details
        if (!empty($_POST['grn_weight_id']) && !empty($_POST['qc_weight_remark'])) {
            foreach ($_POST['grn_weight_id'] as $index => $grnWeightId) {
                $qcRemark = trim($_POST['qc_weight_remark'][$index] ?? '');
                
                if (!empty($qcRemark)) {
                    // Check if already exists
                    $checkStmt = sqlsrv_prepare($conn, "SELECT COUNT(*) as count FROM qc_weight_details WHERE grn_weight_id = ?", [$grnWeightId]);
                    if (!sqlsrv_execute($checkStmt)) {
                        throw new Exception("Failed to check weight details for ID: $grnWeightId");
                    }
                    $exists = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)['count'] > 0;
                    
                    if ($exists) {
                        // Update existing record
                        $stmt = sqlsrv_prepare($conn,
                            "UPDATE qc_weight_details SET remark = ? WHERE grn_weight_id = ?",
                            [$qcRemark, $grnWeightId]
                        );
                    } else {
                        // Insert new record
                        $stmt = sqlsrv_prepare($conn,
                            "INSERT INTO qc_weight_details (grn_weight_id, remark) VALUES (?, ?)",
                            [$grnWeightId, $qcRemark]
                        );
                    }
                    
                    if (!sqlsrv_execute($stmt)) {
                        throw new Exception("Weight details failed for ID: $grnWeightId - " . print_r(sqlsrv_errors(), true));
                    }
                }
            }
        }

        // Handle QC Quantity Details
        if (!empty($_POST['grn_quantity_id'])) {
            foreach ($_POST['grn_quantity_id'] as $index => $grnQuantityId) {
                $materialStatus = trim($_POST['material_status'][$index] ?? '');
                $materialRemark = trim($_POST['material_remark'][$index] ?? '');
                $arNo = trim($_POST['ar_no'][$index] ?? '');
                $acceptedQty = !empty($_POST['accepted_qty'][$index]) ? floatval($_POST['accepted_qty'][$index]) : null;

                // Skip if essential fields are empty
                if (empty($materialStatus) || empty($materialRemark)) {
                    continue;
                }

                // Validate AR No. for Accept/under_Deviation status
                if (($materialStatus === 'Accept' || $materialStatus === 'under_Deviation') && empty($arNo)) {
                    throw new Exception("AR No. is required for Accept/under_Deviation status for quantity ID: $grnQuantityId");
                }

                // Check for duplicate AR No. (only if AR No. is provided)
                if (!empty($arNo)) {
                    $checkAR = sqlsrv_prepare($conn, "SELECT COUNT(*) as count FROM qc_quantity_details WHERE ar_no = ? AND grn_quantity_id != ?", [$arNo, $grnQuantityId]);
                    if (!sqlsrv_execute($checkAR)) {
                        throw new Exception("Failed to check AR No. for: $arNo");
                    }
                    $arExists = sqlsrv_fetch_array($checkAR, SQLSRV_FETCH_ASSOC)['count'] > 0;
                    
                    if ($arExists) {
                        throw new Exception("AR No. $arNo already exists for another record!");
                    }
                }

                // Check if QC record already exists for this quantity ID
                $checkQCQty = sqlsrv_prepare($conn, "SELECT COUNT(*) as count FROM qc_quantity_details WHERE grn_quantity_id = ?", [$grnQuantityId]);
                if (!sqlsrv_execute($checkQCQty)) {
                    throw new Exception("Failed to check QC quantity for ID: $grnQuantityId");
                }
                $qcQtyExists = sqlsrv_fetch_array($checkQCQty, SQLSRV_FETCH_ASSOC)['count'] > 0;

                if ($qcQtyExists) {
                    // Update existing record
                    $stmt = sqlsrv_prepare($conn,
                        "UPDATE qc_quantity_details SET material_status = ?, material_remark = ?, ar_no = ?, accepted_qty = ? WHERE grn_quantity_id = ?",
                        [$materialStatus, $materialRemark, $arNo, $acceptedQty, $grnQuantityId]
                    );
                } else {
                    // Insert new record
                    $stmt = sqlsrv_prepare($conn,
                        "INSERT INTO qc_quantity_details (grn_quantity_id, material_status, material_remark, ar_no, accepted_qty) VALUES (?, ?, ?, ?, ?)",
                        [$grnQuantityId, $materialStatus, $materialRemark, $arNo, $acceptedQty]
                    );
                }

                if (!sqlsrv_execute($stmt)) {
                    throw new Exception("Quantity details failed for ID: $grnQuantityId - " . print_r(sqlsrv_errors(), true));
                }
            }
        }

        // Commit transaction
        sqlsrv_commit($conn);
        echo "<script>alert('QC Data saved successfully!'); window.location.href='QCPageLookup.php';</script>";
        exit;

    } catch (Exception $e) {
        // Rollback transaction
        sqlsrv_rollback($conn);
        echo "<script>alert('Update failed: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// --- Fetch Gate Entry Info ---
$gateEntry = null;
if (!empty($grnData['gate_entry_id'])) {
    $stmt = sqlsrv_prepare($conn,
        "SELECT g.*, s.supplier_name FROM gate_entries g 
         LEFT JOIN suppliers s ON g.supplier_id = s.id 
         WHERE g.id = ?",
        [$grnData['gate_entry_id']]
    );
    if (sqlsrv_execute($stmt)) {
        $gateEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }
}

// --- Fetch QC Maps ---
$qcWeightMap = [];
$res = sqlsrv_query($conn, "SELECT * FROM qc_weight_details WHERE grn_weight_id IN (SELECT weight_id FROM grn_weight_details WHERE grn_header_id = $editId)");
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $qcWeightMap[$row['grn_weight_id']] = $row;
}

$qcQuantityMap = [];
$res = sqlsrv_query($conn, "SELECT * FROM qc_quantity_details WHERE grn_quantity_id IN (SELECT quantity_id FROM grn_quantity_details WHERE grn_header_id = $editId)");
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $qcQuantityMap[$row['grn_quantity_id']] = $row;
}

// Fetch prepared by user name
$preparedByName = '';
if (!empty($grnData['prepared_by'])) {
    $stmt = sqlsrv_query($conn, "SELECT user_name FROM users WHERE id = ?", [$grnData['prepared_by']]);
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $preparedByName = $row['user_name'];
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
        input[disabled], select[disabled], textarea[disabled] {
            background-color: #e9ecef !important;
            color: #495057 !important;
            cursor: not-allowed !important;
        }

        /* Show scroll for overflowing input fields on focus */
        input:focus, textarea:focus {
            overflow-x: auto;
            white-space: nowrap;
            background-color: #fffbe7;
            outline: 2px solid #007bff;
        }

        /* Optional: Make input text fully visible on focus */
        input:focus, textarea:focus {
            background-color: #fffbe7;
            outline: 2px solid #007bff;
        }

        .ar-no-input,
        input[name="material_remark[]"],
        input[name="accepted_qty[]"] {
            min-width: 150px;
            max-width: 100%;
            font-family: 'Consolas', 'Monaco', 'monospace', Arial, sans-serif;
            font-size: 1rem;
            padding: 6px 10px;
            white-space: nowrap;
            overflow-x: auto;
        }
    </style>
</head>
<body>
<div class="main-content">
    <form method="post" action="">
        <input type="hidden" name="grn_header_id" value="<?= htmlspecialchars($grnData['grn_header_id']) ?>">
        <!-- ...Gate Entry Data, Visual Inspection, etc. (same as your edit form)... -->

        <!-- Gate Entry Details -->
        <?php if (!empty($gateEntry)): ?>
        <div class="card mb-3">
            <div class="card-header fw-bold">
                Gate Entry Details (ID: <?= htmlspecialchars($gateEntry['id']) ?>)
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <div class="col-md-3"><b>Gate Entry Date:</b>
    <?php
    if (!empty($gateEntry['entry_date'])) {
        if ($gateEntry['entry_date'] instanceof DateTime) {
            echo htmlspecialchars($gateEntry['entry_date']->format('Y-m-d'));
        } else {
            echo htmlspecialchars($gateEntry['entry_date']);
        }
    } else {
        echo '-';
    }
    ?>
</div>
                    <div class="col-md-3"><b>Gate Entry Time:</b>
    <?php
    if (!empty($gateEntry['entry_time'])) {
        if ($gateEntry['entry_time'] instanceof DateTime) {
            echo htmlspecialchars($gateEntry['entry_time']->format('H:i:s'));
        } else {
            echo htmlspecialchars($gateEntry['entry_time']);
        }
    } else {
        echo '-';
    }
    ?>
</div>
                    <div class="col-md-3"><b>Invoice Number:</b>
    <?= htmlspecialchars($gateEntry['invoice_number'] ?? '-') ?>
</div>
                    <div class="col-md-3"><b>Invoice Date:</b>
    <?php
    if (!empty($gateEntry['invoice_date'])) {
        if ($gateEntry['invoice_date'] instanceof DateTime) {
            echo htmlspecialchars($gateEntry['invoice_date']->format('Y-m-d'));
        } else {
            echo htmlspecialchars($gateEntry['invoice_date']);
        }
    } else {
        echo '-';
    }
    ?>
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
                    <div class="col-md-9"><b>Remark:</b> <?= htmlspecialchars($gateEntry['remark']) ?></div>
                </div>
            </div>
        </div>
          <?php endif; ?>

        <!-- GRN Header Data -->
        <div class="row mb-2">
            <div class="col-md-4 mb-2">
                <label class="form-label">GRN Date</label>
                <input type="date" name="grn_date" class="form-control" required
                    value="<?php 
                        if (!empty($grnData['grn_date'])) {
                            if ($grnData['grn_date'] instanceof DateTime) {
                                echo $grnData['grn_date']->format('Y-m-d');
                            } else {
                                echo htmlspecialchars($grnData['grn_date']);
                            }
                        }
                    ?>" disabled>
            </div>
            <div class="col-md-4 mb-2">
                <label class="form-label">GRN Number</label>
                <input type="text" name="grn_no" class="form-control" required
                    value="<?= htmlspecialchars($grnData['grn_no'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-4 mb-2">
                <label class="form-label">PO No</label>
                <input type="text" name="po_no" class="form-control"
                    value="<?= htmlspecialchars($grnData['po_no'] ?? '') ?>" disabled>
            </div>
        </div>

        <!-- Visual Inspection -->
        <div class="mb-2 section-title">Visual Inspection</div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Tear/Damage/Leak</label>
                <!-- Visual Inspection fields are pre-filled -->
                <select class="form-select" name="tear_damage_leak" disabled>
                    <option value="Yes" <?= (isset($grnData['tear_damage_leak']) && $grnData['tear_damage_leak'] == 'Yes') ? 'selected' : '' ?>>Yes</option>
                    <option value="No" <?= (isset($grnData['tear_damage_leak']) && $grnData['tear_damage_leak'] == 'No') ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Damage Remark</label>
                <input type="text" name="damage_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['damage_remark'] ?? '') ?>" disabled>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Labeling</label>
                <select class="form-select" name="labeling" disabled>
                    <option value="">Select</option>
                    <option value="Good" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Good') ? 'selected' : '' ?>>Good</option>
                    <option value="Average" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Average') ? 'selected' : '' ?>>Average</option>
                    <option value="Bad" <?= (isset($grnData['labeling']) && $grnData['labeling'] == 'Bad') ? 'selected' : '' ?>>Bad</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Labeling Remark</label>
                <input type="text" name="labeling_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['labeling_remark'] ?? '') ?>" disabled>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Packing</label>
                <select class="form-select" name="packing" disabled>
                    <option value="">Select</option>
                    <option value="Good" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Good') ? 'selected' : '' ?>>Good</option>
                    <option value="Average" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Average') ? 'selected' : '' ?>>Average</option>
                    <option value="Bad" <?= (isset($grnData['packing']) && $grnData['packing'] == 'Bad') ? 'selected' : '' ?>>Bad</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Packing Remark</label>
                <input type="text" name="packing_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['packing_remark'] ?? '') ?>" disabled>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label">Certificate of Analysis</label>
                <select class="form-select" name="cert_analysis" disabled>
                    <option value="">Select</option>
                    <option value="Received" <?= (isset($grnData['cert_analysis']) && $grnData['cert_analysis'] == 'Received') ? 'selected' : '' ?>>Received</option>
                    <option value="Not Received" <?= (isset($grnData['cert_analysis']) && $grnData['cert_analysis'] == 'Not Received') ? 'selected' : '' ?>>Not Received</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Certificate of Analysis Remark</label>
                <input type="text" name="cert_analysis_remark" class="form-control"
                    value="<?= htmlspecialchars($grnData['cert_analysis_remark'] ?? '') ?>" disabled>
            </div>
        </div>


        <!-- Item Weight Details -->
        <div class="mb-2 section-title">Item Weight Details</div>
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
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weightDetails as $w): 
                        $qc = $qcWeightMap[$w['weight_id']] ?? [];
                        $itemRemark = !empty($qc['remark']) ? $qc['remark'] : '';
                    ?>
                    <tr>
                        <td>
                            <button type="button" class="btn btn-danger btn-sm remove-row" disabled>
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                        <td>
                            <input type="text" name="drum_number[]" class="form-control" 
                                   value="<?= htmlspecialchars($w['drum_number']) ?>" disabled>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="gross_weight[]" class="form-control" 
                                   value="<?= htmlspecialchars($w['gross_weight']) ?>" disabled>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="actual_weight[]" class="form-control" 
                                   value="<?= htmlspecialchars($w['actual_weight']) ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="weight_checked_by[]" class="form-control" 
                                   value="<?= htmlspecialchars($w['checked_by'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="weight_verified_by[]" class="form-control" 
                                   value="<?= htmlspecialchars($w['verified_by'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="qc_weight_remark[]" class="form-control" 
                                   value="<?= htmlspecialchars($itemRemark) ?>">
                            <input type="hidden" name="grn_weight_id[]" value="<?= $w['weight_id'] ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Quantity Verification -->
        <div class="mb-2 section-title">Quantity Verification</div>
        <div class="table-responsive quality-table-responsive" style="overflow-x:auto;">
            <table class="table table-bordered table-striped table-hover" style="min-width:1500px;">
                <thead class="table-dark">
                    <tr>
                        <th>Delete</th>
                        <th>Material ID</th>
                        <th>Material</th>
                        <th>Unit</th>
                        <th >Batch No</th>
                        <th>Drum/Bag/Box No</th>
                        <th>Packing Details</th>
                        <th>Ordered Qty</th>
                        <th>Actual Qty</th>
                        <th>Accepted Qty</th>
                        <th>Checked By</th>
                        <th>Verified By</th>
                        <th>Material Type</th>
                        <th>Material Status</th>
                        <th>Remark</th>
                        <th>AR No.</th>
                    </tr>
                </thead>
                <tbody id="quantityVerificationTableBody">
                    <?php foreach ($quantityDetails as $q): 
                        $qc = $qcQuantityMap[$q['quantity_id']] ?? [];
                    ?>
                    <tr data-original-status="<?= isset($qc['material_status']) ? htmlspecialchars($qc['material_status']) : '' ?>">
                        <td>
                            <button type="button" class="btn btn-danger btn-sm remove-row" disabled>
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                        <td>
                            <input type="text" name="material_id[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['material_id']) ?>" readonly>
                        </td>
                        <td>
                            <input type="text" name="material_description[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['material']) ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="unit[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['unit'] ?? '') ?>" disabled>
                        </td>
                     <td>
    <div class="form-control-plaintext" style="font-weight:bold; color:#333;">
        <?= htmlspecialchars($q['batch_no'] ?? '-') ?>
    </div>
    <input type="hidden" name="batch_no[]" value="<?= htmlspecialchars($q['batch_no'] ?? '') ?>">
</td>
                        <td>
                            <input type="text" name="box_no[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['box_no'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="packing_details[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['packing_details'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="ordered_qty[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['ordered_qty'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="actual_qty[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['actual_qty'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="accepted_qty[]" class="form-control accepted-qty-input"
                                   value="<?= htmlspecialchars($qc['accepted_qty'] ?? '') ?>">
                        </td>
                        <td>
                            <input type="text" name="checked_by[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['checked_by'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="verified_by[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['verified_by'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <input type="text" name="material_type[]" class="form-control" 
                                   value="<?= htmlspecialchars($q['material_type'] ?? '') ?>" disabled>
                        </td>
                        <td>
                            <select name="material_status[]" class="form-select material-status-select" required>
                                <option value="">Select</option>
                                <option value="Accept" <?= (isset($qc['material_status']) && $qc['material_status'] == 'Accept') ? 'selected' : '' ?>>Accept</option>
                                <option value="under_Deviation" <?= (isset($qc['material_status']) && $qc['material_status'] == 'under_Deviation') ? 'selected' : '' ?>>under_Deviation</option>
                                <option value="Hold" <?= (isset($qc['material_status']) && $qc['material_status'] == 'Hold') ? 'selected' : '' ?>>Hold</option>
                                <option value="Reject" <?= (isset($qc['material_status']) && $qc['material_status'] == 'Reject') ? 'selected' : '' ?>>Reject</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="material_remark[]" class="form-control" 
                                   value="<?= htmlspecialchars($qc['material_remark'] ?? '') ?>" required>
                        </td>
                        <td>
                            <input 
        type="text" 
        name="ar_no[]" 
        class="form-control ar-no-input"
        value="<?= htmlspecialchars($qc['ar_no'] ?? '') ?>"
        placeholder="Enter AR No. (required for Accept/under_Deviation)"
        title="Enter AR No. (required for Accept/under_Deviation status)"
        <?php
        // Disable if status is not Accept/under_Deviation
        $status = $qc['material_status'] ?? '';
        if ($status !== 'Accept' && $status !== 'under_Deviation') echo 'disabled style="background-color:#e9ecef;cursor:not-allowed;"';
        ?>
    >
                        </td>
                        <input type="hidden" name="grn_quantity_id[]" value="<?= $q['quantity_id'] ?>">
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Prepared/Received By -->
        <div class="row mt-4">
            <div class="col-md-6">
                <label for="prepared_by">Prepared By :</label>
                <!-- Prepared By (readonly, not posted) -->
                <input type="text" class="form-control" value="<?= htmlspecialchars($preparedByName) ?>" readonly>
            </div>
            <div class="col-md-6">
                <label for="received_by">Received By :</label>
                <!-- Received By (readonly for display, but post user_id) -->
                <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly>
                <input type="hidden" name="received_by" value="<?= htmlspecialchars($_SESSION['user_id'] ?? '') ?>">
            </div>
        </div>
        <br>
        <div class="mb-3 text-end">
            <button type="submit" class="btn btn-primary">Update</button>
            <a href="QCPageLookup.php" class="btn btn-secondary">Cancel</a>
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
                    <td><input type="text" name="weight_remark[]" class="form-control"></td>
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
<td>
    <input type="text" name="batch_no[]" class="form-control" value="<?= htmlspecialchars($q['batch_no'] ?? '') ?>">
</td>                    <td><input type="text" name="box_no[]" class="form-control"></td>
                    <td><input type="text" name="packing_details[]" class="form-control"></td>
                    <td><input type="number" step="0.01" name="ordered_qty[]" class="form-control"></td>
                    <td><input type="number" step="0.01" name="actual_qty[]" class="form-control"></td>
                    <td>
                        <input type="number" step="0.01" name="accepted_qty[]" class="form-control accepted-qty-input">
                    </td>
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
                    <td>
                        <select name="material_status[]" class="form-select material-status-select" required>
                            <option value="">Select</option>
                            <option value="Accept">Accept</option>
                            <option value="under_Deviation">under_Deviation</option>
                            <option value="Hold">Hold</option>
                            <option value="Reject">Reject</option>
                        </select>
                    </td>
                    <td><input type="text" name="material_remark[]" class="form-control"></td>
                    <td><input type="text" name="ar_no[]" class="form-control ar-no-input"></td>
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

    // Disable all GRN fields (inputs/selects/textareas) except QC fields
    // For weight table
    $('#weightDetailsTable input:not([name="weight_remark[]"]):not([name="grn_weight_id[]"]), #weightDetailsTable select').prop('disabled', true);
    // For quantity table
    $('#quantityVerificationTableBody input:not([name="material_remark[]"]):not([name="ar_no[]"]):not([name="grn_quantity_id[]"]), #quantityVerificationTableBody select:not([name="material_status[]"])').prop('disabled', true);

    // Disable Add Row buttons
    $('#addWeightRowBtn, #addQuantityRowBtn').prop('disabled', true).css({
        'background-color': '#e9ecef',
        'color': '#495057',
        'cursor': 'not-allowed'
    });

    // Disable all delete (remove-row) buttons
    $('.remove-row').prop('disabled', true).css({
        'background-color': '#e9ecef',
        'color': '#495057',
        'cursor': 'not-allowed'
    });

    // Enable only the editable QC columns (should already be enabled, but ensure)
    $('input[name="qc_weight_remark[]"], select[name="material_status[]"], input[name="material_remark[]"], input[name="ar_no[]"]').prop('disabled', false).css({
        'background-color': '#fff',
        'color': '#212529',
        'cursor': 'auto'
    });

    // Enable the submit and cancel buttons
    $('button[type="submit"], a.btn-secondary').prop('disabled', false).css({
        'background-color': '',
        'color': '',
        'cursor': 'pointer'
    });

    // Enable AR No. only if Material Status is Accept
    function toggleArNoInput($row) {
        var status = $row.find('select[name="material_status[]"]').val();
        var $arNo = $row.find('input[name="ar_no[]"]');
        if (status === 'Accept' || status === 'under_Deviation') {
            $arNo.prop('disabled', false).css({
                'background-color': '#fff',
                'color': '#212529',
                'cursor': 'auto'
            });
        } else {
            $arNo.prop('disabled', true).val('').css({
                'background-color': '#e9ecef',
                'color': '#495057',
                'cursor': 'not-allowed'
            });
        }
    }

    // --- Overlap code to ensure correct disabling of Accepted Qty, AR No, Remark, and Material Status in edit mode ---

    function toggleQCFields($row) {
        var originalStatus = $row.data('original-status');
        var $status = $row.find('select[name="material_status[]"]');
        var $remark = $row.find('input[name="material_remark[]"]');
        var $arNo = $row.find('input[name="ar_no[]"]');
        var $acceptedQty = $row.find('input[name="accepted_qty[]"]');
        // If already stored as Accept or under_Deviation, disable all related fields
        if (originalStatus === 'Accept' || originalStatus === 'under_Deviation') {
            $status.prop('disabled', true).css({
                'background-color': '#e9ecef',
                'color': '#495057',
                'cursor': 'not-allowed'
            });
            $remark.prop('disabled', true).css({
                'background-color': '#e9ecef',
                'color': '#495057',
                'cursor': 'not-allowed'
            });
            $arNo.prop('disabled', true).css({
                'background-color': '#e9ecef',
                'color': '#495057',
                'cursor': 'not-allowed'
            });
            $acceptedQty.prop('disabled', true).css({
                'background-color': '#e9ecef',
                'color': '#495057',
                'cursor': 'not-allowed'
            });
        } else {
            $status.prop('disabled', false).css({
                'background-color': '#fff',
                'color': '#212529',
                'cursor': 'auto'
            });
            $remark.prop('disabled', false).css({
                'background-color': '#fff',
                'color': '#212529',
                'cursor': 'auto'
            });
            // AR No. and Accepted Qty enable only if status is Accept or under_Deviation
            if ($status.val() === 'Accept' || $status.val() === 'under_Deviation') {
                $arNo.prop('disabled', false).css({
                    'background-color': '#fff',
                    'color': '#212529',
                    'cursor': 'auto'
                });
                $acceptedQty.prop('disabled', false).css({
                    'background-color': '#fff',
                    'color': '#212529',
                    'cursor': 'auto'
                });
            } else {
                $arNo.prop('disabled', true).val('').css({
                    'background-color': '#e9ecef',
                    'color': '#495057',
                    'cursor': 'not-allowed'
                });
                $acceptedQty.prop('disabled', true).val('').css({
                    'background-color': '#e9ecef',
                    'color': '#495057',
                    'cursor': 'not-allowed'
                });
            }
        }
    }

    // Function to disable Item Weight Details remark if any material_status is Accept or under_Deviation
    function toggleWeightRemarkDisable() {
        let disableRemark = false;
        $('#quantityVerificationTableBody tr').each(function () {
            var originalStatus = $(this).data('original-status');
            if (originalStatus === 'Accept' || originalStatus === 'under_Deviation') {
                disableRemark = true;
                return false; // break loop
            }
        });
        // Disable or enable all Item Weight Details remark fields
        $('#weightDetailsTable input[name="qc_weight_remark[]"]').prop('disabled', disableRemark).css({
            'background-color': disableRemark ? '#e9ecef' : '#fff',
            'color': disableRemark ? '#495057' : '#212529',
            'cursor': disableRemark ? 'not-allowed' : 'auto'
        });
    }

    // Call on page load
    toggleWeightRemarkDisable();

    // Also call when material status changes
    $(document).on('change', 'select[name="material_status[]"]', function () {
        toggleWeightRemarkDisable();
    });

    // On page load, set QC field state for all rows
    $('#quantityVerificationTableBody tr').each(function () {
        toggleQCFields($(this));
    });

    // On change of material status, enable/disable QC fields
    $(document).on('change', 'select[name="material_status[]"]', function () {
        var $row = $(this).closest('tr');
        toggleQCFields($row);
    });

    $(document).on('input', 'input[name="accepted_qty[]"]', function () {
        var $row = $(this).closest('tr');
        var actualQty = parseFloat($row.find('input[name="actual_qty[]"]').val()) || 0;
        var acceptedQty = parseFloat($(this).val()) || 0;
        if (acceptedQty > actualQty) {
            alert('Accepted Qty cannot be greater than Actual Qty!');
            $(this).val(actualQty ? actualQty : '');
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Auto-select all text in input or textarea on focus
    document.querySelectorAll('input, textarea').forEach(function(el) {
        el.addEventListener('focus', function() {
            this.select();
        });
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>