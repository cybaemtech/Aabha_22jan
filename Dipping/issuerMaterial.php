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

$operator_id = $_SESSION['operator_id'];
$departmentName = '';
$departmentId = '';
$requestNo = 1;
$editRequest = []; // Initialize empty array
$successMsg = ''; // Initialize success message

// Fetch department info
$userQuery = sqlsrv_query($conn, "
    SELECT u.department_id, d.department_name, u.user_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.operator_id = ?", [$operator_id]);

if ($userQuery && ($user = sqlsrv_fetch_array($userQuery, SQLSRV_FETCH_ASSOC))) {
    $departmentName = $user['department_name'];
    $departmentId = $user['department_id'];
    $_SESSION['user_name'] = $user['user_name'];
}

// Generate request number
$requestResult = sqlsrv_query($conn, "SELECT COUNT(*) AS total FROM material_request_items");
if ($requestResult && ($row = sqlsrv_fetch_array($requestResult, SQLSRV_FETCH_ASSOC))) {
    $requestNo = (int)$row['total'] + 1;
}

// Fetch material master
$materials = [];
$matResult = sqlsrv_query($conn, "SELECT material_id, material_description, unit_of_measurement FROM materials");
while ($row = sqlsrv_fetch_array($matResult, SQLSRV_FETCH_ASSOC)) {
    $materials[] = $row;
}

// Fetch accepted_qty per material
$acceptedQtyMap = [];
$acceptedQtySql = "
    SELECT gq.material_id, m.material_description, SUM(ISNULL(qc.accepted_qty,0)) AS total_accepted_qty
    FROM grn_quantity_details gq
    LEFT JOIN qc_quantity_details qc ON gq.quantity_id = qc.grn_quantity_id
    LEFT JOIN materials m ON gq.material_id = m.material_id
    GROUP BY gq.material_id, m.material_description";
$res = sqlsrv_query($conn, $acceptedQtySql);
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $key = $row['material_id'] . '||' . $row['material_description'];
    $acceptedQtyMap[$key] = $row['total_accepted_qty'];
}

// Detect edit mode
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editItems = [];

if ($editId > 0) {
    $reqRes = sqlsrv_query($conn, "SELECT * FROM material_request_items WHERE material_request_id = ? ORDER BY id ASC", [$editId]);
    while ($row = sqlsrv_fetch_array($reqRes, SQLSRV_FETCH_ASSOC)) {
        $editItems[] = $row;
    }
    
    // Also fetch the main request details if needed
    $reqMainRes = sqlsrv_query($conn, "SELECT * FROM material_request WHERE id = ?", [$editId]);
    if ($reqMainRes && ($reqMain = sqlsrv_fetch_array($reqMainRes, SQLSRV_FETCH_ASSOC))) {
        $editRequest = $reqMain;
    }
}

// Fetch AR NO list
$arNoMap = [];
$arNoSql = "SELECT g.material_id, q.ar_no FROM qc_quantity_details q JOIN grn_quantity_details g ON q.grn_quantity_id = g.quantity_id WHERE q.ar_no IS NOT NULL AND q.ar_no != ''";
$arNoRes = sqlsrv_query($conn, $arNoSql);
while ($row = sqlsrv_fetch_array($arNoRes, SQLSRV_FETCH_ASSOC)) {
    $arNoMap[$row['material_id']][] = $row['ar_no'];
}

