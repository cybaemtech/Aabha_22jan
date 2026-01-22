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

// Update existing OP IDs to remove "OP" prefix
$update_sql = "
    UPDATE operators
    SET op_id = CAST(SUBSTRING(op_id, 3, LEN(op_id)) AS INT)
    WHERE op_id LIKE 'OP%'
      AND ISNUMERIC(SUBSTRING(op_id, 3, LEN(op_id))) = 1;
";
sqlsrv_query($conn, $update_sql);

function generateNextOpId($conn) {
    $query = "SELECT TOP 1 CAST(op_id AS INT) AS op_id FROM operators WHERE ISNUMERIC(op_id) = 1 ORDER BY CAST(op_id AS INT) DESC";
    $stmt = sqlsrv_query($conn, $query);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return strval(intval($row['op_id']) + 1);
    }
    return '1';
}

function checkOpIdExists($conn, $opId, $excludeId = null) {
    if ($excludeId) {
        $query = "SELECT COUNT(*) AS count FROM operators WHERE op_id = ? AND id != ?";
        $params = [$opId, $excludeId];
    } else {
        $query = "SELECT COUNT(*) AS count FROM operators WHERE op_id = ?";
        $params = [$opId];
    }
    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['count'] > 0;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contract = $_POST['contract'] ?? '';
    $sex = $_POST['sex'] ?? '';
    $month = $_POST['month'] ?? '';
    $grade = $_POST['grade'] ?? '';
    $present_status = $_POST['presentStatus'] ?? '';
    $op_id = trim($_POST['opId'] ?? '');

    if (!empty($month) && strpos($month, '-') !== false && strlen($month) === 10) {
        $dateParts = explode('-', $month);
        if (count($dateParts) === 3) {
            $year = substr($dateParts[0], -2);
            $monthNum = $dateParts[1];
            $day = $dateParts[2];
            $month = "$day/$monthNum/$year";
        }
    }

    $errors = [];
    if (empty($name)) $errors[] = "Name is required";
    if (empty($contract)) $errors[] = "Contract is required";
    if (empty($sex)) $errors[] = "Sex is required";
    if (empty($present_status)) $errors[] = "Present status is required";
    if (empty($op_id)) $errors[] = "Operator ID is required";
    if (!empty($op_id) && !preg_match('/^[0-9]+$/', $op_id)) {
        $errors[] = "Operator ID must contain only numbers";
    }

    if (!empty($errors)) {
        echo "<script>alert('Validation errors: " . implode(", ", $errors) . "');</script>";
    } else {
        if (!empty($_POST['edit_id'])) {
            $edit_id = intval($_POST['edit_id']);
            if (checkOpIdExists($conn, $op_id, $edit_id)) {
                echo "<script>alert('Error: Operator ID \"$op_id\" already exists. Please choose a different ID.');</script>";
            } else {
                $query = "UPDATE operators SET op_id = ?, name = ?, contract = ?, sex = ?, month = ?, grade = ?, present_status = ?, updated_at = GETDATE() WHERE id = ?";
                $params = [$op_id, $name, $contract, $sex, $month, $grade, $present_status, $edit_id];
                $stmt = sqlsrv_query($conn, $query, $params);
                if ($stmt) {
                    echo "<script>alert('Operator updated successfully!'); window.location.href='operatorLookup.php';</script>";
                    exit;
                } else {
                    echo "<script>alert('Failed to update operator.');</script>";
                }
            }
        } else {
            if (checkOpIdExists($conn, $op_id)) {
                echo "<script>alert('Error: Operator ID \"$op_id\" already exists. Please choose a different ID.');</script>";
            } else {
                $query = "INSERT INTO operators (op_id, name, contract, sex, month, grade, present_status) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $params = [$op_id, $name, $contract, $sex, $month, $grade, $present_status];
                $stmt = sqlsrv_query($conn, $query, $params);
                if ($stmt) {
                    echo "<script>alert('Operator added successfully with ID: $op_id'); window.location.href='operatorLookup.php';</script>";
                    exit;
                } else {
                    echo "<script>alert('Failed to add operator.');</script>";
                }
            }
        }
    }
}

$editData = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $query = "SELECT * FROM operators WHERE id = ?";
    $stmt = sqlsrv_query($conn, $query, [$edit_id]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $editData = $row;
    } else {
        echo "<script>alert('Operator not found!'); window.location.href='operator.php';</script>";
        exit;
    }
}

