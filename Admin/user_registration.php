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

$message = "";

// Define menu categories with individual page permissions
$menuCategories = [
    'dashboard' => [
        'name' => 'Dashboard',
        'icon' => 'fa-tachometer-alt',
        'description' => 'Main system dashboard and overview',
        'pages' => [
            'dashboard' => ['name' => 'Dashboard', 'file' => 'dashboard.php', 'icon' => 'fa-home']
        ]
    ],
    'admin' => [
        'name' => 'Admin',
        'icon' => 'fa-user-shield',
        'description' => 'System administration and user management',
        'pages' => [
            'user_registration' => ['name' => 'User Registration', 'file' => 'user_registrationLookup.php', 'icon' => 'fa-user-plus'],
            'batch_creation' => ['name' => 'Batch Creation', 'file' => 'BatchCreationEntryLookup.php', 'icon' => 'fa-layer-group'],
            'check_by' => ['name' => 'CheckBy Creation', 'file' => 'CheckBy.php', 'icon' => 'fa-check']
        ]
    ],
    'master' => [
        'name' => 'Master Data',
        'icon' => 'fa-cogs',
        'description' => 'Master data management',
        'pages' => [
            'department' => ['name' => 'Department', 'file' => 'department.php', 'icon' => 'fa-building'],
            'machine' => ['name' => 'Machine', 'file' => 'machine.php', 'icon' => 'fa-tools'],
            'material' => ['name' => 'Material', 'file' => 'material.php', 'icon' => 'fa-box'],
            'supplier' => ['name' => 'Supplier', 'file' => 'supplier.php', 'icon' => 'fa-truck'],
            'product' => ['name' => 'Product', 'file' => 'product.php', 'icon' => 'fa-boxes']
        ]
    ],
    'transaction' => [
        'name' => 'Transaction',
        'icon' => 'fa-exchange-alt',
        'description' => 'Inventory and transaction management',
        'pages' => [
            'store_entry' => ['name' => 'Store Entry', 'file' => 'StoreEntryLookup.php', 'icon' => 'fa-warehouse'],
            'gate_entry' => ['name' => 'Gate Entry', 'file' => 'GateEntryLookup.php', 'icon' => 'fa-door-open'],
            'grn_entry' => ['name' => 'GRN Entry', 'file' => 'GRNEntryLookup.php', 'icon' => 'fa-file-alt'],
            'qc_page' => ['name' => 'QC Page', 'file' => 'QCPageLookup.php', 'icon' => 'fa-search'],
            'store_stock' => ['name' => 'Store Stock Available', 'file' => 'StoreStockAvailable.php', 'icon' => 'fa-boxes'],
            'issuer_material' => ['name' => 'Issuer Entry Page', 'file' => 'issuerMaterialLookup.php', 'icon' => 'fa-plus']
        ]
    ],
    'dipping' => [
        'name' => 'Dipping Process',
        'icon' => 'fa-vial',
        'description' => 'Dipping production process management',
        'pages' => [
            'lot_creation' => ['name' => 'Lot Creation', 'file' => 'lotcreation.php', 'icon' => 'fa-box'],
            'dipping_entry' => ['name' => 'Dipping Entry Page', 'file' => 'DippingBinwiseEntryLookup.php', 'icon' => 'fa-plus'],
            // Add summary menu
            'summary' => ['name' => 'Summary', 'file' => 'DippingSummary.php', 'icon' => 'fa-chart-bar']
        ]
    ],
    'electronic' => [
        'name' => 'Electronic Testing',
        'icon' => 'fa-microchip',
        'description' => 'Electronic testing and operator management',
        'pages' => [
            'operator' => ['name' => 'Operator', 'file' => 'operatorLookup.php', 'icon' => 'fa-user-cog'],
            'operator_presence' => ['name' => 'Operator Presence', 'file' => 'Operator_presenty.php', 'icon' => 'fa-user-check'],
            'electronic_batch' => ['name' => 'Electronic Batch Entry', 'file' => 'ElectronicBatchEntryLookup.php', 'icon' => 'fa-clipboard-list'],
            // Add summary menu
            'summary' => ['name' => 'Summary', 'file' => 'ElectronicSummary.php', 'icon' => 'fa-chart-bar']
        ]
    ],
    'sealing' => [
        'name' => 'Sealing Process',
        'icon' => 'fa-lock',
        'description' => 'Sealing operations and flavor supervision',
        'pages' => [
            'sealing_entry' => ['name' => 'Sealing Entry', 'file' => 'sealing_lookup.php', 'icon' => 'fa-lock'],
            'flavour_supervisor' => ['name' => 'Flavour Supervisor', 'file' => 'addFlavoure_Supervisor.php', 'icon' => 'fa-plus'],
            // Add summary menu
            'summary' => ['name' => 'Summary', 'file' => 'SealingSummary.php', 'icon' => 'fa-chart-bar']
        ]
    ],
    'material_issue' => [
        'name' => 'Material Issue Note',
        'icon' => 'fa-file-signature',
        'description' => 'Material requisition and issue management',
        'pages' => [
            'material_issue_note' => ['name' => 'Material Issue Note', 'file' => 'MaterialIssueNotePageLookup.php', 'icon' => 'fa-file-signature']
        ]
    ]
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operator_id = $_POST['operator_id'];
    $user_name = trim($_POST['user_name']);
    $email = trim($_POST['email']);
    $department_id = intval($_POST['department_id']);
    $menu_permission = isset($_POST['menu_permission']) ? $_POST['menu_permission'] : [];

    // Handle special admin permission
    if (in_array('admin_all', $menu_permission)) {
        $finalPermissions = [];
        foreach ($menuCategories as $menuKey => $menuInfo) {
            $finalPermissions[$menuKey] = ['read' => true];
            foreach ($menuInfo['pages'] as $pageKey => $pageInfo) {
                $finalPermissions[$menuKey . '_' . $pageKey] = ['read' => true];
            }
        }
    } else {
        $finalPermissions = [];
        
        // Process category-level permissions
        foreach ($menuCategories as $menuKey => $menuInfo) {
            $categoryHasPermission = false;
            
            // Check individual page permissions
            foreach ($menuInfo['pages'] as $pageKey => $pageInfo) {
                $permissionKey = $menuKey . '_' . $pageKey . '_read';
                if (in_array($permissionKey, $menu_permission)) {
                    $finalPermissions[$menuKey . '_' . $pageKey] = ['read' => true];
                    $categoryHasPermission = true;
                }
            }
            
            // If any page in category has permission, grant category access
            if ($categoryHasPermission) {
                $finalPermissions[$menuKey] = ['read' => true];
            }
        }
    }

    $menu_permission_str = json_encode($finalPermissions);

    // Validation
    if (empty($user_name) || empty($email) || empty($department_id) || empty($finalPermissions)) {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                      <i class="fas fa-exclamation-circle me-2"></i>Please fill in all required fields and select at least one permission.
                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>';
    } else {
        if (isset($_POST['edit_id']) && $_POST['edit_id'] != '') {
            // UPDATE logic
            $edit_id = intval($_POST['edit_id']);
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $sql = "UPDATE users SET operator_id=?, user_name=?, email=?, password=?, department_id=?, menu_permission=? WHERE id=?";
                $params = [$operator_id, $user_name, $email, $password, $department_id, $menu_permission_str, $edit_id];
            } else {
                $sql = "UPDATE users SET operator_id=?, user_name=?, email=?, department_id=?, menu_permission=? WHERE id=?";
                $params = [$operator_id, $user_name, $email, $department_id, $menu_permission_str, $edit_id];
            }
            $stmt = sqlsrv_query($conn, $sql, $params);
            
            if ($stmt) {
                header("Location: user_registrationLookup.php?updated=1");
                exit;
            } else {
                $errors = sqlsrv_errors();
                $errorMsg = $errors ? $errors[0]['message'] : 'Unknown error';
                $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                              <i class="fas fa-exclamation-circle me-2"></i>Error updating user: ' . htmlspecialchars($errorMsg) . '
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            }

        } else {
            // INSERT logic - Check for required password
            if (empty($_POST['password'])) {
                $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                              <i class="fas fa-exclamation-circle me-2"></i>Password is required for new users.
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>';
            } else {
                // Check if operator_id or email already exists
                $checkSql = "SELECT COUNT(*) as count FROM users WHERE operator_id = ? OR email = ?";
                $checkParams = [$operator_id, $email];
                $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
                $exists = 0;
                if ($row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC)) {
                    $exists = $row['count'];
                }

                if ($exists > 0) {
                    $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                  <i class="fas fa-exclamation-circle me-2"></i>Operator ID or Email already exists. Please use different values.
                                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>';
                } else {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $sql = "INSERT INTO users (operator_id, user_name, email, password, department_id, menu_permission)
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $params = [$operator_id, $user_name, $email, $password, $department_id, $menu_permission_str];
                    $stmt = sqlsrv_query($conn, $sql, $params);
                    
                    if ($stmt) {
                        header("Location: user_registrationLookup.php?success=1");
                        exit;
                    } else {
                        $errors = sqlsrv_errors();
                        $errorMsg = $errors ? $errors[0]['message'] : 'Unknown error';
                        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                      <i class="fas fa-exclamation-circle me-2"></i>Error creating user: ' . htmlspecialchars($errorMsg) . '
                                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>';
                    }
                }
            }
        }
    }
}

