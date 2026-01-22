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
include '../Includes/db_connect.php'; // should use sqlsrv_connect()
include '../Includes/sidebar.php';

$operator_id = $_SESSION['operator_id'];
$departmentName = '';
$departmentId = '';
$requestNo = 1;

// Fetch department info
$userQuery = sqlsrv_query($conn, "
    SELECT u.department_id, d.department_name, u.user_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.operator_id = ?", [$operator_id]);

if ($userQuery && $user = sqlsrv_fetch_array($userQuery, SQLSRV_FETCH_ASSOC)) {
    $departmentName = $user['department_name'];
    $departmentId = $user['department_id'];
    $_SESSION['user_name'] = $user['user_name'];
}

// Generate request number
$requestResult = sqlsrv_query($conn, "SELECT COUNT(*) AS total FROM material_request_items");
if ($requestResult && $row = sqlsrv_fetch_array($requestResult, SQLSRV_FETCH_ASSOC)) {
    $requestNo = (int)$row['total'] + 1;
}

// Fetch material master
$materials = [];
$matResult = sqlsrv_query($conn, "SELECT material_id, material_description, unit_of_measurement FROM materials");
while ($row = sqlsrv_fetch_array($matResult, SQLSRV_FETCH_ASSOC)) {
    $materials[] = $row;
}

// Fetch latest batch data
$batchQtyMap = [];
$batchSql = "
    SELECT q.qc_quantity_id, g.material_id, g.batch_no, q.accepted_qty
    FROM grn_quantity_details g
    LEFT JOIN qc_quantity_details q ON g.quantity_id = q.grn_quantity_id
    WHERE g.material_id IS NOT NULL
    ORDER BY g.created_at DESC";
$batchRes = sqlsrv_query($conn, $batchSql);
while ($row = sqlsrv_fetch_array($batchRes, SQLSRV_FETCH_ASSOC)) {
    if (!isset($batchQtyMap[$row['material_id']])) {
        $batchQtyMap[$row['material_id']] = [
            'batch_no' => $row['batch_no'],
            'accepted_qty' => $row['accepted_qty'],
            'qc_quantity_id' => $row['qc_quantity_id']
        ];
    }
}

// Accepted quantity summary
$acceptedQtyMap = [];
$acceptedQtySql = "
    SELECT 
        gq.material_id, 
        m.material_description, 
        SUM(ISNULL(qc.accepted_qty, 0)) AS total_accepted_qty
    FROM grn_quantity_details gq
    LEFT JOIN qc_quantity_details qc ON gq.quantity_id = qc.grn_quantity_id
    LEFT JOIN materials m ON gq.material_id = m.material_id
    WHERE gq.material_id IS NOT NULL
    GROUP BY gq.material_id, m.material_description";
$res = sqlsrv_query($conn, $acceptedQtySql);
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $key = $row['material_id'] . '||' . $row['material_description'];
    $acceptedQtyMap[$key] = $row['total_accepted_qty'];
}

// Batch-wise accepted quantity summary (for popup display only)
$batchAcceptedQtyMap = [];
$batchAcceptedQtySql = "
    SELECT 
        g.material_id,
        m.material_description,
        g.batch_no,
        SUM(ISNULL(q.accepted_qty, 0)) AS batch_accepted_qty
    FROM grn_quantity_details g
    LEFT JOIN qc_quantity_details q ON g.quantity_id = q.grn_quantity_id
    LEFT JOIN materials m ON g.material_id = m.material_id
    WHERE g.material_id IS NOT NULL AND g.batch_no IS NOT NULL
    GROUP BY g.material_id, m.material_description, g.batch_no";

$batchRes = sqlsrv_query($conn, $batchAcceptedQtySql);
while ($row = sqlsrv_fetch_array($batchRes, SQLSRV_FETCH_ASSOC)) {
    $key = $row['material_id'] . '||' . $row['material_description'] . '||' . $row['batch_no'];
    $batchAcceptedQtyMap[$key] = [
        'accepted_qty' => $row['batch_accepted_qty'],
        'material_id' => $row['material_id'],
        'description' => $row['material_description'],
        'batch_no' => $row['batch_no']
    ];
}

