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

// Handle Delete (Soft Delete by setting is_deleted = 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];
    $sql = "UPDATE lots SET is_deleted = 1 WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$delete_id]);

    if ($stmt === false) {
        $message = "Error deleting lot.";
    } else {
        $message = "Lot deleted successfully (soft delete)!";
    }
}

// Handle Save or Update
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
    $edit_id = $_POST['edit_id'] ?? null;

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
        if ($edit_id) {
            // Check for duplicate lot_no excluding current ID
            $check_sql = "SELECT COUNT(*) as count FROM lots WHERE lot_no = ? AND id != ? AND is_deleted = 0";
            $check_stmt = sqlsrv_query($conn, $check_sql, [$lot_no, $edit_id]);
            $check_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);

            if ($check_row['count'] > 0) {
                $message = "Error: Lot No '$lot_no' already exists.";
            } else {
                // Update record
                $update_sql = "UPDATE lots 
                               SET machine_no = ?, lot_no = ?, product_id = ?, length = ?, weight = ?, thickness = ?, 
                                   specification = ?, product_description = ?, product_type = ?, color = ? 
                               WHERE id = ?";
                $params = [$machine_no, $lot_no, $product_id, $length, $weight, $thickness, $specification, $product_description, $product_type, $color, $edit_id];
                $stmt = sqlsrv_query($conn, $update_sql, $params);

                $message = ($stmt !== false) ? "Lot updated successfully!" : "Error updating lot.";
            }
        } else {
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

                $message = ($stmt !== false) ? "Lot created successfully!" : "Error creating lot.";
            }
        }
    }
}

// Fetch machines (only Dipping department)
$machine_list = [];
$machine_sql = "SELECT m.*, d.department_name 
                FROM machines m 
                LEFT JOIN departments d ON m.department_id = d.id 
                WHERE d.department_name = 'Dipping' 
                ORDER BY m.machine_id";
$machine_stmt = sqlsrv_query($conn, $machine_sql);
while ($row = sqlsrv_fetch_array($machine_stmt, SQLSRV_FETCH_ASSOC)) {
    $machine_list[] = $row;
}

// Fetch product list
$product_list = [];
$product_sql = "SELECT product_id, product_description, specification, product_type FROM products ORDER BY product_id";
$product_stmt = sqlsrv_query($conn, $product_sql);
if ($product_stmt) {
    while ($row = sqlsrv_fetch_array($product_stmt, SQLSRV_FETCH_ASSOC)) {
        $product_list[] = $row;
    }
}
if (empty($product_list)) {
    $product_list[] = [
        'product_id' => 'SAMPLE001',
        'product_description' => 'Sample Product - Please add products to database',
        'specification' => 'Sample Specification',
        'product_type' => 'Sample Type'
    ];
    $message = "Warning: Product table is empty.";
}

// Fetch lots
$lots_data = [];
$lots_sql = "SELECT l.*, m.machine_name, d.department_name 
             FROM lots l 
             LEFT JOIN machines m ON l.machine_no = m.machine_id 
             LEFT JOIN departments d ON m.department_id = d.id 
             WHERE l.is_deleted = 0 AND d.department_name = 'Dipping'
             ORDER BY l.created_at DESC";
$lots_stmt = sqlsrv_query($conn, $lots_sql);
while ($row = sqlsrv_fetch_array($lots_stmt, SQLSRV_FETCH_ASSOC)) {
    $lots_data[] = $row;
}

// Statistics
$totalLots = $todayLots = $activeMachines = 0;

$total_stmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM lots WHERE is_deleted = 0");
if ($row = sqlsrv_fetch_array($total_stmt, SQLSRV_FETCH_ASSOC)) {
    $totalLots = $row['count'];
}

$today_stmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM lots WHERE is_deleted = 0 AND CAST(created_at AS DATE) = CAST(GETDATE() AS DATE)");
if ($row = sqlsrv_fetch_array($today_stmt, SQLSRV_FETCH_ASSOC)) {
    $todayLots = $row['count'];
}

