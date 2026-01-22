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

$message = "";
$edit_data = null;

// Get machine list for Dipping department (same as Quick Add)
$machine_list = [];
$machine_errors = [];

// Try the primary query first
$machine_sql = "SELECT m.machine_id, m.machine_name, d.department_name 
                FROM machines m 
                LEFT JOIN departments d ON m.department_id = d.id 
                WHERE d.department_name = 'Dipping' 
                ORDER BY m.machine_id";
$machine_result = sqlsrv_query($conn, $machine_sql);

if ($machine_result) {
    while ($machine_row = sqlsrv_fetch_array($machine_result, SQLSRV_FETCH_ASSOC)) {
        $machine_list[] = $machine_row;
    }
    sqlsrv_free_stmt($machine_result);
} else {
    $machine_errors[] = "Primary query failed: " . print_r(sqlsrv_errors(), true);
    
    // Try alternative query with direct department match
    $machine_sql_alt = "SELECT machine_id, machine_name FROM machines WHERE department = 'Dipping' ORDER BY machine_id";
    $machine_result = sqlsrv_query($conn, $machine_sql_alt);
    
    if ($machine_result) {
        while ($machine_row = sqlsrv_fetch_array($machine_result, SQLSRV_FETCH_ASSOC)) {
            $machine_list[] = $machine_row;
        }
        sqlsrv_free_stmt($machine_result);
    } else {
        $machine_errors[] = "Alternative query failed: " . print_r(sqlsrv_errors(), true);
        
        // Final fallback - get all machines
        $machine_sql_all = "SELECT machine_id, machine_name FROM machines ORDER BY machine_id";
        $machine_result = sqlsrv_query($conn, $machine_sql_all);
        
        if ($machine_result) {
            while ($machine_row = sqlsrv_fetch_array($machine_result, SQLSRV_FETCH_ASSOC)) {
                $machine_list[] = $machine_row;
            }
            sqlsrv_free_stmt($machine_result);
        } else {
            $machine_errors[] = "All machines query failed: " . print_r(sqlsrv_errors(), true);
        }
    }
}

// Get product list
$product_list = [];
$product_sql = "SELECT product_id, product_description, specification, product_type FROM products ORDER BY product_id";
$product_result = sqlsrv_query($conn, $product_sql);
if ($product_result) {
    while ($product_row = sqlsrv_fetch_array($product_result, SQLSRV_FETCH_ASSOC)) {
        $product_list[] = $product_row;
    }
    sqlsrv_free_stmt($product_result);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lot'])) {
    // Sanitize input
    $machine_no = trim($_POST['machine_no']);
    $lot_no = trim($_POST['lot_no']);
    $product_id = trim($_POST['product_id']);
    $length = trim($_POST['length']);
    $thickness = trim($_POST['thickness']);
    $specification = trim($_POST['specification']);
    $product_description = trim($_POST['product_description']);
    $product_type = trim($_POST['product_type']);
    $weight = trim($_POST['weight'] ?? null);
    $color = trim($_POST['color'] ?? null);

    // Validate lot number - must be exactly 6 digits, letter is optional
    if (!empty($lot_no)) {
        // Check if length is valid (6 or 7 characters)
        if (strlen($lot_no) < 6 || strlen($lot_no) > 7) {
            $message = "Error: Lot number must be 6 digits (letter optional). Your input has " . strlen($lot_no) . " characters.";
        }
        // Check pattern - 6 digits with optional letter
        else if (strlen($lot_no) == 6) {
            // Only 6 digits - check if all are digits
            if (!preg_match('/^\d{6}$/', $lot_no)) {
                $message = "Error: Lot number must be exactly 6 digits (e.g., 123456). Invalid format: '$lot_no'";
            }
        }
        else if (strlen($lot_no) == 7) {
            // 6 digits + 1 letter - check pattern
            if (!preg_match('/^\d{6}[A-Za-z]{1}$/', $lot_no)) {
                $message = "Error: Lot number format: 6 digits + optional letter (e.g., 123456 or 123456A). Invalid format: '$lot_no'";
            }
        }
    }
    
    if (empty($message)) {
        // Check if lot_no already exists
        $check_sql = "SELECT COUNT(*) as count FROM lots WHERE lot_no = ? AND is_deleted = 0";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$lot_no]);
        $check_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);

        if ($check_row['count'] > 0) {
            $message = "Error: Lot No '$lot_no' already exists.";
        } else {
            // Insert new record with is_deleted = 0
            $insert_sql = "INSERT INTO lots 
                            (machine_no, lot_no, product_id, length, weight, thickness, specification, product_description, product_type, color, is_deleted) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
            $params = [$machine_no, $lot_no, $product_id, $length, $weight, $thickness, $specification, $product_description, $product_type, $color];
            $stmt = sqlsrv_query($conn, $insert_sql, $params);

            if ($stmt !== false) {
                $message = "Lot created successfully!";
                // Clear form by redirecting
                header("Location: LotCreationForm.php?success=1");
                exit;
            } else {
                $message = "Error creating lot.";
            }
        }
    }
}

