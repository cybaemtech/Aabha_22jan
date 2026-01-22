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

// Set timezone to Kolkata/Asia
date_default_timezone_set('Asia/Kolkata');

// Get user details
$operator_id = $_SESSION['operator_id'];
$userName = '';
$userRole = '';

// Fetch user information
$userSql = "SELECT operator_name, role FROM operators WHERE operator_id = ?";
$userStmt = sqlsrv_query($conn, $userSql, array($operator_id));
if ($userStmt && $row = sqlsrv_fetch_array($userStmt, SQLSRV_FETCH_ASSOC)) {
    $userName = $row['operator_name'] ?? 'User';
    $userRole = $row['role'] ?? 'Operator';
}

// Generate time-based greeting (using Kolkata time)
function getTimeBasedGreeting() {
    $hour = date('H');
    if ($hour < 12) {
        return "Good Morning";
    } elseif ($hour < 17) {
        return "Good Afternoon";
    } else {
        return "Good Evening";
    }
}

$greeting = getTimeBasedGreeting();
$currentDate = date('l, F j, Y'); // Kolkata date
$currentTime = date('h:i A'); // Kolkata time

// Helper function to fetch count
function getCount($conn, $query) {
    $stmt = sqlsrv_query($conn, $query);
    if ($stmt === false) return 0;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? $row['cnt'] : 0;
}

// Fetch counts based on user permissions
$suppliers = 0;
$materials = 0;
$departments = 0;
$machines = 0;
$products = 0;
$gateEntries = 0;
$dippingCount = 0;
$electronicCount = 0;
$sealingCount = 0;
$transactionCount = 0;

$userPermissions = $_SESSION['menu_permissions'] ?? [];
$is_admin = in_array('all', $userPermissions) || in_array('admin', $userPermissions);

if ($is_admin || in_array('master', $userPermissions) || in_array('master_supplier', $userPermissions)) {
    $suppliers = getCount($conn, "SELECT COUNT(*) as cnt FROM suppliers");
}
if ($is_admin || in_array('master', $userPermissions) || in_array('master_material', $userPermissions)) {
    $materials = getCount($conn, "SELECT COUNT(*) as cnt FROM materials");
}
if ($is_admin || in_array('master', $userPermissions) || in_array('master_department', $userPermissions)) {
    $departments = getCount($conn, "SELECT COUNT(*) as cnt FROM departments");
}
if ($is_admin || in_array('master', $userPermissions) || in_array('master_machine', $userPermissions)) {
    $machines = getCount($conn, "SELECT COUNT(*) as cnt FROM machines");
}
if ($is_admin || in_array('master', $userPermissions) || in_array('master_product', $userPermissions)) {
    $products = getCount($conn, "SELECT COUNT(*) as cnt FROM products");
}
if ($is_admin || in_array('transaction', $userPermissions) || in_array('transaction_gate_entry', $userPermissions)) {
    $gateEntries = getCount($conn, "SELECT COUNT(*) as cnt FROM gate_entries");
}

// Process data counts
$dippingCount = getCount($conn, "SELECT COUNT(*) as cnt FROM dipping_entries");
$electronicCount = getCount($conn, "SELECT COUNT(*) as cnt FROM electronic_batch_entries");
$sealingCount = getCount($conn, "SELECT COUNT(*) as cnt FROM sealing_entries");
$transactionCount = getCount($conn, "SELECT COUNT(*) as cnt FROM transaction_master");

// Monthly trends (mock data structure for charts)
$monthlyData = [
    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
    'dipping' => [65, 59, 80, 81, 56, 55],
    'electronic' => [28, 48, 40, 19, 86, 27],
    'sealing' => [45, 25, 16, 36, 67, 18]
];

// Today's activity counts (using Kolkata date)
$todayDate = date('Y-m-d'); // This will now be Kolkata date
$todayGRN = getCount($conn, "SELECT COUNT(*) as cnt FROM grn_header WHERE CAST(created_date AS DATE) = '$todayDate'");
$todayMaterialRequests = getCount($conn, "SELECT COUNT(*) as cnt FROM material_request_items WHERE CAST(request_date AS DATE) = '$todayDate'");

// QC Status Counts - Updated to include all statuses
$qcStatusCounts = [
    'Accept' => 0,
    'under_Deviation' => 0,
    'Hold' => 0,
    'Reject' => 0,
    'Pending' => 0
];

