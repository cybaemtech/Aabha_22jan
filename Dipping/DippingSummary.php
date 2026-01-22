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
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../Includes/db_connect.php';

// Dropdown options
$reportTypes = [
    'shift' => 'Shift Report',
    'lot' => 'Lot Summary',
    'binwise' => 'Bin Wise Lot Summary',
    'product_type' => 'Product Type Report', // Added new report type
    'transfer' => 'Material Transfer Report',
    'issue' => 'Material Issue Summary',
    'forward' => 'Forward Entry Report', // Added forward entry report
    'monthly' => 'Month Wise Report' // Added month wise report
];
$selectedReport = $_GET['report_type'] ?? 'shift';

// Get Dipping department's dept_id for supervisor filtering
$dippingDeptId = null;
$deptSql = "SELECT dept_id FROM departments WHERE department_name = 'Dipping'";
$deptStmt = sqlsrv_query($conn, $deptSql);
if ($deptStmt && ($deptRow = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC))) {
    $dippingDeptId = $deptRow['dept_id'];
}
if ($deptStmt) sqlsrv_free_stmt($deptStmt);

// Fetch supervisor list for filter dropdown (used in some reports) - Dipping department only
$supervisorList = [];
if ($dippingDeptId !== null) {
    // First try to get supervisors for Dipping department
    $sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND department_id = ? AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
    $stmt = sqlsrv_query($conn, $sql, [$dippingDeptId]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $supervisorList[] = $row['grn_checked_by'];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // If no supervisors found for Dipping department, get all supervisors as fallback
    if (empty($supervisorList)) {
        $sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $supervisorList[] = $row['grn_checked_by'];
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Common filters
$filterDate = $_GET['entryDate'] ?? '';
$filterShift = $_GET['shift'] ?? '';
$filterSupervisor = $_GET['supervisor'] ?? '';
$filterLot = $_GET['lot_no'] ?? '';
$filterProductType = $_GET['product_type_filter'] ?? ''; // Added product type filter
$filterYear = $_GET['year'] ?? date('Y'); // Added year filter for monthly report
$filterMonth = $_GET['month'] ?? ''; // Added month filter for monthly report

// Data queries for each report type
$summaryRows = [];
if ($selectedReport == 'shift') {
    // Shift Report Summary
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT shift, lot_no, product_type, wt_kg, avg_wt, gross
            FROM dipping_binwise_entry $whereSql ORDER BY shift, lot_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        $currentShift = '';
        $shiftData = [];
        $shiftTotals = [];
        $uniqueLotsPerShift = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $shift = $row['shift'];
            $lotNo = $row['lot_no'];
            if (!isset($shiftData[$shift])) {
                $shiftData[$shift] = [];
                $shiftTotals[$shift] = ['wt_kg' => 0, 'gross' => 0, 'avg_wt_sum' => 0, 'count' => 0];
                $uniqueLotsPerShift[$shift] = array();
            }
            // Only add unique lot_no rows for display
            if (!in_array($lotNo, $uniqueLotsPerShift[$shift], true)) {
                $shiftData[$shift][] = $row;
                $uniqueLotsPerShift[$shift][] = $lotNo;
            }
            $shiftTotals[$shift]['wt_kg'] += floatval($row['wt_kg']);
            $shiftTotals[$shift]['gross'] += floatval($row['gross']);
            $shiftTotals[$shift]['avg_wt_sum'] += floatval($row['avg_wt']);
            $shiftTotals[$shift]['count']++;
        }
        // Flatten the data for display with subtotals
        foreach ($shiftData as $shift => $rows) {
            foreach ($rows as $row) {
                $summaryRows[] = $row;
            }
            // Add subtotal row
            $avgWt = $shiftTotals[$shift]['count'] > 0 ? round($shiftTotals[$shift]['avg_wt_sum'] / $shiftTotals[$shift]['count'], 2) : 0;
            $summaryRows[] = [
                'shift' => $shift . ' Total',
                'lot_no' => 'Unique Lots: ' . count($uniqueLotsPerShift[$shift]),
                'product_type' => '',
                'wt_kg' => $shiftTotals[$shift]['wt_kg'],
                'avg_wt' => $avgWt,
                'gross' => $shiftTotals[$shift]['gross'],
                'is_subtotal' => true
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'lot') {
    // Lot Summary (filter by month/year instead of date)
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterMonth) {
        $where[] = "MONTH(entry_date) = ?";
        $params[] = $filterMonth;
    }
    if ($filterYear) {
        $where[] = "YEAR(entry_date) = ?";
        $params[] = $filterYear;
    }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT 
                lot_no, 
                product, 
                ROUND(AVG(avg_wt), 2) as avg_wt, 
                SUM(wt_kg) as production, 
                SUM(wt_kg) as issued, 
                SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as dipping
            FROM dipping_binwise_entry $whereSql 
            GROUP BY lot_no, product";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'binwise') {
    // Bin Wise Lot Summary
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT bin_no, wt_kg, avg_wt, gross
            FROM dipping_binwise_entry $whereSql ORDER BY bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'transfer') {
    // Material Transfer Report
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT 
                bin_no,
                lot_no, 
                product_type, 
                SUM(wt_kg) as production, 
                SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as issued
            FROM dipping_binwise_entry $whereSql 
            GROUP BY bin_no, lot_no, product_type
            ORDER BY bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'issue') {
    // Material Issue Summary - Show all data
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    // Production = SUM(wt_kg), Issued = SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END), Balance = Production - Issued
    $sql = "SELECT 
                lot_no, 
                product, 
                ROUND(AVG(avg_wt), 2) as avg_wt, 
                SUM(wt_kg) as production, 
                SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as issued
            FROM dipping_binwise_entry $whereSql GROUP BY lot_no, product";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['balance'] = floatval($row['production']) - floatval($row['issued']);
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'forward') {
    // Forward Entry Report: Show all entries where forward_request = 1
    $params = [];
    $where = ["forward_request = 1"];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT id, bin_no, entry_date, shift, lot_no, bin_start_time, bin_finish_time, 
             wt_kg, avg_wt, gross, supervisor, product_type, machine_no, product, 
             created_at, forward_request
         FROM dipping_binwise_entry 
         $whereSql
         ORDER BY CAST(bin_no AS INT) ASC, entry_date DESC";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format dates properly
            if ($row['entry_date'] instanceof DateTime) {
                $row['entry_date'] = $row['entry_date']->format('d-m-Y');
            } else {
                $row['entry_date'] = date('d-m-Y', strtotime($row['entry_date']));
            }
            // Format times
            if ($row['bin_start_time'] instanceof DateTime) {
                $row['bin_start_time'] = $row['bin_start_time']->format('H:i');
            }
            if ($row['bin_finish_time'] instanceof DateTime) {
                $row['bin_finish_time'] = $row['bin_finish_time']->format('H:i');
            }
            if ($row['created_at'] instanceof DateTime) {
                $row['created_at'] = $row['created_at']->format('d-m-Y H:i:s');
            } else {
                $row['created_at'] = date('d-m-Y H:i:s', strtotime($row['created_at']));
            }
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'product_type') {
    // Product Type Report - Show individual entries with Product Type and Total
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    if ($filterProductType) { $where[] = "product_type = ?"; $params[] = $filterProductType; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT 
                entry_date,
                product_type,
                shift,
                lot_no,
                bin_no,
                wt_kg,
                avg_wt,
                gross,
                supervisor
            FROM dipping_binwise_entry 
            $whereSql 
            ORDER BY entry_date DESC, product_type, shift";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'monthly') {
    // Monthly Report - Flexible: shift-wise, machine-wise, or product type-wise
    $monthlyReportType = $_GET['monthly_report_type'] ?? 'shift';
    $params = [];
    $where = [];
    if ($filterDate) {
        $where[] = "entry_date = ?";
        $params[] = $filterDate;
    }
    if ($filterYear) {
        $where[] = "YEAR(entry_date) = ?";
        $params[] = $filterYear;
    }
    if ($filterMonth) {
        $where[] = "MONTH(entry_date) = ?";
        $params[] = $filterMonth;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    // Get all dates in the month
    $sql = "SELECT DISTINCT entry_date FROM dipping_binwise_entry $whereSql ORDER BY entry_date";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $dates = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $dates[] = $row['entry_date'];
        }
        sqlsrv_free_stmt($stmt);
    }
    $summaryRows = [];
    if ($monthlyReportType == 'shift') {
        $shifts = ['I', 'II', 'III'];
        foreach ($dates as $date) {
            $row = [];
            $row['date'] = ($date instanceof DateTime) ? $date->format('Y-m-d') : date('Y-m-d', strtotime($date));
            $shiftSum = 0;
            foreach ($shifts as $shift) {
                $sql = "SELECT SUM(wt_kg) as total FROM dipping_binwise_entry WHERE entry_date = ? AND shift = ?";
                $stmt = sqlsrv_query($conn, $sql, [$row['date'], $shift]);
                $val = 0;
                if ($stmt && ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                    $val = floatval($r['total']);
                }
                $row[$shift] = $val;
                $shiftSum += $val;
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            $row['total'] = $shiftSum;
            $summaryRows[] = $row;
        }
    } elseif ($monthlyReportType == 'machine') {
        // Get all unique machine numbers for the month
        $machineSql = "SELECT DISTINCT machine_no FROM dipping_binwise_entry $whereSql ORDER BY machine_no";
        $machineStmt = sqlsrv_query($conn, $machineSql, $params);
        $machines = [];
        if ($machineStmt) {
            while ($mrow = sqlsrv_fetch_array($machineStmt, SQLSRV_FETCH_ASSOC)) {
                $machines[] = $mrow['machine_no'];
            }
            sqlsrv_free_stmt($machineStmt);
        }
        foreach ($dates as $date) {
            $row = [];
            $row['date'] = ($date instanceof DateTime) ? $date->format('Y-m-d') : date('Y-m-d', strtotime($date));
            $machineSum = 0;
            foreach ($machines as $machine) {
                $sql = "SELECT SUM(wt_kg) as total FROM dipping_binwise_entry WHERE entry_date = ? AND machine_no = ?";
                $stmt = sqlsrv_query($conn, $sql, [$row['date'], $machine]);
                $val = 0;
                if ($stmt && ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                    $val = floatval($r['total']);
                }
                $row[$machine] = $val;
                $machineSum += $val;
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            $row['total'] = $machineSum;
            $summaryRows[] = $row;
        }
        $monthlyMachines = $machines;
    } elseif ($monthlyReportType == 'product_type') {
        // Get all unique product types for the month
        $ptypeSql = "SELECT DISTINCT product_type FROM dipping_binwise_entry $whereSql ORDER BY product_type";
        $ptypeStmt = sqlsrv_query($conn, $ptypeSql, $params);
        $ptypes = [];
        if ($ptypeStmt) {
            while ($prow = sqlsrv_fetch_array($ptypeStmt, SQLSRV_FETCH_ASSOC)) {
                $ptypes[] = $prow['product_type'];
            }
            sqlsrv_free_stmt($ptypeStmt);
        }
        foreach ($dates as $date) {
            $row = [];
            $row['date'] = ($date instanceof DateTime) ? $date->format('Y-m-d') : date('Y-m-d', strtotime($date));
            $ptypeSum = 0;
            foreach ($ptypes as $ptype) {
                $sql = "SELECT SUM(wt_kg) as total FROM dipping_binwise_entry WHERE entry_date = ? AND product_type = ?";
                $stmt = sqlsrv_query($conn, $sql, [$row['date'], $ptype]);
                $val = 0;
                if ($stmt && ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                    $val = floatval($r['total']);
                }
                $row[$ptype] = $val;
                $ptypeSum += $val;
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            $row['total'] = $ptypeSum;
            $summaryRows[] = $row;
        }
        $monthlyProductTypes = $ptypes;
    }
}

// Calculate totals for each report type (except forward report)
$totals = [
    'wt_kg' => 0,
    'avg_wt' => 0,
    'gross' => 0,
    'production' => 0,
    'issued' => 0,
    'dipping' => 0,
    'balance' => 0, // Added for Material Issue Summary
    'avg_wt_count' => 0, // for average calculation
    'total_entries' => 0, // for product type report
    'unique_lots' => 0, // for product type report
    'total_weight' => 0, // for product type report
    'total_gross' => 0, // for product type report
    // Monthly report totals
    'monthly_total_entries' => 0,
    'monthly_unique_lots' => 0,
    'monthly_unique_product_types' => 0,
    'monthly_total_weight' => 0,
    'monthly_average_weight' => 0,
    'monthly_total_gross' => 0,
    'monthly_dipping' => 0,
    'monthly_issued' => 0,
    'monthly_forwarded_weight' => 0,
    'monthly_forwarded_entries' => 0
];

if ($selectedReport != 'forward') {
    foreach ($summaryRows as $row) {
        if ($selectedReport == 'shift' || $selectedReport == 'lot' || $selectedReport == 'binwise' || $selectedReport == 'product_type') {
            $totals['wt_kg'] += floatval($row['wt_kg'] ?? $row['production'] ?? 0);
            $totals['gross'] += floatval($row['gross'] ?? 0);
            if (isset($row['avg_wt']) && $row['avg_wt'] !== '' && is_numeric($row['avg_wt'])) {
                $totals['avg_wt'] += floatval($row['avg_wt']);
                $totals['avg_wt_count']++;
            }
        }
        if ($selectedReport == 'lot' || $selectedReport == 'issue') {
            $production = floatval($row['production'] ?? 0);
            $issued = floatval($row['issued'] ?? 0);
            $balance = $production - $issued;
            $totals['production'] += $production;
            $totals['issued'] += $issued;
            $totals['dipping'] += floatval($row['dipping'] ?? 0);
            $totals['balance'] += $balance;
            if (isset($row['avg_wt']) && $row['avg_wt'] !== '' && is_numeric($row['avg_wt'])) {
                $totals['avg_wt'] += floatval($row['avg_wt']);
                $totals['avg_wt_count']++;
            }
        }
        if ($selectedReport == 'transfer') {
            $totals['production'] += floatval($row['production'] ?? 0);
            $totals['issued'] += floatval($row['issued'] ?? 0);
            // No dipping calculation for transfer report
        }
        if ($selectedReport == 'product_type') {
            $totals['total_entries']++; // Count each row as an entry
        }
        if ($selectedReport == 'monthly') {
            $totals['monthly_total_entries'] += intval($row['total_entries'] ?? 0);
            $totals['monthly_unique_lots'] += intval($row['unique_lots'] ?? 0);
            $totals['monthly_unique_product_types'] += intval($row['unique_product_types'] ?? 0);
            $totals['monthly_total_weight'] += floatval($row['total_weight'] ?? 0);
            $totals['monthly_total_gross'] += floatval($row['total_gross'] ?? 0);
            $totals['monthly_dipping'] += floatval($row['dipping'] ?? 0);
            $totals['monthly_issued'] += floatval($row['issued'] ?? 0);
            $totals['monthly_forwarded_weight'] += floatval($row['forwarded_weight'] ?? 0);
            $totals['monthly_forwarded_entries'] += intval($row['forwarded_entries'] ?? 0);
            if (isset($row['average_weight']) && $row['average_weight'] !== '' && is_numeric($row['average_weight'])) {
                $totals['avg_wt'] += floatval($row['average_weight']);
                $totals['avg_wt_count']++;
            }
        }
    }
}

// Calculate average
$grandAvgWt = $totals['avg_wt_count'] ? round($totals['avg_wt'] / $totals['avg_wt_count'], 2) : 0;

// Create monthlyTotals array for display in the grand total row
$monthlyTotals = [
    'total_entries' => $totals['monthly_total_entries'] ?? 0,
    'unique_lots' => $totals['monthly_unique_lots'] ?? 0,
    'unique_product_types' => $totals['monthly_unique_product_types'] ?? 0,
    'total_weight' => $totals['monthly_total_weight'] ?? 0,
    'avg_weight' => (isset($totals['avg_wt_count']) && $totals['avg_wt_count']) ? round($totals['avg_wt'] / $totals['avg_wt_count'], 2) : 0,
    'total_gross' => $totals['monthly_total_gross'] ?? 0,
    'dipping' => $totals['monthly_dipping'] ?? 0,
    'issued' => $totals['monthly_issued'] ?? 0,
    'forwarded_weight' => $totals['monthly_forwarded_weight'] ?? 0,
    'forwarded_entries' => $totals['monthly_forwarded_entries'] ?? 0
];

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="dipping_summary.xls"');
    echo "<table border='1'>";
    // Table headers
    if ($selectedReport == 'shift') {
        echo "<tr><th>Shift</th><th>Lot No</th><th>Product Type</th><th>WT IN KG</th><th>AVG WT</th><th>GROSS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['shift']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['product_type']}</td>
                <td>{$row['wt_kg']}</td>
                <td>".number_format($row['avg_wt'], 2)."</td>
                <td>{$row['gross']}</td>
            </tr>";
        }
        // Grand Total row
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='3'>Grand Total</td>
            <td>".round($totals['wt_kg'],2)."</td>
            <td>".$grandAvgWt."</td>
            <td>".round($totals['gross'],2)."</td>
        </tr>";
    }
    // Product Type Report Export
    elseif ($selectedReport == 'product_type') {
        echo "<tr><th>Product Type</th><th>Shift</th><th>Lot No</th><th>Bin No</th><th>WT KG</th><th>Avg WT</th><th>Gross</th><th>Supervisor</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['product_type']}</td>
                <td>{$row['shift']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['bin_no']}</td>
                <td>".number_format($row['wt_kg'], 2)."</td>
                <td>".number_format($row['avg_wt'], 2)."</td>
                <td>".number_format($row['gross'], 2)."</td>
                <td>{$row['supervisor']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='3'>Grand Total</td>
            <td>".number_format($totals['total_entries'])." Entries</td>
            <td>".number_format($totals['wt_kg'], 2)."</td>
            <td>".number_format($grandAvgWt, 2)."</td>
            <td>".number_format($totals['gross'], 2)."</td>
            <td>-</td>
        </tr>";
    }
    // Lot Summary
    elseif ($selectedReport == 'lot') {
        echo "<tr><th>Lot No</th><th>Product</th><th>Avg Wt</th><th>Production</th><th>Dipping</th><th>Issued</th><th>Balance</th></tr>";
        foreach ($summaryRows as $row) {
            $balance = floatval($row['dipping']) - floatval($row['issued']);
            echo "<tr>
                <td>{$row['lot_no']}</td>
                <td>{$row['product']}</td>
                <td>".number_format(floatval($row['avg_wt']), 2)."</td>
                <td>".number_format(floatval($row['production']), 2)."</td>
                <td>".number_format(floatval($row['dipping']), 2)."</td>
                <td>".number_format(floatval($row['issued']), 2).   "</td>
                <td>".number_format($balance, 2)."</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='2'>Grand Total</td>
            <td>{$grandAvgWt}</td>
            <td>".round($totals['production'],2)."</td> 
            <td>".round($totals['dipping'],2)."</td>
            <td>".round($totals['issued'],2)."</td>
            <td>".round($totals['dipping'] - $totals['issued'],2)."</td>
        </tr>";
    }
    // Bin Wise Lot Summary
    elseif ($selectedReport == 'binwise') {
        echo "<tr><th>Bin No</th><th>WT IN KG</th><th>AVG WT</th><th>GROSS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['bin_no']}</td>
                <td>{$row['wt_kg']}</td>
                <td>".number_format($row['avg_wt'], 2)."</td>
                <td>{$row['gross']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td>Grand Total</td>
            <td>".round($totals['wt_kg'],2)."</td>
            <td>".number_format($grandAvgWt, 2)."</td>
            <td>".round($totals['gross'],2)."</td>
        </tr>";
    }
    // Material Issue Summary
    elseif ($selectedReport == 'issue') {
    echo "<tr><th colspan='2'></th><th>Avg Wt</th><th>Production</th><th>Issued</th><th>Balance</th></tr>";
        $totalProduction = 0;
        $totalIssued = 0;
        $totalBalance = 0;
        $totalAvgWt = 0;
        $totalAvgWtCount = 0;
        foreach ($summaryRows as $row) {
            $production = floatval($row['production']);
            $issued = floatval($row['issued']);
            $balance = $production - $issued;
            $totalProduction += $production;
            $totalIssued += $issued;
            $totalBalance += $balance;
            if (isset($row['avg_wt']) && $row['avg_wt'] !== '' && is_numeric($row['avg_wt'])) {
                $totalAvgWt += floatval($row['avg_wt']);
                $totalAvgWtCount++;
            }
            echo "<tr>";
            echo "<td>{$row['lot_no']}</td>";
            echo "<td>{$row['product']}</td>";
            echo "<td>".number_format(floatval($row['avg_wt']), 2)."</td>";
            echo "<td>".number_format($production, 2)."</td>";
            echo "<td>".number_format($issued, 2)."</td>";
            echo "<td>".number_format($balance, 2)."</td>";
            echo "</tr>";
        }
        // Grand Total row (only one, after all data rows)
        // Grand Total row removed as per user request
    }
    // Material Transfer Report
    elseif ($selectedReport == 'transfer') {
        echo "<tr><th>Bin No</th><th>Lot No</th><th>Product Type</th><th>Production</th><th>Issued</th><th>Balance</th></tr>";
        foreach ($summaryRows as $row) {
            $balance = floatval($row['production']) - floatval($row['issued']);
            echo "<tr>
                <td>{$row['bin_no']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['product_type']}</td>
                <td>".number_format(floatval($row['production']), 2)."</td>
                <td>".number_format(floatval($row['issued']), 2)."</td>
                <td>".number_format($balance, 2)."</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='3'>Grand Total</td>
            <td>".round($totals['production'],2)."</td>
            <td>".round($totals['issued'],2)."</td>
            <td>".round($totals['production'] - $totals['issued'],2)."</td>
        </tr>";
    }
    // Forward Entry Report
    elseif ($selectedReport == 'forward') {
        echo "<tr><th>ID</th><th>Bin No</th><th>Entry Date</th><th>Shift</th><th>Lot No</th><th>Bin Start Time</th><th>Bin Finish Time</th><th>WT KG</th><th>Avg WT</th><th>Gross</th><th>Supervisor</th><th>Product Type</th><th>Machine No</th><th>Product</th><th>Created At</th><th>Forward Request</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['id']}</td>
                <td>{$row['bin_no']}</td>
                <td>{$row['entry_date']}</td>
                <td>{$row['shift']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['bin_start_time']}</td>
                <td>{$row['bin_finish_time']}</td>
                <td>{$row['wt_kg']}</td>
                <td>{$row['avg_wt']}</td>
                <td>{$row['gross']}</td>
                <td>{$row['supervisor']}</td>
                <td>{$row['product_type']}</td>
                <td>{$row['machine_no']}</td>
                <td>{$row['product']}</td>
                <td>{$row['created_at']}</td>
                <td>" . ($row['forward_request'] ? 'Yes' : 'No') . "</td>
            </tr>";
        }
    }
    // Monthly Report Export
    elseif ($selectedReport == 'monthly') {
        echo "<tr><th>Year</th><th>Month</th><th>Total Entries</th><th>Unique Lots</th><th>Unique Product Types</th><th>Total Weight (KG)</th><th>Average Weight</th><th>Total Gross</th><th>Dipping (KG)</th><th>Issued (KG)</th><th>Balance (KG)</th><th>Forwarded Weight (KG)</th><th>Forwarded Entries</th></tr>";
        foreach ($summaryRows as $row) {
            $balance = floatval($row['dipping']) - floatval($row['issued']);
            echo "<tr>
                <td>{$row['year']}</td>
                <td>{$row['month_name']}</td>
                <td>".number_format($row['total_entries'])."</td>
                <td>".number_format($row['unique_lots'])."</td>
                <td>".number_format($row['unique_product_types'])."</td>
                <td>".number_format($row['total_weight'], 2)."</td>
                <td>".number_format($row['average_weight'], 2)."</td>
                <td>".number_format($row['total_gross'], 2)."</td>
                <td>".number_format($row['dipping'], 2)."</td>
                <td>".number_format($row['issued'], 2)."</td>
                <td>".number_format($balance, 2)."</td>
                <td>".number_format($row['forwarded_weight'], 2)."</td>
                <td>".number_format($row['forwarded_entries'])."</td>
            </tr>";
        }
        // Grand Total row for monthly report
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='2'>Grand Total</td>
            <td>".number_format($totals['monthly_total_entries'])."</td>
            <td>".number_format($totals['monthly_unique_lots'])."</td>
            <td>".number_format($totals['monthly_unique_product_types'])."</td>
            <td>".number_format($totals['monthly_total_weight'], 2)."</td>
            <td>".number_format($grandAvgWt, 2)."</td>
            <td>".number_format($totals['monthly_total_gross'], 2)."</td>
            <td>".number_format($totals['monthly_dipping'], 2)."</td>
            <td>".number_format($totals['monthly_issued'], 2)."</td>
            <td>".number_format($totals['monthly_dipping'] - $totals['monthly_issued'], 2)."</td>
            <td>".number_format($totals['monthly_forwarded_weight'], 2)."</td>
            <td>".number_format($totals['monthly_forwarded_entries'])."</td>
        </tr>";
    }
    echo "</table>";
    exit;
}

// Now include sidebar and continue with normal page rendering
include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dipping Summary</title>
    <link rel="stylesheet" href="../asset/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
   <style>
    .main-content { margin-left: 240px; padding: 32px 24px; }
    .summary-filter-form { display: flex; gap: 16px; margin-bottom: 24px; align-items: flex-end; flex-wrap: wrap; }
    .summary-filter-form label { font-weight: 500; margin-bottom: 4px; }
    .summary-table { 
        width: 100%; 
        border-collapse: collapse; 
        background: #fff; 
        table-layout: auto; /* Ensures columns fit content */
    }
    .summary-table th, .summary-table td { 
        padding: 8px 12px; /* Reduced from excessive padding to reasonable size */
        border: 1px solid #e5e7eb; 
        white-space: nowrap; /* Prevents text wrapping */
        text-align: left !important; /* Force left alignment for all data */
    }
    .summary-table th { 
        background: #f1f3f9; 
        color: #333; 
        font-weight: 600; 
    }
    .summary-table tr:hover { background: #e8f0fe; }
    .summary-title { font-size: 1.4rem; font-weight: bold; margin-bottom: 18px; color: #4f42c1; }
    .filter-btn { background: #4f42c1; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; }
    .filter-btn:hover { background: #3b32a8; }
    .form-control { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
    .report-header { font-weight: bold; font-size: 1.1rem; margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-radius: 4px; }
    
    /* Scrollable table wrapper for ALL reports */
    .table-wrapper {
        max-height: 600px; /* Increased height for better viewing */
        overflow-y: auto;
        overflow-x: auto; /* Allow horizontal scroll for wide tables */
        margin-bottom: 24px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    /* Make header sticky for better UX */
    .table-wrapper .summary-table th {
        position: sticky;
        top: 0;
        background: #f1f3f9;
        z-index: 2;
        box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
    }

    /* Ensure table takes full width inside wrapper */
    .table-wrapper .summary-table {
        margin-bottom: 0;
    }

    /* Custom scrollbar styling */
    .table-wrapper::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    .table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb:hover {
        background: #a1a1a1;
    }

    /* Responsive column sizing */
    .summary-table th:nth-child(1), 
    .summary-table td:nth-child(1) { min-width: 80px; } /* ID/Shift */
    
    .summary-table th:nth-child(2), 
    .summary-table td:nth-child(2) { min-width: 100px; } /* Lot/Bin No */
    
    .summary-table th:nth-child(3), 
    .summary-table td:nth-child(3) { min-width: 120px; } /* Product Type/Date */
    
    .summary-table th:nth-child(4), 
    .summary-table td:nth-child(4) { min-width: 90px; } /* Weight/Shift */
    
    .summary-table th:nth-child(5), 
    .summary-table td:nth-child(5) { min-width: 90px; } /* Avg WT */

    /* Ensure all data is left-aligned for better readability */
    .summary-table td,
    .summary-table th {
        text-align: left !important;
    }

    /* Special styling for total rows */
    .summary-table tr[style*="background:#f9f9c5"] {
        font-weight: bold;
        background: #f9f9c5 !important;
    }
</style>
</head>
<body>
<div class="main-content">
    <div class="summary-title"><i class="fa fa-chart-bar"></i> Dipping Summary</div>
    <form class="summary-filter-form" method="get">
        <div>
            <label for="report_type">Report Type</label>
            <select name="report_type" id="report_type" class="form-control" onchange="this.form.submit()">
                <?php foreach($reportTypes as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php if($selectedReport==$key) echo 'selected'; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Date Filter - Available for all report types -->
        <div>
            <label for="entryDate">Entry Date</label>
            <input type="date" name="entryDate" id="entryDate" value="<?php echo htmlspecialchars($filterDate); ?>" class="form-control">
        </div>
        
        <?php if ($selectedReport == 'shift' || $selectedReport == 'lot' || $selectedReport == 'product_type'): ?>
            <?php if ($selectedReport == 'lot'): ?>
            <div>
                <label for="year">Year</label>
                <select name="year" id="year" class="form-control">
                    <?php
                    $currentYear = date('Y');
                    for ($y = $currentYear; $y >= ($currentYear - 5); $y--) {
                        $selected = ($filterYear == $y) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="month">Month</label>
                <select name="month" id="month" class="form-control">
                    <option value="">All Months</option>
                    <?php
                    $months = [
                        '1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April',
                        '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August',
                        '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                    ];
                    foreach ($months as $num => $name) {
                        $selected = ($filterMonth == $num) ? 'selected' : '';
                        echo "<option value='$num' $selected>$name</option>";
                    }
                    ?>
                </select>
            </div>
            <?php else: ?>
            <!-- Date filter removed for Product Type Report as requested -->
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($selectedReport == 'shift' || $selectedReport == 'product_type'): ?>
            <div>
                <label for="shift">Shift</label>
                <select name="shift" id="shift" class="form-control">
                    <option value="">All</option>
                    <option value="I" <?php if($filterShift=='I') echo 'selected'; ?>>I</option>
                    <option value="II" <?php if($filterShift=='II') echo 'selected'; ?>>II</option>
                    <option value="III" <?php if($filterShift=='III') echo 'selected'; ?>>III</option>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'shift'): ?>
            <div>
                <label for="supervisor">Supervisor</label>
                <select name="supervisor" id="supervisor" class="form-control">
                    <option value="">All</option>
                    <?php foreach($supervisorList as $sup): ?>
                        <option value="<?php echo htmlspecialchars($sup); ?>" <?php if($filterSupervisor==$sup) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sup); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'product_type'): ?>
            <div>
                <label for="product_type_filter">Product Type</label>
                <select name="product_type_filter" id="product_type_filter" class="form-control">
                    <option value="">All Product Types</option>
                    <?php
                    // Get distinct product types for filter
                    $productTypeSql = "SELECT DISTINCT product_type FROM dipping_binwise_entry WHERE product_type IS NOT NULL ORDER BY product_type";
                    $productTypeStmt = sqlsrv_query($conn, $productTypeSql);
                    if ($productTypeStmt) {
                        while ($ptRow = sqlsrv_fetch_array($productTypeStmt, SQLSRV_FETCH_ASSOC)) {
                            $selected = ($filterProductType == $ptRow['product_type']) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($ptRow['product_type']) . "' $selected>" . htmlspecialchars($ptRow['product_type']) . "</option>";
                        }
                        sqlsrv_free_stmt($productTypeStmt);
                    }
                    ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'binwise' || $selectedReport == 'transfer' || $selectedReport == 'forward' || $selectedReport == 'lot'): ?>
            <div>
                <label for="lot_no">Lot No</label>
                <input type="text" name="lot_no" id="lot_no" value="<?php echo htmlspecialchars($filterLot); ?>" class="form-control">
            </div>
        <?php endif; ?>
        
        <?php if ($selectedReport == 'monthly'): ?>
            <div>
                <label for="monthly_report_type">Monthly Report Type</label>
                <select name="monthly_report_type" id="monthly_report_type" class="form-control" onchange="this.form.submit()">
                    <option value="shift" <?php if(isset($monthlyReportType) && $monthlyReportType=='shift') echo 'selected'; ?>>Shift Wise</option>
                    <option value="machine" <?php if(isset($monthlyReportType) && $monthlyReportType=='machine') echo 'selected'; ?>>Machine Wise</option>
                    <option value="product_type" <?php if(isset($monthlyReportType) && $monthlyReportType=='product_type') echo 'selected'; ?>>Product Type Wise</option>
                </select>
            </div>
            <div>
                <label for="year">Year</label>
                <select name="year" id="year" class="form-control">
                    <?php
                    $currentYear = date('Y');
                    for ($y = $currentYear; $y >= ($currentYear - 5); $y--) {
                        $selected = ($filterYear == $y) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="month">Month (Optional)</label>
                <select name="month" id="month" class="form-control">
                    <option value="">All Months</option>
                    <?php
                    $months = [
                        '1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April',
                        '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August',
                        '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                    ];
                    foreach ($months as $num => $name) {
                        $selected = ($filterMonth == $num) ? 'selected' : '';
                        echo "<option value='$num' $selected>$name</option>";
                    }
                    ?>
                </select>
            </div>
        <?php endif; ?>
        
        <button type="submit" class="filter-btn"><i class="fa fa-filter"></i> Filter</button>
        <button type="submit" name="export" value="excel" class="filter-btn" style="background:#2e7d32;margin-left:8px;">
            <i class="fa fa-file-excel"></i> Export to Excel
        </button>
        <button type="button" class="filter-btn" style="background:#b71c1c;margin-left:8px;" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?report_type=<?php echo $selectedReport; ?>'">
            <i class="fa fa-refresh"></i> Reset
        </button>
    </form>

    <!-- Report Header for Product Type -->
    <?php if ($selectedReport == 'product_type'): ?>
        <div class="report-header">
            PRODUCT TYPE REPORT - Summary by Product Types
        </div>
    <?php elseif ($selectedReport == 'forward'): ?>
        <div class="report-header">
            FORWARDED ENTRIES REPORT - All Forwarded Dipping Binwise Entries
        </div>
    <?php elseif ($selectedReport == 'issue'): ?>
        <div class="report-header">
            MATERIAL ISSUE SUMMARY - Dipping and Balance Calculations
        </div>
    <?php elseif ($selectedReport == 'monthly'): ?>
        <div class="report-header">
            MONTH WISE REPORT - Summary by Month and Year
        </div>
    <?php endif; ?>

    <!-- Regular reports table -->
    <?php if ($selectedReport != 'forward'): ?>
        <div class="table-wrapper">
            <table class="summary-table">
            <thead>
                <?php if ($selectedReport == 'shift'): ?>
                    <tr>
                        <th>Shift</th>
                        <th>Lot No</th>
                        <th>Product Type</th>
                        <th>WT IN KG</th>
                        <th>AVG WT</th>
                        <th>GROSS</th>
                    </tr>
                <?php elseif ($selectedReport == 'product_type'): ?>
                    <tr>
                        <th>Product Type</th>
                        <th>Shift</th>
                        <th>Lot No</th>
                        <th>Bin No</th>
                        <th>WT KG</th>
                        <th>Avg WT</th>
                        <th>Gross</th>
                        <th>Supervisor</th>
                    </tr>
                <?php elseif ($selectedReport == 'lot'): ?>
                    <tr>
                        <th>Lot No</th>
                        <th>Product</th>
                        <th>Avg Wt</th>
                        <th>Production</th>
                        <th>Dipping</th>
                        <th>Issued</th>
                        <th>Balance</th>
                    </tr>
                <?php elseif ($selectedReport == 'issue'): ?>
                    <tr>
                        <th>Lot No</th>
                        <th>Product</th>
                        <th>Avg Wt</th>
                        <th>Production</th>
                        <th>Issued</th>
                        <th>Balance</th>
                    </tr>
                <?php elseif ($selectedReport == 'binwise'): ?>
                    <tr>
                        <th>Bin No</th>
                        <th>WT IN KG</th>
                        <th>AVG WT</th>
                        <th>GROSS</th>
                    </tr>
                <?php elseif ($selectedReport == 'transfer'): ?>
                    <tr>
                        <th>Bin No</th>
                        <th>Lot No</th>
                        <th>Product Type</th>
                        <th>Production</th>
                        <th>Issued</th>
                        <th>Balance</th>
                    </tr>
                <?php elseif ($selectedReport == 'monthly'): ?>
                    <tr>
                        <th>Date</th>
                        <?php if ($monthlyReportType == 'shift'): ?>
                            <th>I</th><th>II</th><th>III</th><th>Total</th>
                        <?php elseif ($monthlyReportType == 'machine' && isset($monthlyMachines)): ?>
                            <?php
                            // Fetch machine names for the machine numbers
                            $machineNames = [];
                            if (!empty($monthlyMachines)) {
                                $machineNosStr = implode(",", array_map('intval', $monthlyMachines));
                                $machineNameSql = "SELECT machine_no, machine_name FROM machines WHERE machine_no IN ($machineNosStr)";
                                $machineNameStmt = sqlsrv_query($conn, $machineNameSql);
                                if ($machineNameStmt) {
                                    while ($mrow = sqlsrv_fetch_array($machineNameStmt, SQLSRV_FETCH_ASSOC)) {
                                        $machineNames[$mrow['machine_no']] = $mrow['machine_name'];
                                    }
                                    sqlsrv_free_stmt($machineNameStmt);
                                }
                            }
                            ?>
                            <?php foreach ($monthlyMachines as $machine): ?>
                                <th><?= htmlspecialchars(isset($machineNames[$machine]) ? $machineNames[$machine] : $machine) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        <?php elseif ($monthlyReportType == 'product_type' && isset($monthlyProductTypes)): ?>
                            <?php foreach ($monthlyProductTypes as $ptype): ?>
                                <th><?= htmlspecialchars($ptype) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        <?php endif; ?>
                    </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if (count($summaryRows)): ?>
                    <?php 
                    // Only calculate and render monthly report totals if in monthly report
                    if ($selectedReport == 'monthly') {
                        $grandTotals = [];
                        $monthlyReportType = $monthlyReportType ?? 'shift';
                        if ($monthlyReportType == 'shift') {
                            $grandTotals['I'] = 0; $grandTotals['II'] = 0; $grandTotals['III'] = 0; $grandTotals['total'] = 0;
                            foreach ($summaryRows as $row) {
                                $grandTotals['I'] += $row['I'];
                                $grandTotals['II'] += $row['II'];
                                $grandTotals['III'] += $row['III'];
                                $grandTotals['total'] += $row['total'];
                            }
                        } elseif ($monthlyReportType == 'machine' && isset($monthlyMachines)) {
                            foreach ($monthlyMachines as $machine) {
                                $grandTotals[$machine] = 0;
                            }
                            $grandTotals['total'] = 0;
                            foreach ($summaryRows as $row) {
                                foreach ($monthlyMachines as $machine) {
                                    $grandTotals[$machine] += $row[$machine];
                                }
                                $grandTotals['total'] += $row['total'];
                            }
                        } elseif ($monthlyReportType == 'product_type' && isset($monthlyProductTypes)) {
                            foreach ($monthlyProductTypes as $ptype) {
                                $grandTotals[$ptype] = 0;
                            }
                            $grandTotals['total'] = 0;
                            foreach ($summaryRows as $row) {
                                foreach ($monthlyProductTypes as $ptype) {
                                    $grandTotals[$ptype] += $row[$ptype];
                                }
                                $grandTotals['total'] += $row['total'];
                            }
                        }
                        foreach ($summaryRows as $row): ?>
                            <tr>
                                <td><?= isset($row['date']) && $row['date'] !== null ? htmlspecialchars((string)$row['date']) : '' ?></td>
                                <?php if ($monthlyReportType == 'shift'): ?>
                                    <td><?= number_format($row['I'], 2) ?></td>
                                    <td><?= number_format($row['II'], 2) ?></td>
                                    <td><?= number_format($row['III'], 2) ?></td>
                                    <td style="background:yellow;font-weight:bold;"><?= number_format($row['total'], 2) ?></td>
                                <?php elseif ($monthlyReportType == 'machine' && isset($monthlyMachines)): ?>
                                    <?php foreach ($monthlyMachines as $machine): ?>
                                        <td><?= number_format($row[$machine], 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="background:yellow;font-weight:bold;"><?= number_format($row['total'], 2) ?></td>
                                <?php elseif ($monthlyReportType == 'product_type' && isset($monthlyProductTypes)): ?>
                                    <?php foreach ($monthlyProductTypes as $ptype): ?>
                                        <td><?= number_format($row[$ptype], 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="background:yellow;font-weight:bold;"><?= number_format($row['total'], 2) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <!-- GRAND TOTAL ROW (only one, not duplicated) -->
                        <tr style="font-weight:bold;background:#f9f9c5;">
                            <td>GRAND TOTAL</td>
                            <?php if ($monthlyReportType == 'shift'): ?>
                                <td><?= number_format($grandTotals['I'], 2) ?></td>
                                <td><?= number_format($grandTotals['II'], 2) ?></td>
                                <td><?= number_format($grandTotals['III'], 2) ?></td>
                                <td style="background:yellow;font-weight:bold;"><?= number_format($grandTotals['total'], 2) ?></td>
                            <?php elseif ($monthlyReportType == 'machine' && isset($monthlyMachines)): ?>
                                <?php foreach ($monthlyMachines as $machine): ?>
                                    <td><?= number_format($grandTotals[$machine], 2) ?></td>
                                <?php endforeach; ?>
                                <td style="background:yellow;font-weight:bold;"><?= number_format($grandTotals['total'], 2) ?></td>
                            <?php elseif ($monthlyReportType == 'product_type' && isset($monthlyProductTypes)): ?>
                                <?php foreach ($monthlyProductTypes as $ptype): ?>
                                    <td><?= number_format($grandTotals[$ptype], 2) ?></td>
                                <?php endforeach; ?>
                                <td style="background:yellow;font-weight:bold;"><?= number_format($grandTotals['total'], 2) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php } else { // Not monthly report, render other reports as before ?>
                        <?php foreach ($summaryRows as $row): ?>
                            <tr>
                                <?php if ($selectedReport == 'shift'): ?>
                                    <td><?php echo htmlspecialchars($row['shift']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                                    <td><?php echo number_format($row['wt_kg'], 2); ?></td>
                                    <td><?php echo number_format($row['avg_wt'], 2); ?></td>
                                    <td><?php echo number_format($row['gross'], 2); ?></td>
                                <?php elseif ($selectedReport == 'product_type'): ?>
                                    <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['shift']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                                    <td><?php echo number_format($row['wt_kg'], 2); ?></td>
                                    <td><?php echo number_format($row['avg_wt'], 2); ?></td>
                                    <td><?php echo number_format($row['gross'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($row['supervisor']); ?></td>
                                <?php elseif ($selectedReport == 'lot'): ?>
                                    <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['product']); ?></td>
                                    <td><?php echo number_format(floatval($row['avg_wt']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['production']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['dipping']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['issued']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['dipping']) - floatval($row['issued']), 2); ?></td>
                                <?php elseif ($selectedReport == 'issue'): ?>
                                    <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['product']); ?></td>
                                    <td><?php echo number_format(floatval($row['avg_wt']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['production']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['issued']), 2); ?></td>
                                    <td><?php echo number_format(floatval($row['production']) - floatval($row['issued']), 2); ?></td>
                                <?php elseif ($selectedReport == 'binwise'): ?>
                                    <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['wt_kg']); ?></td>
                                    <td><?php echo htmlspecialchars($row['avg_wt']); ?></td>
                                    <td><?php echo htmlspecialchars($row['gross']); ?></td>
                                <?php elseif ($selectedReport == 'transfer'): ?>
                                    <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                    <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                                    <td><?php echo number_format($row['production'], 2); ?></td>
                                    <td><?php echo number_format($row['issued'], 2); ?></td>
                                    <td><?php echo number_format(floatval($row['production']) - floatval($row['issued']), 2); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php } ?>
                    <!-- Grand Total Row -->
                    <?php if ($selectedReport == 'product_type'): ?>
                        <tr style="font-weight:bold;background:#f9f9c5;">
                            <td colspan="3">Grand Total</td>
                            <td><?php echo number_format($totals['total_entries']); ?> Entries</td>
                            <td><?php echo number_format($totals['wt_kg'], 2); ?></td>
                            <td><?php echo number_format($grandAvgWt, 2); ?></td>
                            <td><?php echo number_format($totals['gross'], 2); ?></td>
                            <td>-</td>
                        </tr>
                    <?php elseif ($selectedReport == 'shift' || $selectedReport == 'lot' || $selectedReport == 'binwise' || $selectedReport == 'transfer'): ?>
                        <tr style="font-weight:bold;background:#f9f9c5;">
                            <?php if ($selectedReport == 'shift'): ?>
                                <td colspan="3">Grand Total</td>
                                <td><?php echo round($totals['wt_kg'], 2); ?></td>
                                <td><?php echo $grandAvgWt; ?></td>
                                <td><?php echo round($totals['gross'], 2); ?></td>
                            <?php elseif ($selectedReport == 'lot'): ?>
                                <td colspan="2">Grand Total</td>
                                <td><?php echo $grandAvgWt; ?></td>
                                <td><?php echo round($totals['production'], 2); ?></td>
                                <td><?php echo round($totals['dipping'], 2); ?></td>
                                <td><?php echo round($totals['issued'], 2); ?></td>
                                <td><?php echo round($totals['dipping'] - $totals['issued'], 2); ?></td>
                            <?php elseif ($selectedReport == 'binwise'): ?>
                                <td colspan="1">Grand Total</td>
                                <td><?php echo round($totals['wt_kg'], 2); ?></td>
                                <td><?php echo $grandAvgWt; ?></td>
                                <td><?php echo round($totals['gross'], 2); ?></td>
                            <?php elseif ($selectedReport == 'transfer'): ?>
                                <td colspan="3">Grand Total</td>
                                <td><?php echo round($totals['production'], 2); ?></td>
                                <td><?php echo round($totals['issued'], 2); ?></td>
                                <td><?php echo round($totals['production'] - $totals['issued'], 2); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php elseif ($selectedReport == 'issue'): ?>
                        <tr style="font-weight:bold;background:#f9f9c5;">
                            <td colspan="2">Grand Total</td>
                            <td><?php echo $grandAvgWt; ?></td>
                            <td><?php echo round($totals['production'], 2); ?></td>
                            <td><?php echo round($totals['issued'], 2); ?></td>
                            <td><?php echo round($totals['production'] - $totals['issued'], 2); ?></td>
                        </tr>
                    <?php elseif ($selectedReport == 'monthly'): ?>
                        <!-- Removed duplicate GRAND TOTAL row for monthly report -->
                    <?php endif; ?>
                <?php else: ?>
                    <tr><td colspan="20" style="text-align:left;">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <!-- Forward Entry Report with scrollable wrapper -->
    <?php if ($selectedReport == 'forward'): ?>
        <div class="table-wrapper">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Bin No</th>
                        <th>Entry Date</th>
                        <th>Shift</th>
                        <th>Lot No</th>
                        <th>Bin Start Time</th>
                        <th>Bin Finish Time</th>
                        <th>WT KG</th>
                        <th>Avg WT</th>
                        <th>Gross</th>
                        <th>Supervisor</th>
                        <th>Product Type</th>
                        <th>Machine No</th>
                        <th>Product</th>
                        <th>Created At</th>
                        <th>Forward Request</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($summaryRows)): ?>
                        <?php foreach ($summaryRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['id']); ?></td>
                                <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['entry_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['shift']); ?></td>
                                <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['bin_start_time']); ?></td>
                                <td><?php echo htmlspecialchars($row['bin_finish_time']); ?></td>
                                <td><?php echo htmlspecialchars($row['wt_kg']); ?></td>
                                <td><?php echo htmlspecialchars($row['avg_wt']); ?></td>
                                <td><?php echo htmlspecialchars($row['gross']); ?></td>
                                <td><?php echo htmlspecialchars($row['supervisor']); ?></td>
                                <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                                <td><?php echo htmlspecialchars($row['machine_no']); ?></td>
                                <td><?php echo htmlspecialchars($row['product']); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td><?php echo $row['forward_request'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="16" style="text-align:center;">No forwarded entries found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>