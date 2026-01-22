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

// Always define $editData as an empty array to prevent undefined variable warnings
$editData = [];

// Check for edit mode and populate $editData if editing
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $sql = "SELECT * FROM sealing_entry WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$editId]);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $editData = $row;
    }
    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    }
}

// Initialize variables to prevent warnings
$sealingDeptId = null;
$supervisors = [];
$success = '';
$error = '';

// Get department ID
$deptSql = "SELECT dept_id FROM departments WHERE department_name = 'Sealing'";
$deptStmt = sqlsrv_query($conn, $deptSql);
if ($deptStmt && ($deptRow = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC))) {
    $sealingDeptId = $deptRow['dept_id'];
    sqlsrv_free_stmt($deptStmt);
}

// Get supervisors
if ($sealingDeptId !== null) {
    $supervisorSql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND department_id = ? AND grn_checked_by IS NOT NULL AND grn_checked_by != '' ORDER BY grn_checked_by";
    $supervisorResult = sqlsrv_query($conn, $supervisorSql, [$sealingDeptId]);
    if ($supervisorResult) {
        while ($row = sqlsrv_fetch_array($supervisorResult, SQLSRV_FETCH_ASSOC)) {
            $supervisors[] = $row['grn_checked_by'];
        }
        sqlsrv_free_stmt($supervisorResult);
    }
}