// Check if qc_quantity_details table exists
$tableCheckSql = "SELECT COUNT(*) as table_exists FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'qc_quantity_details'";
$tableCheckStmt = sqlsrv_query($conn, $tableCheckSql);
$tableExists = false;

if ($tableCheckStmt) {
    $result = sqlsrv_fetch_array($tableCheckStmt, SQLSRV_FETCH_ASSOC);
    $tableExists = $result['table_exists'] > 0;
    sqlsrv_free_stmt($tableCheckStmt);
}

if ($tableExists) {
    $qcSql = "SELECT material_status, COUNT(*) as cnt FROM qc_quantity_details WHERE material_status IS NOT NULL AND material_status != '' GROUP BY material_status";
    $qcRes = sqlsrv_query($conn, $qcSql);
    if ($qcRes) {
        while ($row = sqlsrv_fetch_array($qcRes, SQLSRV_FETCH_ASSOC)) {
            $status = trim($row['material_status']);
            if (isset($qcStatusCounts[$status])) {
                $qcStatusCounts[$status] = $row['cnt'];
            } else {
                // If status not in our predefined list, add to Pending
                $qcStatusCounts['Pending'] += $row['cnt'];
            }
        }
        sqlsrv_free_stmt($qcRes);
    }

    // Count records without QC status as Pending
    $pendingQcSql = "
        SELECT COUNT(*) as cnt
        FROM grn_quantity_details gqd
        LEFT JOIN qc_quantity_details qcd ON gqd.quantity_id = qcd.grn_quantity_id
        WHERE qcd.material_status IS NULL OR qcd.material_status = ''
    ";
    $pendingQcRes = sqlsrv_query($conn, $pendingQcSql);
    if ($pendingQcRes) {
        $row = sqlsrv_fetch_array($pendingQcRes, SQLSRV_FETCH_ASSOC);
        $pendingCount = $row ? $row['cnt'] : 0;
        $qcStatusCounts['Pending'] += $pendingCount;
        sqlsrv_free_stmt($pendingQcRes);
    }
}

// Recent activities data - Updated to show only 4
$recentActivities = [];
$grnSql = "
    SELECT TOP 3 
        'GRN Entry' as activity_type,
        grn_header_id as reference_id,
        created_date as activity_date,
        'grn' as source_type
    FROM grn_header 
    WHERE created_date IS NOT NULL
    ORDER BY created_date DESC
";
$grnRes = sqlsrv_query($conn, $grnSql);
if ($grnRes) {
    while ($row = sqlsrv_fetch_array($grnRes, SQLSRV_FETCH_ASSOC)) {
        $recentActivities[] = $row;
    }
    sqlsrv_free_stmt($grnRes);
}

$materialRequestSql = "
    SELECT TOP 3 
        'Material Request' as activity_type,
        CAST(material_request_id as varchar) as reference_id,
        request_date as activity_date,
        'material_request' as source_type,
        request_by,
        COUNT(*) as item_count
    FROM material_request_items 
    WHERE request_date IS NOT NULL
    GROUP BY material_request_id, request_date, request_by
    ORDER BY request_date DESC
";
$materialRes = sqlsrv_query($conn, $materialRequestSql);
if ($materialRes) {
    while ($row = sqlsrv_fetch_array($materialRes, SQLSRV_FETCH_ASSOC)) {
        $recentActivities[] = $row;
    }
    sqlsrv_free_stmt($materialRes);
}

// Sort activities by date
usort($recentActivities, function($a, $b) {
    $dateA = $a['activity_date'];
    $dateB = $b['activity_date'];
    
    if ($dateA instanceof DateTime) {
        $dateA = $dateA->format('Y-m-d H:i:s');
    }
    if ($dateB instanceof DateTime) {
        $dateB = $dateB->format('Y-m-d H:i:s');
    }
    
    return strtotime($dateB) - strtotime($dateA);
});