// Check for success message
if (isset($_GET['success'])) {
    $message = "Lot created successfully!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create New Lot - Dipping Process</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .main-content {
            margin-left: 240px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin: 10px 0 0 0;
        }
        
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #495057;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: #667eea;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-label .input-icon {
            color: #667eea;
        }
        
        .required {
            color: #dc3545;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .readonly-field {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        
        .lot-number-input {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: 600;
            text-align: center;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }
        
        .lot-number-input.is-valid {
            border-color: #28a745;
            background-color: #f8fff9;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }
        
        .lot-number-input.is-invalid {
            border-color: #dc3545;
            background-color: #fff8f8;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .lot-number-input.digits-complete {
            border-color: #ffc107;
            background-color: #fffbf0;
            box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25);
        }
        
        .lot-number-input.digits-partial {
            border-color: #17a2b8;
            background-color: #f0fbff;
            box-shadow: 0 0 0 0.1rem rgba(23, 162, 184, 0.15);
        }
        
        /* Enhanced status styling */
        .format-status {
            font-weight: bold;
            margin-left: 5px;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .format-status.status-empty {
            color: #6c757d;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        
        .format-status.status-partial {
            color: #17a2b8;
            background: #e7f7ff;
            border: 1px solid #bee5eb;
            animation: pulse-info 2s infinite;
        }
        
        .format-status.status-complete {
            color: #856404;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            animation: pulse-warning 2s infinite;
        }
        
        .format-status.status-valid {
            color: #155724;
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .format-status.status-invalid {
            color: #721c24;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        @keyframes pulse-info {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes pulse-warning {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        
        .form-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
            text-align: right;
        }
        
        .form-actions .btn {
            margin-left: 10px;
        }
        
        /* Select2 styling */
        .select2-container--default .select2-selection--single {
            height: 46px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px;
            padding-left: 0;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }
        
        .select2-dropdown {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .navigation-buttons {
            margin-bottom: 20px;
        }
        
        .btn-back {
            background: linear-gradient(45deg, #6c757d, #5a6268);
            color: white;
            border: none;
            border-radius: 25px;
            padding: 10px 20px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.4);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Navigation -->
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-plus-circle"></i>
                Create New Lot
            </h1>
            <p class="page-subtitle">Add a new lot for the dipping process with all required specifications</p>
            
         
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo (strpos($message, 'Error') === 0) ? 'alert-danger' : 'alert-success'; ?>" role="alert">
                <i class="fas <?php echo (strpos($message, 'Error') === 0) ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Main Form -->
        <div class="form-container">
            <form method="POST" id="lotForm" onsubmit="return validateForm()">
                <!-- Basic Information Section -->
                <div class="mb-4">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i>
                        Basic Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-cog input-icon"></i>
                                    Machine No (Dipping Department) <span class="required">*</span>
                                </label>
                                <select class="form-control machine-select2" name="machine_no" id="machine_no" required style="width:100%;">
                                    <option value="">-- Select Machine --</option>
                                    <?php foreach ($machine_list as $machine): ?>
                                        <option value="<?= htmlspecialchars($machine['machine_id']) ?>" 
                                                data-machine-name="<?= htmlspecialchars($machine['machine_name']) ?>">
                                            <?= htmlspecialchars($machine['machine_id']) ?> - <?= htmlspecialchars($machine['machine_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Machines found: <?= count($machine_list) ?> | First machine: <?= count($machine_list) > 0 ? htmlspecialchars($machine_list[0]['machine_id']) : 'None' ?>
                                </small>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-hashtag input-icon"></i>
                                    Lot No <span class="required">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control lot-number-input" 
                                       name="lot_no" 
                                       id="lot_no" 
                                       required 
                                       placeholder="Enter 6 digits (letter optional)..."
                                       pattern="(\d{6}|\d{6}[A-Za-z]{1})" 
                                       maxlength="7"
                                       title="Lot number: 6 digits required, letter optional (e.g., 123456 or 123456A)"
                                       autocomplete="off"
                                       oninput="validateLotNumber(this); if(this.value.length > 7) this.value = this.value.slice(0, 7);"
                                       onblur="validateLotNumberOnBlur(this)"
                                       onchange="validateLotNumber(this)"
                                       onkeypress="return (event.charCode >= 48 && event.charCode <= 57) || (event.target.value.length >= 6 && event.charCode >= 65 && event.charCode <= 90) || (event.target.value.length >= 6 && event.charCode >= 97 && event.charCode <= 122) ? true : false">
                                <div class="invalid-feedback" id="lot_invalid_feedback">
                                    Please enter exactly 6 digits. Letter is optional (e.g., 123456 or 123456A)
                                </div>
                                <div class="valid-feedback" id="lot_valid_feedback">
                                    ✓ Valid lot number format - ready to save!
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    Format: <strong>6 digits</strong> (required) + <strong>letter</strong> (optional)<br>
                                    Valid: 123456 ✓ | 123456A ✓ | Current: <span id="lot_length">0</span>/6-7 
                                    <span id="format_status" class="format-status">Enter 6 digits to start</span>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Information Section -->
                <div class="mb-4">
                    <div class="section-title">
                        <i class="fas fa-cube"></i>
                        Product Information
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-barcode input-icon"></i>
                                    Product ID <span class="required">*</span>
                                </label>
                                <select class="form-control product-select2" name="product_id" id="product_id" required style="width:100%;" onchange="updateProductFields()">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach ($product_list as $product): ?>
                                        <option value="<?= htmlspecialchars($product['product_id']) ?>" 
                                                data-description="<?= htmlspecialchars($product['product_description']) ?>"
                                                data-specification="<?= htmlspecialchars($product['specification']) ?>"
                                                data-type="<?= htmlspecialchars($product['product_type']) ?>">
                                            <?= htmlspecialchars($product['product_id']) ?> - <?= htmlspecialchars($product['product_description']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Products available: <?= count($product_list) ?></small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-list-alt input-icon"></i>
                                    Specification
                                </label>
                                <input type="text" class="form-control" name="specification" id="specification" 
                                       placeholder="Enter product specifications">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-palette input-icon"></i>
                                    Color <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" name="color" id="color" required
                                       placeholder="Enter color (e.g., Red, Blue, Green)">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dimensions Section -->
                <div class="mb-4">
                    <div class="section-title">
                        <i class="fas fa-ruler-combined"></i>
                        Dimensions
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-ruler input-icon"></i>
                                    Length minimum (mm) <span class="required">*</span>
                                </label>
                                <input type="text"
                                       class="form-control"
                                       name="length"
                                       id="length"
                                       required
                                       placeholder="e.g. 10-12, 5+2, 8=10"
                                       pattern="^[0-9.\-~+=\s]+$"
                                       title="Enter a range (e.g. 10-12, 5+2, 8=10) or single value">
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-arrows-alt-h input-icon"></i>
                                    Width (mm) <span class="required">*</span>
                                </label>
                                <input type="text"
                                       class="form-control"
                                       name="weight"
                                       id="weight"
                                       required
                                       placeholder="e.g. 5.5-6.0"
                                       pattern="^[0-9.\-~ ]+$"
                                       title="Enter a range (e.g. 5.5-6.0, 5.5~6.0) or a single value">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-arrows-alt-v input-icon"></i>
                                    Thickness (mm) <span class="required">*</span>
                                </label>
                                <input type="text"
                                       class="form-control"
                                       name="thickness"
                                       id="thickness"
                                       required
                                       placeholder="e.g. 0.05-0.07"
                                       pattern="^[0-9.\-~ ]+$"
                                       title="Enter a range (e.g. 0.05-0.07, 0.05~0.07) or a single value">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Details Section -->
                <div class="mb-4">
                    <div class="section-title">
                        <i class="fas fa-clipboard-list"></i>
                        Product Details
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-align-left input-icon"></i>
                                    Product Description
                                </label>
                                <input type="text" class="form-control readonly-field" name="product_description" id="product_description" readonly placeholder="Auto-filled from product">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-tag input-icon"></i>
                                    Product Type
                                </label>
                                <input type="text" class="form-control readonly-field" name="product_type" id="product_type" readonly placeholder="Auto-filled from product">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="lotcreation.php" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" name="save_lot" class="btn btn-primary" id="save_lot_btn">
                        <i class="fas fa-save me-2"></i>Create Lot
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            console.log('Form loaded. Machine list count:', <?= count($machine_list) ?>);
            console.log('Product list count:', <?= count($product_list) ?>);
            
            // Initialize Select2 with detailed logging
            try {
                $('.machine-select2').select2({
                    placeholder: "-- Select Machine from Dipping Department --",
                    allowClear: true,
                    width: '100%'
                });
                console.log('Machine Select2 initialized successfully');
            } catch (error) {
                console.error('Error initializing machine Select2:', error);
            }

            try {
                $('.product-select2').select2({
                    placeholder: "-- Select Product --",
                    allowClear: true,
                    width: '100%'
                });
                console.log('Product Select2 initialized successfully');
            } catch (error) {
                console.error('Error initializing product Select2:', error);
            }
            
            // Check if options are present
            const machineOptions = $('#machine_no option').length;
            const productOptions = $('#product_id option').length;
            console.log('Machine dropdown options:', machineOptions);
            console.log('Product dropdown options:', productOptions);
        });

        // Product field update function
        function updateProductFields() {
            const productSelect = document.getElementById('product_id');
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                document.getElementById('product_description').value = selectedOption.dataset.description || '';
                document.getElementById('specification').value = selectedOption.dataset.specification || '';
                document.getElementById('product_type').value = selectedOption.dataset.type || '';
            } else {
                document.getElementById('product_description').value = '';
                document.getElementById('specification').value = '';
                document.getElementById('product_type').value = '';
            }
        }

        // Enhanced lot number validation with proper conditions
        function validateLotNumber(input) {
            const value = input.value.trim();
            const length = value.length;
            const lengthSpan = document.getElementById('lot_length');
            const statusSpan = document.getElementById('format_status');
            const invalidFeedback = document.getElementById('lot_invalid_feedback');
            const validFeedback = document.getElementById('lot_valid_feedback');
            
            // Update length display
            if (lengthSpan) lengthSpan.textContent = length;
            
            // Remove all validation classes
            input.classList.remove('is-valid', 'is-invalid', 'digits-complete', 'digits-partial');
            
            // Clear previous feedback
            if (invalidFeedback) invalidFeedback.textContent = '';
            if (validFeedback) validFeedback.textContent = '';
            
            if (length === 0) {
                // Empty state
                if (statusSpan) {
                    statusSpan.textContent = 'Enter 6 digits to start';
                    statusSpan.className = 'format-status status-empty';
                }
                return false;
            }
            
            if (length < 6) {
                // Partial entry - needs more digits
                const remainingDigits = 6 - length;
                const isAllDigits = /^\d+$/.test(value);
                
                if (isAllDigits) {
                    input.classList.add('digits-partial');
                    if (statusSpan) {
                        statusSpan.textContent = `Need ${remainingDigits} more digit${remainingDigits > 1 ? 's' : ''}`;
                        statusSpan.className = 'format-status status-partial';
                    }
                } else {
                    input.classList.add('is-invalid');
                    if (statusSpan) {
                        statusSpan.textContent = 'Only digits allowed';
                        statusSpan.className = 'format-status status-invalid';
                    }
                    if (invalidFeedback) {
                        invalidFeedback.textContent = 'Only numbers are allowed for the first 6 characters';
                    }
                }
                return false;
            }
            
            if (length === 6) {
                // Exactly 6 characters - check if all digits
                if (/^\d{6}$/.test(value)) {
                    input.classList.add('is-valid'); // Changed to is-valid to show as fully complete
                    if (statusSpan) {
                        statusSpan.textContent = '✓ Valid! Ready to save';
                        statusSpan.className = 'format-status status-valid';
                    }
                    if (validFeedback) {
                        validFeedback.textContent = '✓ Valid lot number format';
                    }
                    return true; // Valid as 6 digits
                } else {
                    input.classList.add('is-invalid');
                    if (statusSpan) {
                        statusSpan.textContent = 'Must be 6 digits only';
                        statusSpan.className = 'format-status status-invalid';
                    }
                    if (invalidFeedback) {
                        invalidFeedback.textContent = 'Must be exactly 6 digits (0-9)';
                    }
                    return false;
                }
            }
            
            if (length === 7) {
                // 7 characters - check format: 6 digits + 1 letter
                if (/^\d{6}[A-Za-z]{1}$/.test(value)) {
                    input.classList.add('is-valid');
                    if (statusSpan) {
                        statusSpan.textContent = '✓ Perfect! Valid format';
                        statusSpan.className = 'format-status status-valid';
                    }
                    if (validFeedback) {
                        validFeedback.textContent = '✓ Perfect! Valid lot number format';
                    }
                    return true;
                } else {
                    input.classList.add('is-invalid');
                    if (statusSpan) {
                        statusSpan.textContent = 'Invalid: 7 digits not allowed';
                        statusSpan.className = 'format-status status-invalid';
                    }
                    if (invalidFeedback) {
                        invalidFeedback.textContent = '7 digits not allowed. Use: 6 digits (123456) OR 6 digits + 1 letter (123456A)';
                    }
                    return false;
                }
            }
            
            if (length > 7) {
                // Too long
                input.classList.add('is-invalid');
                if (statusSpan) {
                    statusSpan.textContent = 'Maximum 7 characters';
                    statusSpan.className = 'format-status status-invalid';
                }
                if (invalidFeedback) {
                    invalidFeedback.textContent = 'Maximum 7 characters allowed (6 digits + optional letter)';
                }
                return false;
            }
            
            return false;
        }

        function validateLotNumberOnBlur(input) {
            validateLotNumber(input);
        }

        // Enhanced form validation
        function validateForm() {
            const lotNo = document.getElementById('lot_no').value.trim();
            const machineNo = document.getElementById('machine_no').value;
            const productId = document.getElementById('product_id').value;
            const color = document.getElementById('color').value.trim();
            const length = document.getElementById('length').value.trim();
            const weight = document.getElementById('weight').value.trim();
            const thickness = document.getElementById('thickness').value.trim();

            // Check required fields
            if (!machineNo) {
                alert('Please select a machine.');
                document.getElementById('machine_no').focus();
                return false;
            }
            
            if (!lotNo) {
                alert('Please enter a lot number.');
                document.getElementById('lot_no').focus();
                return false;
            }
            
            if (!productId) {
                alert('Please select a product.');
                document.getElementById('product_id').focus();
                return false;
            }
            
            if (!color) {
                alert('Please enter a color.');
                document.getElementById('color').focus();
                return false;
            }
            
            if (!length) {
                alert('Please enter length specifications.');
                document.getElementById('length').focus();
                return false;
            }
            
            if (!weight) {
                alert('Please enter width specifications.');
                document.getElementById('weight').focus();
                return false;
            }
            
            if (!thickness) {
                alert('Please enter thickness specifications.');
                document.getElementById('thickness').focus();
                return false;
            }

            // Validate lot number format using our enhanced validation
            const lotInput = document.getElementById('lot_no');
            const isValidLot = validateLotNumber(lotInput);
            
            if (!isValidLot) {
                alert('Please enter a valid lot number:\n\n' +
                      '• Exactly 6 digits (required)\n' +
                      '• Optional single letter at the end\n' +
                      '• Examples: 123456 or 123456A');
                lotInput.focus();
                return false;
            }

            // Additional lot number pattern validation
            if (!/^(\d{6}|\d{6}[A-Za-z]{1})$/.test(lotNo)) {
                alert('Invalid lot number format!\n\n' +
                      'Required format: 6 digits + optional letter\n' +
                      'Valid examples: 123456, 123456A, 789012B\n' +
                      'Invalid examples: 12345, 1234567, 123456AB');
                document.getElementById('lot_no').focus();
                return false;
            }

            return true;
        }

        // Handle sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            
            function adjustMainContent() {
                if (sidebar && sidebar.classList.contains('hide')) {
                    mainContent.classList.add('sidebar-collapsed');
                } else {
                    mainContent.classList.remove('sidebar-collapsed');
                }
            }
            
            adjustMainContent();
            
            if (sidebar) {
                const observer = new MutationObserver(adjustMainContent);
                observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
            }
            
            window.addEventListener('resize', adjustMainContent);
        });
    </script>
</body>
</html>