// Build AR NO to accepted_qty map
$arNoQtyMap = [];
$arNoQtySql = "SELECT ar_no, accepted_qty FROM qc_quantity_details WHERE ar_no IS NOT NULL AND ar_no != ''";
$arNoQtyRes = sqlsrv_query($conn, $arNoQtySql);
while ($row = sqlsrv_fetch_array($arNoQtyRes, SQLSRV_FETCH_ASSOC)) {
    $arNoQtyMap[$row['ar_no']] = $row['accepted_qty'];
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ar_no'])) {
    $issuer_ar_nos = $_POST['ar_no'];
    $issuer_issued_qtys = $_POST['issued_qty'];
    $issuer_short_issues = $_POST['short_issue'];
    $issuer_accepted_qtys = $_POST['accepted_qty_arno'];
    $issue_date = $_POST['issue_date'];
    $issued_by = $_POST['issued_by'];

    foreach ($issuer_ar_nos as $idx => $ar_no) {
        $material_id = '';
        foreach ($editItems as $item) {
            if (in_array($ar_no, $arNoMap[$item['material_id']] ?? [])) {
                $material_id = $item['material_id'];
                break;
            }
        }

        $insertParams = [
            $editId,
            $material_id,
            $ar_no,
            (float)$issuer_issued_qtys[$idx],
            (float)$issuer_short_issues[$idx],
            (float)$issuer_accepted_qtys[$idx],
            $issue_date,
            $issued_by
        ];
        $insertQuery = "
            INSERT INTO material_issuer_items
            (material_request_id, material_id, ar_no, issued_qty, short_issue, accepted_qty, issue_date, issued_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        sqlsrv_query($conn, $insertQuery, $insertParams);

        // Update accepted_qty
        $updateQuery = "UPDATE qc_quantity_details SET accepted_qty = accepted_qty - ? WHERE ar_no = ?";
        sqlsrv_query($conn, $updateQuery, [(float)$issuer_issued_qtys[$idx], $ar_no]);
    }

    $successMsg = 'Material request updated successfully!';
    echo "<script>alert('$successMsg'); window.location.href = 'issuerMaterialLookup.php';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>issuer Request Entry</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .card-header {
            background-color: #6c757d;
            color: white;
        }
        body, html { height: 100%; margin: 0; padding: 0; overflow: auto; }
        .main-content { 
            margin-left: 240px; /* Match your sidebar width */
            padding: 30px; 
            min-height: 100vh; 
            background: #f4f6f8; 
            transition: margin-left 0.3s;
        }
        .section-title { background: #888; color: #fff; font-weight: bold; padding: 6px 10px; margin-bottom: 0; }
        .material-suggestions {
            min-width: 300px !important;
            max-width: 400px;
            font-size: 1rem;
        }
        /* When sidebar is collapsed, adjust margin */
        body.sidebar-collapsed .main-content {
            margin-left: 60px; /* Or your collapsed sidebar width */
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="container-fluid">
         <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Issuer Request Entry</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label class="form-label">Request No</label>
                            <input type="text" name="material_request_id" class="form-control" value="<?= htmlspecialchars($editRequest['material_request_id'] ?? $requestNo) ?>" readonly>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($departmentName) ?>" readonly>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="form-label">Request Date</label>
                            <input type="date" name="request_date" class="form-control" value="<?= $editRequest ? htmlspecialchars($editRequest['request_date']) : date('Y-m-d') ?>" readonly>
                        </div>
                    </div>

                   
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center mb-0" id="materialTable">
                           <thead class="text-center" style="background-color:rgb(172, 174, 179);">
    <tr>
        <th>Action</th>
        <th>Sr. No.</th>
        <th>Material ID</th>
        <th>Material</th>
        <th>Unit</th>
        <th>Batch Number</th>
        <th>Request Quantity</th>
        <th>Actual Quantity Available</th>
        <th>Issued Quantity</th>
        <th>Short Issue</th>
       <th style="width: 150px;">AR NO</th>
        <th>Available Material</th>
    </tr>
</thead>

                            <tbody id="materialBody">
                                <?php if ($editItems): ?>
                                    <?php foreach ($editItems as $i => $item): ?>
                                        <tr class="text-center">
                                            <td>
                                                <?php if ($i === count($editItems) - 1): // Only last row gets the add icon ?>
                                                    <button type="button" class="btn btn-link text-success p-0 add-row-btn" title="Add Row">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </td>
                                            <td><?= $i + 1 ?></td>
                                            <td><input type="text" class="form-control" name="material_id[]" value="<?= htmlspecialchars($item['material_id']) ?>" readonly></td>
                                            <td><input type="text" class="form-control" name="description[]" value="<?= htmlspecialchars($item['description']) ?>" readonly></td>
                                            <td><input type="text" class="form-control" name="unit[]" value="<?= htmlspecialchars($item['unit']) ?>" readonly></td>
                                            <td>
                                                <?php
                                                // Example: If you have a $batchDetails array with batch_no as key
                                                $batchDisplay = $item['batch_no'];
                                                if (isset($batchDetails[$item['batch_no']])) {
                                                    $batchInfo = $batchDetails[$item['batch_no']];
                                                    $batchDisplay = $batchInfo['batch_no'] . " (" . $batchInfo['material'] . ", " . date('d-m-Y', strtotime($batchInfo['date'])) . ")";
                                                }
                                                ?>
                                                <input type="text" class="form-control" name="batch_no[]" value="<?= htmlspecialchars($batchDisplay) ?>" readonly style="width:150px;">
                                            </td>
                                            <td><input type="number" class="form-control" name="request_qty[]" value="<?= htmlspecialchars($item['request_qty']) ?>" readonly></td>
                                            <td><input type="text" class="form-control" name="available_qty[]" value="<?= htmlspecialchars($item['available_qty']) ?>" readonly></td>
                                            <!-- Issued Quantity -->
                                            <td>
                                                <input type="number" class="form-control" name="issued_qty[]" value="" oninput="updateShortIssue(<?= $i ?>)">
                                            </td>
                                            <!-- Short Issue -->
                                            <td>
                                                <input type="text" class="form-control" name="short_issue[]" value="" readonly>
                                            </td>
                                            <!-- AR NO -->
                                            <td>
                                                <select class="form-control ar-no-select" name="ar_no[<?= $i ?>]" style="width: 250px;" onchange="updateAcceptedQtySingle(this, <?= $i ?>)">
                                                    <option value="">-- Select AR NO --</option>
                                                    <?php
                                                    $matId = $item['material_id'];
                                                    if (isset($arNoMap[$matId])) {
                                                        foreach ($arNoMap[$matId] as $arNo) {
                                                            if (!empty($arNoQtyMap[$arNo]) && $arNoQtyMap[$arNo] > 1) { // Only show if accepted_qty > 1
                                                                echo '<option value="' . htmlspecialchars($arNo) . '">' . htmlspecialchars($arNo) . '</option>';
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                            <!-- Available Material (Accepted Qty for AR NO) -->
                                            <td>
                                                <input type="text" class="form-control accepted-qty-field" name="accepted_qty_arno[]" value="" readonly>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <br>
                     
                     <div class="form-row mt-4">
                        <div class="form-group col-md-4">
                            <label class="form-label">Request By Name</label>
                            <input type="text" name="request_by" class="form-control" value="<?= htmlspecialchars($editRequest['request_by'] ?? ($_SESSION['user_name'] ?? '')) ?>" readonly>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="form-label">Issued Date</label>
                            <input 
                                type="date" 
                                name="issue_date" 
                                class="form-control"
                                min="<?= htmlspecialchars($editRequest['request_date'] ?? date('Y-m-d')) ?>"
                                value="<?= htmlspecialchars($editRequest['issue_date'] ?? '') ?>"
                                required
                            >
                        </div>
                        <div class="form-group col-md-4">
                            <label class="form-label">Issued By Name</label>
                            <input 
                                type="text" 
                                name="issued_by" 
                                class="form-control" 
                                value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" 
                                readonly
                            >
                        </div>
                    </div>

                    <div class="text-right">

                        <button type="submit" class="btn btn-primary">Accept Request</button>
                    <button type="button" class="btn btn-secondary" id="backBtn" onclick="handleBackClick()">Back</button>

                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function handleBackClick() {
    var btn = document.getElementById('backBtn');
    // Change button style to grey (clicked effect)
    btn.style.backgroundColor = 'grey';
    btn.style.borderColor = 'grey';

    // Optional: Disable button to prevent multiple clicks
    btn.disabled = true;

    // Redirect after short delay (e.g., 200ms for visual effect)
    setTimeout(function () {
        window.location.href = 'issuerMaterialLookup.php';
    }, 200);
}
</script>
<!-- JS Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    // Prepare material data for JS
    const materials = <?= json_encode($materials) ?>;
    const acceptedQtyMap = <?= json_encode($acceptedQtyMap) ?>;
    const arNoMap = <?= json_encode($arNoMap) ?>;
    const arNoQtyMap = <?= json_encode($arNoQtyMap) ?>;

    function onMaterialChange(select, rowIdx) {
        const selectedDesc = select.value;
        const mat = materials.find(m => m.material_description === selectedDesc);
        if (mat) {
            document.getElementsByName('material_id[]')[rowIdx].value = mat.material_id;
            document.getElementsByName('unit[]')[rowIdx].value = mat.unit_of_measurement || '';

            // Update AR NO options
            const $arNoSelect = $('.ar-no-select').eq(rowIdx);
            $arNoSelect.empty().append('<option value="">-- Select AR NO --</option>');
            if (arNoMap[mat.material_id]) {
                arNoMap[mat.material_id].forEach(function(arNo) {
                    $arNoSelect.append(`<option value="${arNo}">${arNo}</option>`);
                });
            }
            $arNoSelect.val('').trigger('change');
            document.getElementsByName('available_qty[]')[rowIdx].value = '';
        } else {
            document.getElementsByName('material_id[]')[rowIdx].value = '';
            document.getElementsByName('unit[]')[rowIdx].value = '';
            document.getElementsByName('available_qty[]')[rowIdx].value = '';
        }
        // Always clear batch_no for manual entry
        document.getElementsByName('batch_no[]')[rowIdx].value = '';

        updateArNoOptions(rowIdx);
    }

    function addRow() {
        const tableBody = document.getElementById("materialBody");
        const rowCount = tableBody.rows.length;
        let materialOptions = '<option value="">-- Select Material --</option>';
        materials.forEach(mat => {
            materialOptions += `<option value="${mat.material_description}">${mat.material_description}</option>`;
        });

        let arNoOptions = '<option value="">-- Select AR NO --</option>';
        // AR NOs will be updated dynamically on material change

        const newRow = tableBody.insertRow();
        newRow.classList.add("text-center");
        newRow.innerHTML = `
            <td>
                <button type="button" class="btn btn-link text-success p-0 add-row-btn" title="Add Row">
                    <i class="fas fa-plus"></i>
                </button>
                <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
            <td>${rowCount + 1}</td>
            <td>
                <input type="text" class="form-control" name="material_id[]" readonly>
            </td>
            <td>
                <select class="form-control material-select2" name="description[]" style="width: 220px;" onchange="onMaterialChange(this, ${rowCount})">
                    ${materialOptions}
                </select>
            </td>
            <td>
                <input type="text" class="form-control" name="unit[]" readonly>
            </td>
            <td>
                <input type="text" class="form-control" name="batch_no[]" maxlength="10" style="width:120px;">
            </td>
            <td>
                <input type="number" class="form-control" name="request_qty[]" oninput="updateShortIssue(${rowCount})">
            </td>
            <td>
                <input type="text" class="form-control" name="available_qty[]" readonly>
            </td>
            <td>
                <input type="number" class="form-control" name="issued_qty[]" oninput="updateShortIssue(${rowCount})">
            </td>
            <td>
                <input type="text" class="form-control" name="short_issue[]" readonly>
            </td>
            <td>
                <select class="form-control ar-no-select" name="ar_no[${rowCount}]" style="width: 250px;" onchange="updateAcceptedQtySingle(this, ${rowCount})">
                    ${arNoOptions}
                </select>
            </td>
            <td>
                <input type="text" class="form-control accepted-qty-field" name="accepted_qty_arno[]" value="" readonly>
            </td>
        `;

        // Initialize Select2 for new selects
        $(newRow).find('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
        $(newRow).find('.ar-no-select').select2({
            placeholder: "Select AR NO",
            allowClear: true,
            width: '250px'
        });
    }

    // Update first row on page load
    document.addEventListener('DOMContentLoaded', function() {
        const firstSelect = document.querySelector('select[name="description[]"]');
        if (firstSelect) {
            firstSelect.addEventListener('change', function() { onMaterialChange(this, 0); });
        }
    });

    // Add this JS to handle row deletion by icon
    $(document).on('click', '.delete-row-btn', function() {
    const row = $(this).closest('tr');
    row.remove();
    // Re-index Sr. No.
    $('#materialBody tr').each(function(i, tr) {
        $(tr).find('td').eq(1).text(i + 1);
    });
    // Ensure only last row has add icon
    $('#materialBody .add-row-btn').remove();
    $('#materialBody tr:last td:first').prepend(
        '<button type="button" class="btn btn-link text-success p-0 add-row-btn" title="Add Row"><i class="fas fa-plus"></i></button>'
    );
});

    // Initialize Select2
    $(document).ready(function() {
        $('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
        // Initialize Select2 with checkboxes for AR NO
        $('.ar-no-select').select2({
            placeholder: "Select AR NO",
            allowClear: true,
            width: '250px'
        });
    });

    // Update short_issue when request_qty or issued_qty changes
    $(document).on('input', 'input[name="request_qty[]"], input[name="issued_qty[]"]', function() {
        const $row = $(this).closest('tr');
        const rowIdx = $row.index();
        updateShortIssue(rowIdx);
    });

    // Calculate and display short_issue as request_qty - issued_qty
    function updateShortIssue(rowIdx) {
        const reqQtyInputs = document.getElementsByName('request_qty[]');
        const issuedQtyInputs = document.getElementsByName('issued_qty[]');
        const shortIssueInputs = document.getElementsByName('short_issue[]');
        const reqQty = parseFloat(reqQtyInputs[rowIdx]?.value) || 0;
        const issuedQty = parseFloat(issuedQtyInputs[rowIdx]?.value) || 0;
        shortIssueInputs[rowIdx].value = reqQty - issuedQty;
    }

    function updateAcceptedQtySingle(select, rowIdx) {
        const arNo = select.value;
        const qty = arNoQtyMap[arNo] ? arNoQtyMap[arNo] : '';
        const $row = $(select).closest('tr');
        $row.find('.accepted-qty-field').val(qty).prop('readonly', true);
        $row.find('input[name="issued_qty[]"]').attr('max', qty ? qty : '');
        const issuedInput = $row.find('input[name="issued_qty[]"]');
        if (qty && parseFloat(issuedInput.val()) > parseFloat(qty)) {
            issuedInput.val(qty);
        }
    }

    // Update accepted_qty field based on selected AR NO(s)
    function updateAcceptedQty(select, rowIdx) {
        let total = 0;
        const selected = $(select).val() || [];
        selected.forEach(function(arNo) {
            if (arNoQtyMap[arNo]) {
                total += parseFloat(arNoQtyMap[arNo]) || 0;
            }
        });
        // Find the accepted-qty field in the same row
        const $row = $(select).closest('tr');
        $row.find('.accepted-qty-field').val(total);
    }

    // --- Update Available Material (accepted-qty-field) and restrict Issued Quantity ---
function updateAcceptedQtySingle(select, rowIdx) {
    const arNo = select.value;
    const qty = arNoQtyMap[arNo] ? arNoQtyMap[arNo] : '';
    // Set accepted-qty-field to accepted_qty for AR NO (readonly)
    const $row = $(select).closest('tr');
    $row.find('.accepted-qty-field').val(qty).prop('readonly', true);
    // Set max for Issued Quantity
    $row.find('input[name="issued_qty[]"]').attr('max', qty ? qty : '');
    // If issued qty is greater than accepted_qty, reset it
    const issuedInput = $row.find('input[name="issued_qty[]"]');
    if (qty && parseFloat(issuedInput.val()) > parseFloat(qty)) {
        issuedInput.val(qty);
    }
}

// Prevent Issued Quantity from exceeding Available Material
$(document).on('input', 'input[name="issued_qty[]"]', function() {
    const $row = $(this).closest('tr');
    const issuedQty = parseFloat($(this).val()) || 0;
    const maxQty = parseFloat($row.find('.accepted-qty-field').val()) || 0;
    if (maxQty && issuedQty > maxQty) {
        alert('Issued Quantity cannot be greater than Available Material!');
        $(this).val(maxQty);
    }
    updateAllShortIssues();
});

    function updateAcceptedQtySingle(select, rowIdx) {
        const arNo = select.value;
        const qty = arNoQtyMap[arNo] ? arNoQtyMap[arNo] : '';
        // Set the accepted-qty-field value in the same row
        const $row = $(select).closest('tr');
        $row.find('.accepted-qty-field').val(qty);
    }

    function updateAcceptedQtyDetails(select, rowIdx) {
        const selected = $(select).val() || [];
        let html = '';
        let total = 0;
        selected.forEach(function(arNo) {
            const qty = arNoQtyMap[arNo] ? arNoQtyMap[arNo] : 0;
            html += `<div><b>AR NO:</b> ${arNo} &nbsp; <b>Accepted Qty:</b> ${qty}</div>`;
            total += parseFloat(qty) || 0;
        });
        // Show the list below the select
        $(select).siblings('.arno-accepted-qty-list').html(html);
        // Set the accepted-qty-field value in the same row
        const $row = $(select).closest('tr');
        $row.find('.accepted-qty-field').val(total ? total.toFixed(3) : '');
    }

    // Initialize on page load for already selected AR NOs
    $(document).ready(function() {
        $('.ar-no-select').each(function(idx, select) {
        updateAcceptedQtySingle(select, idx);
    });
    $(document).on('change', '.ar-no-select', function() {
        const idx = $(this).closest('tr').index();
        updateAcceptedQtySingle(this, idx);
    });
    });

    function updateArNoOptions(rowIdx) {
        const materialId = document.getElementsByName('material_id[]')[rowIdx].value;
        const $arNoSelect = $(`.ar-no-select`).eq(rowIdx);
        $arNoSelect.empty().append('<option value="">-- Select AR NO --</option>');
        if (materialId && arNoMap[materialId]) {
            arNoMap[materialId].forEach(function(arNo) {
                $arNoSelect.append(`<option value="${arNo}">${arNo}</option>`);
            });
        }
        $arNoSelect.trigger('change'); // Refresh Select2
    }

    $(document).on('click', '.add-row-btn', function() {
    const $currentRow = $(this).closest('tr');
    const $newRow = $currentRow.clone();

    // Clear only issuer fields in the new row
    $newRow.find('input[name="issued_qty[]"]').val('');
    $newRow.find('input[name="short_issue[]"]').val('');
    $newRow.find('select[name^="ar_no"]').val('').trigger('change');
    $newRow.find('.accepted-qty-field').val('');

    // Remove all add icons from all rows (including the cloned one)
    $('#materialBody .add-row-btn').remove();

    // Remove any duplicate add-row-btn from the cloned row
    $newRow.find('.add-row-btn').remove();

    // --- FIX: Destroy Select2 and remove its DOM before re-initializing ---
    $newRow.find('.ar-no-select').select2('destroy');
    $newRow.find('.ar-no-select').next('.select2').remove();

    // Prepend add icon ONLY to the new last row
    $newRow.find('td:first').prepend(
        '<button type="button" class="btn btn-link text-success p-0 add-row-btn" title="Add Row"><i class="fas fa-plus"></i></button>'
    );

    // Enable delete icon for the new row
    $newRow.find('.delete-row-btn').prop('disabled', false);

    // Append the new row
    $('#materialBody').append($newRow);

    // Re-index Sr. No.
    $('#materialBody tr').each(function(i, tr) {
        $(tr).find('td').eq(1).text(i + 1);
    });

    // Re-initialize Select2 for new selects
    $newRow.find('.material-select2').select2({
        placeholder: "-- Select Material --",
        allowClear: true,
        width: 'resolve'
    });
    $newRow.find('.ar-no-select').select2({
        placeholder: "Select AR NO",
        allowClear: true,
        width: '250px'
    });
});


// --- On page load, set accepted-qty-field as readonly and update for selected AR NOs ---
$(document).ready(function() {
    $('.accepted-qty-field').prop('readonly', true);
    $('.ar-no-select').each(function(idx, select) {
        updateAcceptedQtySingle(select, idx);
    });
    $(document).on('change', '.ar-no-select', function() {
        const idx = $(this).closest('tr').index();
        updateAcceptedQtySingle(this, idx);
    });
});

function updateAllShortIssues() {
    // For each group (key), keep a running sum of issued qty
    const runningIssuedMap = {};

    $('#materialBody tr').each(function() {
        // Use a unique key for each material+batch (adjust as needed)
        const materialId = $(this).find('input[name="material_id[]"]').val();
        const batchNo = $(this).find('input[name="batch_no[]"]').val();
        const key = materialId + '||' + batchNo;

        const reqQty = parseFloat($(this).find('input[name="request_qty[]"]').val()) || 0;
        if (typeof runningIssuedMap[key] === 'undefined') runningIssuedMap[key] = 0;

        // Get this row's issued qty
        let issued = parseFloat($(this).find('input[name="issued_qty[]"]').val()) || 0;

        // Prevent total issued from exceeding request qty
        if (runningIssuedMap[key] + issued > reqQty) {
            issued = reqQty - runningIssuedMap[key];
            $(this).find('input[name="issued_qty[]"]').val(issued > 0 ? issued : 0);
            alert('Total Issued Quantity cannot be greater than Request Quantity!');
        }

        // Short Issue for this row is reqQty - (runningIssuedMap[key] + issued)
        const shortIssue = Math.max(reqQty - (runningIssuedMap[key] + issued), 0);
        $(this).find('input[name="short_issue[]"]').val(shortIssue);

        // Add this row's issued qty to running sum for next rows
        runningIssuedMap[key] += issued;
    });
}

// Call this function whenever issued_qty changes
$(document).on('input', 'input[name="issued_qty[]"]', function() {
    updateAllShortIssues();
});
</script>
</body>
</html>