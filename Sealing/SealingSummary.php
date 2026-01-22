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
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

$reportTypes = [
    'shift' => 'Shift Report',
    'batch' => 'Batch Summary',
    'monthly' => 'Monthly Report',
    'machine' => 'Machine Wise Report',
    'shift_machine' => 'Shift Wise Machine report',
    'datewise' => 'Date Wise Sealing Report'
];
$selectedReport = $_GET['report_type'] ?? 'shift';

$filterDate = $_GET['entryDate'] ?? '';
$filterBatch = $_GET['batch_no'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterFlavour = $_GET['flavour'] ?? '';
$filterShift = $_GET['shift'] ?? '';  // Add shift filter

$summaryRows = [];
$grandTotal = 0;

if ($selectedReport == 'shift') {
    // Shift Report: Join with batch_creation to get brand_name
    $summaryRows = [];
    $totals = ['total'=>0];
    $params = [];
    $where = [];
    if (!empty($filterDate)) {
        $where[] = "se.[date] = ?";
        $params[] = $filterDate;
    }
    if (!empty($filterShift)) {
        $where[] = "se.shift = ?";
        $params[] = $filterShift;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                se.shift,
                ISNULL(bc.brand_name, 'Unknown') AS brand,
                se.batch_no,
                se.flavour,
                SUM(CAST(ISNULL(se.seal_gross, 0) AS DECIMAL(18,2))) AS total
            FROM sealing_entry se
            LEFT JOIN batch_creation bc ON se.batch_no = bc.batch_number
            $whereSql
            GROUP BY se.shift, bc.brand_name, se.batch_no, se.flavour
            ORDER BY se.shift, bc.brand_name, se.batch_no, se.flavour";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $totals['total'] += (float)$row['total'];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'batch') {
    // Batch Summary: Show all data, or filter by batch_no if provided
    $params = [];
    $where = [];
    if ($filterBatch) {
        $where[] = "se.batch_no = ?";
        $params[] = $filterBatch;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT se.[date], se.shift, se.machine_no, se.bag_no, se.batch_no, se.lot_no, se.bin_no, se.flavour,
                   ISNULL(bc.brand_name, 'Unknown') AS brand_name,
                   ISNULL(se.seal_kg,0) AS seal_kg, 
                   ISNULL(se.avg_wt,0) AS avg_wt, 
                   ISNULL(se.seal_gross,0) AS seal_gross
            FROM sealing_entry se
            LEFT JOIN batch_creation bc ON se.batch_no = bc.batch_number
            $whereSql
            ORDER BY se.[date], se.shift, se.machine_no, se.bag_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $summaryRows = [];
    $totals = ['seal_kg'=>0, 'avg_wt'=>0, 'seal_gross'=>0];
    $rowCount = 0;
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $totals['seal_kg'] += $row['seal_kg'];
            $totals['avg_wt'] += $row['avg_wt'];
            $totals['seal_gross'] += $row['seal_gross'];
            $rowCount++;
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $totals['avg_wt'] = $rowCount ? round($totals['avg_wt'] / $rowCount, 2) : 0;
}
elseif ($selectedReport == 'monthly') {
    // Monthly Report: Show shift-wise totals grouped by date, filtered by selected month
    $params = [];
    $where = [];
    if (!empty($filterMonth)) {
        // Convert filterMonth (YYYY-MM) to SQL LIKE for month matching
        $monthYear = date('M-y', strtotime($filterMonth . '-01')); // e.g. Aug-25
        $where[] = "[month] = ?";
        $params[] = $monthYear;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    // Simplified approach: Get data directly grouped by date and shift
    $sql = "SELECT 
                [date],
                shift,
                SUM(CAST(ISNULL(seal_gross, 0) AS DECIMAL(18,2))) AS shift_total
            FROM sealing_entry
            $whereSql
            GROUP BY [date], shift
            ORDER BY [date], shift";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    $rawData = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime object to string key
            if ($row['date'] instanceof DateTime) {
                $dateKey = $row['date']->format('Y-m-d');
            } else {
                $dateKey = (string)$row['date'];
            }
            $rawData[$dateKey][$row['shift']] = (float)$row['shift_total'];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Process raw data into summary rows
    $summaryRows = [];
    $grandTotal = ['I'=>0, 'II'=>0, 'III'=>0, 'total'=>0];
    
    foreach ($rawData as $dateKey => $shiftData) {
        $rowData = ['I'=>0, 'II'=>0, 'III'=>0, 'total'=>0];
        
        // Set shift values
        foreach (['I', 'II', 'III'] as $shift) {
            if (isset($shiftData[$shift])) {
                $rowData[$shift] = $shiftData[$shift];
                $rowData['total'] += $shiftData[$shift];
            }
        }
        
        // Format date
        $rowData['date'] = date('d-m-Y', strtotime($dateKey));
        
        // Add to grand total
        foreach(['I','II','III','total'] as $k) {
            $grandTotal[$k] += $rowData[$k];
        }
        
        $summaryRows[] = $rowData;
    }
    
    // Sort by date
    usort($summaryRows, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}
elseif ($selectedReport == 'machine') {
    // Machine Wise Report: Show machine-wise totals for each day
    $params = [];
    $where = [];
    if (!empty($filterMonth)) {
        // Use YEAR and MONTH functions to filter by actual date instead of stored month column
        $year = date('Y', strtotime($filterMonth . '-01'));
        $month = date('n', strtotime($filterMonth . '-01')); // n = 1-12 without leading zeros
        $where[] = "YEAR(se.[date]) = ? AND MONTH(se.[date]) = ?";
        $params[] = $year;
        $params[] = $month;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT se.[date], se.machine_no, SUM(CAST(ISNULL(se.seal_gross, 0) AS DECIMAL(18,2))) AS total
            FROM sealing_entry se
            $whereSql
            GROUP BY se.[date], se.machine_no
            ORDER BY se.[date], se.machine_no";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    $machineDays = [];
    $machines = [];
    $grandTotal = 0;
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime object to proper format
            if ($row['date'] instanceof DateTime) {
                $dateKey = $row['date']->format('Y-m-d');
            } else {
                $dateKey = (string)$row['date'];
            }
            $machineDays[$dateKey][$row['machine_no']] = (float)$row['total'];
            $machines[$row['machine_no']] = true;
            $grandTotal += (float)$row['total'];
        }
        sqlsrv_free_stmt($stmt);
    }
    $machines = array_keys($machines);
    sort($machines);
}
elseif ($selectedReport == 'shift_machine') {
    // Shift Wise Machine report: Show shift-wise machine totals for each day
    $params = [];
    $where = [];
    if (!empty($filterMonth)) {
        // Use YEAR and MONTH functions to filter by actual date instead of stored month column
        $year = date('Y', strtotime($filterMonth . '-01'));
        $month = date('n', strtotime($filterMonth . '-01')); // n = 1-12 without leading zeros
        $where[] = "YEAR(se.[date]) = ? AND MONTH(se.[date]) = ?";
        $params[] = $year;
        $params[] = $month;
    }
    if (!empty($filterShift)) {
        $where[] = "se.shift = ?";
        $params[] = $filterShift;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT se.[date], se.machine_no, se.shift, SUM(CAST(ISNULL(se.seal_gross, 0) AS DECIMAL(18,2))) AS total
            FROM sealing_entry se
            $whereSql
            GROUP BY se.[date], se.machine_no, se.shift
            ORDER BY se.[date], se.machine_no, se.shift";

    $stmt = sqlsrv_query($conn, $sql, $params);
    $shiftMachines = [];
    $machines = [];
    $machineNames = [];
    $shifts = ['I','II','III'];
    $grandTotal = 0;
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime object to proper format
            if ($row['date'] instanceof DateTime) {
                $dateKey = $row['date']->format('Y-m-d');
            } else {
                $dateKey = (string)$row['date'];
            }
            $shiftMachines[$dateKey][$row['machine_no']][$row['shift']] = (float)$row['total'];
            $machines[$row['machine_no']] = true;
            $machineNames[$row['machine_no']] = $row['machine_no'];
            $grandTotal += (float)$row['total'];
        }
        sqlsrv_free_stmt($stmt);
    }
    $machines = array_keys($machines);
    sort($machines);
}
elseif ($selectedReport == 'datewise') {
    // Date Wise Sealing Report: Show batch/flavour summary for a date
    $params = [];
    $where = [];
    if (!empty($filterDate)) {
        $where[] = "[date] = ?";
        $params[] = $filterDate;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT batch_no, flavour, SUM(CAST(ISNULL(seal_gross, 0) AS DECIMAL(18,2))) AS total
            FROM sealing_entry
            $whereSql
            GROUP BY batch_no, flavour
            ORDER BY batch_no, flavour";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    $grandTotal = 0;
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $grandTotal += (float)$row['total'];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Add this before the HTML output, after the report logic

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sealing_summary.xls"');
    echo "<table border='1'>";
    
    if ($selectedReport == 'shift') {
        echo "<tr><th>SHIFT</th><th>BRAND</th><th>BATCH NO</th><th>FLAVOUR</th><th>SEAL GROSS TOTAL</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['shift']}</td>
                <td>{$row['brand']}</td>
                <td>{$row['batch_no']}</td>
                <td>{$row['flavour']}</td>
                <td>".number_format($row['total'], 2)."</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;'><td colspan='4'>GRAND TOTAL</td><td>".number_format($totals['total'], 2)."</td></tr>";
    }
    elseif ($selectedReport == 'batch') {
        echo "<tr><th>DATE</th><th>SHIFT</th><th>BRAND NAME</th><th>BATCH NO</th><th>BIN NO</th><th>MACHINE NO</th><th>BAG NO</th><th>LOT NO</th><th>FLAVOUR</th><th>SEAL KG</th><th>AVG. WT.</th><th>SEAL GROSS</th></tr>";
        foreach ($summaryRows as $row) {
            $dateFormatted = ($row['date'] instanceof DateTime) ? $row['date']->format('d-m-Y') : date('d-m-Y', strtotime((string)$row['date']));
            echo "<tr>
                <td>{$dateFormatted}</td>
                <td>{$row['shift']}</td>
                <td>{$row['brand_name']}</td>
                <td>{$row['batch_no']}</td>
                <td>{$row['bin_no']}</td>
                <td>{$row['machine_no']}</td>
                <td>{$row['bag_no']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['flavour']}</td>
                <td>".number_format($row['seal_kg'], 2)."</td>
                <td>".number_format($row['avg_wt'], 2)."</td>
                <td>".number_format($row['seal_gross'], 2)."</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;'><td colspan='9'>GRAND TOTAL</td><td>".number_format($totals['seal_kg'], 2)."</td><td>".number_format($totals['avg_wt'], 2)."</td><td>".number_format($totals['seal_gross'], 2)."</td></tr>";
    }
    // ... add other report types as needed
    
    echo "</table>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sealing Summary</title>
    <link rel="stylesheet" href="../asset/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        .main-content { margin-left: 240px; padding: 32px 24px; }
        .summary-filter-form { display: flex; gap: 16px; margin-bottom: 24px; align-items: flex-end; flex-wrap: wrap; }
        .summary-table { width: 100%; border-collapse: collapse; background: #fff; }
        .summary-table th, .summary-table td { padding: 10px 12px; border: 1px solid #e5e7eb; text-align: center; }
        .summary-table th { background: #f1f3f9; color: #333; font-weight: 600; }
        .summary-table tr:hover { background: #c4d3ecff; }
        .summary-title { font-size: 1.4rem; font-weight: bold; margin-bottom: 18px; color: #4f42c1; }
        .filter-btn { background: #4f42c1; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; }
        .filter-btn:hover { background: #3b32a8; }
        .form-control { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .report-header { font-weight: bold; font-size: 1.1rem; margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-radius: 4px; }
        .total-row { font-weight: bold; background: #e0ffe0; }
        .grand-total-row { font-weight: bold; background: #c8e6c9; font-size: 1.1em; }
    </style>
</head>
<body>
<div class="main-content">
    <div class="summary-title"><i class="fa fa-chart-bar"></i> Sealing Summary</div>
    <form class="summary-filter-form" method="get">
        <div>
            <label for="report_type">Report Type</label>
            <select name="report_type" id="report_type" class="form-control" onchange="this.form.submit()">
                <?php foreach($reportTypes as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php if($selectedReport==$key) echo 'selected'; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($selectedReport == 'shift' || $selectedReport == 'datewise'): ?>
            <div>
                <label for="entryDate">Date</label>
                <input type="date" name="entryDate" id="entryDate" value="<?php echo htmlspecialchars($filterDate); ?>" class="form-control">
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'shift' || $selectedReport == 'shift_machine'): ?>
            <div>
                <label for="shift">Shift</label>
                <select name="shift" id="shift" class="form-control">
                    <option value="">All Shifts</option>
                    <option value="I" <?php if($filterShift=='I') echo 'selected'; ?>>Shift I</option>
                    <option value="II" <?php if($filterShift=='II') echo 'selected'; ?>>Shift II</option>
                    <option value="III" <?php if($filterShift=='III') echo 'selected'; ?>>Shift III</option>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'batch'): ?>
            <div>
                <label for="batch_no">Batch No</label>
                <input type="text" name="batch_no" id="batch_no" value="<?php echo htmlspecialchars($filterBatch); ?>" class="form-control">
            </div>
        <?php endif; ?>
        <?php if ($selectedReport == 'monthly' || $selectedReport == 'machine' || $selectedReport == 'shift_machine'): ?>
            <div>
                <label for="month">Month</label>
                <input type="month" name="month" id="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="form-control">
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

    <!-- Report Headers -->
    <?php if ($selectedReport == 'shift'): ?>
        <div class="report-header">
            <?php if (!empty($filterDate) || !empty($filterShift)): ?>
                <?php if (!empty($filterDate)): ?>
                    DATE <?php echo htmlspecialchars(date('n/j/Y', strtotime($filterDate))); ?>
                <?php endif; ?>
                <?php if (!empty($filterShift)): ?>
                    <?php echo !empty($filterDate) ? ' - ' : ''; ?>SHIFT <?php echo htmlspecialchars($filterShift); ?>
                <?php endif; ?>
                &nbsp; SHIFT REPORT SUMMARY
            <?php else: ?>
            <?php endif; ?>
        </div>
    <?php elseif ($selectedReport == 'monthly'): ?>
        <div class="report-header">
            <?php if (!empty($filterMonth)): ?>
                MONTHLY REPORT - <?php echo htmlspecialchars(date('F Y', strtotime($filterMonth . '-01'))); ?>
            <?php else: ?>
                MONTHLY REPORT - All Data
            <?php endif; ?>
        </div>
    <?php elseif ($selectedReport == 'machine'): ?>
        <div class="report-header">
            <?php if (!empty($filterMonth)): ?>
                MACHINE WISE REPORT - <?php echo htmlspecialchars(date('F Y', strtotime($filterMonth . '-01'))); ?>
            <?php else: ?>
            <?php endif; ?>
        </div>
    <?php elseif ($selectedReport == 'shift_machine'): ?>
        <div class="report-header">
            <?php if (!empty($filterMonth) || !empty($filterShift)): ?>
                SHIFT WISE MACHINE REPORT - 
                <?php if (!empty($filterMonth)): ?>
                    <?php echo htmlspecialchars(date('F Y', strtotime($filterMonth . '-01'))); ?>
                <?php endif; ?>
                <?php if (!empty($filterShift)): ?>
                    <?php echo !empty($filterMonth) ? ' - ' : ''; ?>SHIFT <?php echo htmlspecialchars($filterShift); ?>
                <?php endif; ?>
            <?php else: ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Shift Report Table -->
    <?php if ($selectedReport == 'shift'): ?>
    <div class="report-header">
        <?php if (!empty($filterDate)): ?>
            DATE <?php echo htmlspecialchars(date('n/j/Y', strtotime($filterDate))); ?> &nbsp; SHIFT REPORT SUMMARY
        <?php else: ?>
        <?php endif; ?>
    </div>
    <table class="summary-table">
        <thead>
            <tr>
                <th>SHIFT</th>
                <th>BRAND</th>
                <th>BATCH NO</th>
                <th>FLAVOUR</th>
                <th>SEAL GROSS TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summaryRows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['shift']); ?></td>
                    <td><?php echo htmlspecialchars($row['brand']); ?></td>
                    <td><?php echo htmlspecialchars($row['batch_no']); ?></td>
                    <td><?php echo htmlspecialchars($row['flavour']); ?></td>
                    <td><?php echo number_format($row['total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!empty($summaryRows)): ?>
            <tr class="grand-total-row">
                <td colspan="4">GRAND TOTAL (SEAL GROSS)</td>
                <td><?php echo number_format($totals['total'], 2); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Batch Summary Table -->
    <?php if ($selectedReport == 'batch'): ?>
        <div class="report-header">
            <?php if (!empty($filterBatch)): ?>
                BATCH SUMMARY - Batch No: <?php echo htmlspecialchars($filterBatch); ?>
            <?php else: ?>
                BATCH SUMMARY - All Batches
            <?php endif; ?>
        </div>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>SHIFT</th>
                    <th>BRAND NAME</th>
                    <th>BATCH NO</th>
                    <th>BIN NO</th>
                    <th>MACHINE NO</th>
                    <th>BAG NO</th>
                    <th>LOT NO</th>
                    <th>FLAVOUR</th>
                    <th>SEAL KG</th>
                    <th>AVG. WT.</th>
                    <th>SEAL GROSS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td>
                            <?php
                            if ($row['date'] instanceof DateTime) {
                                echo htmlspecialchars($row['date']->format('d-m-Y'));
                            } else {
                                echo htmlspecialchars(date('d-m-Y', strtotime((string)$row['date'])));
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['shift']); ?></td>
                        <td><?php echo htmlspecialchars($row['brand_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['batch_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['machine_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['bag_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['flavour']); ?></td>
                        <td><?php echo number_format($row['seal_kg'],2); ?></td>
                        <td><?php echo number_format($row['avg_wt'],2); ?></td>
                        <td><?php echo number_format($row['seal_gross'],2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($summaryRows)): ?>
                <tr class="grand-total-row">
                    <td colspan="9">GRAND TOTAL</td>
                    <td><?php echo number_format($totals['seal_kg'],2); ?></td>
                    <td><?php echo number_format($totals['avg_wt'],2); ?></td>
                    <td><?php echo number_format($totals['seal_gross'],2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Monthly Report Table -->
    <?php if ($selectedReport == 'monthly'): ?>
        
        <!-- Remove debug section after confirming fix -->
    
        
        <table class="summary-table">
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>SHIFT I<br>SEAL GROSS</th>
                    <th>SHIFT II<br>SEAL GROSS</th>
                    <th>SHIFT III<br>SEAL GROSS</th>
                    <th>DAILY TOTAL<br>SEAL GROSS</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summaryRows)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:#666;">No data available</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($summaryRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                            <td><?php echo number_format($row['I'],2); ?></td>
                            <td><?php echo number_format($row['II'],2); ?></td>
                            <td><?php echo number_format($row['III'],2); ?></td>
                            <td style="font-weight:bold;"><?php echo number_format($row['total'],2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="grand-total-row">
                        <td>MONTHLY TOTAL</td>
                        <td><?php echo number_format($grandTotal['I'],2); ?></td>
                        <td><?php echo number_format($grandTotal['II'],2); ?></td>
                        <td><?php echo number_format($grandTotal['III'],2); ?></td>
                        <td><?php echo number_format($grandTotal['total'],2); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Machine Wise Report Table -->
    <?php if ($selectedReport == 'machine'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <?php foreach ($machines as $mc): ?>
                        <th>Machine <?php echo htmlspecialchars($mc); ?><br>SEAL GROSS</th>
                    <?php endforeach; ?>
                    <th>DAILY TOTAL<br>SEAL GROSS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($machineDays as $date => $mcData): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('d-M-Y', strtotime($date))); ?></td>
                        <?php $rowTotal = 0; foreach ($machines as $mc): ?>
                            <td><?php echo isset($mcData[$mc]) ? number_format($mcData[$mc],2) : '0.00'; $rowTotal += $mcData[$mc] ?? 0; ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight:bold;"><?php echo number_format($rowTotal,2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($machineDays)): ?>
                <tr class="grand-total-row">
                    <td>GRAND TOTAL</td>
                    <?php 
                    $machineTotals = [];
                    foreach ($machines as $mc) {
                        $machineTotal = 0;
                        foreach ($machineDays as $mcData) {
                            $machineTotal += $mcData[$mc] ?? 0;
                        }
                        $machineTotals[$mc] = $machineTotal;
                    }
                    foreach ($machines as $mc): ?>
                        <td><?php echo number_format($machineTotals[$mc],2); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($grandTotal,2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Shift Wise Machine Report Table -->
    <?php if ($selectedReport == 'shift_machine'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <?php foreach ($machines as $mc): ?>
                        <th colspan="<?php echo count($shifts); ?>">
                            <?php echo "Machine " . htmlspecialchars($machineNames[$mc]); ?><br>SEAL GROSS
                        </th>
                    <?php endforeach; ?>
                    <th>DAILY TOTAL<br>SEAL GROSS</th>
                </tr>
                <tr>
                    <th></th>
                    <?php foreach ($machines as $mc): ?>
                        <?php foreach ($shifts as $shift): ?>
                            <th>Shift <?php echo htmlspecialchars($shift); ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shiftMachines as $date => $mcData): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('d-M-Y', strtotime($date))); ?></td>
                        <?php $rowTotal = 0; foreach ($machines as $mc): ?>
                            <?php foreach ($shifts as $shift): ?>
                                <td>
                                    <?php
                                    $val = isset($mcData[$mc][$shift]) ? $mcData[$mc][$shift] : 0;
                                    echo number_format($val,2);
                                    $rowTotal += $val;
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <td style="font-weight:bold;"><?php echo number_format($rowTotal,2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($shiftMachines)): ?>
                <tr class="grand-total-row">
                    <td>GRAND TOTAL</td>
                    <?php 
                    $shiftMachineTotals = [];
                    foreach ($machines as $mc) {
                        foreach ($shifts as $shift) {
                            $total = 0;
                            foreach ($shiftMachines as $mcData) {
                                $total += $mcData[$mc][$shift] ?? 0;
                            }
                            $shiftMachineTotals[$mc][$shift] = $total;
                        }
                    }
                    foreach ($machines as $mc): ?>
                        <?php foreach ($shifts as $shift): ?>
                            <td><?php echo number_format($shiftMachineTotals[$mc][$shift],2); ?></td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <td><?php echo number_format($grandTotal,2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Date Wise Sealing Report Table -->
    <?php if ($selectedReport == 'datewise'): ?>
        <div class="report-header">
            <?php if (!empty($filterDate)): ?>
                DATE WISE SEALING REPORT - <?php echo htmlspecialchars(date('d-M-Y', strtotime($filterDate))); ?>
            <?php else: ?>
            <?php endif; ?>
        </div>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>BATCH NO</th>
                    <th>FLAVOUR</th>
                    <th>SEAL GROSS TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['batch_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['flavour']); ?></td>
                        <td><?php echo number_format($row['total'],2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($summaryRows)): ?>
                <tr class="grand-total-row">
                    <td colspan="2">GRAND TOTAL</td>
                    <td><?php echo number_format($grandTotal,2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (empty($summaryRows) && empty($machineDays) && empty($shiftMachines)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <i class="fa fa-info-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
            <p>No data found for the selected criteria.</p>
        </div>
    <?php endif; ?>
</div>