// Handle AJAX requests first (before any HTML output)
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'fetch_lot_bin_by_batch':
            $batch_number = $_POST['batch_number'] ?? '';
            $lots = [];
            $bins = [];
            if ($batch_number) {
                $lotSql = "SELECT DISTINCT lot_no FROM electronic_batch_entry WHERE batch_number = ? AND forward = 1 ORDER BY lot_no";
                $lotStmt = sqlsrv_query($conn, $lotSql, [$batch_number]);
                if ($lotStmt) {
                    while ($row = sqlsrv_fetch_array($lotStmt, SQLSRV_FETCH_ASSOC)) {
                        $lots[] = $row['lot_no'];
                    }
                    sqlsrv_free_stmt($lotStmt);
                }
                
                $binSql = "SELECT DISTINCT bin_no FROM electronic_batch_entry WHERE batch_number = ? AND forward = 1 ORDER BY bin_no";
                $binStmt = sqlsrv_query($conn, $binSql, [$batch_number]);
                if ($binStmt) {
                    while ($row = sqlsrv_fetch_array($binStmt, SQLSRV_FETCH_ASSOC)) {
                        $bins[] = $row['bin_no'];
                    }
                    sqlsrv_free_stmt($binStmt);
                }
            }
            echo json_encode(['success' => true, 'lots' => $lots, 'bins' => $bins]);
            exit;
            
        case 'fetch_bag_no_by_batch':
            $batch_no = $_POST['batch_no'] ?? '';
            $nextBagNo = 1;
            
            if ($batch_no) {
                $sql = "SELECT MAX(CAST(bag_no AS INT)) as max_bag FROM sealing_entry WHERE batch_no = ? AND ISNUMERIC(bag_no) = 1";
                $stmt = sqlsrv_query($conn, $sql, [$batch_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    if ($row['max_bag'] && is_numeric($row['max_bag'])) {
                        $nextBagNo = intval($row['max_bag']) + 1;
                    }
                }
                if ($stmt) {
                    sqlsrv_free_stmt($stmt);
                }
            }
            
            echo json_encode(['success' => true, 'next_bag_no' => $nextBagNo]);
            exit;
            
        case 'fetch_batch_details':
            $batch_number = $_POST['batch_number'] ?? '';
            
            if ($batch_number) {
                $sql = "SELECT * FROM batch_creation WHERE batch_number = ?";
                $stmt = sqlsrv_query($conn, $sql, [$batch_number]);
                
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    // Format dates properly for JSON
                    if (isset($row['mfg_date']) && $row['mfg_date'] instanceof DateTime) {
                        $row['mfg_date'] = $row['mfg_date']->format('Y-m-d');
                    }
                    if (isset($row['exp_date']) && $row['exp_date'] instanceof DateTime) {
                        $row['exp_date'] = $row['exp_date']->format('Y-m-d');
                    }
                    if (isset($row['created_at']) && $row['created_at'] instanceof DateTime) {
                        $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
                    }
                    if (isset($row['updated_at']) && $row['updated_at'] instanceof DateTime) {
                        $row['updated_at'] = $row['updated_at']->format('Y-m-d H:i:s');
                    }
                    
                    sqlsrv_free_stmt($stmt);
                    echo json_encode(['success' => true, 'batch_details' => $row]);
                } else {
                    if ($stmt) sqlsrv_free_stmt($stmt);
                    echo json_encode(['success' => false, 'message' => 'No batch details found']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Batch number is required']);
            }
            exit;
            
        case 'fetch_pass_gross':
            $batch_number = $_POST['batch_number'] ?? '';
            $lot_no = $_POST['lot_no'] ?? '';
            $bin_no = $_POST['bin_no'] ?? '';
            $pass_gross = '0.00';

            if ($batch_number && $lot_no && $bin_no) {
                $sql = "SELECT SUM(pass_gross) as total_pass_gross FROM electronic_batch_entry WHERE batch_number = ? AND lot_no = ? AND bin_no = ? AND forward = 1";
                $stmt = sqlsrv_query($conn, $sql, [$batch_number, $lot_no, $bin_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $pass_gross = $row['total_pass_gross'] ? number_format($row['total_pass_gross'], 2) : '0.00';
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            echo json_encode(['success' => true, 'pass_gross' => $pass_gross]);
            exit;
            
        case 'fetch_seal_gross':
            $batch_no = $_POST['batch_no'] ?? '';
            $lot_no = $_POST['lot_no'] ?? '';
            $bin_no = $_POST['bin_no'] ?? '';
            $seal_gross = '0.00';

            if ($batch_no && $lot_no && $bin_no) {
                $sql = "SELECT SUM(seal_gross) as total_seal_gross FROM sealing_entry WHERE batch_no = ? AND lot_no = ? AND bin_no = ?";
                $stmt = sqlsrv_query($conn, $sql, [$batch_no, $lot_no, $bin_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $seal_gross = $row['total_seal_gross'] ? number_format($row['total_seal_gross'], 2) : '0.00';
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            echo json_encode(['success' => true, 'seal_gross' => $seal_gross]);
            exit;
            
        case 'fetch_batch_summary':
            $batch_no = $_POST['batch_no'] ?? '';
            $summary = ['total_entries' => 0, 'total_seal_gross' => 0];
            
            if ($batch_no) {
                $sql = "SELECT COUNT(*) as total_entries, COALESCE(SUM(seal_gross), 0) as total_seal_gross FROM sealing_entry WHERE batch_no = ?";
                $stmt = sqlsrv_query($conn, $sql, [$batch_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $summary = $row;
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            
            echo json_encode(['success' => true] + $summary);
            exit;
            
        case 'fetch_lot_bin_summary':
            $batch_no = $_POST['batch_no'] ?? '';
            $lot_no = $_POST['lot_no'] ?? '';
            $bin_no = $_POST['bin_no'] ?? '';
            $summary = ['entries' => 0];
            
            if ($batch_no && $lot_no && $bin_no) {
                $sql = "SELECT COUNT(*) as entries FROM sealing_entry WHERE batch_no = ? AND lot_no = ? AND bin_no = ?";
                $stmt = sqlsrv_query($conn, $sql, [$batch_no, $lot_no, $bin_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $summary = $row;
                }
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            
            echo json_encode(['success' => true] + $summary);
            exit;
            
        case 'fetch_batch_wise_totals':
            $batch_number = $_POST['batch_number'] ?? '';
            $result = [
                'success' => false,
                'total_pass_gross' => 0,
                'total_seal_gross' => 0,
                'batch_entries' => 0,
                'seal_entries' => 0
            ];
            
            if ($batch_number) {
                // Get total pass kg from electronic batch entries for this batch
                $passKgSql = "SELECT COALESCE(SUM(pass_kg), 0) as total_pass_kg, COUNT(*) as batch_entries 
                                FROM electronic_batch_entry 
                                WHERE batch_number = ? AND forward = 1";
                $passKgStmt = sqlsrv_query($conn, $passKgSql, [$batch_number]);
                if ($passKgStmt && $row = sqlsrv_fetch_array($passKgStmt, SQLSRV_FETCH_ASSOC)) {
                    $result['total_pass_gross'] = (float)$row['total_pass_kg'];
                    $result['batch_entries'] = (int)$row['batch_entries'];
                    sqlsrv_free_stmt($passKgStmt);
                }
                
                // Get total seal gross from sealing entries for this batch
                $sealGrossSql = "SELECT COALESCE(SUM(seal_gross), 0) as total_seal_gross, COUNT(*) as seal_entries 
                                FROM sealing_entry 
                                WHERE batch_no = ?";
                $sealGrossStmt = sqlsrv_query($conn, $sealGrossSql, [$batch_number]);
                if ($sealGrossStmt && $row = sqlsrv_fetch_array($sealGrossStmt, SQLSRV_FETCH_ASSOC)) {
                    $result['total_seal_gross'] = (float)$row['total_seal_gross'];
                    $result['seal_entries'] = (int)$row['seal_entries'];
                    sqlsrv_free_stmt($sealGrossStmt);
                }
                
                $result['success'] = true;
            }
            
            echo json_encode($result);
            exit;
            
        case 'fetch_lot_bin_pass_kg':
            $batch_number = $_POST['batch_number'] ?? '';
            $lot_no = $_POST['lot_no'] ?? '';
            $bin_no = $_POST['bin_no'] ?? '';
            
            if ($batch_number && $lot_no && $bin_no) {
                $sql = "SELECT COALESCE(SUM(pass_kg), 0) as total_pass_kg, COUNT(*) as entry_count 
                        FROM electronic_batch_entry 
                        WHERE batch_number = ? AND lot_no = ? AND bin_no = ? AND forward = 1";
                
                $stmt = sqlsrv_query($conn, $sql, [$batch_number, $lot_no, $bin_no]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    sqlsrv_free_stmt($stmt);
                    echo json_encode([
                        'success' => true,
                        'pass_gross' => number_format((float)$row['total_pass_kg'], 2, '.', ''),
                        'entry_count' => $row['entry_count']
                    ]);
                } else {
                    if ($stmt) sqlsrv_free_stmt($stmt);
                    echo json_encode(['success' => false, 'pass_gross' => '0.00', 'entry_count' => 0]);
                }
            } else {
                echo json_encode(['success' => false, 'pass_gross' => '0.00', 'entry_count' => 0]);
            }
            exit;
            
        case 'fetch_bins_for_lot':
            $batch_number = $_POST['batch_number'] ?? '';
            $lot_no = $_POST['lot_no'] ?? '';
            $bins = [];
            
            if ($batch_number && $lot_no) {
                $binSql = "SELECT DISTINCT bin_no FROM electronic_batch_entry 
                          WHERE batch_number = ? AND lot_no = ? AND forward = 1 
                          ORDER BY bin_no";
                $binStmt = sqlsrv_query($conn, $binSql, [$batch_number, $lot_no]);
                if ($binStmt) {
                    while ($row = sqlsrv_fetch_array($binStmt, SQLSRV_FETCH_ASSOC)) {
                        $bins[] = $row['bin_no'];
                    }
                    sqlsrv_free_stmt($binStmt);
                }
            }
            
            echo json_encode(['success' => true, 'bins' => $bins]);
            exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Get form data with null coalescing
    $month = trim($_POST['month'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $shift = trim($_POST['shift'] ?? '');
    $bag_no = trim($_POST['bag_no'] ?? '');
    $batch_no = trim($_POST['batch_no'] ?? '');
    $machine_no = trim($_POST['machine_no'] ?? '');
    $lot_no = trim($_POST['lot_no'] ?? '');
    $bin_no = trim($_POST['bin_no'] ?? '');
    $flavour = trim($_POST['flavour'] ?? '');
    $seal_kg = trim($_POST['seal_kg'] ?? '');
    $avg_wt = trim($_POST['avg_wt'] ?? '');
    $foil_rej_kg = trim($_POST['foil_rej_kg'] ?? '');
    $product_rej = trim($_POST['product_rej'] ?? '');
    $rej_avg_wt = trim($_POST['rej_avg_wt'] ?? '');
    $rej_gross = trim($_POST['rej_gross'] ?? '');
    $seal_gross = trim($_POST['seal_gross'] ?? '');
    $supervisor = trim($_POST['supervisor'] ?? '');

    // Check if edit mode (update)
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $editId = intval($_GET['edit']);
        $sql = "UPDATE sealing_entry SET
            month = ?, date = ?, shift = ?, bag_no = ?, batch_no = ?, machine_no = ?, lot_no = ?, bin_no = ?, flavour = ?,
            seal_kg = ?, avg_wt = ?, foil_rej_kg = ?, product_rej = ?, rej_avg_wt = ?, rej_gross = ?, seal_gross = ?, supervisor = ?
            WHERE id = ?";
        $params = [
            $month, $date, $shift, $bag_no, $batch_no, $machine_no, $lot_no, $bin_no, $flavour,
            $seal_kg, $avg_wt, $foil_rej_kg, $product_rej, $rej_avg_wt, $rej_gross, $seal_gross, $supervisor,
            $editId
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt) {
            $success = "Sealing entry updated successfully.";
            sqlsrv_free_stmt($stmt);
            // Refresh edit data
            $sql = "SELECT * FROM sealing_entry WHERE id = ?";
            $stmt = sqlsrv_query($conn, $sql, [$editId]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $editData = $row;
            }
            if ($stmt) sqlsrv_free_stmt($stmt);
        } else {
            $errors = sqlsrv_errors();
            $error = "Database error: " . ($errors ? $errors[0]['message'] : 'Unknown error');
        }
    } else {
        // Insert mode
        $sql = "INSERT INTO sealing_entry (
            month, date, shift, bag_no, batch_no, machine_no, lot_no, bin_no, flavour, seal_kg, avg_wt, foil_rej_kg, product_rej, rej_avg_wt, rej_gross, seal_gross, supervisor
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            $month, $date, $shift, $bag_no, $batch_no, $machine_no, $lot_no, $bin_no, $flavour,
            $seal_kg, $avg_wt, $foil_rej_kg, $product_rej, $rej_avg_wt, $rej_gross, $seal_gross, $supervisor
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt) {
            $success = "Sealing entry added successfully.";
            sqlsrv_free_stmt($stmt);
        } else {
            $errors = sqlsrv_errors();
            $error = "Database error: " . ($errors ? $errors[0]['message'] : 'Unknown error');
        }
    }
}

// Initialize arrays to prevent warnings
$batchNumbers = [];
$machines = [];
$flavours = [];

// Fetch batch numbers
$batchSql = "SELECT DISTINCT batch_number FROM electronic_batch_entry WHERE forward=1 AND batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number";
$batchResult = sqlsrv_query($conn, $batchSql);
if ($batchResult) {
    while ($row = sqlsrv_fetch_array($batchResult, SQLSRV_FETCH_ASSOC)) {
        $batchNumbers[] = $row['batch_number'];
    }
    sqlsrv_free_stmt($batchResult);
}

// Fetch machines
$machineSql = "
    SELECT 
        m.machine_id, 
        m.machine_name, 
        d.department_name
    FROM machines m 
    INNER JOIN departments d ON m.department_id = d.id 
    WHERE d.department_name = 'Sealing'
    ORDER BY m.machine_name ASC";

$machineResult = sqlsrv_query($conn, $machineSql);
if ($machineResult) {
    while ($row = sqlsrv_fetch_array($machineResult, SQLSRV_FETCH_ASSOC)) {
        $machines[] = $row;
    }
    sqlsrv_free_stmt($machineResult);
}

// Fetch flavours
$flavourSql = "SELECT DISTINCT flavour FROM flavour_supervisor WHERE flavour IS NOT NULL AND flavour != '' ORDER BY flavour";
$flavourResult = sqlsrv_query($conn, $flavourSql);
if ($flavourResult) {
    while ($row = sqlsrv_fetch_array($flavourResult, SQLSRV_FETCH_ASSOC)) {
        $flavours[] = $row['flavour'];
    }
    sqlsrv_free_stmt($flavourResult);
}

// Get current month
$currentMonth = date('M-y');

// Include sidebar after all processing
include '../Includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sealing Entry Form</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <!-- Animate.css for animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Include existing CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background: #f7f9fa; 
        }
        
        /* Enhanced main container styling */
        .main-container {
            max-width: 1084px;
            margin: 0px 0 0px 260px; /* 260px for sidebar width */
            padding: 30px;  
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            animation: fadeInDown 0.8s;
        }
        
        @media (max-width: 991px) {
            .main-container { 
                margin-left: 0; 
                padding: 20px;
                margin: 20px 10px;
            }
        }
        
        .section-title {
            font-size: 1.8em;
            margin-bottom: 30px;
            font-weight: bold;
            text-align: center;
            color: #2b6777;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 0px;
        }
        
        .form-row > div {
            flex: 1 1 280px;
            min-width: 200px;
        }
        
        /* New Grid Layout - Electronic Testing Style */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 0px;
            padding: 5px 0;
        }

        .shift-group {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .shift-group label {
            font-weight: 600;
            color: #2b6777;
            margin-bottom: 0px;
            display: flex;
            align-items: center;
            gap: 0px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .shift-group input, 
        .shift-group select {
            padding: 0px 15px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #fff;
            min-height: 40px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .shift-group input:focus, 
        .shift-group select:focus {
            outline: none;
            border-color: #4a90e2;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
            background: #fafbfc;
        }
        
        .shift-group input:hover, 
        .shift-group select:hover {
            border-color: #b8c6d0;
        }
        
        /* Button Container */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 3px;
            padding-top: 20px;
        }
        
        /* Responsive Grid */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .form-group {
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2b6777;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        label i {
            margin-right: 8px;
            color: #4a90e2;
        }
        
        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            background: #fff;
            transition: all 0.3s ease;
            font-size: 1rem;
            min-height: 48px;
            box-sizing: border-box;
        }
        
        input:focus, select:focus {
            border-color: #667eea;
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        /* Batch field with view icon */
        .batch-field-container {
            position: relative;
        }
        
        /* Batch field container for proper button positioning */
        .batch-field-container {
            position: relative;
        }
        
        .view-batch-btn {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 6px;
            padding: 8px 10px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1000;
            opacity: 0;
            font-size: 0.9rem;
            height: 32px;
            width: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .view-batch-btn.show {
            opacity: 1;
        }
        
        .view-batch-btn:hover {
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        /* Ensure Select2 container has proper padding for the button */
        .batch-field-container .select2-container .select2-selection--single {
            padding-right: 50px !important;
        }
        
        /* Additional spacing for the Select2 dropdown arrow */
        .batch-field-container .select2-container--default .select2-selection--single .select2-selection__arrow {
            right: 45px !important;
        }
        
        /* Batch Details Modal Styling */
        .batch-details-container {
            padding: 20px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #667eea;
        }
        
        .detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-value {
            font-size: 1rem;
            color: #212529;
            font-weight: 500;
        }
        
        .detail-item.special-req {
            grid-column: 1 / -1;
        }
        
        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        /* Enhanced button styling */
        .form-actions {
            margin-top: 30px;
        }
        
        .submit-btn, .close-btn {
            padding: 15px 25px;
            font-size: 1.1em;
            font-weight: bold;
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            min-height: 56px;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(76, 175, 80, 0.4);
        }
        
        .close-btn {
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(244, 67, 54, 0.3);
        }
        
        .close-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(244, 67, 54, 0.4);
            color: #fff;
            text-decoration: none;
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 20px 25px;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .batch-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .batch-detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #495057;
            flex: 1;
        }
        
        .detail-value {
            flex: 2;
            text-align: right;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .detail-item.special-req {
            grid-column: 1 / -1;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-active {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .status-completed {
            background: #cff4fc;
            color: #055160;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
            color: white;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .form-row { 
                flex-direction: column; 
                gap: 15px; 
            }
            
            .main-container { 
                padding: 20px 15px; 
                margin: 10px 5px;
            }
            
            .section-title {
                font-size: 1.5rem;
                flex-direction: column;
                gap: 5px;
            }
            
            .batch-detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .detail-value {
                text-align: left;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Loading animation */
        .loading-spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Enhanced Select2 styling */
        .select2-container--default .select2-selection--single {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            height: 48px;
            line-height: 44px;
            background: #f8fafb;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        /* Summary section styling */
        .summary-section {
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
        }
        
        .batch-summary-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid #e9ecef;
        }
        
        .summary-title {
            color: #2c3e50;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .summary-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
        }
        
        .summary-item label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-item .value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .summary-item i {
            color: #667eea;
        }

        /* Batch summary table styling */
        .batch-summary-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #dee2e6;
            animation: slideInUp 0.5s ease;
        }
        
        .batch-summary-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .batch-summary-table th {
            color: white;
            font-weight: bold;
            padding: 15px 12px;
            text-align: center;
            font-size: 14px;
            background: #6c63ff;
        }
        
        .batch-summary-table td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .batch-summary-table tr:hover {
            background-color: #f0f8ff;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .batch-summary-table tr:first-child td {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        
        .batch-summary-table tr:last-child td {
            background-color: #e8f5e8 !important;
            border-left: 4px solid #28a745;
            font-weight: bold;
        }
        
        /* Entry count styling */
        .entry-count-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .electronic-count {
            color: #007bff;
            font-weight: bold;
            padding: 2px 6px;
            background: rgba(0, 123, 255, 0.1);
            border-radius: 3px;
        }
        
        .sealing-count {
            color: #28a745;
            font-weight: bold;
            padding: 2px 6px;
            background: rgba(40, 167, 69, 0.1);
            border-radius: 3px;
        }
        
        .current-entry-indicator {
            color: #28a745;
            font-weight: bold;
            padding: 2px 6px;
            background: rgba(40, 167, 69, 0.15);
            border-radius: 3px;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .summary-header h6 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .progress-section {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .progress-bar.bg-success {
            background: linear-gradient(90deg, #28a745, #20c997) !important;
        }
        
        .progress-bar.bg-warning {
            background: linear-gradient(90deg, #ffc107, #fd7e14) !important;
        }
        
        .progress-bar.bg-danger {
            background: linear-gradient(90deg, #dc3545, #e83e8c) !important;
        }
        
        /* Alert animations */
        .validation-alert {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1050;
            min-width: 300px;
            max-width: 400px;
            animation: slideInRight 0.5s ease;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            pointer-events: auto;
            margin-bottom: 20px;
        }
        
        /* Ensure alert doesn't block form interactions */
        .validation-alert + * {
            pointer-events: auto;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            pointer-events: auto !important;
            position: relative;
            z-index: 1;
        }
        
        /* Ensure all interactive elements are accessible */
        .shift-group input, .shift-group select, .shift-group textarea,
        input[type="text"], input[type="number"], input[type="date"],
        select, textarea {
            pointer-events: auto !important;
            position: relative;
            z-index: 2;
        }
        
        /* Prevent any overlays from blocking form interaction */
        .form-container, .form-grid, .shift-group {
            position: relative;
            z-index: 1;
        }
        
        .validation-alert.fade-out {
            animation: slideOutRight 0.5s ease forwards;
        }
        
        /* Ensure form elements are not covered by alerts */
        .form-grid {
            position: relative;
            z-index: 1;
            width: 100%;
        }
        
        /* Responsive alert positioning */
        @media (max-width: 768px) {
            .validation-alert {
                top: 60px;
                left: 20px;
                right: 20px;
                min-width: auto;
                max-width: none;
            }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        @keyframes slideInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Status metrics styling */
        .batch-status-overview {
            animation: slideInUp 0.6s ease;
        }
        
        .status-metric {
            padding: 15px;
        }
        
        .status-metric .metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .status-metric .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .card-header h6 {
            margin-bottom: 0;
            font-weight: 600;
        }

        
        /* Enhanced reset button styles for both shift and supervisor */
        #resetShiftBtn, #resetSupervisorBtn {
            background: none !important;
            border: none !important;
            color: #007bff !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            opacity: 0.8 !important;
        }

        #resetShiftBtn:hover, #resetSupervisorBtn:hover {
            background: rgba(0, 123, 255, 0.1) !important;
            color: #0056b3 !important;
            transform: translateY(-50%) scale(1.1) !important;
            opacity: 1 !important;
        }

        #resetShiftBtn i, #resetSupervisorBtn i {
            font-size: 1.1em;
            transition: color 0.2s;
        }

        /* Ensure selects have proper padding for the reset button */
        #shift {
            padding-right: 40px !important;
        }

        #supervisor {
            padding-right: 65px !important; /* More padding for Select2 */
        }

        /* Locked field styles */
        #shift.shift-locked, #supervisor.supervisor-locked {
            background-color: #e9ecef !important;
            border-color: #ced4da !important;
            color: #6c757d !important;
            cursor: not-allowed !important;
        }

        #shift.shift-locked:focus, #supervisor.supervisor-locked:focus {
            box-shadow: none !important;
            border-color: #ced4da !important;
        }

        /* Select2 locked state */
        .select2-container.supervisor-locked .select2-selection {
            background-color: #e9ecef !important;
            border-color: #ced4da !important;
            cursor: not-allowed !important;
        }

        /* Enhanced form styling */
        .form-control, .form-select {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 12px 15px;
            background: #f8fafb;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        /* Button enhancements */
        .btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004494 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border: none;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        
        /* Tooltip styling */
        .tooltip-inner {
            background-color: #333;
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        
        .tooltip-arrow {
            border-top-color: #333 !important;
        }
    </style>
</head>
<body>
<div class="main-container animate__animated animate__fadeInDown">
    <div class="section-title">
        <i class="fas fa-lock"></i> 
        Sealing Entry Form
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="post" action="" autocomplete="off">
        <!-- Header Row: Month, Date, Shift -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="month"><i class="fas fa-calendar-alt"></i> MONTH</label>
                <input type="text" id="month" name="month" value="<?= htmlspecialchars($editData['month'] ?? $currentMonth) ?>" required>
            </div>
            <div class="shift-group">
                <label for="date"><i class="fas fa-calendar-day"></i> DATE</label>
                <input type="date" id="date" name="date"
                    value="<?php
                        if (isset($editData['date']) && $editData['date']) {
                            // Format date for input[type=date]
                            if ($editData['date'] instanceof DateTime) {
                                echo $editData['date']->format('Y-m-d');
                            } else {
                                // If it's a string, try to format
                                echo date('Y-m-d', strtotime($editData['date']));
                            }
                        } else {
                            echo '';
                        }
                    ?>"
                    required>
            </div>
            <div class="shift-group position-relative">
                <label for="shift"><i class="fas fa-clock"></i> SHIFT</label>
                <div style="position: relative;">
                    <select id="shift" name="shift" required <?= !empty($editData) ? 'disabled' : '' ?>>
                        <option value="">Select Shift</option>
                        <option value="I" <?= (isset($editData['shift']) && $editData['shift'] == 'I') ? 'selected' : '' ?>>Shift I</option>
                        <option value="II" <?= (isset($editData['shift']) && $editData['shift'] == 'II') ? 'selected' : '' ?>>Shift II</option>
                        <option value="III" <?= (isset($editData['shift']) && $editData['shift'] == 'III') ? 'selected' : '' ?>>Shift III</option>
                    </select>
                    <button type="button" id="resetShiftBtn" title="Reset Shift"
                        style="background: none; border: none; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #007bff; cursor: pointer; z-index: 2; display: none;">
                        <i class="fas fa-rotate-right"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Second Row: Bag No, Batch No, Machine No -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="bag_no"><i class="fas fa-box"></i> BAG NO.</label>
                <input type="text" id="bag_no" name="bag_no" readonly placeholder="Auto-generated" value="<?= htmlspecialchars($editData['bag_no'] ?? '') ?>">
            </div>
            <div class="shift-group batch-field-container">
                <label for="batch_no"><i class="fas fa-barcode"></i> BATCH NO.</label>
                <div style="position: relative;">
                    <select id="batch_no" name="batch_no" class="select2" required>
                        <option value="">Select Batch No.</option>
                        <?php foreach($batchNumbers as $batch): ?>
                            <option value="<?= htmlspecialchars($batch) ?>" <?= (isset($editData['batch_no']) && $editData['batch_no'] == $batch) ? 'selected' : '' ?>><?= htmlspecialchars($batch) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="viewBatchBtn" class="view-batch-btn" title="View Batch Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="shift-group">
                <label for="machine_no"><i class="fas fa-cogs"></i> MACHINE NO.</label>
                <select id="machine_no" name="machine_no" class="select2" required>
                    <option value="">-- Select Machine --</option>
                    <?php foreach($machines as $machine): ?>
                        <option value="<?= htmlspecialchars($machine['machine_id']) ?>"
                            <?= (isset($editData['machine_no']) && $editData['machine_no'] == $machine['machine_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($machine['machine_id']) ?> - <?= htmlspecialchars($machine['machine_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Third Row: Lot No, Bin No, Product -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="lot_no"><i class="fas fa-tag"></i> LOT NO.</label>
                <select id="lot_no" name="lot_no" class="select2" required disabled>
                    <option value="">Select Batch No. first</option>
                </select>
            </div>
            <div class="shift-group">
                <label for="bin_no"><i class="fas fa-archive"></i> BIN NO.</label>
                <select id="bin_no" name="bin_no" class="select2" required disabled>
                    <option value="">Select Batch No. first</option>
                </select>
            </div>
            <div class="shift-group">
                <label for="flavour"><i class="fas fa-cookie-bite"></i> FLAVOUR</label>
                <select id="flavour" name="flavour" class="select2" required>
                    <option value="">Select Flavour</option>
                    <?php foreach($flavours as $flavour): ?>
                        <option value="<?= htmlspecialchars($flavour) ?>" <?= (isset($editData['flavour']) && $editData['flavour'] == $flavour) ? 'selected' : '' ?>><?= htmlspecialchars($flavour) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Fourth Row: Seal KG, Avg. WT, Seal Gross -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="seal_kg"><i class="fas fa-weight"></i> SEAL KG</label>
                <div class="position-relative">
                    <input type="number" step="0.01" id="seal_kg" name="seal_kg" placeholder="0.00" required value="<?= htmlspecialchars($editData['seal_kg'] ?? '') ?>">
                    <button type="button" class="guidance-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #007bff; cursor: pointer; z-index: 5;" title="Show guidance for Seal KG">
                        <i class="fas fa-info-circle"></i>
                    </button>
                </div>
            </div>
            <div class="shift-group">
                <label for="avg_wt"><i class="fas fa-balance-scale"></i> AVG. WT.</label>
                <input type="number" step="0.01" id="avg_wt" name="avg_wt" placeholder="0.00" required value="<?= htmlspecialchars($editData['avg_wt'] ?? '') ?>">
            </div>
            <div class="shift-group">
                <label for="seal_gross"><i class="fas fa-calculator"></i> SEAL GROSS</label>
                <input type="number" step="0.01" id="seal_gross" name="seal_gross" placeholder="0.00" required value="<?= htmlspecialchars($editData['seal_gross'] ?? '') ?>">
            </div>
        </div>
        
        <!-- Fifth Row: Foil Rej. KG, Product Rej., Rej. Avg. WT -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="foil_rej_kg"><i class="fas fa-times-circle"></i> FOIL REJ. KG</label>
                <input type="number" step="0.01" id="foil_rej_kg" name="foil_rej_kg" placeholder="0.00" value="<?= htmlspecialchars($editData['foil_rej_kg'] ?? '') ?>">
            </div>
            <div class="shift-group">
                <label for="product_rej"><i class="fas fa-exclamation-triangle"></i> PRODUCT REJ.</label>
                <input type="number" step="0.01" id="product_rej" name="product_rej" placeholder="0.00" value="<?= htmlspecialchars($editData['product_rej'] ?? '') ?>">
            </div>
            <div class="shift-group">
                <label for="rej_avg_wt"><i class="fas fa-weight-hanging"></i> REJ. AVG. WT.</label>
                <input type="number" step="0.01" id="rej_avg_wt" name="rej_avg_wt" placeholder="0.00" value="<?= htmlspecialchars($editData['rej_avg_wt'] ?? '') ?>">
            </div>
        </div>
        
        <!-- Sixth Row: Rej. Gross, Supervisor -->
        <div class="form-grid">
            <div class="shift-group">
                <label for="rej_gross"><i class="fas fa-calculator"></i> REJ. GROSS</label>
                <input type="number" step="0.01" id="rej_gross" name="rej_gross" placeholder="0.00" value="<?= htmlspecialchars($editData['rej_gross'] ?? '') ?>">
            </div>
            <div class="shift-group">
                <label for="supervisor"><i class="fas fa-user-tie"></i> SUPERVISOR</label>
                <div style="position: relative;">
                    <select id="supervisor" name="supervisor" class="select2" required>
                        <option value="">Select Supervisor</option>
                        <?php foreach($supervisors as $supervisor): ?>
                            <option value="<?= htmlspecialchars($supervisor) ?>" <?= (isset($editData['supervisor']) && $editData['supervisor'] == $supervisor) ? 'selected' : '' ?>><?= htmlspecialchars($supervisor) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="resetSupervisorBtn" title="Reset Supervisor"
                        style="background: none; border: none; position: absolute; right: 35px; top: 50%; transform: translateY(-50%); color: #007bff; cursor: pointer; z-index: 2; display: none;">
                        <i class="fas fa-rotate-right"></i>
                    </button>
                </div>
            </div>
            <div class="shift-group">
                <!-- Empty div for grid alignment -->
            </div>
        </div>

        <!-- Action Buttons (before Batch Summary) -->
        <div class="btn-container" style="margin-bottom: 18px;">
            <button type="submit" class="submit-btn">
                <i class="fas fa-save me-2"></i>Submit
            </button>
            <a href="sealing_lookup.php" class="close-btn" style="margin-left: 10px;">
                <i class="fas fa-times me-2"></i>Cancel
            </a>
        </div>
        <!-- Batch Summary Section -->
        <div class="form-row summary-section">
            <div class="col-12">
                <div class="batch-summary-container">
                    <h6 class="summary-title">
                        <i class="fas fa-chart-bar"></i> Batch Summary
                    </h6>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <label><i class="fas fa-layer-group"></i> Total Batch Entries</label>
                            <div class="value" id="totalBatchEntries">0</div>
                        </div>
                        <div class="summary-item">
                            <label><i class="fas fa-weight"></i> Total Seal Gross</label>
                            <div class="value" id="totalSealGross">0.00</div>
                        </div>
                        <div class="summary-item">
                            <label><i class="fas fa-boxes"></i> Current Lot/Bin Entries</label>
                            <div class="value" id="currentLotBinEntries">0</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="btn-container">
            <button type="submit" class="submit-btn">
                <i class="fas fa-save me-2"></i>Submit Entry
            </button>
            <a href="sealing_lookup.php" class="close-btn">
                <i class="fas fa-times me-2"></i>Close
            </a>
        </div>
    </form>
    
    <!-- Batch Summary Table -->
        <div class="batch-summary-section" style="display: none; margin-top: 20px;">
            <div class="summary-header">
                <h6 class="mb-3">
                    <i class="fas fa-table me-2"></i>
                    Batch Summary & Sealing Analysis
                </h6>
            </div>
            <div class="table-responsive">
                <table class="batch-summary-table">
                    <thead>
                        <tr>
                            <th style="background: #6c63ff;">Type</th>
                            <th style="background: #6c63ff;">Pass KG Total</th>
                            <th style="background: #6c63ff;">Seal Gross</th>
                            <th style="background: #6c63ff;">Remaining</th>
                            <th style="background: #6c63ff;">Total Entries</th>
                            <th style="background: #6c63ff;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="batchTotalRow">
                            <td id="batchNumberCell" style="font-weight: bold; background-color: #fff3cd;">-</td>
                            <td id="totalPassGrossCell" style="font-weight: bold;">0.00</td>
                            <td id="totalSealGrossCell" style="font-weight: bold;">0.00</td>
                            <td id="totalRemainingCell" style="font-weight: bold;">0.00</td>
                            <td id="totalEntriesCell" style="font-weight: bold; color: #007bff;">0 + 0</td>
                            <td id="batchStatusCell" style="font-weight: bold;">Pending</td>
                        </tr>
                        <tr id="currentEntryRow" style="background-color: #f8f9fa;">
                            <td style="font-style: italic; color: #6c757d;">Current Entry</td>
                            <td id="currentPassGross">0.00</td>
                            <td id="currentSealGross">0.00</td>
                            <td id="currentRemaining">0.00</td>
                            <td id="currentEntryCount" style="color: #28a745;">+1</td>
                            <td id="currentStatus" style="font-style: italic;">Pending</td>
                        </tr>
                        <tr id="finalStatusRow" style="background-color: #e8f5e8; border-top: 2px solid #28a745;">
                            <td style="font-weight: bold; color: #333;">Final Status</td>
                            <td id="finalPassGross" style="font-weight: bold; color: #28a745;">0.00</td>
                            <td id="finalSealGross" style="font-weight: bold; color: #17a2b8;">0.00</td>
                            <td id="finalRemaining" style="font-weight: bold;">0.00</td>
                            <td id="finalEntryCount" style="font-weight: bold; color: #6c757d;">0 Total</td>
                            <td id="finalStatusText" style="font-weight: bold;">Ready</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Progress indicator -->
            <div class="progress-section mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="fw-bold">Batch Completion Progress</small>
                    <small id="batchProgressPercentage" class="fw-bold">0%</small>
                </div>
                <div class="progress" style="height: 10px; border-radius: 5px;">
                    <div class="progress-bar progress-bar-striped" id="batchProgressBar" 
                         role="progressbar" style="width: 0%; transition: width 0.6s ease;"></div>
                </div>
            </div>
        </div>
        
        <!-- Add this enhanced status section after the Enhanced Batch Summary Display -->
        <div class="batch-status-overview" style="display: none; margin-top: 20px;">
            <div class="row">
                <div class="col-12">
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i>
                                Electronic Batch Entry Status Summary
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <div class="status-metric">
                                        <div class="metric-value text-primary" id="totalPassKg">0.00</div>
                                        <div class="metric-label">Total Pass KG</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="status-metric">
                                        <div class="metric-value text-success" id="sealedGross">0.00</div>
                                        <div class="metric-label">Sealed Gross</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="status-metric">
                                        <div class="metric-value" id="pendingGross">0.00</div>
                                        <div class="metric-label">Pending Gross</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="status-metric">
                                        <div class="metric-value text-info" id="completionRate">0%</div>
                                        <div class="metric-label">Completion</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Form Actions with Close Button -->
        <!-- <div class="form-actions">
            <div class="d-flex gap-3 mt-4">
                <button type="submit" class="submit-btn flex-fill">
                    <i class="fas fa-save me-2"></i>Submit Entry
                </button>
                <a href="sealing_lookup.php" class="close-btn flex-fill">
                    <i class="fas fa-times me-"></i>Close
                </a>
            </div>
        </div> -->
    </form>
    
    <!-- Add this below your form or wherever you want the result -->

</div>

<!-- Batch Details Modal -->
<div class="modal fade" id="batchDetailsModal" tabindex="-1" aria-labelledby="batchDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchDetailsModalLabel">
                    <i class="fas fa-info-circle me-2"></i>Batch Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="batchDetailsContent">
                <div class="text-center">
                    <div class="loading-spinner"></div>
                    Loading batch details...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (required for Select2) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: 'Select an option',
        allowClear: true
    });
    
    // Position the view batch button correctly after Select2 initialization
    setTimeout(function() {
        var batchContainer = $('#batch_no').closest('.batch-field-container');
        var viewBtn = $('#viewBatchBtn');
        if (batchContainer.length && viewBtn.length) {
            // Ensure button is positioned relative to the container, not the select
            viewBtn.css({
                'position': 'absolute',
                'top': '50%',
                'right': '15px',
                'transform': 'translateY(-50%)',
                'z-index': '1000'
            });
        }
        
        // Show button if batch is already selected
        if ($('#batch_no').val()) {
            $('#viewBatchBtn').addClass('show');
        }
    }, 100);
    
    // Initially disable lot and bin dropdowns
    $('#lot_no, #bin_no').prop('disabled', true);
    
    // Handle batch selection with batch-wise summary
    $('#batch_no').on('change', function() {
        var batchNumber = $(this).val();
        $('#bag_no').val('');
        
        // Clear and disable lot_no and bin_no dropdowns
        $('#lot_no').html('<option value="">Select Lot No.</option>').prop('disabled', true);
        $('#bin_no').html('<option value="">Select Bin No.</option>').prop('disabled', true);
        
        // Reset validation variables
        batchTotalPassGross = 0;
        batchTotalSealGross = 0;
        batchElectronicEntries = 0;
        batchSealingEntries = 0;
        validationInProgress = false;
        
        // Clear form fields that depend on lot/bin selection
        $('#seal_gross').val('');
        
        // Hide batch summary
        $('.batch-summary-section').slideUp();
        
        // Show/hide view button
        if (batchNumber) {
            $('#viewBatchBtn').addClass('show');
            
            // Fetch batch-wise totals
            fetchBatchTotals(batchNumber);
            
            // Fetch bag numbers
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'fetch_bag_no_by_batch', batch_no: batchNumber },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#bag_no').val(response.next_bag_no);
                    } else {
                        $('#bag_no').val('1');
                    }
                },
                error: function() {
                    $('#bag_no').val('1');
                }
            });
            
            // Fetch lot and bin numbers for the selected batch
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'fetch_lot_bin_by_batch', batch_number: batchNumber },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Populate lot_no dropdown
                        var lotOptions = '<option value="">Select Lot No.</option>';
                        response.lots.forEach(function(lot) {
                            lotOptions += '<option value="' + lot + '">' + lot + '</option>';
                        });
                        $('#lot_no').html(lotOptions).prop('disabled', false);

                        // Populate bin_no dropdown
                        var binOptions = '<option value="">Select Bin No.</option>';
                        response.bins.forEach(function(bin) {
                            binOptions += '<option value="' + bin + '">' + bin + '</option>';
                        });
                        $('#bin_no').html(binOptions).prop('disabled', false);
                    }
                }
            });
        } else {
            $('#viewBatchBtn').removeClass('show');
            $('#bag_no').val('');
        }

        // Update old batch summary (keep for other summary data)
        updateOldBatchSummary(batchNumber);
    });
    
    // Set today's date by default
    $('#date').val(new Date().toISOString().split('T')[0]);

    // Enhanced variables for batch-wise validation
    let batchTotalPassGross = 0;
    let batchTotalSealGross = 0;
    let batchElectronicEntries = 0;
    let batchSealingEntries = 0;
    let validationInProgress = false;

    // Function to show validation alert
    function showValidationAlert(message, type = 'danger') {
        // Remove any existing alerts
        $('.validation-alert').remove();
        
        const alertHtml = `
            <div class="alert alert-${type} validation-alert alert-dismissible fade show" role="alert" style="cursor: pointer;">
                <div class="d-flex align-items-center">
                    <i class="fas ${type === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle'} me-2"></i>
                    <div>
                        <strong>${type === 'danger' ? 'Validation Error!' : 'Information'}</strong><br>
                        ${message}
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <small class="d-block mt-2 text-muted">
                    <i class="fas fa-hand-pointer me-1"></i>Click anywhere on this alert to dismiss
                </small>
            </div>
        `;
        
        $('body').append(alertHtml);
        
        // Add click to dismiss functionality
        $('.validation-alert').on('click', function() {
            $(this).addClass('fade-out');
            setTimeout(() => $(this).remove(), 500);
        });
        
        // Auto-remove after 8 seconds (increased time for better UX)
        setTimeout(() => {
            $('.validation-alert').addClass('fade-out');
            setTimeout(() => $('.validation-alert').remove(), 500);
        }, 8000);
    }

    // Enhanced function to calculate Seal Gross with batch-wise validation
    function calculateSealGross() {
        if (validationInProgress) return;
        
        var sealKg = parseFloat($('#seal_kg').val()) || 0;
        var avgWt = parseFloat($('#avg_wt').val()) || 0;
        var sealGross = 0;
        
        // Only proceed if both values are actually entered and greater than 0
        if (avgWt > 0 && sealKg > 0) {
            sealGross = (sealKg * 1000) / avgWt / 144;
            
            // Only validate if we have valid batch data
            if (batchTotalPassGross > 0) {
                var totalAfterEntry = batchTotalSealGross + sealGross;
                
                if (totalAfterEntry > batchTotalPassGross) {
                    validationInProgress = true;
                    
                    var exceeded = totalAfterEntry - batchTotalPassGross;
                    showValidationAlert(`
                        <strong>Seal Gross Exceeds Batch Pass Gross!</strong><br>
                        <small>
                            • Calculated Seal Gross: <strong>${sealGross.toFixed(2)}</strong><br>
                            • Batch Total Pass Gross: <strong>${batchTotalPassGross.toFixed(2)}</strong><br>
                            • Already Used Seal Gross: <strong>${batchTotalSealGross.toFixed(2)}</strong><br>
                            • Would exceed by: <strong>${exceeded.toFixed(2)}</strong><br>
                            • Please reduce Seal KG or adjust Avg. WT.
                        </small>
                    `, 'danger');
                    
                    // Still calculate and show the seal gross but mark it as invalid
                    $('#seal_gross').val(sealGross.toFixed(2)).css('border-color', '#dc3545');
                    
                    // Update the batch summary
                    updateBatchSummary();
                    
                    // Reset validation flag after a delay
                    setTimeout(() => {
                        validationInProgress = false;
                    }, 1000);
                    
                    return;
                } else {
                    // Valid calculation - remove any red border
                    $('#seal_gross').css('border-color', '');
                }
            }
        }
        
        $('#seal_gross').val(sealGross > 0 ? sealGross.toFixed(2) : '');
        updateBatchSummary();
    }

    // Function to calculate Reject Gross
    function calculateRejGross() {
        var productRej = parseFloat($('#product_rej').val()) || 0;
        var rejAvgWt = parseFloat($('#rej_avg_wt').val()) || 0;
        var rejGross = 0;
        if (rejAvgWt > 0) {
            rejGross = (productRej * 1000) / rejAvgWt / 144;
        }
        $('#rej_gross').val(rejGross > 0 ? rejGross.toFixed(2) : '');
    }

    // Function to update batch summary
    // Function to update lot/bin summary
    function updateLotBinSummary(batchNo, lotNo, binNo) {
        if (!batchNo || !lotNo || !binNo) {
            resetLotBinSummary();
            return;
        }

        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'fetch_lot_bin_summary',
                batch_no: batchNo,
                lot_no: lotNo,
                bin_no: binNo
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#currentLotBinEntries').text(response.entries);
                }
            }
        });
    }

    // Function to update batch summary table
    function updateBatchSummary() {
        const batchNo = $('#batch_no').val();
        
        console.log('updateBatchSummary called:', {
            batchNo: batchNo,
            batchTotalPassGross: batchTotalPassGross,
            batchTotalSealGross: batchTotalSealGross,
            batchElectronicEntries: batchElectronicEntries,
            batchSealingEntries: batchSealingEntries
        });
        
        if (!batchNo || batchTotalPassGross === 0) {
            $('.batch-summary-section').slideUp();
            console.log('Hiding batch summary - no batch or no pass gross data');
            return;
        }
        
        // Get current seal gross from form
        const currentSealGross = parseFloat($('#seal_gross').val()) || 0;
        const totalAfterEntry = batchTotalSealGross + currentSealGross;
        const remaining = batchTotalPassGross - totalAfterEntry;
        
        // Update batch number display
        $('#batchNumberCell').text(batchNo);
        
        // Update batch total row
        $('#totalPassGrossCell').text(batchTotalPassGross.toFixed(2));
        $('#totalSealGrossCell').text(batchTotalSealGross.toFixed(2));
        $('#totalRemainingCell').text((batchTotalPassGross - batchTotalSealGross).toFixed(2));
        
        // Update total entries display (Electronic + Sealing)
        $('#totalEntriesCell').html(`
            <div class="entry-count-display">
                <span class="electronic-count">${batchElectronicEntries} ET</span>
                <span style="color: #6c757d;">+</span>
                <span class="sealing-count">${batchSealingEntries} Seal</span>
            </div>
        `);
        
        // Update batch status
        let batchStatus = 'Pending';
        let batchStatusColor = '#ffc107';
        if (batchTotalSealGross >= batchTotalPassGross) {
            batchStatus = 'Complete';
            batchStatusColor = '#28a745';
        } else if (batchTotalSealGross > (batchTotalPassGross * 0.8)) {
            batchStatus = 'Nearly Complete';
            batchStatusColor = '#17a2b8';
        }
        $('#batchStatusCell').text(batchStatus).css('color', batchStatusColor);
        
        // Update current entry row
        $('#currentSealGross').text(currentSealGross.toFixed(2));
        $('#currentRemaining').text(Math.max(0, remaining).toFixed(2));
        
        // Update current entry count display
        if (currentSealGross > 0) {
            $('#currentEntryCount').html('<span class="current-entry-indicator">+1 New</span>').show();
        } else {
            $('#currentEntryCount').html('<span style="color: #6c757d;">+0</span>').show();
        }
        
        let currentStatus = 'Pending';
        if (currentSealGross > 0) {
            if (remaining < 0) {
                currentStatus = 'Exceeds Limit';
                $('#currentStatus').css('color', '#dc3545');
            } else if (remaining === 0) {
                currentStatus = 'Exact Match';
                $('#currentStatus').css('color', '#28a745');
            } else {
                currentStatus = 'Valid';
                $('#currentStatus').css('color', '#17a2b8');
            }
        }
        $('#currentStatus').text(currentStatus);
        
        // Update final status row
        $('#finalPassGross').text(batchTotalPassGross.toFixed(2));
        $('#finalSealGross').text(totalAfterEntry.toFixed(2));
        $('#finalRemaining').text(remaining.toFixed(2));
        
        // Update final entry count (total after current entry)
        const finalTotalEntries = batchElectronicEntries + batchSealingEntries + (currentSealGross > 0 ? 1 : 0);
        const finalSealingEntries = batchSealingEntries + (currentSealGross > 0 ? 1 : 0);
        $('#finalEntryCount').html(`
            <div class="entry-count-display">
                <span class="electronic-count">${batchElectronicEntries} ET</span>
                <span style="color: #6c757d;">+</span>
                <span class="sealing-count">${finalSealingEntries} Seal</span>
            </div>
            <small style="color: #6c757d; display: block; margin-top: 3px;">
                = ${finalTotalEntries} Total
            </small>
        `);
        
        let finalStatus = 'Ready';
        if (remaining < 0) {
            finalStatus = 'Over Limit';
            $('#finalStatusText').css('color', '#dc3545');
        } else if (remaining === 0) {
            finalStatus = 'Complete';
            $('#finalStatusText').css('color', '#28a745');
        } else if (currentSealGross > 0) {
            finalStatus = 'In Progress';
            $('#finalStatusText').css('color', '#17a2b8');
        }
        $('#finalStatusText').text(finalStatus);
        
        // Update progress
        const progressPercent = batchTotalPassGross > 0 ? (totalAfterEntry / batchTotalPassGross * 100) : 0;
        $('#batchProgressPercentage').text(Math.min(progressPercent, 100).toFixed(1) + '%');
        $('#batchProgressBar').css('width', Math.min(progressPercent, 100) + '%');
        
        // Update progress bar color
        const $progressBar = $('#batchProgressBar');
        if (progressPercent >= 100) {
            $progressBar.removeClass('bg-warning bg-success').addClass('bg-success');
        } else if (progressPercent >= 80) {
            $progressBar.removeClass('bg-warning bg-danger').addClass('bg-warning');
        } else {
            $progressBar.removeClass('bg-success bg-warning').addClass('bg-primary');
        }
        
        // Show the summary table
        $('.batch-summary-section').slideDown();
        console.log('Showing batch summary section');
    }

    // Function to fetch batch-wise totals
    function fetchBatchTotals(batchNumber) {
        console.log('fetchBatchTotals called with:', batchNumber);
        
        if (!batchNumber) {
            batchTotalPassGross = 0;
            batchTotalSealGross = 0;
            batchElectronicEntries = 0;
            batchSealingEntries = 0;
            updateBatchSummary();
            return;
        }

        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'fetch_batch_wise_totals',
                batch_number: batchNumber
            },
            dataType: 'json',
            success: function(response) {
                console.log('fetchBatchTotals response:', response);
                if (response.success) {
                    batchTotalPassGross = parseFloat(response.total_pass_gross) || 0;
                    batchTotalSealGross = parseFloat(response.total_seal_gross) || 0;
                    batchElectronicEntries = parseInt(response.batch_entries) || 0;
                    batchSealingEntries = parseInt(response.seal_entries) || 0;
                    console.log('Batch totals updated:', {
                        passGross: batchTotalPassGross,
                        sealGross: batchTotalSealGross,
                        electronicEntries: batchElectronicEntries,
                        sealingEntries: batchSealingEntries
                    });
                    updateBatchSummary();
                } else {
                    console.log('fetchBatchTotals failed - no success');
                }
            },
            error: function(xhr, status, error) {
                console.error('fetchBatchTotals error:', error);
                batchTotalPassGross = 0;
                batchTotalSealGross = 0;
                batchElectronicEntries = 0;
                batchSealingEntries = 0;
                updateBatchSummary();
            }
        });
    }

    // Function to reset summary
    function resetSummary() {
        $('#totalBatchEntries').text('0');
        $('#totalSealGross').text('0.00');
        resetLotBinSummary();
    }

    // Function to reset lot/bin summary
    function resetLotBinSummary() {
        $('#currentLotBinEntries').text('0');
    }

    // Enhanced event handlers with validation
    $('#seal_kg, #avg_wt').on('input', function() {
        // Clear any existing alerts first
        $('.validation-alert').remove();
        
        // Only calculate if both fields have meaningful values
        var sealKg = parseFloat($('#seal_kg').val()) || 0;
        var avgWt = parseFloat($('#avg_wt').val()) || 0;
        
        if (sealKg > 0 && avgWt > 0) {
            calculateSealGross();
        } else {
            // Clear seal gross if inputs are not complete
            $('#seal_gross').val('');
            updateBatchSummary();
        }
    });
    
    // Add blur event for final validation only
    $('#seal_kg, #avg_wt').on('blur', function() {
        if (!validationInProgress) {
            var sealKg = parseFloat($('#seal_kg').val()) || 0;
            var avgWt = parseFloat($('#avg_wt').val()) || 0;
            
            if (sealKg > 0 && avgWt > 0) {
                calculateSealGross();
            }
        }
    });
    
    $('#product_rej, #rej_avg_wt').on('input', calculateRejGross);

    // Clear alerts when fields are cleared
    $('#seal_kg, #avg_wt').on('keyup', function() {
        var sealKg = parseFloat($('#seal_kg').val()) || 0;
        var avgWt = parseFloat($('#avg_wt').val()) || 0;
        
        // If both fields are empty, clear any validation alerts
        if (sealKg === 0 && avgWt === 0) {
            $('.validation-alert').removeClass('show').addClass('fade-out');
            setTimeout(() => $('.validation-alert').remove(), 500);
            validationInProgress = false;
        }
    });

    // Updated lot/bin change handlers for batch-wise tracking
    $('#lot_no').on('change', function() {
        var batchNo = $('#batch_no').val();
        var lotNo = $('#lot_no').val();
        
        // Clear bin selection when lot changes
        $('#bin_no').html('<option value="">Select Bin No.</option>').prop('disabled', true);
        
        if (batchNo && lotNo) {
            // Load bins for the selected lot
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'fetch_bins_for_lot',
                    batch_number: batchNo,
                    lot_no: lotNo
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.bins.length > 0) {
                        var binOptions = '<option value="">Select Bin No.</option>';
                        response.bins.forEach(function(bin) {
                            binOptions += '<option value="' + bin + '">' + bin + '</option>';
                        });
                        $('#bin_no').html(binOptions).prop('disabled', false);
                    } else {
                        $('#bin_no').html('<option value="">No bins available for this lot</option>').prop('disabled', true);
                    }
                },
                error: function() {
                    $('#bin_no').html('<option value="">Error loading bins</option>').prop('disabled', true);
                }
            });
        }
        
        // Clear current values and update summary
        updateLotBinSummary(batchNo, lotNo, '');
        $('#seal_gross').val('');
        validationInProgress = false;
        updateBatchSummary();
    });
    
    $('#bin_no').on('change', function() {
        var batchNo = $('#batch_no').val();
        var lotNo = $('#lot_no').val();
        var binNo = $('#bin_no').val();
        updateLotBinSummary(batchNo, lotNo, binNo);
        
        // Clear current seal gross calculation to prevent confusion
        $('#seal_gross').val('');
        validationInProgress = false;
        
        // Update batch summary when lot/bin changes
        updateBatchSummary();
    });

    // Form submission validation for batch-wise totals
    $('form').on('submit', function(e) {
        const currentSealGross = parseFloat($('#seal_gross').val()) || 0;
        const totalAfterEntry = batchTotalSealGross + currentSealGross;
        
        if (totalAfterEntry > batchTotalPassGross && batchTotalPassGross > 0) {
            e.preventDefault();
            showValidationAlert(`
                <strong>Cannot Submit - Batch Limit Exceeded!</strong><br>
                <small>
                    • Current entry Seal Gross: <strong>${currentSealGross.toFixed(2)}</strong><br>
                    • Total after this entry: <strong>${totalAfterEntry.toFixed(2)}</strong><br>
                    • Batch Pass Gross limit: <strong>${batchTotalPassGross.toFixed(2)}</strong><br>
                    • Please adjust your values before submitting.
                </small>
            `, 'danger');
            $('#seal_kg').focus();
            return false;
        }
        
        // Show success message if validation passes
        if (currentSealGross > 0 && totalAfterEntry <= batchTotalPassGross) {
            const remainingAfter = batchTotalPassGross - totalAfterEntry;
            showValidationAlert(`
                <strong>Form validation passed!</strong><br>
                <small>
                    • Entry Seal Gross: <strong>${currentSealGross.toFixed(2)}</strong><br>
                    • Remaining after entry: <strong>${remainingAfter.toFixed(2)}</strong><br>
                    • Ready to submit!
                </small>
            `, 'success');
        }
    });

    // Function to update old batch summary (for other data not related to batch-wise totals)
    function updateOldBatchSummary(batchNo) {
        if (!batchNo) {
            resetSummary();
            return;
        }

        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'fetch_batch_summary',
                batch_no: batchNo
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#totalBatchEntries').text(response.total_entries);
                    $('#totalSealGross').text(parseFloat(response.total_seal_gross).toFixed(2));
                }
            }
        });
    }

    // Helper function to provide user guidance
    function showUserGuidance() {
        const remaining = batchTotalPassGross - batchTotalSealGross;
        if (remaining > 0) {
            showValidationAlert(`
                <strong>Batch Guidance:</strong><br>
                <small>
                    • Batch Total Pass Gross: <strong>${batchTotalPassGross.toFixed(2)}</strong><br>
                    • Already Used Seal Gross: <strong>${batchTotalSealGross.toFixed(2)}</strong><br>
                    • Remaining Available: <strong>${remaining.toFixed(2)}</strong><br>
                    • Enter Seal KG and Avg. WT so that Seal Gross ≤ ${remaining.toFixed(2)}<br>
                    • Tip: Lower Avg. WT or Seal KG will reduce Seal Gross
                </small>
            `, 'info');
        }
    }

    // Form submission validation
    $('form').on('submit', function(e) {
        const currentSealGross = parseFloat($('#seal_gross').val()) || 0;
        const remaining = currentPassGross - currentUsedSealGross;
        
        if (currentSealGross > remaining && remaining > 0) {
            e.preventDefault();
            showValidationAlert(`
                Cannot submit: Seal Gross (${currentSealGross.toFixed(2)}) exceeds available Pass Gross (${remaining.toFixed(2)}).
                Please adjust your values before submitting.
            `, 'danger');
            $('#seal_kg').focus();
            return false;
        }
        
        // Show success message if validation passes
        if (currentSealGross > 0 && remaining >= currentSealGross) {
            showValidationAlert(`
                Form validation passed! Seal Gross: ${currentSealGross.toFixed(2)}, Remaining: ${(remaining - currentSealGross).toFixed(2)}
            `, 'success');
        }
    });

    // Add guidance button click handler (you can add a guidance button in the form if needed)
    $(document).on('click', '.guidance-btn', function() {
        showUserGuidance();
    });

    // Shift locking and reset functionality
    const shiftSelect = $('#shift');
    const supervisorSelect = $('#supervisor');
    const resetShiftBtn = $('#resetShiftBtn');
    const resetSupervisorBtn = $('#resetSupervisorBtn');

    // Function to initialize reset buttons for both fields
    function initializeResetButtons() {
        // Initialize shift
        const savedShift = localStorage.getItem('selectedShift');
        if (savedShift && !<?= !empty($editData) ? 'true' : 'false' ?>) {
            shiftSelect.val(savedShift);
            shiftSelect.addClass('shift-locked');
            shiftSelect.prop('disabled', true);
            resetShiftBtn.show();
        } else if (shiftSelect.val()) {
            resetShiftBtn.show();
        }

        // Initialize supervisor
        const savedSupervisor = localStorage.getItem('selectedSupervisor');
        if (savedSupervisor && !<?= !empty($editData) ? 'true' : 'false' ?>) {
            supervisorSelect.val(savedSupervisor);
            supervisorSelect.addClass('supervisor-locked');
            supervisorSelect.prop('disabled', true);
            supervisorSelect.next('.select2-container').addClass('supervisor-locked');
            resetSupervisorBtn.show();
        } else if (supervisorSelect.val()) {
            resetSupervisorBtn.show();
        }
    }

    // Call initialization
    initializeResetButtons();

    // Shift selection handler
    shiftSelect.on('change', function() {
        const selectedValue = $(this).val();
        
        if (selectedValue && !<?= !empty($editData) ? 'true' : 'false' ?>) {
            // Save to localStorage and lock the shift
            localStorage.setItem('selectedShift', selectedValue);
            $(this).addClass('shift-locked');
            $(this).prop('disabled', true);
            resetShiftBtn.show();
        } else if (selectedValue) {
            resetShiftBtn.show();
        } else {
            resetShiftBtn.hide();
        }
    });

    // Supervisor selection handler
    supervisorSelect.on('change', function() {
        const selectedValue = $(this).val();
        
        if (selectedValue && !<?= !empty($editData) ? 'true' : 'false' ?>) {
            // Save to localStorage and lock the supervisor
            localStorage.setItem('selectedSupervisor', selectedValue);
            $(this).addClass('supervisor-locked');
            $(this).prop('disabled', true);
            $(this).next('.select2-container').addClass('supervisor-locked');
            resetSupervisorBtn.show();
        } else if (selectedValue) {
            resetSupervisorBtn.show();
        } else {
            resetSupervisorBtn.hide();
        }
    });

    // Reset shift functionality
    resetShiftBtn.on('click', function() {
        if (confirm('Are you sure you want to reset the shift? This will allow you to select a different shift.')) {
            // Remove from localStorage
            localStorage.removeItem('selectedShift');
            
            // Unlock and reset the shift select
            shiftSelect.removeClass('shift-locked');
            shiftSelect.prop('disabled', false);
            shiftSelect.val('').trigger('change');
            
            // Hide reset button and focus on shift select
            $(this).hide();
            shiftSelect.focus();
        }
    });

    // Reset supervisor functionality
    resetSupervisorBtn.on('click', function() {
        if (confirm('Are you sure you want to reset the supervisor? This will allow you to select a different supervisor.')) {
            // Remove from localStorage
            localStorage.removeItem('selectedSupervisor');
            
            // Unlock and reset the supervisor select
            supervisorSelect.removeClass('supervisor-locked');
            supervisorSelect.prop('disabled', false);
            supervisorSelect.next('.select2-container').removeClass('supervisor-locked');
            supervisorSelect.val('').trigger('change');
            
            // Hide reset button and focus on supervisor select
            $(this).hide();
            supervisorSelect.select2('open'); // Open Select2 dropdown
        }
    });

    // Show/hide reset buttons based on selection
    shiftSelect.on('change', function() {
        if ($(this).val()) {
            resetShiftBtn.show();
        } else {
            resetShiftBtn.hide();
        }
    });

    supervisorSelect.on('change', function() {
        if ($(this).val()) {
            resetSupervisorBtn.show();
        } else {
            resetSupervisorBtn.hide();
        }
    });

    // Prevent form submission if locked fields are cleared manually
    $('form').on('submit', function(e) {
        // Existing validation code...
        
        // Check if shift is required and empty
        if (!shiftSelect.val()) {
            e.preventDefault();
            showValidationAlert('Please select a shift before submitting.', 'danger');
            shiftSelect.focus();
            return false;
        }
        
        // Check if supervisor is required and empty
        if (!supervisorSelect.val()) {
            e.preventDefault();
            showValidationAlert('Please select a supervisor before submitting.', 'danger');
            supervisorSelect.select2('open');
            return false;
        }
        
        // ... rest of existing validation code ...
    });

    // Handle page refresh/reload - restore locked state
    $(window).on('beforeunload', function() {
        // Values are already saved in localStorage by change handlers
    });

    // Enhanced visual feedback for locked fields
    function updateLockedFieldAppearance() {
        // Update shift appearance
        if (shiftSelect.hasClass('shift-locked')) {
            shiftSelect.attr('title', 'Shift is locked. Click reset button to change.');
        } else {
            shiftSelect.removeAttr('title');
        }
        
        // Update supervisor appearance
        if (supervisorSelect.hasClass('supervisor-locked')) {
            supervisorSelect.attr('title', 'Supervisor is locked. Click reset button to change.');
        } else {
            supervisorSelect.removeAttr('title');
        }
    }

    // Call on load and after changes
    updateLockedFieldAppearance();
    shiftSelect.on('change', updateLockedFieldAppearance);
    supervisorSelect.on('change', updateLockedFieldAppearance);

    // Handle view batch details button
    $('#viewBatchBtn').on('click', function() {
        console.log('View batch button clicked'); // Debug log
        
        var batchNumber = $('#batch_no').val();
        console.log('Selected batch number:', batchNumber); // Debug log
        
        if (!batchNumber) {
            alert('Please select a batch number first.');
            return;
        }

        $('#batchDetailsModal').modal('show');
        $('#batchDetailsContent').html(`
            <div class="text-center">
                <div class="loading-spinner"></div>
                <p class="mt-2">Loading batch details...</p>
            </div>
        `);

        $.ajax({
            url: '',
            method: 'POST',
            data: { 
                action: 'fetch_batch_details',
                batch_number: batchNumber 
            },
            dataType: 'json',
            success: function(response) {
                console.log('AJAX response:', response); // Debug log
                
                if (response.success && response.batch_details) {
                    const batch = response.batch_details;
                    const statusClasses = {
                        'Pending': 'bg-warning',
                        'In Progress': 'bg-info',
                        'Completed': 'bg-success',
                        'Cancelled': 'bg-danger'
                    };

                    // Better date formatting function
                    function formatDate(dateString) {
                        if (!dateString) return 'N/A';
                        try {
                            // Handle SQL Server date format
                            let date = new Date(dateString);
                            if (isNaN(date.getTime())) {
                                // Try alternative parsing for SQL Server format
                                date = new Date(dateString.replace(/(\d{4}-\d{2}-\d{2}).*/, '$1'));
                            }
                            if (isNaN(date.getTime())) {
                                return 'Invalid Date';
                            }
                            return date.toLocaleDateString('en-GB', {
                                day: '2-digit',
                                month: '2-digit', 
                                year: 'numeric'
                            });
                        } catch (e) {
                            console.error('Date formatting error:', e, 'for date:', dateString);
                            return 'Invalid Date';
                        }
                    }

                    // Format dates
                    const mfgDate = formatDate(batch.mfg_date);
                    const expDate = formatDate(batch.exp_date);
                    const createdAt = formatDate(batch.created_at);

                    const detailsHtml = `
                        <div class="batch-details-container">
                            <div class="detail-section">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Batch Number</div>
                                        <div class="detail-value fw-bold">${batch.batch_number || 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Brand Name</div>
                                        <div class="detail-value">${batch.brand_name || 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Product Type</div>
                                        <div class="detail-value">${batch.product_type || 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Status</div>
                                        <div class="detail-value">
                                            <span class="badge ${statusClasses[batch.status] || 'bg-secondary'}">${batch.status || 'N/A'}</span>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Manufacturing Date</div>
                                        <div class="detail-value">${mfgDate}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Expiry Date</div>
                                        <div class="detail-value">${expDate}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Strip Cutting</div>
                                        <div class="detail-value">${batch.strip_cutting || 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Silicone Oil Qty</div>
                                        <div class="detail-value">${batch.silicone_oil_qty ? batch.silicone_oil_qty + ' kg' : 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Benzocaine Details</div>
                                        <div class="detail-value">
                                            ${batch.benzocaine_used === 'Yes' 
                                                ? `<span class="text-success">Used (${batch.benzocaine_qty || 'N/A'} kg)</span>` 
                                                : '<span class="text-danger">Not Used</span>'}
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Order Quantity</div>
                                        <div class="detail-value">${batch.order_qty || 'N/A'}</div>
                                    </div>
                                    ${batch.special_requirement ? `
                                        <div class="detail-item special-req">
                                            <div class="detail-label">Special Requirements</div>
                                            <div class="detail-value alert alert-info mb-0 py-1">
                                                ${batch.special_requirement}
                                            </div>
                                        </div>
                                    ` : ''}
                                    <div class="detail-item">
                                        <div class="detail-label">Created By</div>
                                        <div class="detail-value">${batch.created_by || 'System'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Created At</div>
                                        <div class="detail-value">${createdAt}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    $('#batchDetailsContent').html(detailsHtml);
                } else {
                    $('#batchDetailsContent').html(`
                        <div class="text-center text-muted p-4">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <h5>No Details Found</h5>
                            <p>No information available for batch number: ${batchNumber}</p>
                            <small class="text-danger">Please check if the batch number exists in the database.</small>
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                $('#batchDetailsContent').html(`
                    <div class="text-center text-danger p-4">
                        <i class="fas fa-times-circle fa-3x mb-3"></i>
                        <h5>Error Loading Details</h5>
                        <p>Failed to load batch details. Please try again.</p>
                        <small>Error: ${error}</small>
                    </div>
                `);
            }
        });
    });

    <?php if (!empty($editData) && !empty($editData['batch_no'])): ?>
        // Set batch_no and trigger change to load lot/bin options
        $('#batch_no').val(<?= json_encode($editData['batch_no']) ?>).trigger('change');

        // Wait for AJAX to load options, then select the correct ones
        function setLotBinIfReady() {
            var lotValue = <?= json_encode($editData['lot_no'] ?? '') ?>;
            var binValue = <?= json_encode($editData['bin_no'] ?? '') ?>;
            
            if (lotValue) {
                $('#lot_no').val(lotValue).trigger('change');
            }
            if (binValue) {
                $('#bin_no').val(binValue).trigger('change');
            }
        }
        
        // Use MutationObserver to detect when lot_no options are loaded
        const lotNoSelect = document.getElementById('lot_no');
        if (lotNoSelect) {
            const observer = new MutationObserver(function() {
                var lotValue = <?= json_encode($editData['lot_no'] ?? '') ?>;
                if (lotValue && $('#lot_no option[value="' + lotValue + '"]').length > 0) {
                    setLotBinIfReady();
                    observer.disconnect();
                }
            });
            observer.observe(lotNoSelect, { childList: true });
            
            // Fallback: also try after a short delay
            setTimeout(setLotBinIfReady, 800);
        }
    <?php endif; ?>
});
</script>

</body>
</html>