// Keep only top 4 activities (changed from 5 to 4)
$recentActivities = array_slice($recentActivities, 0, 4);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Aabha Contraceptive - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; 
            background-color: #f8fafc;
            color: #334155;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 16px;
            min-height: 100vh;
        }

        /* Enhanced info item for timezone display */
        .info-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #475569;
            font-weight: 500;
            font-size: 0.875rem;
            position: relative;
        }

        .info-item i {
            color: #3b82f6;
            font-size: 0.875rem;
        }

        .timezone-badge {
            background: #3b82f6;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 4px;
            font-weight: 600;
        }

        /* Real-time clock update */
        .live-time {
            font-weight: 600;
            color: #1e293b;
        }

        /* Compact Welcome Header */
        .welcome-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
        }

        .welcome-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
            border-radius: 50%;
        }

        .welcome-header h1 {
            color: #1e293b;
            font-weight: 600;
            margin: 0 0 8px 0;
            font-size: 1.75rem;
        }

        .welcome-text {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 12px;
        }

        .user-info {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 24px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #475569;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .info-item i {
            color: #3b82f6;
            font-size: 0.875rem;
        }

        /* Compact Dashboard Title */
        .dashboard-title {
            background: #ffffff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .dashboard-title h2 {
            color: #1e293b;
            font-weight: 600;
            margin: 0;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .dashboard-title h2 i {
            font-size: 1.125rem;
        }

        /* Compact Today's Stats */
        .today-stats {
            background: #ffffff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .today-stats h4 {
            color: #1e293b;
            margin-bottom: 12px;
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .today-stats h4 i {
            font-size: 0.875rem;
        }

        .today-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
        }

        .today-item {
            text-align: center;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 8px;
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.25);
        }

        .today-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .today-label {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        /* Single Row Stats Grid - Updated */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .dashboard-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 20px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #6366f1);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .dashboard-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-color: #3b82f6;
        }

        .dashboard-card:hover::before {
            opacity: 1;
        }

        .dashboard-card .icon {
            font-size: 2rem;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: transform 0.3s;
        }

        .dashboard-card:hover .icon {
            transform: scale(1.2) rotate(5deg);
        }

        .dashboard-card .count {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
            line-height: 1;
        }

        .dashboard-card .label {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 500;
            text-transform: capitalize;
            line-height: 1.2;
        }

        .chart-container {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s ease;
        }

        .chart-container:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(-4px);
        }

        .activity-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .activity-card h5 {
            font-size: 1.125rem;
            margin-bottom: 16px;
            color: #1e293b;
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .activity-card h5 i {
            font-size: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background: rgba(59, 130, 246, 0.05);
            border-radius: 6px;
            margin: 0 -6px;
            padding: 10px 6px;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 10px;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        /* Compact animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 12px;
            }
            
            .welcome-header h1 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 12px;
            }
            
            .dashboard-card {
                padding: 12px;
                min-height: 85px;
            }
            
            .dashboard-card .icon {
                font-size: 1.25rem;
                margin-bottom: 4px;
            }
            
            .dashboard-card .count {
                font-size: 1.25rem;
            }
            
            .dashboard-card .label {
                font-size: 0.65rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .today-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Compact Welcome Header with Kolkata Time -->
    <div class="welcome-header animate__animated animate__fadeInDown">
        <h1><?= $greeting ?>, <?= htmlspecialchars($userName) ?>!</h1>
        <p class="welcome-text">Welcome back to your dashboard. Have a productive day!</p>
        <div class="user-info">
            <div class="info-item">
                <i class="fas fa-user"></i>
                <span><?= htmlspecialchars($userRole) ?></span>
            </div>
            <div class="info-item">
                <i class="fas fa-calendar"></i>
                <span><?= $currentDate ?></span>
            </div>
            <div class="info-item">
                <i class="fas fa-clock"></i>
                <span class="live-time" id="currentTime"><?= $currentTime ?></span>
 
            </div>
        </div>
    </div>

    <!-- Compact Today's Stats -->
    <div class="today-stats animate__animated animate__fadeIn">
        <h4><i class="fas fa-calendar-day"></i> Today's Activity</h4>
        <div class="today-grid">
            <div class="today-item">
                <div class="today-count"><?= $todayGRN ?></div>
                <div class="today-label">GRN Entries</div>
            </div>
            <div class="today-item">
                <div class="today-count"><?= $todayMaterialRequests ?></div>
                <div class="today-label">Material Requests</div>
            </div>
        </div>
    </div>

    <!-- Process Stats Grid -->
    <!-- <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="dashboard-card animate__animated animate__zoomIn" style="animation-delay: 0.1s; border-left: 5px solid #3b82f6;">
                <div class="icon"><i class="fas fa-vial"></i></div>
                <div class="count"><?= $dippingCount ?></div>
                <div class="label">Total Dipping</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card animate__animated animate__zoomIn" style="animation-delay: 0.2s; border-left: 5px solid #10b981;">
                <div class="icon"><i class="fas fa-microchip"></i></div>
                <div class="count"><?= $electronicCount ?></div>
                <div class="label">Electronic Testing</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card animate__animated animate__zoomIn" style="animation-delay: 0.3s; border-left: 5px solid #f59e0b;">
                <div class="icon"><i class="fas fa-lock"></i></div>
                <div class="count"><?= $sealingCount ?></div>
                <div class="label">Sealing Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card animate__animated animate__zoomIn" style="animation-delay: 0.4s; border-left: 5px solid #ef4444;">
                <div class="icon"><i class="fas fa-exchange-alt"></i></div>
                <div class="count"><?= $transactionCount ?></div>
                <div class="label">Total Transactions</div>
            </div>
        </div>
    </div> -->

    <!-- Compact Stats Cards -->
    <div class="stats-grid">
        <?php if ($suppliers > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
            <div class="icon"><i class="fas fa-truck"></i></div>
            <div class="count" data-target="<?= $suppliers ?>"><?= $suppliers ?></div>
            <div class="label">Suppliers</div>
        </div>
        <?php endif; ?>

        <?php if ($departments > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
            <div class="icon"><i class="fas fa-building"></i></div>
            <div class="count" data-target="<?= $departments ?>"><?= $departments ?></div>
            <div class="label">Departments</div>
        </div>
        <?php endif; ?>

        <?php if ($materials > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
            <div class="icon"><i class="fas fa-boxes"></i></div>
            <div class="count" data-target="<?= $materials ?>"><?= $materials ?></div>
            <div class="label">Materials</div>
        </div>
        <?php endif; ?>

        <?php if ($machines > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
            <div class="icon"><i class="fas fa-cogs"></i></div>
            <div class="count" data-target="<?= $machines ?>"><?= $machines ?></div>
            <div class="label">Machines</div>
        </div>
        <?php endif; ?>

        <?php if ($products > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
            <div class="icon"><i class="fas fa-cubes"></i></div>
            <div class="count" data-target="<?= $products ?>"><?= $products ?></div>
            <div class="label">Products</div>
        </div>
        <?php endif; ?>

        <?php if ($gateEntries > 0 || $is_admin): ?>
        <div class="dashboard-card animate__animated animate__fadeInUp" style="animation-delay: 0.6s;">
            <div class="icon"><i class="fas fa-sign-in-alt"></i></div>
            <div class="count" data-target="<?= $gateEntries ?>"><?= $gateEntries ?></div>
            <div class="label">Gate Entries</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Compact Charts and Activities Row -->
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="chart-container animate__animated animate__fadeInLeft">
                <div class="chart-title"><i class="fas fa-chart-pie"></i> QC Status Distribution</div>
                <canvas id="qcChart" style="max-height: 250px;"></canvas>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="chart-container animate__animated animate__fadeInUp">
                <div class="chart-title"><i class="fas fa-chart-line"></i> Production Trends (Last 6 Months)</div>
                <canvas id="trendChart" style="max-height: 250px;"></canvas>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="chart-container animate__animated animate__fadeInLeft">
                <div class="chart-title"><i class="fas fa-tasks"></i> Process Completion</div>
                <canvas id="processBarChart" style="max-height: 250px;"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="activity-card animate__animated animate__fadeInRight">
                <h5><i class="fas fa-history"></i> Recent Activities</h5>
                <?php if (empty($recentActivities)): ?>
                    <div class="activity-item">
                        <div class="activity-icon" style="background: #64748b;"><i class="fas fa-info"></i></div>
                        <div>
                            <strong style="color: #1e293b; font-size: 0.875rem;">No recent activities</strong><br>
                            <small style="color: #64748b;">System is ready for new entries</small>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach($recentActivities as $activity): ?>
                        <div class="activity-item">
                            <?php
                            $iconClass = 'fas fa-file-alt';
                            $activityTitle = htmlspecialchars($activity['activity_type']);
                            $activityDetails = 'Reference: ' . htmlspecialchars($activity['reference_id']);
                            
                            if ($activity['source_type'] === 'material_request') {
                                $iconClass = 'fas fa-shopping-cart';
                                $activityDetails = 'Request ID: ' . htmlspecialchars($activity['reference_id']);
                                if (isset($activity['request_by']) && !empty($activity['request_by'])) {
                                    $activityDetails .= ' • By: ' . htmlspecialchars($activity['request_by']);
                                }
                                if (isset($activity['item_count'])) {
                                    $activityDetails .= ' • Items: ' . $activity['item_count'];
                                }
                            } elseif ($activity['source_type'] === 'grn') {
                                $iconClass = 'fas fa-truck';
                            }
                            
                            $activityDate = $activity['activity_date'];
                            if ($activityDate instanceof DateTime) {
                                $dateFormatted = $activityDate->format('M d, Y');
                            } else {
                                $dateFormatted = date('M d, Y', strtotime($activityDate));
                            }
                            ?>
                            <div class="activity-icon">
                                <i class="<?= $iconClass ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <strong style="color: #1e293b; font-size: 0.875rem;"><?= $activityTitle ?></strong><br>
                                <small style="color: #64748b; font-size: 0.8rem;"><?= $activityDetails ?></small><br>
                                <small style="color: #94a3b8; font-size: 0.75rem;"><?= $dateFormatted ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

    <script>
        // Real-time clock update for Kolkata time
        function updateTime() {
            const now = new Date();
            const kolkataTime = new Date(now.toLocaleString("en-US", {timeZone: "Asia/Kolkata"}));
            const timeString = kolkataTime.toLocaleString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            
            const timeEl = document.getElementById('currentTime');
            if (timeEl) timeEl.textContent = timeString;
        }

        setInterval(updateTime, 1000);

        // Animated count-up for dashboard cards
        document.querySelectorAll('.dashboard-card .count').forEach(function(el) {
            const target = parseInt(el.textContent.replace(/,/g, '')) || 0;
            let start = 0;
            const duration = 2000;
            const step = target / (duration / 16);
            
            const timer = setInterval(function() {
                start += step;
                if (start >= target) {
                    el.textContent = target.toLocaleString();
                    clearInterval(timer);
                } else {
                    el.textContent = Math.floor(start).toLocaleString();
                }
            }, 16);
        });

        // QC Status Chart
        const qcCtx = document.getElementById('qcChart');
        if (qcCtx) {
            new Chart(qcCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Accept', 'Under Deviation', 'Hold', 'Reject', 'Pending'],
                    datasets: [{
                        data: [
                            <?= (int)$qcStatusCounts['Accept'] ?>,
                            <?= (int)$qcStatusCounts['under_Deviation'] ?>,
                            <?= (int)$qcStatusCounts['Hold'] ?>,
                            <?= (int)$qcStatusCounts['Reject'] ?>,
                            <?= (int)$qcStatusCounts['Pending'] ?>
                        ],
                        backgroundColor: ['#10b981', '#f97316', '#f59e0b', '#ef4444', '#6b7280'],
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    animation: { duration: 2000, easing: 'easeOutElastic' },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
                    },
                    cutout: '65%'
                }
            });
        }

        // Production Trend Chart
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            new Chart(trendCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthlyData['labels']) ?>,
                    datasets: [
                        {
                            label: 'Dipping',
                            data: <?= json_encode($monthlyData['dipping']) ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointHoverRadius: 8
                        },
                        {
                            label: 'Electronic',
                            data: <?= json_encode($monthlyData['electronic']) ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointHoverRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    animation: { duration: 2500, easing: 'easeOutQuart' },
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        // Process Bar Chart
        const barCtx = document.getElementById('processBarChart');
        if (barCtx) {
            new Chart(barCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Dipping', 'Electronic', 'Sealing', 'Transactions'],
                    datasets: [{
                        label: 'Process Volume',
                        data: [<?= (int)$dippingCount ?>, <?= (int)$electronicCount ?>, <?= (int)$sealingCount ?>, <?= (int)$transactionCount ?>],
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'],
                        borderRadius: 12,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    animation: { duration: 2000, easing: 'easeOutBounce' },
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
    </script>

</body>
</html>