$active_stmt = sqlsrv_query($conn, "SELECT COUNT(DISTINCT machine_no) as count FROM lots WHERE is_deleted = 0");
if ($row = sqlsrv_fetch_array($active_stmt, SQLSRV_FETCH_ASSOC)) {
    $activeMachines = $row['count'];
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Lot Creation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to lot creation page */
        .lot-number-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .product-id-badge {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .machine-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        /* Fix modal and form overlap issues */
        .modal {
            z-index: 1055 !important;
        }
        
        .modal-backdrop {
            z-index: 1050 !important;
        }
        
        /* Compact form styling for professional appearance */
        .form-group {
            margin-bottom: 1rem !important; /* Further reduced from 1.25rem */
            position: relative;
            z-index: 1;
            clear: both;
        }
        
        /* Prevent any floating elements from overlapping */
        .form-group::after {
            content: "";
            display: table;
            clear: both;
        }
        
        /* Enhanced lot number input styling */
        .lot-input-container {
            position: relative;
        }
        
        .lot-number-input {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: 600;
            text-align: center;
            letter-spacing: 1px;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .lot-number-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            z-index: 3;
        }
        
        .lot-number-input.is-valid {
            border-color: #28a745;
            background-color: #f8fff9;
        }
        
        .lot-number-input.is-invalid {
            border-color: #dc3545;
            background-color: #fff8f8;
        }
        
        .lot-number-input.digits-complete {
            border-color: #ffc107;
            background-color: #fffbf0;
            box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25);
        }
        
        /* Character counter styling */
        #lot_length {
            font-weight: bold;
            color: #667eea;
        }
        
        .format-status {
            font-weight: bold;
            margin-left: 10px;
        }
        
        .format-status.digits-needed {
            color: #6c757d;
        }
        
        .format-status.letter-needed {
            color: #ffc107;
            animation: pulse 1.5s infinite;
        }
        
        .format-status.complete {
            color: #28a745;
        }
        
        /* Compact feedback messages */
        .invalid-feedback,
        .valid-feedback {
            display: block !important;
            margin-top: 0.5rem !important; /* Reduced from 0.75rem */
            margin-bottom: 0.25rem !important; /* Reduced from 0.5rem */
            font-size: 0.875rem;
            line-height: 1.4;
            position: relative;
            z-index: 1;
            clear: both;
        }
        
        /* Compact help text */
        .text-muted {
            margin-top: 0.5rem !important; /* Reduced from 0.75rem */
            margin-bottom: 0.25rem !important; /* Reduced from 0.5rem */
            display: block;
            clear: both;
            position: relative;
            z-index: 1;
        }
        
        /* Fix Select2 dropdowns from overlapping and display issues */
        .select2-container {
            z-index: 1060 !important; /* Higher z-index */
            width: 100% !important;
        }
        
        .select2-dropdown {
            z-index: 1065 !important; /* Even higher for dropdown */
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        
        /* Fix Select2 selection height to match form controls */
        .select2-container .select2-selection--single {
            height: 36px !important; /* Match form-control height */
            border: 1px solid #ced4da !important;
            border-radius: 4px !important;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 34px !important; /* Center text vertically */
            font-size: 13px !important; /* Match form-control font size */
            padding-left: 10px !important;
            color: #495057 !important;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 34px !important; /* Match selection height */
            right: 10px !important;
        }
        
        /* Fix dropdown positioning and appearance */
        .select2-container--open .select2-dropdown--below {
            border-top: none !important;
            margin-top: 1px !important;
        }
        
        .select2-results__option {
            padding: 6px 10px !important; /* Compact options */
            font-size: 13px !important;
        }
        
        .select2-results__option--highlighted {
            background-color: #007bff !important;
            color: white !important;
        }
        
        /* Custom dropdown class for modal */
        .select2-dropdown-modal {
            z-index: 1070 !important; /* Highest z-index for modal dropdowns */
        }
        
        /* Very compact spacing between form sections */
        .form-section {
            margin-bottom: 1.5rem !important; /* Further reduced from 1.75rem */
            padding-bottom: 0.5rem; /* Reduced from 0.75rem */
        }
        
        /* Compact label spacing */
        .form-label {
            margin-bottom: 0.5rem !important; /* Reduced from 0.75rem */
            display: block;
            clear: both;
        }
        
        .machine-name {
            color: #6c757d;
            font-size: 0.8rem;
            font-style: italic;
        }
        
        .dimension-value {
            color: #28a745;
            font-weight: 600;
        }
        
        .product-type-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-raw {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-finished {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }
        
        .badge-semi {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .modal-content {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .readonly-field {
            background-color: #f8f9fa !important;
            cursor: not-allowed;
        }
        
        .no-data-message {
            text-align: center;
            padding: 30px 20px; /* Reduced from 40px */
            color: #6c757d;
        }
        
        .no-data-icon {
            font-size: 2.5rem; /* Reduced from 3rem */
            color: #dee2e6;
            margin-bottom: 10px; /* Reduced from 15px */
        }
        
        .add-new-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .add-new-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        /* Enhanced Select2 styling */
        .select2-container {
            z-index: 99999 !important;
            width: 100% !important;
        }

        .select2-container--default .select2-selection--single {
            height: 42px !important;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 8px 12px;
            background-color: #fff;
            transition: all 0.3s ease;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 24px;
            padding-left: 0;
            padding-right: 20px;
            color: #495057;
            font-size: 14px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
            position: absolute;
            top: 1px;
            right: 10px;
            width: 20px;
        }
        
        .select2-container--default .select2-selection--single:focus,
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        /* Dropdown styling */
        .select2-dropdown {
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 99999 !important;
        }
        
        .select2-container--default .select2-results__option {
            padding: 8px 12px;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #007bff;
            color: white;
        }
        
        .select2-search--dropdown .select2-search__field {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 14px;
        }
        
        /* Form row improvements */
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -8px;
            gap: 15px;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
            margin-bottom: 20px;
            padding: 0 8px;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 6px 10px; /* Reduced padding for more compact fields */
            font-size: 13px; /* Smaller font size */
            line-height: 1.4; /* Tighter line height */
            color: #495057;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 4px; /* Smaller border radius */
            transition: all 0.3s ease;
            height: 36px; /* Reduced height from 42px */
        }

        .form-control:focus {
            color: #495057;
            background-color: #fff;
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        /* Ensure modal has proper z-index */
        .modal {
            z-index: 1050;
            overflow-y: auto !important;
        }

        .modal-backdrop {
            z-index: 1040;
        }

        /* Professional compact form styling */
        .modal-body {
            padding: 1.5rem; /* More compact modal body */
        }

        .modal-header {
            padding: 1rem 1.5rem; /* Reduced header padding */
            border-bottom: 1px solid #e9ecef;
        }

        .modal-footer {
            padding: 1rem 1.5rem; /* Reduced footer padding */
            border-top: 1px solid #e9ecef;
        }

        /* Compact row spacing */
        .row {
            margin-bottom: 0.75rem;
        }

        /* Reduce excessive spacing in small elements */
        small.text-muted {
            margin-top: 0.25rem !important;
            margin-bottom: 0.1rem !important;
            font-size: 0.8rem;
        }

        /* Make cards more compact */
        .card-body {
            padding: 1.25rem; /* Reduced from default 1.5rem */
        }

        .card-header {
            padding: 0.75rem 1.25rem; /* More compact header */
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        /* Additional compact styling */
        .compact-modal .modal-dialog {
            max-width: 900px; /* Smaller modal width */
        }

        .modal-body {
            padding: 1.25rem; /* Reduced modal body padding */
        }

        .modal-header {
            padding: 0.875rem 1.25rem; /* Reduced header padding */
        }

        .modal-footer {
            padding: 0.875rem 1.25rem; /* Reduced footer padding */
        }

        /* Compact labels */
        .form-label {
            margin-bottom: 0.375rem !important; /* Further reduced label spacing */
            font-size: 0.9rem; /* Smaller label font */
            font-weight: 500;
        }

        /* Compact section titles */
        .section-title {
            font-size: 1rem; /* Reduced from 1.1rem */
            margin-bottom: 0.75rem; /* Reduced spacing */
            padding-bottom: 0.375rem;
        }

        /* Compact select2 elements */
        .select2-container .select2-selection--single {
            height: 36px !important; /* Match form-control height */
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 34px !important; /* Adjust line-height for vertical centering */
            font-size: 13px;
        }

        /* Compact small text */
        small.text-muted {
            font-size: 0.75rem; /* Smaller help text */
            margin-top: 0.25rem !important;
        }

        /* Compact row spacing */
        .row {
            margin-bottom: 0.5rem; /* Reduced row spacing */
        }

        /* Compact buttons */
        .btn {
            padding: 0.5rem 1rem; /* Smaller button padding */
            font-size: 0.9rem;
        }

        /* Action buttons styling */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }

        .action-buttons a,
        .action-buttons button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            background: none;
            transition: all 0.3s ease;
            cursor: pointer;
            font-size: 14px;
        }

        .action-buttons a:hover,
        .action-buttons button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Edit button styling */
        .action-buttons .edit-btn {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white !important;
            border: 1px solid #17a2b8;
        }

        .action-buttons .edit-btn:hover {
            background: linear-gradient(45deg, #138496, #117a8b);
            color: white !important;
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
        }

        /* Delete button styling */
        .action-buttons .delete-btn {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white !important;
            border: 1px solid #dc3545;
        }

        .action-buttons .delete-btn:hover {
            background: linear-gradient(45deg, #c82333, #bd2130);
            color: white !important;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }

        /* View button styling (if needed) */
        .action-buttons .view-btn {
            background: linear-gradient(45deg, #28a745, #218838);
            color: white !important;
            border: 1px solid #28a745;
        }

        .action-buttons .view-btn:hover {
            background: linear-gradient(45deg, #218838, #1e7e34);
            color: white !important;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }

        /* Print button styling (if needed) */
        .action-buttons .print-btn {
            background: linear-gradient(45deg, #6c757d, #5a6268);
            color: white !important;
            border: 1px solid #6c757d;
        }

        .action-buttons .print-btn:hover {
            background: linear-gradient(45deg, #5a6268, #545b62);
            color: white !important;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.4);
        }

        /* Icon styling within buttons */
        .action-buttons i {
            font-size: 14px;
            margin: 0;
        }

        /* Responsive action buttons */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
            
            .action-buttons a,
            .action-buttons button {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
        }

        /* Table cell styling for actions */
        .table td.action-cell {
            text-align: center;
            vertical-align: middle;
            padding: 8px 4px;
            width: 120px;
        }

        /* Additional hover effects */
        .action-buttons .edit-btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
        }

        .action-buttons .delete-btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
    </style>
</head>

<body>
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-industry"></i>
                Lot Creation Management - Dipping Department
            </h1>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, 'Error:') === 0 || strpos($message, 'Warning:') === 0 ? 'alert-warning' : 'alert-success' ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?= strpos($message, 'Error:') === 0 ? 'exclamation-circle' : (strpos($message, 'Warning:') === 0 ? 'exclamation-triangle' : 'check-circle') ?> me-2"></i>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Debug Information (remove in production) -->
        <div class="debug-info">
            <strong>Debug Info:</strong> 
            Products found: <?= count($product_list) ?> | 
            Machines found: <?= count($machine_list) ?> |
            First product: <?= !empty($product_list) ? $product_list[0]['product_id'] : 'None' ?>
        </div>

        <!-- Statistics -->
        <div class="row mb-2"> <!-- Reduced from mb-4 for compact layout -->
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $totalLots; ?></div>
                    <div class="stats-label">Total Lots</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $todayLots; ?></div>
                    <div class="stats-label">Today's Lots</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo count($machine_list); ?></div>
                    <div class="stats-label">Dipping Machines</div>
                </div>
            </div>
        </div>

        <!-- Add New Button -->
        <div class="mb-4">
            <a href="LotCreationForm.php" class="btn add-new-btn">
                <i class="fas fa-plus me-2"></i>Create New Lot
            </a>
            <button type="button" class="btn btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#lotModal" onclick="openAddModal()">
                <i class="fas fa-plus me-2"></i>Quick Add (Modal)
            </button>
        </div>

        <!-- Lot Number Search Form -->
        <form method="get" class="mb-3 d-flex" style="max-width:400px;">
            <input type="text" name="search_lot_no" class="form-control me-2" placeholder="Search by Lot Number" value="<?= isset($_GET['search_lot_no']) ? htmlspecialchars($_GET['search_lot_no']) : '' ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <?php if (!empty($_GET['search_lot_no'])): ?>
                <a href="lotcreation.php" class="btn btn-secondary ms-2">Reset</a>
            <?php endif; ?>
        </form>

        <!-- Lot List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Lot List
                <span class="badge bg-light text-dark ms-2"><?php echo count($lots_data); ?> lots</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 120px;">Actions</th>
                                <th style="width: 80px;">Sr. No.</th>
                                <th>Machine Info</th>
                                <th>Lot No</th>
                                <th>Product ID</th>
                                <th>Length minimum</th>
                                <th>Width</th>
                                <th>Thickness</th>
                                <th>Specification</th>
                                <th>Description</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lots_data) > 0): ?>
                                <?php $i = 1; foreach ($lots_data as $lot): ?>
                                    <tr>
                                        <td class="action-cell">
                                            <div class="action-buttons">
                                                <!-- Edit Button -->
                                                <a href="javascript:void(0);" 
                                                   onclick='editLot(<?= json_encode($lot) ?>)' 
                                                   class="edit-btn" 
                                                   title="Edit Lot"
                                                   data-bs-toggle="tooltip" 
                                                   data-bs-placement="top">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <!-- Delete Button -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($lot['lot_no']) ?>');">
                                                    <input type="hidden" name="delete_id" value="<?= $lot['id'] ?>">
                                                    <button type="submit" 
                                                            class="delete-btn" 
                                                            title="Delete Lot"
                                                            data-bs-toggle="tooltip" 
                                                            data-bs-placement="top">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                                
                                                <!-- Optional: View/Print Button (uncomment if needed) -->
                                                <!--
                                                <a href="view_lot.php?id=<?= $lot['id'] ?>" 
                                                   class="view-btn" 
                                                   title="View Lot Details"
                                                   data-bs-toggle="tooltip" 
                                                   data-bs-placement="top">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                -->
                                            </div>
                                        </td>
                                        <td><strong><?= $i++ ?></strong></td>
                                        <td>
                                            <div class="machine-info">
                                                <span class="machine-number">
                                                    <?= htmlspecialchars($lot['machine_no']) ?>
                                                </span>
                                                <?php if ($lot['machine_name']): ?>
                                                    <span class="machine-name">
                                                        <?= htmlspecialchars($lot['machine_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($lot['department_name']): ?>
                                                    <span class="department-badge">
                                                        <?= htmlspecialchars($lot['department_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="lot-number-badge">
                                                <?= htmlspecialchars($lot['lot_no']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="product-id-badge">
                                                <?= htmlspecialchars($lot['product_id']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="dimension-value">
                                                <?= htmlspecialchars($lot['length']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="dimension-value">
                                                <?= htmlspecialchars($lot['weight']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="dimension-value">
                                                <?= htmlspecialchars($lot['thickness']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($lot['specification']) ?></td>
                                        <td><?= htmlspecialchars($lot['product_description']) ?></td>
                                        <td>
                                            <?php 
                                            $type = strtolower($lot['product_type']);
                                            $badgeClass = 'badge-semi';
                                            if (strpos($type, 'raw') !== false) $badgeClass = 'badge-raw';
                                            elseif (strpos($type, 'finished') !== false) $badgeClass = 'badge-finished';
                                            ?>
                                            <span class="product-type-badge <?= $badgeClass ?>">
                                                <?= htmlspecialchars($lot['product_type']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="no-data-message">
                                        <div>
                                            <i class="fas fa-industry no-data-icon"></i>
                                            <h5>No Lots Available</h5>
                                            <p>No lots have been created yet.</p>
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#lotModal" onclick="openAddModal()">
                                                <i class="fas fa-plus me-2"></i>Create First Lot
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="lotModal" tabindex="-1" aria-labelledby="lotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered compact-modal"> <!-- Compact modal size -->
            <div class="modal-content">
                <form method="POST" id="lotForm" onsubmit="return validateForm()">
                    <div class="modal-header">
                        <h5 class="modal-title" id="lotModalLabel">
                            <i class="fas fa-plus-circle me-2"></i>Add New Lot
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            
                            <div class="row"> <!-- Optimized layout for Basic Information -->
                                <div class="col-md-7"> <!-- Larger column for machine selection -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-cog input-icon"></i>
                                            Machine No (Dipping Department) <span class="required">*</span>
                                        </label>
                                        <!-- Machine No (Dipping Department) -->
<select class="form-control machine-select2" name="machine_no" id="machine_no" required style="width:100%;">
    <option value="">-- Select Machine --</option>
    <?php foreach ($machine_list as $machine): ?>
        <option value="<?= htmlspecialchars($machine['machine_id']) ?>" 
                data-machine-name="<?= htmlspecialchars($machine['machine_name']) ?>">
            <?= htmlspecialchars($machine['machine_id']) ?> - <?= htmlspecialchars($machine['machine_name']) ?>
        </option>
    <?php endforeach; ?>
</select>
                                    </div>
                                </div>

                                <div class="col-md-5"> <!-- Smaller column for lot number (only 7 characters) -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-hashtag input-icon"></i>
                                            Lot No <span class="required">*</span>
                                        </label>
                                        <!-- Enhanced Lot Number Input with Optional Letter (Keyboard Only) -->
                                        <div class="lot-input-container">
                                            <input type="text" 
                                                   class="form-control lot-number-input" 
                                                   name="lot_no" 
                                                   id="lot_no" 
                                                   required 
                                                   placeholder="Enter 6 digits (add letter if needed)..."
                                                   pattern="(\d{6}|\d{6}[A-Za-z]{1})" 
                                                   maxlength="7"
                                                   title="Lot number: 6 digits required, letter optional (e.g., 123456 or 123456A)"
                                                   autocomplete="off"
                                                   oninput="validateLotNumber(this)"
                                                   onblur="validateLotNumberOnBlur(this)"
                                                   onchange="validateLotNumber(this)">
                                        </div>
                                        <div class="invalid-feedback">
                                            Lot number: 6 digits required, letter optional (e.g., 123456 or 123456A)
                                        </div>
                                        <div class="valid-feedback">
                                            Valid lot number format
                                        </div>
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            Format: <strong>6 digits (required) + letter (optional)</strong> (e.g., 123456 or 123456A). Current: <span id="lot_length">0</span>/6-7
                                            <span id="format_status" class="format-status"></span>
                                        </small>
                                    </div>
                                </div>
                            </div> <!-- End of two-column row -->
                        </div>

                        <!-- Product Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-cube"></i>
                                Product Information
                            </div>
                            
                            <div class="row"> <!-- Two-column layout for Product Information -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-barcode input-icon"></i>
                                            Product ID <span class="required">*</span>
                                        </label>
                                        <!-- Product ID -->
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
                                    <!-- In your modal form, replace the specification input with this: -->
<div class="form-group">
    <label class="form-label">
        <i class="fas fa-list-alt input-icon"></i>
        Specification
    </label>
    <input type="text" class="form-control" name="specification" id="specification" 
           placeholder="Enter product specifications">
</div>
                                </div>
                            </div> <!-- End of two-column row for Product Info -->

<!-- Add this new form group for color (after the specification field) -->
<div class="form-group">
    <label class="form-label">
        <i class="fas fa-palette input-icon"></i>
        Color <span class="required">*</span>
    </label>
    <input type="text" class="form-control" name="color" id="color" required
           placeholder="Enter color (e.g., Red, Blue, Green)">
</div>
                        </div>

                        <!-- Dimensions Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-ruler-combined"></i>
                                Dimensions
                            </div>
                            
                            <div class="row"> <!-- Three-column layout for Dimensions -->
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
                            </div> <!-- End of three-column row for Dimensions -->
                        </div>

                        <!-- Product Details Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-clipboard-list"></i>
                                Product Details
                            </div>
                            
                            <div class="row"> <!-- Two-column layout for Product Details -->
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
                            </div> <!-- End of two-column row for Product Details -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" name="save_lot" class="btn btn-success" id="save_lot_btn">
                            <i class="fas fa-save me-2"></i>Save Lot
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
$(document).ready(function() {
    // Remove duplicate Select2 initializations - they're handled below with enhanced configuration

    // Handle sidebar toggle
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

    // Enhanced Select2 initialization for Machine dropdown
    $('.machine-select2').select2({
        placeholder: "-- Select Machine from Dipping Department --",
        allowClear: true,
        width: '100%',
        dropdownParent: $('#lotModal'),
        minimumResultsForSearch: 5, // Show search if more than 5 options
        escapeMarkup: function (markup) {
            return markup;
        },
        templateResult: function(data) {
            if (data.loading) {
                return data.text;
            }
            
            if (!data.id) {
                return data.text;
            }
            
            // Extract machine ID and name from the option text
            const parts = data.text.split(' - ');
            const machineId = parts[0];
            const machineName = parts[1] || '';
            
            var markup = '<div class="machine-option">' +
                '<div class="machine-id"><strong>' + machineId + '</strong></div>';
            
            if (machineName) {
                markup += '<div class="machine-name text-muted">' + machineName + '</div>';
            }
            
            markup += '</div>';
            
            return markup;
        },
        templateSelection: function(data) {
            if (!data.id) {
                return data.text;
            }
            
            const parts = data.text.split(' - ');
            return parts[0] + (parts[1] ? ' - ' + parts[1] : '');
        }
    });

    // Enhanced Select2 initialization for Product dropdown
    $('.product-select2').select2({
        placeholder: "-- Select Product ID --",
        allowClear: true,
        width: '100%',
        dropdownParent: $('#lotModal'),
        dropdownCssClass: 'select2-dropdown-modal', // Custom CSS class for z-index
        minimumResultsForSearch: 3,
        escapeMarkup: function (markup) { return markup; },
        templateResult: function(data) {
            if (data.loading) return data.text;
            if (!data.id) return data.text;
            const parts = data.text.split(' - ');
            const productId = parts[0];
            const description = parts[1] || '';
            var markup = '<div class="product-option">' +
                '<div class="product-id"><strong>' + productId + '</strong></div>';
            if (description) {
                markup += '<div class="product-desc text-muted">' + description + '</div>';
            }
            markup += '</div>';
            return markup;
        },
        templateSelection: function(data) {
            if (!data.id) return data.text;
            const parts = data.text.split(' - ');
            return parts[0];
        }
    });

    // Handle dropdown opening to ensure proper positioning
    $('.machine-select2, .product-select2').on('select2:open', function() {
        setTimeout(function() {
            $('.select2-dropdown').css('z-index', 10000);
        }, 1);
    });

    // Handle product selection change to update related fields
    $('#product_id').on('change.select2', function() {
        console.log('Product selection changed:', $(this).val());
        updateProductFields();
    });

    // ENHANCED LOT NUMBER VALIDATION - KEYBOARD INPUT ONLY
    $('#lot_no').on('input keyup paste', function(e) {
        let value = $(this).val();
        
        // Clean input: first 6 characters must be digits, 7th can be letter
        let digits = value.substring(0, 6).replace(/[^0-9]/g, '');
        let letter = value.length > 6 ? value.substring(6, 7).replace(/[^A-Za-z]/g, '').toUpperCase() : '';
        
        // Reconstruct value
        value = digits + letter;
        $(this).val(value);
        
        // Update status display
        updateLotNumberStatus(value);
        
        // Visual feedback based on completion
        if (value.length === 6 && /^\d{6}$/.test(value)) {
            // Valid 6 digits - complete and valid
            $(this).removeClass('is-invalid digits-complete').addClass('is-valid');
            $('#save_lot_btn').prop('disabled', false);
        } else if (value.length === 7 && /^\d{6}[A-Z]{1}$/.test(value)) {
            // Valid 6 digits + 1 letter - complete and valid
            $(this).removeClass('is-invalid digits-complete').addClass('is-valid');
            $('#save_lot_btn').prop('disabled', false);
        } else if (value.length > 0 && value.length < 6) {
            // In progress - show as neutral
            $(this).removeClass('is-valid is-invalid').addClass('digits-complete');
            $('#save_lot_btn').prop('disabled', false);
        } else if (value.length > 7) {
            // Too long - invalid
            $(this).removeClass('is-valid digits-complete').addClass('is-invalid');
            $('#save_lot_btn').prop('disabled', true);
        } else {
            // Empty or other states
            $(this).removeClass('is-valid is-invalid digits-complete');
            $('#save_lot_btn').prop('disabled', false);
        }
    });

    // PREVENT INAPPROPRIATE KEYSTROKES IN LOT NUMBER FIELD
    $('#lot_no').on('keypress', function(e) {
        // Allow: backspace, delete, tab, escape, enter, home, end, left, right
        if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 110, 35, 36, 37, 39]) !== -1 ||
            // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X, Ctrl+Z
            (e.keyCode === 65 && e.ctrlKey === true) ||
            (e.keyCode === 67 && e.ctrlKey === true) ||
            (e.keyCode === 86 && e.ctrlKey === true) ||
            (e.keyCode === 88 && e.ctrlKey === true) ||
            (e.keyCode === 90 && e.ctrlKey === true)) {
            return;
        }
        
        const currentValue = $(this).val();
        const currentLength = currentValue.length;
        
        // Stop input if already at 7 character limit
        if (currentLength >= 7) {
            e.preventDefault();
            return false;
        }
        
        // For positions 1-6: Only allow digits (0-9)
        if (currentLength < 6) {
            // Stop keypress if not a number (0-9)
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
                return false;
            }
        } 
        // For position 7 (after 6 digits): Only allow letters (A-Z, a-z) - OPTIONAL
        else if (currentLength === 6) {
            // Allow letters only (A-Z = 65-90, a-z = 97-122)
            if (!((e.keyCode >= 65 && e.keyCode <= 90) || (e.keyCode >= 97 && e.keyCode <= 122))) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Enhanced paste handling
    $('#lot_no').on('paste', function(e) {
        e.preventDefault();
        
        // Get pasted data
        let pastedData = (e.originalEvent || e).clipboardData.getData('text').trim().toUpperCase();
        
        // Allow both formats: 6 digits only OR 6 digits + 1 letter
        const pattern6Digits = /^\d{6}$/;
        const pattern7Chars = /^\d{6}[A-Z]{1}$/;
        
        if (pattern6Digits.test(pastedData) || pattern7Chars.test(pastedData)) {
            // Valid format - insert the entire value
            $(this).val(pastedData);
            // Trigger validation
            validateLotNumber(this);
            updateLotNumberStatus(pastedData);
        } else {
            // Invalid format - try to extract valid parts
            // Extract only the first 6 digits
            const digits = pastedData.replace(/[^0-9]/g, '').substring(0, 6);
            $(this).val(digits);
            
            // Update status
            updateLotNumberStatus(digits);
            
            // Show feedback that paste was partially processed
            if (digits.length > 0) {
                $(this).addClass('is-invalid');
                console.log('Paste: Only digits extracted from invalid format');
            }
        }
    });

    // Confirm delete action
    window.confirmDelete = function(lotNo) {
        return confirm(`⚠️ Delete Confirmation\n\nAre you sure you want to delete lot "${lotNo}"?\n\n❌ This action cannot be undone!\n\nClick OK to confirm deletion.`);
    }

    // Open modal for adding new lot
    window.openAddModal = function() {
        $('#lotForm')[0].reset();
        $('#edit_id').val('');
        $('#machine_no').val('').trigger('change');
        $('#product_id').val('').trigger('change');
        $('.readonly-field').val('').prop('readonly', true);
        $('#save_lot_btn').prop('disabled', false);
        $('#lotModalLabel').html('<i class="fas fa-plus-circle me-2"></i>Add New Lot');
        $('.modal-footer').show();
        $('#lotModal').modal('show');
    }

    // Edit lot details
   window.editLot = function(lot) {
    console.log('Editing lot:', lot); // Debug log
    
    // Reset form
    $('#lotForm')[0].reset();
    $('#edit_id').val(lot.id);
    
    // Set form values first
    $('#lot_no').val(lot.lot_no);
    $('#length').val(lot.length);
    $('#weight').val(lot.weight);
    $('#thickness').val(lot.thickness);
    $('#specification').val(lot.specification);
    $('#color').val(lot.color || '');
    
    // Set product fields directly (in case Select2 fails)
    $('#product_description').val(lot.product_description || '');
    $('#product_type').val(lot.product_type || '');
    
    // Update modal title and show modal
    $('#lotModalLabel').html('<i class="fas fa-edit me-2"></i>Edit Lot Details');
    $('.modal-footer').show();
    $('#save_lot_btn').prop('disabled', false);
    
    // Show modal first, then set Select2 values
    $('#lotModal').modal('show');
    
    // Wait for modal to be fully shown, then set Select2 values
    $('#lotModal').on('shown.bs.modal', function() {
        console.log('Setting machine_no:', lot.machine_no);
        console.log('Setting product_id:', lot.product_id);
        
        // Set machine selection with multiple triggers
        if (lot.machine_no) {
            $('#machine_no').val(lot.machine_no).trigger('change.select2').trigger('change');
        }
        
        // Set product selection with multiple triggers
        if (lot.product_id) {
            $('#product_id').val(lot.product_id).trigger('change.select2').trigger('change');
        }
        
        // Force update product fields after a delay
        setTimeout(function() {
            updateProductFields();
            
            // Double-check product fields are populated
            if (!$('#product_description').val() && lot.product_description) {
                $('#product_description').val(lot.product_description);
            }
            if (!$('#product_type').val() && lot.product_type) {
                $('#product_type').val(lot.product_type);
            }
        }, 200);
        
        // Remove the event handler to prevent multiple bindings
        $('#lotModal').off('shown.bs.modal');
    });
}

    // Enhanced update product fields function
window.updateProductFields = function() {
    var selectedOption = $('#product_id option:selected');
    var description = selectedOption.data('description') || '';
    var specification = selectedOption.data('specification') || '';
    var type = selectedOption.data('type') || '';

    var isEditMode = $('#edit_id').val() !== '';
    
    console.log('updateProductFields called:', {
        selectedValue: $('#product_id').val(),
        description: description,
        specification: specification,
        type: type,
        isEditMode: isEditMode
    });
    
    // In edit mode, always update the fields with fresh data from the selected option
    if (isEditMode) {
        $('#product_description').val(description);
        $('#specification').val(specification);
        $('#product_type').val(type);
    } else {
        // In add mode, only update if fields are empty
        if ($('#product_description').val() === '') {
            $('#product_description').val(description);
        }
        if ($('#specification').val() === '') {
            $('#specification').val(specification);
        }
        if ($('#product_type').val() === '') {
            $('#product_type').val(type);
        }
    }
    
    // Set readonly properties
    $('#product_description').prop('readonly', true);
    $('#product_type').prop('readonly', true);
}

    // Debug function to check Select2 state
    window.debugSelect2 = function() {
        console.log('=== Select2 Debug ===');
        console.log('Machine Select2 initialized:', $('#machine_no').hasClass('select2-hidden-accessible'));
        console.log('Product Select2 initialized:', $('#product_id').hasClass('select2-hidden-accessible'));
        console.log('Machine value:', $('#machine_no').val());
        console.log('Product value:', $('#product_id').val());
        console.log('Product options:', $('#product_id option').length);
        $('#product_id option').each(function() {
            console.log('Option:', $(this).val(), '-', $(this).text());
        });
    };

    // Function to force Select2 refresh
    window.refreshSelect2 = function() {
        console.log('Refreshing Select2 dropdowns...');
        $('#machine_no').select2('destroy').select2({
            placeholder: "-- Select Machine from Dipping Department --",
            allowClear: true,
            width: '100%',
            dropdownParent: $('#lotModal'),
            dropdownCssClass: 'select2-dropdown-modal'
        });
        
        $('#product_id').select2('destroy').select2({
            placeholder: "-- Select Product ID --",
            allowClear: true,
            width: '100%',
            dropdownParent: $('#lotModal'),
            dropdownCssClass: 'select2-dropdown-modal'
        });
    };

    // Real-time validation for lot number input with enhanced feedback
    $('#lot_no').on('input keyup', function() {
        const value = this.value;
        updateLotNumberStatus(value);
    });

    // Real-time validation for numeric inputs (length, width, thickness)
    $('#length, #weight, #thickness').on('input', function() {
        // Allow all characters for ranges
        this.value = this.value.replace(/[^0-9.\-~+=\s]/g, '');
        
        // Visual feedback for valid range format
        const value = this.value.trim();
        if (value.length > 0) {
            // Basic validation for range format (optional)
            if (/^[0-9.\-~+=\s]+$/.test(value)) {
                $(this).removeClass('is-invalid').addClass('is-valid');
            } else {
                $(this).removeClass('is-valid').addClass('is-invalid');
            }
        } else {
            $(this).removeClass('is-valid is-invalid');
        }
    });

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Reinitialize tooltips when modal is shown/hidden
    $('#lotModal').on('shown.bs.modal', function () {
        $('#product_id').val('').trigger('change');
    });
    
    $('#lotModal').on('hidden.bs.modal', function () {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
});

// Function to update lot number status (simplified - no alphabet selector)
function updateLotNumberStatus(value) {
    const lengthDisplay = document.getElementById('lot_length');
    const statusDisplay = document.getElementById('format_status');
    
    if (lengthDisplay) {
        lengthDisplay.textContent = value.length;
        
        // Color-code the length display
        if (value.length === 0) {
            lengthDisplay.style.color = '#6c757d'; // Gray for empty
        } else if (value.length === 6 || value.length === 7) {
            lengthDisplay.style.color = '#28a745'; // Green for complete
        } else if (value.length < 6) {
            lengthDisplay.style.color = '#667eea'; // Blue for in progress
        } else {
            lengthDisplay.style.color = '#dc3545'; // Red for too long
        }
    }
    
    if (statusDisplay) {
        statusDisplay.className = 'format-status';
        
        if (value.length === 0) {
            statusDisplay.textContent = '(Enter 6 digits required)';
            statusDisplay.classList.add('digits-needed');
        } else if (value.length < 6) {
            const remaining = 6 - value.length;
            statusDisplay.textContent = `(Need ${remaining} more digit${remaining > 1 ? 's' : ''})`;
            statusDisplay.classList.add('digits-needed');
        } else if (value.length === 6 && /^\d{6}$/.test(value)) {
            statusDisplay.textContent = '✓ Complete! (Type letter if needed)';
            statusDisplay.classList.add('complete');
        } else if (value.length === 7 && /^\d{6}[A-Z]{1}$/.test(value)) {
            statusDisplay.textContent = '✓ Complete with letter!';
            statusDisplay.classList.add('complete');
        } else {
            statusDisplay.textContent = '(Invalid format)';
            statusDisplay.classList.add('digits-needed');
        }
    }
}

// Lot Number Validation Function (enhanced)
function validateLotNumber(input) {
    const value = input.value.trim().toUpperCase();
    const pattern6 = /^\d{6}$/;  // 6 digits only
    const pattern7 = /^\d{6}[A-Z]{1}$/;  // 6 digits + 1 letter
    
    // Convert to uppercase automatically
    input.value = value;
    
    // Update status display
    updateLotNumberStatus(value);
    
    // Remove previous validation classes
    input.classList.remove('is-valid', 'is-invalid', 'digits-complete');
    
    // Don't validate empty fields
    if (value.length === 0) {
        input.setCustomValidity('');
        return;
    }
    
    // Check if exactly 6 digits (complete and valid)
    if (value.length === 6 && pattern6.test(value)) {
        input.classList.add('is-valid');
        input.setCustomValidity('');
        return;
    }
    
    // Check if 7 characters (6 digits + 1 letter)
    if (value.length === 7 && pattern7.test(value)) {
        input.classList.add('is-valid');
        input.setCustomValidity('');
        return;
    }
    
    // If we're still typing (less than 6 digits), show neutral state
    if (value.length < 6 && /^\d+$/.test(value)) {
        input.classList.add('digits-complete');
        input.setCustomValidity('');
        return;
    }
    
    // Invalid format
    input.classList.add('is-invalid');
    input.setCustomValidity('Lot number: 6 digits required, letter optional (e.g., 123456 or 123456A)');
    
    // Trim excess characters silently
    if (value.length > 7) {
        input.value = value.substring(0, 7);
        updateLotNumberStatus(input.value);
    }
}

// Enhanced form submission validation - accept both 6 digits and 6 digits + letter
function validateForm() {
    const lotNoInput = document.getElementById('lot_no');
    const lotNo = lotNoInput.value.trim().toUpperCase();
    
    // Only validate if user is actually trying to submit with some content
    if (!lotNo || lotNo.length === 0) {
        alert('❌ Lot number is required');
        lotNoInput.focus();
        return false;
    }
    
    // Check length (must be 6 or 7)
    if (lotNo.length < 6) {
        alert('❌ INCOMPLETE LOT NUMBER\n\nLot number must have at least 6 digits.\nYour input: "' + lotNo + '" (' + lotNo.length + ' characters)\n\nRequired: 6 digits (letter optional)\nExample: 123456 or 123456A');
        lotNoInput.focus();
        return false;
    }
    
    if (lotNo.length > 7) {
        alert('❌ LOT NUMBER TOO LONG\n\nLot number cannot exceed 7 characters.\nYour input: "' + lotNo + '" (' + lotNo.length + ' characters)\n\nRequired: 6 digits (letter optional)\nExample: 123456 or 123456A');
        lotNoInput.focus();
        return false;
    }
    
    // Check pattern for 6 digits
    if (lotNo.length === 6) {
        const pattern6 = /^\d{6}$/;
        if (!pattern6.test(lotNo)) {
            alert('❌ INVALID FORMAT (6 digits)\n\nYour input: "' + lotNo + '"\n\nFor 6-digit format: All characters must be digits (0-9)\nExample: 123456');
            lotNoInput.focus();
            return false;
        }
    }
    
    // Check pattern for 7 characters (6 digits + 1 letter)
    if (lotNo.length === 7) {
        const pattern7 = /^\d{6}[A-Z]{1}$/;
        if (!pattern7.test(lotNo)) {
            const first6 = lotNo.substring(0, 6);
            const last1 = lotNo.substring(6, 7);
            
            let errorMsg = '❌ INVALID FORMAT (6 digits + letter)\n\n';
            errorMsg += 'Your input: "' + lotNo + '"\n\n';
            
            if (!/^\d{6}$/.test(first6)) {
                errorMsg += 'Error: First 6 characters must be digits only (0-9)\n';
                errorMsg += 'Your first 6: "' + first6 + '"\n\n';
            }
            
            if (!/^[A-Z]$/.test(last1)) {
                errorMsg += 'Error: 7th character must be a letter (A-Z)\n';
                errorMsg += 'Your 7th character: "' + last1 + '"\n\n';
            }
            
            errorMsg += 'Correct format: 6 digits + 1 letter (e.g., 123456A)';
            
            alert(errorMsg);
            lotNoInput.focus();
            return false;
        }
    }
    
    // If we get here, the lot number is valid (either 6 digits or 6 digits + letter)
    return true;
}

// Separate function for blur events - NO ALERTS for empty or partial input
function validateLotNumberOnBlur(input) {
    const value = input.value.trim().toUpperCase();
    
    // IMPORTANT: Don't do anything for empty fields
    if (value.length === 0) {
        return; // Exit early for empty fields - no alerts, no validation
    }
    
    // Just do the regular validation (which provides visual feedback only)
    validateLotNumber(input);
    
    // NO ALERTS ON BLUR - only visual feedback through CSS classes
    // Users will see red border for invalid, green for valid
    // Detailed error messages only appear on form submission
}
    </script>
</body>
</html>