if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $query = "DELETE FROM operators WHERE id = ?";
    $stmt = sqlsrv_query($conn, $query, [$delete_id]);
    if ($stmt && sqlsrv_rows_affected($stmt) > 0) {
        echo "<script>alert('Operator deleted successfully!'); window.location.href='operator.php';</script>";
    } else {
        echo "<script>alert('Operator not found or delete failed!'); window.location.href='operator.php';</script>";
    }
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background: #f8f9fa;
        }

        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .form-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
        }

        .operator-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group input.error {
            border-color: #dc3545;
            background-color: #fff5f5;
        }

        .form-group input.success {
            border-color: #28a745;
            background-color: #f8fff8;
        }

        .validation-message {
            font-size: 12px;
            margin-top: 5px;
            padding: 5px;
            border-radius: 3px;
        }

        .validation-message.error {
            color: #dc3545;
            background-color: #fff5f5;
            border: 1px solid #f5c6cb;
        }

        .validation-message.success {
            color: #28a745;
            background-color: #f8fff8;
            border: 1px solid #c3e6cb;
        }

        /* Enhanced Button Styling */
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 5px;
            transition: all 0.3s;
            min-width: 140px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
            transform: translateY(-2px);
        }

        .btn-close {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-close:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, #0e6674 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-start;
            align-items: center;
            margin-top: 20px;
        }

        /* Button responsive design */
        @media (max-width: 768px) {
            .button-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
                margin: 5px 0;
                min-width: auto;
            }
        }

        @media (max-width: 480px) {
            .btn {
                padding: 10px 15px;
                font-size: 13px;
            }
        }

        .helper-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 5px;
        }

        .id-input-group {
            display: flex;
            align-items: center;
        }

        .id-input-group input {
            flex: 1;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .operator-form {
                grid-template-columns: 1fr;
            }

            .id-input-group {
                flex-direction: column;
            }

           
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="form-container">
        <div class="form-title">
            <?= $editData ? 'Edit Operator' : 'Add New Operator' ?>
        </div>

        <form method="POST" class="operator-form" id="operatorForm">
            <?php if ($editData): ?>
                <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="opId">Operator ID <span style="color: red;">*</span></label>
                <div class="id-input-group">
                    <input type="text" id="opId" name="opId" value="<?= $editData['op_id'] ?? '' ?>" 
                           required pattern="[0-9]+" title="Only numbers are allowed" 
                           placeholder="Enter operator ID (numbers only)">
                    <?php if (!$editData): ?>
                      
                    <?php endif; ?>
                </div>
                <div class="helper-text">
                    Enter a unique numeric ID 
                    <?php if (!$editData): ?>
                       
                    <?php endif; ?>
                </div>
                <div id="opIdValidation" class="validation-message" style="display: none;"></div>
            </div>

            <div class="form-group">
                <label for="name">NAME <span style="color: red;">*</span></label>
                <input type="text" id="name" name="name" value="<?= $editData['name'] ?? '' ?>" required>
            </div>

            <div class="form-group">
                <label for="contract">CONTRACT <span style="color: red;">*</span></label>
                <select id="contract" name="contract" required>
                    <option value="">-- Select Contract --</option>
                    <option value="Permanent" <?= ($editData['contract'] ?? '') == 'Permanent' ? 'selected' : '' ?>>Permanent</option>
                    <option value="Temporary" <?= ($editData['contract'] ?? '') == 'Temporary' ? 'selected' : '' ?>>Temporary</option>
                    <option value="Contract" <?= ($editData['contract'] ?? '') == 'Contract' ? 'selected' : '' ?>>Contract</option>
                    <option value="Trainee" <?= ($editData['contract'] ?? '') == 'Trainee' ? 'selected' : '' ?>>Trainee</option>
                </select>
            </div>

            <div class="form-group">
                <label for="sex">SEX <span style="color: red;">*</span></label>
                <select id="sex" name="sex" required>
                    <option value="">-- Select Sex --</option>
                    <option value="Male" <?= ($editData['sex'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($editData['sex'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <div class="form-group">
                <label for="month">DATE <small>(DD/MM/YY)</small></label>
                <input type="date" id="month" name="month" value="<?= 
                    $editData['month'] ?? '' ? 
                    (function() {
                        $monthValue = $editData['month'] ?? '';
                        if ($monthValue) {
                            // Convert "25/06/25" to "2025-06-25" format for HTML5 date input
                            if (strpos($monthValue, '/') !== false) {
                                $parts = explode('/', $monthValue);
                                if (count($parts) === 3) {
                                    $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                                    $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                                    $year = '20' . $parts[2];
                                    return $year . '-' . $month . '-' . $day;
                                }
                            }
                        }
                        return '';
                    })() : '' 
                ?>" class="date-picker" title="Select date - will be saved as DD/MM/YY format">
                <small class="form-text text-muted">Selected date will be stored as DD/MM/YY format</small>
            </div>

            <div class="form-group">
                <label for="grade">GRADE</label>
                <select id="grade" name="grade">
                    <option value="">-- Select Grade --</option>
                    <option value="A" <?= ($editData['grade'] ?? '') == 'A' ? 'selected' : '' ?>>A</option>
                    <option value="B" <?= ($editData['grade'] ?? '') == 'B' ? 'selected' : '' ?>>B</option>
                    <option value="C" <?= ($editData['grade'] ?? '') == 'C' ? 'selected' : '' ?>>C</option>
                    <option value="D" <?= ($editData['grade'] ?? '') == 'D' ? 'selected' : '' ?>>D</option>
                    <option value="E" <?= ($editData['grade'] ?? '') == 'E' ? 'selected' : '' ?>>E</option>
                </select>
            </div>

            <div class="form-group">
                <label for="presentStatus">PRESENT STATUS <span style="color: red;">*</span></label>
                <select id="presentStatus" name="presentStatus" required>
                    <option value="">-- Select Status --</option>
                    <option value="Active" <?= ($editData['present_status'] ?? '') == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= ($editData['present_status'] ?? '') == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="On Leave" <?= ($editData['present_status'] ?? '') == 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="Transferred" <?= ($editData['present_status'] ?? '') == 'Transferred' ? 'selected' : '' ?>>Transferred</option>
                </select>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <div class="button-group">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fa fa-save"></i> <?= $editData ? 'Update Operator' : 'Add Operator' ?>
                    </button>
                    
                    <?php if ($editData): ?>
                        <a href="operatorLookup.php" class="btn btn-secondary">
                            <i class="fa fa-times"></i> Cancel
                        </a>
                    <?php endif; ?>
                    
                    <!-- Close button that always shows -->
                    <a href="operatorLookup.php" class="btn btn-close" onclick="return confirmClose()">
                        <i class="fa fa-times-circle"></i> Close
                    </a>
                    
                    <?php if (!$editData): ?>
                       
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const opIdInput = document.getElementById('opId');
    const submitBtn = document.getElementById('submitBtn');
    const validationDiv = document.getElementById('opIdValidation');
    
    // Set default date value to today
    const dateInput = document.getElementById('month');
    if (!dateInput.value) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        dateInput.value = `${year}-${month}-${day}`;
    }

    // Real-time validation for Operator ID
    opIdInput.addEventListener('input', function() {
        const opId = this.value.trim();
        
        // Clear previous validation
        this.classList.remove('error', 'success');
        validationDiv.style.display = 'none';
        submitBtn.disabled = false;
        
        if (opId === '') {
            return;
        }
        
        // Check if it's only numbers
        if (!/^[0-9]+$/.test(opId)) {
            this.classList.add('error');
            showValidationMessage('Operator ID must contain only numbers', 'error');
            submitBtn.disabled = true;
            return;
        }
        
        // Check for duplicates via AJAX
        checkOpIdDuplicate(opId);
    });

    // Check for duplicate operator IDs
    function checkOpIdDuplicate(opId) {
        const editId = document.querySelector('input[name="edit_id"]')?.value || '';
        
        fetch('check_operator_id.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                op_id: opId,
                edit_id: editId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                opIdInput.classList.add('error');
                showValidationMessage('Operator ID already exists. Please choose a different ID.', 'error');
                submitBtn.disabled = true;
            } else {
                opIdInput.classList.add('success');
                showValidationMessage('Operator ID is available', 'success');
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error checking operator ID:', error);
        });
    }

    function showValidationMessage(message, type) {
        validationDiv.textContent = message;
        validationDiv.className = `validation-message ${type}`;
        validationDiv.style.display = 'block';
    }

    // Form submission validation
    document.getElementById('operatorForm').addEventListener('submit', function(e) {
        const opId = opIdInput.value.trim();
        
        if (opId === '') {
            e.preventDefault();
            alert('Please enter an Operator ID');
            opIdInput.focus();
            return;
        }
        
        if (!/^[0-9]+$/.test(opId)) {
            e.preventDefault();
            alert('Operator ID must contain only numbers');
            opIdInput.focus();
            return;
        }
        
        if (submitBtn.disabled) {
            e.preventDefault();
            alert('Please fix the validation errors before submitting');
            return;
        }
    });
});

// Auto-generate operator ID function

// Close confirmation function
function confirmClose() {
    const form = document.getElementById('operatorForm');
    const formData = new FormData(form);
    let hasChanges = false;
    
    // Check if any form field has been modified
    for (let [key, value] of formData.entries()) {
        if (value && value.trim() !== '') {
            hasChanges = true;
            break;
        }
    }
    
    if (hasChanges) {
        return confirm('You have unsaved changes. Are you sure you want to close without saving?');
    }
    
    return true; // Allow navigation if no changes
}

// Warn user before leaving page if form has changes
window.addEventListener('beforeunload', function(e) {
    const form = document.getElementById('operatorForm');
    const formData = new FormData(form);
    let hasChanges = false;
    
    // Check if any form field has been modified
    for (let [key, value] of formData.entries()) {
        if (value && value.trim() !== '') {
            hasChanges = true;
            break;
        }
    }
    
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = ''; // Chrome requires returnValue to be set
        return 'You have unsaved changes. Are you sure you want to leave?';
    }
});
</script>

</body>
</html>