// Edit mode detection
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editRequest = null;
$editItems = [];

if ($editId > 0) {
    // Fetch request
    $reqRes = sqlsrv_query($conn, "SELECT TOP 1 * FROM material_request_items WHERE material_request_id = ?", [$editId]);
    if ($reqRes && $editRequest = sqlsrv_fetch_array($reqRes, SQLSRV_FETCH_ASSOC)) {}

    // Fetch all related items
    $itemRes = sqlsrv_query($conn, "SELECT * FROM material_request_items WHERE material_request_id = ? ORDER BY id ASC", [$editId]);
    while ($row = sqlsrv_fetch_array($itemRes, SQLSRV_FETCH_ASSOC)) {
        $editItems[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POSTed fields
    $material_ids   = $_POST['material_id'];
    $descriptions   = $_POST['description'];
    $units          = $_POST['unit'];
    $batch_nos      = $_POST['batch_no'];
    $request_qtys   = $_POST['request_qty'];
    $available_qtys = $_POST['available_qty'];

    $department_id = $departmentId;
    $request_date  = $_POST['request_date'];
    $request_by    = $_POST['request_by'];

    if ($editId > 0) {
        sqlsrv_query($conn, "DELETE FROM material_request_items WHERE material_request_id = ?", [$editId]);
        $material_request_id = $editId;
    } else {
        $result = sqlsrv_query($conn, "SELECT MAX(material_request_id) AS max_id FROM material_request_items");
        $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
        $material_request_id = $row['max_id'] ? $row['max_id'] + 1 : 1;
    }
// Insert each material row
foreach ($material_ids as $i => $material_id) {
    $sr_no = $i + 1;
    $description = $descriptions[$i];
    $unit = $units[$i];
    $batch_no = $batch_nos[$i] ?: null;
    $request_qty = is_numeric($request_qtys[$i]) ? (float)$request_qtys[$i] : 0;
    $available_qty = is_numeric($available_qtys[$i]) ? (float)$available_qtys[$i] : 0;
    $request_date_obj = date_create_from_format('Y-m-d', $request_date);

    $insertSql = "INSERT INTO material_request_items (
        material_request_id, sr_no, material_id, description, unit, batch_no, request_qty,
        available_qty, department_id, request_date, request_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $material_request_id,
        $sr_no,
        $material_id,
        $description,
        $unit,
        $batch_no,
        $request_qty,
        $available_qty,
        $department_id,
        $request_date_obj,
        $request_by
    ];

    $stmt = sqlsrv_query($conn, $insertSql, $params);
    if (!$stmt) {
        die(print_r(sqlsrv_errors(), true));
    }
}


    header("Location: MaterialIssueNotePageLookup.php?success=1");
    exit;
}

// Success alert
if (isset($_GET['success']) && $_GET['success'] == 1) {
    echo "<script>
        alert('Material request updated successfully!');
        window.location.href = 'MaterialIssueNotePageLookup.php';
    </script>";
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Request Entry</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .card-header {
            background-color: #6c757d;
            color: white;
        }
         .main-content { 
            padding: 20px; 
            height: calc(100vh - 40px); /* Adjust for header/footer if needed */
            box-sizing: border-box;
            overflow: hidden;
        }
        .form-label {
            font-weight: 500;
        }
        .content-area {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease-in-out;
        }
        .sidebar-collapsed .content-area {
            margin-left: 70px;
        }
    </style>
</head>
<body>

<div class="content-area">
    <div class="container-fluid">
         <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Material Request Entry</h5>
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
                            <input type="text" name="department" class="form-control" value="<?= $editRequest ? htmlspecialchars($departmentName) : htmlspecialchars($departmentName) ?>" readonly>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="form-label">Request Date</label>
                            <input type="date" name="request_date" class="form-control" value="<?= $editRequest ? $editRequest['request_date']->format('Y-m-d') : date('Y-m-d') ?>" readonly>

                        </div>
                    </div>

                    <div class="mb-3">
                        <button type="button" class="btn btn-primary" onclick="addRow()">+ Add Row</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="materialTable">
                            <thead class="thead-dark text-center">
    <tr>
        <th>Delete</th>
        <th>Sr. No.</th>
        <th>Material ID</th>
        <th>Material Description</th>
        <th>Unit</th>
        <th>Batch Number</th>
        <th>Request Qty</th>
        <th>Issued Qty</th>
        <th>Accepted Qty</th>
    </tr>
</thead>
                            <tbody id="materialBody">
<?php if ($editItems): ?>
    <?php foreach ($editItems as $i => $item): ?>
        <tr class="text-center">
            <td>
                <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
            <td><?= $i + 1 ?></td>
            <td>
                <input type="text" class="form-control" name="material_id[]" value="<?= htmlspecialchars($item['material_id'] ?? '') ?>" readonly>
            </td>
            <td>
                <select class="form-control material-select2" name="description[]" onchange="onMaterialChange(this, <?= $i ?? 0 ?>)" style="width: 220px;">
                    <option value="">-- Select Material --</option>
                    <?php foreach ($materials as $mat): ?>
                        <option 
                            value="<?= htmlspecialchars($mat['material_description']) ?>"
                            data-id="<?= htmlspecialchars($mat['material_id']) ?>"
                            data-unit="<?= htmlspecialchars($mat['unit_of_measurement']) ?>"
                            <?= (isset($item['description']) && $item['description'] == $mat['material_description']) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($mat['material_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" class="form-control" name="unit[]" value="<?= htmlspecialchars($item['unit'] ?? '') ?>" readonly></td>
            <td>
                <input type="text" class="form-control" name="batch_no[]" maxlength="10" style="width:120px;" 
                       placeholder="Enter batch" value="<?= htmlspecialchars($item['batch_no'] ?? '') ?>"
                       onblur="onBatchBlur(this, <?= $i ?? 0 ?>)" 
                       title="Enter batch number to check batch-specific accepted quantity">
            </td>
            <td><input type="number" class="form-control" name="request_qty[]" value="<?= htmlspecialchars($item['request_qty'] ?? '') ?>" oninput="updateShortIssue(<?= $i ?>)"></td>
            <td><input type="number" class="form-control" name="issued_qty[]" value="" readonly disabled></td>
            <td>
                <input type="text" class="form-control" name="available_qty[]" value="<?= htmlspecialchars($item['available_qty'] ?? '') ?>" readonly 
                       title="Total material-wise accepted quantity">
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <!-- Show a blank row for add mode -->
    <tr class="text-center">
        <td>
            <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
        <td>1</td>
        <td><input type="text" class="form-control" name="material_id[]" readonly></td>
        <td>
            <select class="form-control material-select2" name="description[]" onchange="onMaterialChange(this, 0)" style="width: 220px;">
                <option value="">-- Select Material --</option>
                <?php foreach ($materials as $mat): ?>
                    <option 
                        value="<?= htmlspecialchars($mat['material_description']) ?>"
                        data-id="<?= htmlspecialchars($mat['material_id']) ?>"
                        data-unit="<?= htmlspecialchars($mat['unit_of_measurement']) ?>"
                    >
                        <?= htmlspecialchars($mat['material_description']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" class="form-control" name="unit[]" readonly></td>
        <td>
            <input type="text" class="form-control" name="batch_no[]" maxlength="10" style="width:120px;" 
                   placeholder="Enter batch" onblur="onBatchBlur(this, 0)" 
                   title="Enter batch number to check batch-specific accepted quantity">
        </td>
        <td><input type="number" class="form-control" name="request_qty[]" oninput="updateShortIssue(0)"></td>
        <td><input type="number" class="form-control" name="issued_qty[]" readonly disabled></td>
        <td>
            <input type="text" class="form-control" name="available_qty[]" readonly 
                   title="Total material-wise accepted quantity">
        </td>
    </tr>
<?php endif; ?>
</tbody>

                        </table>
                    </div>

                    <div class="form-row mt-4">
                        <div class="form-group col-md-4">
                            <label class="form-label">Request By Name</label>
                            <input type="text" name="request_by" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="form-label">Issued Date</label>
                            <input type="date" name="issue_date" class="form-control" readonly>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="form-label">Issued By Name</label>
                            <input type="text" name="issued_by" class="form-control" readonly>
                        </div>
                    </div>

                    <div class="text-right">

                        <button type="submit" class="btn btn-primary">Forward Request</button>
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
        window.location.href = 'MaterialIssueNotePageLookup.php';
    }, 200);
}
</script>
<!-- JS Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    const acceptedQtyMap = <?= json_encode($acceptedQtyMap) ?>;
    const batchAcceptedQtyMap = <?= json_encode($batchAcceptedQtyMap) ?>;

    function onMaterialChange(select, rowIdx) {
        const selectedOption = select.options[select.selectedIndex];
        const materialId = selectedOption.getAttribute('data-id') || '';
        const unit = selectedOption.getAttribute('data-unit') || '';
        
        // Update material ID and unit fields
        document.getElementsByName('material_id[]')[rowIdx].value = materialId;
        document.getElementsByName('unit[]')[rowIdx].value = unit;

        // Fill total material-wise accepted quantity in the Accepted Qty field
        const materialDesc = select.value;
        const materialKey = materialId + '||' + materialDesc;
        const totalAcceptedQty = acceptedQtyMap[materialKey] ? parseFloat(acceptedQtyMap[materialKey]) : 0;
        
        // Update the Accepted Qty field with material-wise total
        document.getElementsByName('available_qty[]')[rowIdx].value = totalAcceptedQty.toFixed(3);

        // Clear batch number when material changes
        document.getElementsByName('batch_no[]')[rowIdx].value = '';
        
        // Show material summary notification
        if (materialId && materialDesc) {
            showMaterialNotification(materialId, materialDesc, totalAcceptedQty);
        }
    }

    function showMaterialNotification(materialId, materialDesc, acceptedQty) {
        const summaryMessage = `Material Selected: ${materialDesc}
Total Accepted Quantity: ${acceptedQty.toFixed(3)}`;

        // Create a small notification in top-right
        showNotification('Material Selected', summaryMessage, 'info', 2000);
    }

    function showNotification(title, message, type, duration = 3000) {
        const alertTypes = {
            'success': { icon: '✅', bgColor: '#d4edda', borderColor: '#c3e6cb', textColor: '#155724' },
            'warning': { icon: '⚠️', bgColor: '#fff3cd', borderColor: '#ffeaa7', textColor: '#856404' },
            'error': { icon: '❌', bgColor: '#f8d7da', borderColor: '#f5c6cb', textColor: '#721c24' },
            'info': { icon: 'ℹ️', bgColor: '#d1ecf1', borderColor: '#bee5eb', textColor: '#0c5460' }
        };

        const alertType = alertTypes[type] || alertTypes['info'];
        
        const notificationDiv = document.createElement('div');
        notificationDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${alertType.bgColor};
            border: 2px solid ${alertType.borderColor};
            color: ${alertType.textColor};
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            max-width: 350px;
            font-family: Arial, sans-serif;
            font-size: 13px;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        `;

        notificationDiv.innerHTML = `
            <div style="font-weight: bold; margin-bottom: 5px;">
                ${alertType.icon} ${title}
            </div>
            <div style="line-height: 1.3;">
                ${message}
            </div>
        `;

        document.body.appendChild(notificationDiv);

        // Animate in
        setTimeout(() => {
            notificationDiv.style.opacity = '1';
            notificationDiv.style.transform = 'translateX(0)';
        }, 100);

        // Auto-remove
        setTimeout(() => {
            notificationDiv.style.opacity = '0';
            notificationDiv.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notificationDiv.parentElement) {
                    notificationDiv.remove();
                }
            }, 300);
        }, duration);
    }

    // Enhanced batch blur function - shows batch-wise accepted qty in popup only
    function onBatchBlur(input, rowIdx) {
        const batchNo = input.value.trim();
        const materialId = document.getElementsByName('material_id[]')[rowIdx].value;
        const materialDesc = document.getElementsByName('description[]')[rowIdx].value;
        
        if (!materialId || !materialDesc || !batchNo) {
            if (batchNo && (!materialId || !materialDesc)) {
                showAlert('Warning', 'Please select a material first before entering batch number.', 'warning');
            }
            return;
        }

        const batchKey = materialId + '||' + materialDesc + '||' + batchNo;
        const batchAcceptedData = batchAcceptedQtyMap[batchKey];

        if (batchAcceptedData && batchAcceptedData.accepted_qty > 0) {
            // Show batch-wise accepted quantity in popup only
            const alertMessage = `
Batch Information:
━━━━━━━━━━━━━━━━━━━━━━━━
Material ID: ${materialId}
Material: ${materialDesc}
Batch No: ${batchNo}
Batch Accepted Qty: ${parseFloat(batchAcceptedData.accepted_qty).toFixed(3)}
━━━━━━━━━━━━━━━━━━━━━━━━
Status: ✅ Batch Found
Source: QC Quantity Details`;

            showAlert('Batch Details', alertMessage, 'success');
            
            // Keep the material-wise total in the Accepted Qty field (don't change it)
            
        } else {
            // Check if batch exists in the data
            const batchExists = Object.keys(batchAcceptedQtyMap).some(k => k.includes(batchNo));
            
            if (batchExists) {
                const alertMessage = `
Batch Information:
━━━━━━━━━━━━━━━━━━━━━━━━
Material ID: ${materialId}
Material: ${materialDesc}
Batch No: ${batchNo}
Batch Accepted Qty: 0.000
━━━━━━━━━━━━━━━━━━━━━━━━
Status: ⚠️ Batch Found - No Accepted Quantity
Source: QC Quantity Details`;

                showAlert('Batch Found - No Quantity', alertMessage, 'warning');
            } else {
                const alertMessage = `
Batch Information:
━━━━━━━━━━━━━━━━━━━━━━━━
Material ID: ${materialId}
Material: ${materialDesc}
Batch No: ${batchNo}
Batch Accepted Qty: 0.000
━━━━━━━━━━━━━━━━━━━━━━━━
Status: ❌ Batch Not Found
━━━━━━━━━━━━━━━━━━━━━━━━
Note: This batch number does not exist in the system.`;

                showAlert('Batch Not Found', alertMessage, 'error');
            }
            
            // Always keep the material-wise total in the Accepted Qty field
        }
    }

    function showAlert(title, message, type) {
        const alertTypes = {
            'success': { icon: '✅', bgColor: '#d4edda', borderColor: '#c3e6cb', textColor: '#155724' },
            'warning': { icon: '⚠️', bgColor: '#fff3cd', borderColor: '#ffeaa7', textColor: '#856404' },
            'error': { icon: '❌', bgColor: '#f8d7da', borderColor: '#f5c6cb', textColor: '#721c24' },
            'info': { icon: 'ℹ️', bgColor: '#d1ecf1', borderColor: '#bee5eb', textColor: '#0c5460' }
        };

        const alertType = alertTypes[type] || alertTypes['info'];
        
        const alertDiv = document.createElement('div');
        alertDiv.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: ${alertType.bgColor};
            border: 2px solid ${alertType.borderColor};
            color: ${alertType.textColor};
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 10000;
            max-width: 500px;
            min-width: 300px;
            font-family: 'Courier New', monospace;
            white-space: pre-line;
        `;

        alertDiv.innerHTML = `
            <div style="font-weight: bold; margin-bottom: 10px; font-size: 16px;">
                ${alertType.icon} ${title}
            </div>
            <div style="font-size: 14px; line-height: 1.4;">
                ${message}
            </div>
            <div style="text-align: center; margin-top: 15px;">
                <button onclick="this.parentElement.parentElement.remove()" 
                        style="background: ${alertType.textColor}; color: white; border: none; padding: 8px 20px; border-radius: 5px; cursor: pointer;">
                    OK
                </button>
            </div>
        `;

        document.body.appendChild(alertDiv);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentElement) {
                alertDiv.remove();
            }
        }, 5000);
    }

    function addRow() {
        const tableBody = document.getElementById("materialBody");
        const rowCount = tableBody.rows.length;
        const newRow = tableBody.insertRow();
        newRow.classList.add("text-center");
        newRow.innerHTML = `
            <td>
                <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
            <td>${rowCount + 1}</td>
            <td><input type="text" class="form-control" name="material_id[]" readonly></td>
            <td>
                <select class="form-control material-select2" name="description[]" onchange="onMaterialChange(this, ${rowCount})" style="width: 220px;">
                    <option value="">-- Select Material --</option>
                    <?php foreach ($materials as $mat): ?>
                        <option 
                            value="<?= htmlspecialchars($mat['material_description']) ?>"
                            data-id="<?= htmlspecialchars($mat['material_id']) ?>"
                            data-unit="<?= htmlspecialchars($mat['unit_of_measurement']) ?>"
                        >
                            <?= htmlspecialchars($mat['material_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" class="form-control" name="unit[]" readonly></td>
            <td>
                <input type="text" class="form-control" name="batch_no[]" maxlength="10" style="width:120px;" 
                       placeholder="Enter batch" onblur="onBatchBlur(this, ${rowCount})" 
                       title="Enter batch number to check batch-specific accepted quantity">
            </td>
            <td><input type="number" class="form-control" name="request_qty[]" oninput="updateShortIssue(${rowCount})"></td>
            <td><input type="number" class="form-control" name="issued_qty[]" readonly disabled></td>
            <td>
                <input type="text" class="form-control" name="available_qty[]" readonly 
                       title="Total material-wise accepted quantity">
            </td>
        `;
        
        // Re-initialize Select2 for the new dropdown
        $(newRow).find('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
    }

    // Initialize edit mode data
    document.addEventListener('DOMContentLoaded', function() {
        // For edit mode, populate all information for existing rows
        <?php if ($editItems): ?>
            <?php foreach ($editItems as $i => $item): ?>
                // Set the material description and trigger change to populate other fields
                const materialSelect<?= $i ?> = document.getElementsByName('description[]')[<?= $i ?>];
                if (materialSelect<?= $i ?>) {
                    materialSelect<?= $i ?>.value = '<?= htmlspecialchars($item['description']) ?>';
                    onMaterialChange(materialSelect<?= $i ?>, <?= $i ?>);
                }
                
                <?php if (!empty($item['batch_no'])): ?>
                    // Set the batch number value after material is set
                    setTimeout(() => {
                        const batchInput<?= $i ?> = document.getElementsByName('batch_no[]')[<?= $i ?>];
                        if (batchInput<?= $i ?>) {
                            batchInput<?= $i ?>.value = '<?= htmlspecialchars($item['batch_no']) ?>';
                            onBatchBlur(batchInput<?= $i ?>, <?= $i ?>);
                        }
                    }, 100);
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        // Initialize Select2 for all existing rows
        $('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
    });

    // Handle row deletion with proper re-indexing
    $(document).on('click', '.delete-row-btn', function() {
        if (confirm('Are you sure you want to delete this row?')) {
            const row = $(this).closest('tr');
            row.remove();
            
            // Re-index Sr. No. and update function calls
            $('#materialBody tr').each(function(i, tr) {
                $(tr).find('td').eq(1).text(i + 1);
                
                // Update function calls with new index
                const materialSelect = $(tr).find('select[name="description[]"]');
                if (materialSelect.length) {
                    materialSelect.attr('onchange', `onMaterialChange(this, ${i})`);
                }
                
                const batchInput = $(tr).find('input[name="batch_no[]"]');
                if (batchInput.length) {
                    batchInput.attr('onblur', `onBatchBlur(this, ${i})`);
                }
                
                const requestQtyInput = $(tr).find('input[name="request_qty[]"]');
                if (requestQtyInput.length) {
                    requestQtyInput.attr('oninput', `updateShortIssue(${i})`);
                }
            });
        }
    });

    function updateShortIssue(rowIdx) {
        const reqQtyInputs = document.getElementsByName('request_qty[]');
        const availQtyInputs = document.getElementsByName('available_qty[]');
        const shortIssueInputs = document.getElementsByName('short_issue[]');
        
        const reqQty = parseFloat(reqQtyInputs[rowIdx]?.value) || 0;
        const availQty = parseFloat(availQtyInputs[rowIdx]?.value) || 0;
        
        if (shortIssueInputs[rowIdx]) {
            shortIssueInputs[rowIdx].value = Math.max(0, reqQty - availQty);
        }
    }
</script>

<?php if (!empty($successMsg)): ?>
    <script>
        alert("<?= $successMsg ?>");
        window.location.href = "MaterialIssueNotePage.php";
    </script>
<?php endif; ?>

</body>
</html>