include '../Includes/sidebar.php';

// =====================
// Handle Add / Edit Form - Initialize all variables
// =====================
$departments = [];
$deptSql = "SELECT id, department_name FROM departments ORDER BY department_name";
$deptRes = sqlsrv_query($conn, $deptSql);
if ($deptRes) {
    while ($row = sqlsrv_fetch_array($deptRes, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $row;
    }
}

// Initialize all form variables with default values
$formTitle = "Add New User";
$btnText = "Create User";
$operator_id = "";
$user_name = "";
$email = "";
$department_id = "";
$existingPermissions = [];
$edit_id = "";
$password_required = "required";

if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $sql = "SELECT * FROM users WHERE id = ?";
    $params = [$edit_id];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($user) {
            $formTitle = "Edit User Information";
            $btnText = "Update User";
            $operator_id = $user['operator_id'];
            $user_name = $user['user_name'];
            $email = $user['email'];
            $department_id = $user['department_id'];
            $existingPermissions = json_decode($user['menu_permission'], true) ?: [];
            $password_required = "";
        }
    }
} else {
    // Generate next Operator ID
    $nextNum = 1;
    do {
        $nextOperatorId = 'Operator-' . $nextNum;
        $checkSql = "SELECT COUNT(*) AS total FROM users WHERE operator_id = ?";
        $checkParams = [$nextOperatorId];
        $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
        $exists = 0;
        if ($checkStmt && ($row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC))) {
            $exists = $row['total'];
        }
        $nextNum++;
    } while ($exists > 0);
    $operator_id = $nextOperatorId;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $formTitle; ?> - ERP System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-content {
            margin-left: 240px;
            padding: 30px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out;
        }

        .sidebar.hide ~ .main-content,
        .main-content.sidebar-collapsed {
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
            max-width: 1400px;
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

        .form-control[readonly] {
            background: #e9ecef;
            color: #6c757d;
        }

        .required {
            color: #dc3545;
            font-weight: bold;
        }

        .input-icon {
            color: #667eea;
            font-size: 1rem;
        }

        .permissions-container {
            background: #f8f9ff;
            border: 2px dashed #667eea;
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
        }

        .permissions-container:hover {
            border-color: #764ba2;
            background: #f5f7ff;
        }

        .permissions-scroll {
            max-height: 600px;
            overflow-y: auto;
            padding-right: 10px;
            margin-top: 20px;
        }

        .permissions-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .permissions-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .permissions-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
        }

        .menu-category {
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .category-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .category-header:hover {
            background: linear-gradient(135deg, #5a6fd8, #6a3d9a);
        }

        .category-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .category-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .category-details h5 {
            margin: 0 0 3px 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .category-description {
            font-size: 0.85rem;
            opacity: 0.9;
            margin: 0;
        }

        .category-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-all-category {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .select-all-category:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .collapse-icon {
            transition: transform 0.3s ease;
        }

        .collapse-icon.collapsed {
            transform: rotate(-90deg);
        }

        .submenu-container {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .submenu-container.expanded {
            max-height: 1000px;
            padding: 20px;
        }

        .submenu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .submenu-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .submenu-item:hover {
            background: #e9ecef;
            border-color: #667eea;
        }

        .submenu-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .submenu-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .submenu-name {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }

        .submenu-checkbox input[type="checkbox"] {
            transform: scale(1.3);
            margin: 0;
        }

        .admin-permission {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            border: 2px solid #f39c12;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: center;
        }

        .admin-permission label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            color: #d35400;
            margin: 0;
            cursor: pointer;
        }

        .admin-permission input[type="checkbox"] {
            transform: scale(1.8);
            margin: 0;
        }

        .permission-controls {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            justify-content: center;
            flex-wrap: wrap;
        }

        .control-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-select-all {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .btn-clear-all {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
            color: white;
        }

        .btn-expand-all {
            background: linear-gradient(135deg, #17a2b8, #007bff);
            color: white;
        }

        .btn-collapse-all {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
        }

        .control-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .form-actions {
            background: #f8f9fa;
            padding: 25px 40px;
            margin: 0 -40px -40px -40px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .btn-custom {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-secondary-custom {
            background: #6c757d;
            color: white;
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }

        .help-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

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

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .form-body {
                padding: 25px;
            }
            
            .form-actions {
                padding: 20px 25px;
                margin: 0 -25px -25px -25px;
                flex-direction: column;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .submenu-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .submenu-item {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .permission-controls {
                flex-direction: column;
                align-items: center;
            }

            .control-btn {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }
        }

        body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f6f9fc;
}
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 240px;
    height: 100vh;
    background: #fff;
    color: #222;
    border-right: 1px solid #e5e7eb;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease-in-out;
    transform: translateX(0);
    overflow-y: auto;
    scroll-behavior: smooth;
}

.sidebar.hide {
    transform: translateX(-100%);
}
.sidebar-header {
    padding: 24px 18px 12px 18px;
    border-bottom: 1px solid #f1f1f1;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
}
.sidebar-header img {
    width: 50px;
    height: 50px;
    object-fit: contain;
}
.sidebar-header span {
    font-weight: bold;
    font-size: 1.2rem;
    color: #3b82f6;
    letter-spacing: 1px;
}
.sidebar-menu {
    flex: 1;
    padding: 18px 0 0 0;
}
.sidebar-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.sidebar-menu li {
    margin-bottom: 4px;
    position: relative;
}
.sidebar-menu a,
.sidebar-main-menu {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 24px;
    color: #222;
    text-decoration: none;
    font-size: 1rem;
    transition: background 0.2s, color 0.2s;
    border-radius: 0;
}
.sidebar-menu a.active,
.sidebar-menu a:hover,
.sidebar-main-menu:hover {
    background: #e8f0fe;
    color: rgb(79 66 193);
}
.sidebar-main-menu.active,
.sidebar-menu a.active {
    background-color: #4f42c1;
    color: #fff;
    font-weight: 600;
}
.submenu {
    display: none;
    padding-left: 20px;
    background-color: #f1f3f9;
    transition: all 0.3s ease;
}
.submenu.show {
    display: block;
}
.submenu li a {
    padding: 10px 40px;
    background-color: #f1f3f9;
    color: #333;
    display: flex;
    align-items: center;
}
.submenu li a:hover {
    background-color: #e0e7ff;
    color: #111;
}
.sidebar-main-menu {
    cursor: pointer;
}
.sidebar-main-menu .icon {
    font-size: 1.2em;
    width: 22px;
    text-align: center;
}
.dropdown-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
}
.sidebar-main-menu.active .dropdown-arrow i {
    transform: rotate(180deg);
}
.menu-label {
    flex: 1;
    white-space: normal;
    overflow-wrap: break-word;
    font-size: 0.95rem;
    line-height: 1.2;
}

#sidebar-toggle {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: background 0.2s;
    margin-right: 10px;
}
#sidebar-toggle:hover {
    background: #f3f4f6;
}

.sidebar-footer {
    padding: 16px 18px 12px 18px;
    border-top: 1px solid #f1f1f1;
    font-size: 0.95em;
    color: #888;
    background: #fff;
}

@media (max-width: 900px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-header {
        margin-left: 0 !important;
        padding-left: 12px !important;
    }
    
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .main-footer {
        left: 0 !important;
        width: 100% !important;
    }
}

.main-header {
    margin-left: 240px;
    height: 70px;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    z-index: 900;
    position: relative;
    transition: margin-left 0.3s ease-in-out;
}
.main-content {
    margin-left: 240px;
    padding: 32px 32px 60px 32px;
    min-height: calc(100vh - 70px - 40px);
    background: #f6f9fc;
    transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out;
}
.sidebar.hide ~ .main-content,
.main-content.sidebar-collapsed {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100vw !important;
}
.main-footer {
    position: fixed;
    left: 240px;
    bottom: 0;
    width: calc(100% - 240px);
    transition: left 0.3s ease-in-out, width 0.3s ease-in-out;
    z-index: 900;
}

.sidebar.hide ~ .main-footer,
.main-footer.sidebar-collapsed {
    left: 0;
    width: 100%;
}
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas <?php echo isset($_GET['edit']) ? 'fa-user-edit' : 'fa-user-plus'; ?>"></i>
                <?php echo $formTitle; ?>
            </h1>
        </div>

        <!-- Show Messages -->
        <?php if ($message): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <!-- User Registration Form -->
        <div class="form-container">
            <div class="form-header">
                <h3>
                    <i class="fas fa-user-cog"></i>
                    Granular Menu Permissions Management
                </h3>
            </div>
            
            <div class="form-body">
                <form method="post" autocomplete="off" novalidate>
                    <?php if ($edit_id): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                    <?php endif; ?>

                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-id-card"></i>
                            Basic Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-hashtag input-icon"></i>
                                    Operator ID
                                </label>
                                <input type="text" class="form-control" name="operator_id" value="<?php echo htmlspecialchars($operator_id); ?>" readonly>
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Auto-generated unique identifier
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user input-icon"></i>
                                    Full Name <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" name="user_name" value="<?php echo htmlspecialchars($user_name); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-envelope input-icon"></i>
                                    Email Address <span class="required">*</span>
                                </label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building input-icon"></i>
                                    Department <span class="required">*</span>
                                </label>
                                <select class="form-select" name="department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php if ($dept['id'] == $department_id) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Security Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-lock"></i>
                            Security Settings
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-key input-icon"></i>
                                    Password <?php if (!$edit_id): ?><span class="required">*</span><?php endif; ?>
                                </label>
                                <input type="password" class="form-control" name="password" <?php echo $password_required; ?> placeholder="<?php echo $edit_id ? 'Leave blank to keep current password' : 'Enter secure password'; ?>" minlength="6">
                                <?php if ($edit_id): ?>
                                    <div class="help-text">
                                        <i class="fas fa-info-circle"></i>
                                        Leave empty to keep existing password
                                    </div>
                                <?php else: ?>
                                    <div class="help-text">
                                        <i class="fas fa-info-circle"></i>
                                        Password must be at least 6 characters long
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Menu Permissions Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-shield-alt"></i>
                            Detailed Menu Permissions <span class="required">*</span>
                        </div>
                        
                        <div class="permissions-container">
                            <!-- Admin All Permission -->
                            <div class="admin-permission">
                                <label>
                                    <input type="checkbox" name="menu_permission[]" value="admin_all" id="admin_all">
                                    <i class="fas fa-crown"></i>
                                    <strong>Super Admin (Complete System Access)</strong>
                                </label>
                                <div class="help-text" style="margin-top: 10px; color: #d35400;">
                                    <i class="fas fa-info-circle"></i>
                                    Grants unrestricted access to all system modules and individual pages
                                </div>
                            </div>

                            <!-- Permission Controls -->
                            <div class="permission-controls">
                                <button type="button" class="control-btn btn-select-all" onclick="selectAllPermissions()">
                                    <i class="fas fa-check-double"></i>Select All Pages
                                </button>
                                <button type="button" class="control-btn btn-clear-all" onclick="clearAllPermissions()">
                                    <i class="fas fa-times"></i>Clear All
                                </button>
                                <button type="button" class="control-btn btn-expand-all" onclick="expandAllCategories()">
                                    <i class="fas fa-expand-arrows-alt"></i>Expand All
                                </button>
                                <button type="button" class="control-btn btn-collapse-all" onclick="collapseAllCategories()">
                                    <i class="fas fa-compress-arrows-alt"></i>Collapse All
                                </button>
                            </div>

                            <!-- Permissions Scrollable Container -->
                            <div class="permissions-scroll">
                                <!-- Menu Categories with Individual Page Permissions -->
                                <?php foreach($menuCategories as $menuKey => $menuInfo): ?>
                                    <div class="menu-category">
                                        <div class="category-header" onclick="toggleCategory('<?php echo $menuKey; ?>')">
                                            <div class="category-info">
                                                <div class="category-icon">
                                                    <i class="fas <?php echo $menuInfo['icon']; ?>"></i>
                                                </div>
                                                <div class="category-details">
                                                    <h5><?php echo $menuInfo['name']; ?></h5>
                                                    <p class="category-description"><?php echo $menuInfo['description']; ?></p>
                                                </div>
                                            </div>
                                            <div class="category-toggle">
                                                <button type="button" class="select-all-category" onclick="event.stopPropagation(); selectCategoryAll('<?php echo $menuKey; ?>')">
                                                    Select All
                                                </button>
                                                <i class="fas fa-chevron-down collapse-icon" id="icon-<?php echo $menuKey; ?>"></i>
                                            </div>
                                        </div>
                                        
                                        <div class="submenu-container" id="submenu-<?php echo $menuKey; ?>">
                                            <div class="submenu-grid">
                                                <?php foreach($menuInfo['pages'] as $pageKey => $pageInfo): 
                                                    $hasPagePermission = isset($existingPermissions[$menuKey . '_' . $pageKey]['read']) && $existingPermissions[$menuKey . '_' . $pageKey]['read'];
                                                ?>
                                                    <div class="submenu-item">
                                                        <div class="submenu-info">
                                                            <div class="submenu-icon">
                                                                <i class="fas <?php echo $pageInfo['icon']; ?>"></i>
                                                            </div>
                                                            <div class="submenu-name"><?php echo $pageInfo['name']; ?></div>
                                                        </div>
                                                        <div class="submenu-checkbox">
                                                            <input type="checkbox" 
                                                                   name="menu_permission[]" 
                                                                   value="<?php echo $menuKey . '_' . $pageKey; ?>_read" 
                                                                   id="<?php echo $menuKey . '_' . $pageKey; ?>_read" 
                                                                   class="page-checkbox" 
                                                                   data-category="<?php echo $menuKey; ?>"
                                                                   <?php if ($hasPagePermission) echo 'checked'; ?>>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="user_registrationLookup.php" class="btn-custom btn-secondary-custom">
                            <i class="fas fa-arrow-left"></i>
                            Cancel
                        </a>
                        
                        <button type="submit" class="btn-custom btn-primary-custom" name="add_user">
                            <i class="fas <?php echo isset($_GET['edit']) ? 'fa-save' : 'fa-user-plus'; ?>"></i>
                            <?php echo $btnText; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Admin checkbox functionality
        document.getElementById('admin_all').addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('input[name="menu_permission[]"]:not(#admin_all)');
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Individual permission checkboxes
        document.querySelectorAll('.page-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateAdminCheckbox();
            });
        });

        function updateAdminCheckbox() {
            const adminCheckbox = document.getElementById('admin_all');
            const allIndividualCheckboxes = document.querySelectorAll('input[name="menu_permission[]"]:not(#admin_all)');
            const checkedIndividualCheckboxes = document.querySelectorAll('input[name="menu_permission[]"]:not(#admin_all):checked');
            
            if (checkedIndividualCheckboxes.length === allIndividualCheckboxes.length && allIndividualCheckboxes.length > 0) {
                adminCheckbox.checked = true;
            } else {
                adminCheckbox.checked = false;
            }
        }

        function selectAllPermissions() {
            const allCheckboxes = document.querySelectorAll('input[type="checkbox"]');
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        function clearAllPermissions() {
            const allCheckboxes = document.querySelectorAll('input[type="checkbox"]');
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        function toggleCategory(categoryKey) {
            const submenu = document.getElementById('submenu-' + categoryKey);
            const icon = document.getElementById('icon-' + categoryKey);
            
            if (submenu.classList.contains('expanded')) {
                submenu.classList.remove('expanded');
                icon.classList.add('collapsed');
            } else {
                submenu.classList.add('expanded');
                icon.classList.remove('collapsed');
            }
        }

        function selectCategoryAll(categoryKey) {
            const categoryCheckboxes = document.querySelectorAll(`input[data-category="${categoryKey}"]`);
            const allChecked = Array.from(categoryCheckboxes).every(checkbox => checkbox.checked);
            
            categoryCheckboxes.forEach(checkbox => {
                checkbox.checked = !allChecked;
            });
            
            updateAdminCheckbox();
        }

        function expandAllCategories() {
            const allSubmenus = document.querySelectorAll('.submenu-container');
            const allIcons = document.querySelectorAll('.collapse-icon');
            
            allSubmenus.forEach(submenu => {
                submenu.classList.add('expanded');
            });
            
            allIcons.forEach(icon => {
                icon.classList.remove('collapsed');
            });
        }

        function collapseAllCategories() {
            const allSubmenus = document.querySelectorAll('.submenu-container');
            const allIcons = document.querySelectorAll('.collapse-icon');
            
            allSubmenus.forEach(submenu => {
                submenu.classList.remove('expanded');
            });
            
            allIcons.forEach(icon => {
                icon.classList.add('collapsed');
            });
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

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="menu_permission[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one menu permission.');
                return false;
            }
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Initialize collapsed state
        document.addEventListener('DOMContentLoaded', function() {
            collapseAllCategories();
        });
    </script>
</body>
</html>