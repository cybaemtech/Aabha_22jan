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
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle AJAX request for batch number validation
if (isset($_POST['action']) && $_POST['action'] === 'check_batch_number') {
    header('Content-Type: application/json');
    
    $batchNumber = trim($_POST['batch_number'] ?? '');
    $editId = $_POST['edit_id'] ?? '';
    
    if (empty($batchNumber)) {
        echo json_encode(['exists' => false, 'message' => 'Batch number is required']);
        exit;
    }

    // Modified query to exclude current record when editing
    $checkQuery = "SELECT COUNT(*) AS cnt FROM batch_creation WHERE batch_number = ?";
    $params = [$batchNumber];
    
    if (!empty($editId)) {
        $checkQuery .= " AND id != ?";
        $params[] = $editId;
    }
    
    $stmt = sqlsrv_query($conn, $checkQuery, $params);
    
    if ($stmt === false) {
        echo json_encode(['exists' => false, 'message' => 'Database error']);
        exit;
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    echo json_encode([
        'exists' => $row['cnt'] > 0,
        'message' => $row['cnt'] > 0 ? 'Batch number already exists!' : 'Batch number is available'
    ]);
    exit;
}

// --- Now include files that output HTML ---
include '../Includes/sidebar.php';

// Initialize variables for edit mode
// Initialize variables for edit mode
$isEdit = false;
$isEditMode = false; // Add this line
$editData = null;
$pageTitle = "Batch Creation Entry";
$submitButtonText = "Create Batch";
$submitIcon = "fa-plus-circle";
// Check if this is edit mode
if (isset($_GET['edit_id']) && !empty($_GET['edit_id'])) {
    $isEdit = true;
    $editId = intval($_GET['edit_id']);
    $pageTitle = "Edit Batch Entry";
    $submitButtonText = "Update Batch";
    $submitIcon = "fa-save";
    
    // Fetch existing data
    $fetchQuery = "SELECT * FROM batch_creation WHERE id = ?";
    $fetchStmt = sqlsrv_query($conn, $fetchQuery, [$editId]);
    
    if ($fetchStmt && sqlsrv_has_rows($fetchStmt)) {
        $editData = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC);
    } else {
        echo "<script>alert('❌ Batch record not found!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
        exit;
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['action'])) {
    $batchNumber = trim($_POST['batchNumber'] ?? '');
    $brandName = trim($_POST['brandName'] ?? '');
    $mfgDate = $_POST['mfgDate'] ?? '';
    $expDate = $_POST['expDate'] ?? '';
    $productType = trim($_POST['product_type'] ?? '');
    $stripCutting = trim($_POST['strip_cutting'] ?? '') ?: null;
    $siliconeOilQty = $_POST['silicone_oil_qty'] !== '' ? floatval($_POST['silicone_oil_qty']) : null;
    $benzocaineUsed = $_POST['benzocaine_used'] ?? '';
    $benzocaineQty = $_POST['benzocaine_qty'] !== '' ? floatval($_POST['benzocaine_qty']) : null;
    $orderQty = $_POST['order_qty'] !== '' ? intval($_POST['order_qty']) : null;
    $specialRequirement = trim($_POST['special_requirement'] ?? '') ?: null;
    $editId = $_POST['edit_id'] ?? '';
    $status = trim($_POST['status'] ?? 'Pending');
    $allowedStatuses = ['Pending', 'Completed'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'Pending';
    }
    $isEditMode = !empty($editId);

    if (empty($batchNumber) || empty($brandName) || empty($mfgDate) || empty($expDate) || empty($productType) || empty($benzocaineUsed)) {
        echo "<script>alert('❌ Please fill in all required fields!'); window.history.back();</script>";
        exit;
    }

    try {
        $mfgDateFormatted = date('Y-m-01', strtotime($mfgDate . '-01'));
        $expDateFormatted = date('Y-m-01', strtotime($expDate . '-01'));

        if ($expDateFormatted <= $mfgDateFormatted) {
            echo "<script>alert('❌ Expiry date must be after manufacturing date!'); window.history.back();</script>";
            exit;
        }
    } catch (Exception $e) {
        echo "<script>alert('❌ Invalid date format!'); window.history.back();</script>";
        exit;
    }

    try {
        sqlsrv_begin_transaction($conn);

        if ($isEditMode) {
            // UPDATE MODE - Enhanced with better error handling
            $checkQuery = "SELECT id FROM batch_creation WHERE batch_number = ? AND id != ?";
            $checkStmt = sqlsrv_query($conn, $checkQuery, [$batchNumber, $editId]);
            if (!$checkStmt) {
                $errors = sqlsrv_errors();
                throw new Exception("Batch check failed: " . ($errors ? $errors[0]['message'] : 'Unknown error'));
            }
            if (sqlsrv_has_rows($checkStmt)) {
                sqlsrv_rollback($conn);
                echo "<script>alert('❌ Batch Number \"$batchNumber\" already exists for another record!\\nPlease enter a unique Batch Number.'); window.history.back();</script>";
                exit;
            }

            // Check if the record exists before updating
            $existsQuery = "SELECT id FROM batch_creation WHERE id = ?";
            $existsStmt = sqlsrv_query($conn, $existsQuery, [$editId]);
            if (!$existsStmt || !sqlsrv_has_rows($existsStmt)) {
                sqlsrv_rollback($conn);
                echo "<script>alert('❌ Record not found for editing!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
                exit;
            }

            // Updated query with proper field handling
            $updateQuery = "
                UPDATE batch_creation SET 
                    batch_number = ?, 
                    brand_name = ?, 
                    mfg_date = ?, 
                    exp_date = ?, 
                    product_type = ?, 
                    strip_cutting = ?, 
                    silicone_oil_qty = ?, 
                    benzocaine_used = ?, 
                    benzocaine_qty = ?, 
                    order_qty = ?, 
                    special_requirement = ?,
                    status = ?
                WHERE id = ?
            ";

            $updateParams = [
                $batchNumber, 
                $brandName, 
                $mfgDateFormatted, 
                $expDateFormatted, 
                $productType,
                $stripCutting, 
                $siliconeOilQty, 
                $benzocaineUsed, 
                $benzocaineQty, 
                $orderQty,
                $specialRequirement, 
                $status,
                $editId
            ];

            

            $updateStmt = sqlsrv_query($conn, $updateQuery, $updateParams);

            if (!$updateStmt) {
                $errors = sqlsrv_errors();
                $errorDetails = $errors ? $errors[0]['message'] : 'Unknown error';
                throw new Exception("Update failed: " . $errorDetails);
            }

            // Check if any rows were affected
            $rowsAffected = sqlsrv_rows_affected($updateStmt);
            if ($rowsAffected === false || $rowsAffected === 0) {
                throw new Exception("No rows were updated. Record may not exist or no changes were made.");
            }

            sqlsrv_commit($conn);
            echo "<script>alert('✅ Batch \"$batchNumber\" updated successfully!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
            exit;
        } else {
            // CREATE MODE
            $checkQuery = "SELECT id FROM batch_creation WHERE batch_number = ?";
            $checkStmt = sqlsrv_query($conn, $checkQuery, [$batchNumber]);
            if (!$checkStmt) {
                throw new Exception("Batch check failed");
            }
            if (sqlsrv_has_rows($checkStmt)) {
                sqlsrv_rollback($conn);
                echo "<script>alert('❌ Batch Number \"$batchNumber\" already exists!\\nPlease enter a unique Batch Number.'); window.history.back();</script>";
                exit;
            }

            $insertQuery = "
                INSERT INTO batch_creation 
                (batch_number, brand_name, mfg_date, exp_date, product_type, strip_cutting, 
                 silicone_oil_qty, benzocaine_used, benzocaine_qty, order_qty, special_requirement, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $insertParams = [
                $batchNumber, $brandName, $mfgDateFormatted, $expDateFormatted, $productType,
                $stripCutting, $siliconeOilQty, $benzocaineUsed, $benzocaineQty, $orderQty,
                $specialRequirement, $status, $_SESSION['operator_id']
            ];
            $insertStmt = sqlsrv_query($conn, $insertQuery, $insertParams);

            if (!$insertStmt) {
                $errors = sqlsrv_errors();
                throw new Exception("Insert failed: " . ($errors ? $errors[0]['message'] : 'Unknown error'));
            }

            // Get inserted ID
            $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS inserted_id");
            $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
            $batchId = $idRow['inserted_id'];

            // Optional activity log
            $logQuery = "
                INSERT INTO activity_log (user_id, action, table_name, record_id, details) 
                VALUES (?, 'CREATE', 'batch_creation', ?, ?)
            ";
            $logDetails = "Created batch: $batchNumber for product: $productType";
            sqlsrv_query($conn, $logQuery, [$_SESSION['operator_id'], $batchId, $logDetails]);

            sqlsrv_commit($conn);
            echo "<script>alert('✅ Batch \"$batchNumber\" created successfully!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
        }
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Batch Creation Error: " . $e->getMessage());
        echo "<script>alert('❌ Database Error: " . addslashes($e->getMessage()) . "\\n\\nPlease contact administrator.'); window.history.back();</script>";
    }
}

// Fetch product types from products table for searchable dropdown
try {
    $productTypeQuery = "SELECT DISTINCT product_type FROM products WHERE product_type IS NOT NULL AND product_type != '' ORDER BY product_type ASC";
    $productTypeResult = sqlsrv_query($conn, $productTypeQuery);

    $productTypes = [];
    if ($productTypeResult) {
        while ($row = sqlsrv_fetch_array($productTypeResult, SQLSRV_FETCH_ASSOC)) {
            $productTypes[] = $row['product_type'];
        }
    }
    if (empty($productTypes)) {
        $productTypes = ['Standard Condom', 'Premium Condom', 'Ultra Thin', 'Textured'];
    }
} catch (Exception $e) {
    $productTypes = ['Standard Condom', 'Premium Condom', 'Ultra Thin'];
    error_log("Product Type Query Error: " . $e->getMessage());
}

// Helper function to safely get date value for month input
function getMonthValue($dateValue) {
    if (empty($dateValue)) return '';
    
    try {
        if ($dateValue instanceof DateTime) {
            return $dateValue->format('Y-m');
        } else {
            $date = new DateTime($dateValue);
            return $date->format('Y-m');
        }
    } catch (Exception $e) {
        return '';
    }
}
if ($isEditMode) {
    // UPDATE MODE - Enhanced with better error handling
    $checkQuery = "SELECT id FROM batch_creation WHERE batch_number = ? AND id != ?";
    $checkStmt = sqlsrv_query($conn, $checkQuery, [$batchNumber, $editId]);
    if (!$checkStmt) {
        $errors = sqlsrv_errors();
        throw new Exception("Batch check failed: " . ($errors ? $errors[0]['message'] : 'Unknown error'));
    }
    if (sqlsrv_has_rows($checkStmt)) {
        sqlsrv_rollback($conn);
        echo "<script>alert('❌ Batch Number \"$batchNumber\" already exists for another record!\\nPlease enter a unique Batch Number.'); window.history.back();</script>";
        exit;
    }

    // Check if the record exists before updating
    $existsQuery = "SELECT id FROM batch_creation WHERE id = ?";
    $existsStmt = sqlsrv_query($conn, $existsQuery, [$editId]);
    if (!$existsStmt || !sqlsrv_has_rows($existsStmt)) {
        sqlsrv_rollback($conn);
        echo "<script>alert('❌ Record not found for editing!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
        exit;
    }

    // Updated query with proper field handling
    $updateQuery = "
        UPDATE batch_creation SET 
            batch_number = ?, 
            brand_name = ?, 
            mfg_date = ?, 
            exp_date = ?, 
            product_type = ?, 
            strip_cutting = ?, 
            silicone_oil_qty = ?, 
            benzocaine_used = ?, 
            benzocaine_qty = ?, 
            order_qty = ?, 
            special_requirement = ?,
            status = ?
        WHERE id = ?
    ";

    $updateParams = [
        $batchNumber, 
        $brandName, 
        $mfgDate, 
        $expDate, 
        $productType,
        $stripCutting, 
        $siliconeOilQty, 
        $benzocaineUsed, 
        $benzocaineQty, 
        $orderQty,
        $specialRequirement, 
        $status,
        $editId
    ];

    // Debug the parameters

    $updateStmt = sqlsrv_query($conn, $updateQuery, $updateParams);

    if (!$updateStmt) {
        $errors = sqlsrv_errors();
        $errorDetails = $errors ? $errors[0]['message'] : 'Unknown error';
        throw new Exception("Update failed: " . $errorDetails);
    }

    // Check if any rows were affected
    $rowsAffected = sqlsrv_rows_affected($updateStmt);
    if ($rowsAffected === false || $rowsAffected === 0) {
        throw new Exception("No rows were updated. Record may not exist or no changes were made.");
    }

    sqlsrv_commit($conn);
    echo "<script>alert('✅ Batch \"$batchNumber\" updated successfully!'); window.location.href = 'BatchCreationEntryLookup.php';</script>";
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <meta charset="UTF-8">
    <title>Batch Creation Entry Page</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .main-container {
            margin-left: 240px;
            padding: 30px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out;
        }

        .sidebar.hide ~ .main-container,
        .main-container.sidebar-collapsed {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 35px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: slideInUp 0.6s ease-out;
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px 30px;
            border-bottom: none;
        }

        .form-header h3 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-body {
            padding: 40px;
        }

        .form-section {
            margin-bottom: 35px;
        }

        .section-title {
            color: #667eea;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            position: relative;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control, .form-select {
            padding: 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control.is-valid {
            border-color: #28a745;
            background: #f8fff9;
        }

        .form-control.is-invalid {
            border-color: #dc3545;
            background: #fff8f8;
        }

        .required {
            color: #dc3545;
            font-weight: bold;
        }

        .input-icon {
            color: #667eea;
            font-size: 1rem;
        }

        .input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .batch-number-container {
            position: relative;
            flex: 1;
        }

        .batch-validation-message {
            font-size: 0.85rem;
            margin-top: 5px;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .batch-validation-message.success {
            color: #155724;
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }

        .batch-validation-message.error {
            color: #721c24;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .batch-validation-message.checking {
            color: #856404;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
        }

    
        .date-display {
            margin-left: 10px;
            font-weight: bold;
            color: #667eea;
            font-size: 0.9rem;
            background: #f0f4ff;
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #d1e7dd;
        }

        .date-input-container {
            position: relative;
        }

        .date-format-hint {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 3px;
            font-style: italic;
        }

        .form-actions {
            background: #f8f9fa;
            padding: 25px 40px;
            margin: 0 -40px -40px -40px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .btn-custom {
            padding: 15px 40px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary-custom {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .btn-primary-custom:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary-custom {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }

        .btn-secondary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
            color: white;
        }

        /* Animations */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .checking {
            animation: pulse 1.5s infinite;
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .main-container {
                margin-left: 0;
                padding: 20px;
            }
            
            .form-body {
                padding: 25px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .input-group {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 600px) {
            .main-container {
                padding: 15px;
            }
            
            .form-body {
                padding: 20px;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .btn-custom {
                padding: 12px 25px;
                font-size: 0.9rem;
                width: 100%;
                justify-content: center;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-plus-circle"></i>
                Batch Creation Entry
            </h1>
        </div>

        <!-- Batch Creation Form -->
        <div class="form-container">
            <div class="form-header">
                <h3>
                    <i class="fas fa-clipboard-list"></i>
                    Batch Information
                </h3>
            </div>
            
            <div class="form-body">
                <form method="post" autocomplete="off" id="batchForm">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="edit_id" value="<?= htmlspecialchars($editData['id']) ?>">
                    <?php endif; ?>
                    
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i>
                            Basic Information
                            <?php if ($isEdit): ?>
                                <span class="badge bg-warning ms-2">Edit Mode</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-tag input-icon"></i>
                                    Brand Name <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" name="brandName" 
                                       value="<?= htmlspecialchars($editData['brand_name'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-barcode input-icon"></i>
                                    Batch Number <span class="required">*</span>
                                </label>
                                <div class="input-group">
                                    <div class="batch-number-container">
                                        <input type="text" class="form-control" name="batchNumber" id="batchNumber" 
                                               value="<?= htmlspecialchars($editData['batch_number'] ?? '') ?>" required>
                                        <div id="batchValidationMessage" class="batch-validation-message" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-box input-icon"></i>
                                    Product Type <span class="required">*</span>
                                </label>
                                <select class="form-select" name="product_type" id="productTypeDDL" required>
                                    <option value="">Select Product Type</option>
                                    <?php foreach($productTypes as $productType): ?>
                                        <option value="<?= htmlspecialchars($productType) ?>"
                                            <?= ($editData['product_type'] ?? '') === $productType ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($productType) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-cut input-icon"></i>
                                    Strip Cutting
                                </label>
                                <input type="text" class="form-control" name="strip_cutting" 
                                       value="<?= htmlspecialchars($editData['strip_cutting'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Dates Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Manufacturing & Expiry Dates
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calendar-check input-icon"></i>
                                    Manufacturing Date <span class="required">*</span>
                                </label>
                                <div class="date-input-container">
                                    <input type="month" class="form-control" name="mfgDate" id="mfgDate" 
                                           value="<?= getMonthValue($editData['mfg_date'] ?? '') ?>" required>
                                    <div class="date-format-hint">Select month and year (MM/YYYY format)</div>
                                </div>
                                <div id="mfgDateDisplay" class="date-display" style="display: none;"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calendar-times input-icon"></i>
                                    Expiry Date <span class="required">*</span>
                                </label>
                                <div class="date-input-container">
                                    <input type="month" class="form-control" name="expDate" id="expDate" 
                                           value="<?= getMonthValue($editData['exp_date'] ?? '') ?>" required>
                                    <div class="date-format-hint">Select month and year (MM/YYYY format)</div>
                                </div>
                                <div id="expDateDisplay" class="date-display" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Specifications Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-flask"></i>
                            Product Specifications
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-oil-can input-icon"></i>
                                    Silicone Oil Qty. per pcs (mg. Minimum)
                                </label>
                                <input type="number" step="0.01" class="form-control" name="silicone_oil_qty" min="0"
                                       value="<?= htmlspecialchars($editData['silicone_oil_qty'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-check-circle input-icon"></i>
                                    Benzocaine 4.5% Used <span class="required">*</span>
                                </label>
                                <select class="form-select" name="benzocaine_used" required>
                                    <option value="">Select</option>
                                    <option value="Yes" <?= ($editData['benzocaine_used'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="No" <?= ($editData['benzocaine_used'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-pills input-icon"></i>
                                    Benzocaine Qty. per pcs (mg. Minimum)
                                </label>
                                <input type="number" step="0.01" class="form-control" name="benzocaine_qty" min="0"
                                       value="<?= htmlspecialchars($editData['benzocaine_qty'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-shopping-cart input-icon"></i>
                                    Order Qty. (Gross)
                                </label>
                                <input type="number" step="0.01" class="form-control" name="order_qty" min="0"
                                       value="<?= htmlspecialchars($editData['order_qty'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-sticky-note"></i>
                            Additional Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-comment input-icon"></i>
                                    Special Requirements
                                </label>
                                <input type="text" class="form-control" name="special_requirement" 
                                       value="<?= htmlspecialchars($editData['special_requirement'] ?? '') ?>"
                                       placeholder="Enter any special requirements...">
                            </div>
                        </div>
                    </div>

                    <!-- Status Field -->
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-tasks input-icon"></i>
                            Status <span class="required">*</span>
                        </label>
                        <select class="form-select" name="status" id="statusDDL" required>
                            <option value="Pending" <?= ($editData['status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Completed" <?= ($editData['status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="BatchCreationEntryLookup.php" class="btn-custom btn-secondary-custom">
                            <i class="fas fa-arrow-left"></i>
                            Back to List
                        </a>
                        
                        <button type="submit" class="btn-custom btn-primary-custom" id="submitBtn">
                            <i class="fas <?= $submitIcon ?>"></i>
                            <?= $submitButtonText ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let batchCheckTimeout = null;
        let lastCheckedBatch = '';
        const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
        const editId = <?= $isEdit ? $editData['id'] : 'null' ?>;

        // Real-time batch number validation
        $('#batchNumber').on('input', function() {
            const batchNumber = $(this).val().trim();
            const messageDiv = $('#batchValidationMessage');
            const submitBtn = $('#submitBtn');
            
            if (batchNumber === '') {
                messageDiv.hide();
                $(this).removeClass('is-valid is-invalid');
                submitBtn.prop('disabled', false);
                return;
            }
            
            if (batchCheckTimeout) {
                clearTimeout(batchCheckTimeout);
            }
            
            messageDiv.removeClass('success error').addClass('checking')
                .html('<i class="fas fa-spinner fa-spin"></i> Checking availability...').show();
            $(this).removeClass('is-valid is-invalid');
            
            batchCheckTimeout = setTimeout(function() {
                if (batchNumber === lastCheckedBatch) return;
                lastCheckedBatch = batchNumber;
                
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'check_batch_number',
                        batch_number: batchNumber,
                        edit_id: editId || ''
                    },
                    dataType: 'json',
                    success: function(response) {
                        messageDiv.removeClass('checking');
                        if (response.exists) {
                            messageDiv.removeClass('success').addClass('error')
                                .html('<i class="fas fa-times-circle"></i> ' + response.message);
                            $('#batchNumber').removeClass('is-valid').addClass('is-invalid');
                            submitBtn.prop('disabled', true);
                        } else {
                            messageDiv.removeClass('error').addClass('success')
                                .html('<i class="fas fa-check-circle"></i> ' + response.message);
                            $('#batchNumber').removeClass('is-invalid').addClass('is-valid');
                            submitBtn.prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        messageDiv.removeClass('checking success').addClass('error')
                            .html('<i class="fas fa-exclamation-triangle"></i> Error checking batch number');
                        $('#batchNumber').removeClass('is-valid is-invalid');
                        submitBtn.prop('disabled', false);
                    }
                });
            }, 500);
        });

        // Format month year display to MM/YYYY
        function formatMonthYear(val) {
            if (!val) return '';
            const [year, month] = val.split('-');
            if (year && month) {
                return month + '/' + year;
            }
        }

        // Date display handlers
        $('#mfgDate').on('change', function() {
            const displayValue = formatMonthYear(this.value);
            const displayDiv = $('#mfgDateDisplay');
            if (displayValue) {
                displayDiv.html('<i class="fas fa-calendar-alt"></i> Manufacturing: ' + displayValue).show();
            } else {
                displayDiv.hide();
            }
        });

        $('#expDate').on('change', function() {
            const displayValue = formatMonthYear(this.value);
            const displayDiv = $('#expDateDisplay');
            if (displayValue) {
                displayDiv.html('<i class="fas fa-calendar-times"></i> Expiry: ' + displayValue).show();
            } else {
                displayDiv.hide();
            }
        });

        // Enhanced form submission
        $('#batchForm').on('submit', function(e) {
            const batchNumber = $('#batchNumber').val().trim();
            const brandName = $('input[name="brandName"]').val().trim();
            const productType = $('select[name="product_type"]').val();
            const mfgDate = $('#mfgDate').val();
            const expDate = $('#expDate').val();
            const benzocaineUsed = $('select[name="benzocaine_used"]').val();
            
            if (!batchNumber || !brandName || !productType || !mfgDate || !expDate || !benzocaineUsed) {
                alert('❌ Please fill in all required fields!');
                e.preventDefault();
                return false;
            }
            
            if ($('#batchNumber').hasClass('is-invalid')) {
                alert('❌ Please enter a unique batch number!');
                e.preventDefault();
                return false;
            }
            
            const mfgDateObj = new Date(mfgDate + '-01');
            const expDateObj = new Date(expDate + '-01');
            
            if (expDateObj <= mfgDateObj) {
                alert('❌ Expiry date must be after manufacturing date!');
                e.preventDefault();
                return false;
            }
            
            const mfgFormatted = formatMonthYear(mfgDate);
            const expFormatted = formatMonthYear(expDate);
            
            const actionText = isEditMode ? 'update' : 'create';
            const confirmed = confirm(`✅ Are you sure you want to ${actionText} batch "${batchNumber}"?\n\n` +
                                `Manufacturing Date: ${mfgFormatted}\n` +
                                `Expiry Date: ${expFormatted}\n` +
                                `Product Type: ${productType}`);
            if (!confirmed) {
                e.preventDefault();
                return false;
            }
            
            const submitBtn = $('#submitBtn');
            const originalText = submitBtn.html();
            const loadingText = isEditMode ? 
                '<i class="fas fa-spinner fa-spin"></i> Updating Batch...' : 
                '<i class="fas fa-spinner fa-spin"></i> Creating Batch...';
            
            submitBtn.prop('disabled', true).html(loadingText);
            
            return true;
        });

        // Initialize displays on page load
        $(document).ready(function() {
            const mfgDate = $('#mfgDate').val();
            const expDate = $('#expDate').val();
            
            if (mfgDate) {
                const displayValue = formatMonthYear(mfgDate);
                $('#mfgDateDisplay').html('<i class="fas fa-calendar-alt"></i> Manufacturing: ' + displayValue).show();
            }
            if (expDate) {
                const displayValue = formatMonthYear(expDate);
                $('#expDateDisplay').html('<i class="fas fa-calendar-times"></i> Expiry: ' + displayValue).show();
            }
            
            // Show edit mode indicator
            if (isEditMode) {
                $('input[name="brandName"]').focus();
            }
        });

        // Date validation
        $('#mfgDate, #expDate').on('change', function() {
            const mfgDate = $('#mfgDate').val();
            const expDate = $('#expDate').val();
            
            if (mfgDate && expDate) {
                const mfgDateObj = new Date(mfgDate + '-01');
                const expDateObj = new Date(expDate + '-01');
                
                if (expDateObj <= mfgDateObj) {
                    alert('❌ Expiry date must be after manufacturing date!');
                    $(this).val('');
                }
            }
        });

        // Select2 initialization
        $(document).ready(function() {
            $('#productTypeDDL').select2({
                placeholder: "Select Product Type",
                allowClear: true,
                width: '100%'
            });

            $('#statusDDL').select2({
                minimumResultsForSearch: Infinity,
                width: '100%'
            });
        });
    </script>

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</body>
</html>