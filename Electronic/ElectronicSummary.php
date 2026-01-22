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

// Dropdown options for Electronic reports
$reportTypes = [
    'monthly' => 'Monthly Report',
    'monthly_machine' => 'Monthly Machine wise report',
    'shift' => 'Shift Report',
    'lot' => 'Lot Wise Report',
    'bin' => 'Bin Wise Report',
    'product' => 'Product Wise Report',
    'tested' => 'Product Wise Tested Untested',
    'datewise' => 'Date Wise Production Report',
    'operator' => 'Operator Performance Report',
    'batch_issue' => 'Batch Material Issue Report',
    'forward' => 'Forward Entry Report'
];
$selectedReport = $_GET['report_type'] ?? 'shift';

// Filters
$filterDate = $_GET['entryDate'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterShift = $_GET['shift'] ?? '';
$filterLot = $_GET['lot_no'] ?? '';
$filterBatch = $_GET['batch_no'] ?? '';
$filterOperator = $_GET['operator'] ?? '';

// Fetch data for each report type
$summaryRows = [];
$grandTotal = [];

if ($selectedReport == 'monthly') {
    // Monthly Report: Show all date data by default, filter by month if selected
    $params = [];
    $where = [];
    if (!empty($filterMonth)) {
        // Convert filterMonth (YYYY-MM) to SQL LIKE for month matching
        $monthYear = date('M-y', strtotime($filterMonth . '-01')); // e.g. Aug-25
        $where[] = "[month] = ?";
        $params[] = $monthYear;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT 
        [date],
        SUM(CASE WHEN shift = 'I' THEN total_kg ELSE 0 END) AS I,
        SUM(CASE WHEN shift = 'II' THEN total_kg ELSE 0 END) AS II,
        SUM(CASE WHEN shift = 'III' THEN total_kg ELSE 0 END) AS III,
        SUM(total_kg) AS total,
        SUM(pass_kg) AS pass,
        SUM(rej_kg) AS rej
        FROM electronic_batch_entry
        $whereSql
        GROUP BY [date]
        ORDER BY [date]";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $grandTotal = ['I'=>0, 'II'=>0, 'III'=>0, 'total'=>0, 'pass'=>0, 'rej'=>0];
    $summaryRows = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format date as DD-MM-YYYY
            if ($row['date'] instanceof DateTime) {
                $row['date'] = $row['date']->format('d-m-Y');
            } else {
                $row['date'] = date('d-m-Y', strtotime($row['date']));
            }
            $row['I'] = $row['I'] ?? 0;
            $row['II'] = $row['II'] ?? 0;
            $row['III'] = $row['III'] ?? 0;
            $row['total'] = $row['total'] ?? 0;
            $row['pass'] = $row['pass'] ?? 0;
            $row['rej'] = $row['rej'] ?? 0;
            $row['rej_percent'] = $row['total'] ? round(($row['rej']/$row['total'])*100,2) : 0;
            foreach(['I','II','III','total','pass','rej'] as $k) $grandTotal[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    $grandTotal['rej_percent'] = $grandTotal['total'] ? round(($grandTotal['rej']/$grandTotal['total'])*100,2) : 0;
}
elseif ($selectedReport == 'monthly_machine') {
    // Monthly Machine wise report: Show machine-wise totals for each day, all dates by default
    $params = [];
    $where = [];
    if (!empty($filterMonth)) {
        $where[] = "FORMAT([date], 'yyyy-MM') = ?";
        $params[] = $filterMonth;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT 
        [date],
        mc_no,
        SUM(total_kg) AS qty
        FROM electronic_batch_entry
        $whereSql
        GROUP BY [date], mc_no
        ORDER BY [date], mc_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $machineDays = [];
    $machines = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format date as DD-MM-YYYY
            if ($row['date'] instanceof DateTime) {
                $dateKey = $row['date']->format('d-m-Y');
            } else {
                $dateKey = date('d-m-Y', strtotime($row['date']));
            }
            $machineDays[$dateKey][$row['mc_no']] = $row['qty'];
            $machines[$row['mc_no']] = true;
        }
        sqlsrv_free_stmt($stmt);
    }
    $machines = array_keys($machines);
    sort($machines);
}
elseif ($selectedReport == 'shift') {
    // Shift Report: Show shift-wise lot summary for a date
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "[date] = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT shift, lot_no, product_type, pass_kg, rej_kg, total_kg, pass_gross, reject_gross, et_total_gs
            FROM electronic_batch_entry $whereSql ORDER BY shift, lot_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['pass_kg'=>0,'rej_kg'=>0,'total_kg'=>0,'pass_gross'=>0,'reject_gross'=>0,'et_total_gs'=>0];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'lot') {
    // Lot Wise Report: Search by lot_no
    $params = [];
    $where = [];
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT lot_no, SUM(pass_kg) AS pass_kg, SUM(rej_kg) AS rej_kg, SUM(total_kg) AS total_kg,
                   SUM(pass_gross) AS pass_gross, SUM(reject_gross) AS reject_gross, SUM(et_total_gs) AS et_total_gs
            FROM electronic_batch_entry $whereSql GROUP BY lot_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['pass_kg'=>0,'rej_kg'=>0,'total_kg'=>0,'pass_gross'=>0,'reject_gross'=>0,'et_total_gs'=>0];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'bin') {
    // Bin Wise Report: Search by lot_no and bin_no, show bin-wise details same as lot-wise
    $params = [];
    $where = [];
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT lot_no, bin_no, SUM(pass_kg) AS pass_kg, SUM(rej_kg) AS rej_kg, SUM(total_kg) AS total_kg,
                   SUM(pass_gross) AS pass_gross, SUM(reject_gross) AS reject_gross, SUM(et_total_gs) AS et_total_gs
            FROM electronic_batch_entry $whereSql GROUP BY lot_no, bin_no ORDER BY lot_no, bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['pass_kg'=>0,'rej_kg'=>0,'total_kg'=>0,'pass_gross'=>0,'reject_gross'=>0,'et_total_gs'=>0];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'product') {
    // Product Wise Report
    $params = [];
    $where = [];
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT lot_no, product_type AS product, SUM(pass_kg+rej_kg) AS recd_kg, SUM(pass_kg) AS pass_kg,
                   SUM(total_kg) AS issued_kg, SUM(total_kg)-SUM(pass_kg) AS test_stock_kg,
                   SUM(rej_kg) AS untested, AVG(avg_wt) AS avg_wt,
                   SUM(pass_gross) AS tested_gross, SUM(reject_gross) AS untested_gross
            FROM electronic_batch_entry
            $whereSql
            GROUP BY lot_no, product_type";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['tested_gross'=>0, 'untested_gross'=>0];
    $summaryRows = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $totals['tested_gross'] += $row['tested_gross'];
            $totals['untested_gross'] += $row['untested_gross'];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'tested') {
    // Product Wise Tested Untested
    $sql = "SELECT product_type AS summary,
                   SUM(pass_gross) AS tested,
                   SUM(reject_gross) AS untested,
                   SUM(pass_gross)+SUM(reject_gross) AS total
            FROM electronic_batch_entry
            GROUP BY product_type";
    $stmt = sqlsrv_query($conn, $sql);
    $totals = ['tested'=>0,'untested'=>0,'total'=>0];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'datewise') {
    // Date Wise Production Report: Filter by date and shift, show operator ID wise data with subtotals
    $params = [];
    $where = [];
    if (!empty($filterDate)) {
        $where[] = "[date] = ?";
        $params[] = $filterDate;
    }
    if (!empty($filterShift)) {
        $where[] = "shift = ?";
        $params[] = $filterShift;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT 
            op_id,
            mc_no,
            lot_no,
            bin_no,
            pass_kg,
            rej_kg,
            total_kg,
            pass_gross,
            reject_gross,
            et_total_gs,
            shift,
            [date]
        FROM electronic_batch_entry
        $whereSql
        ORDER BY op_id, mc_no, lot_no, bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    // Group data by operator ID
    $operatorData = [];
    $grandTotals = [
        'pass_kg' => 0,
        'rej_kg' => 0,
        'total_kg' => 0,
        'pass_gross' => 0,
        'reject_gross' => 0,
        'et_total_gs' => 0
    ];
    
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $opId = $row['op_id'];
            
            // Initialize operator group if not exists
            if (!isset($operatorData[$opId])) {
                $operatorData[$opId] = [
                    'rows' => [],
                    'totals' => [
                        'pass_kg' => 0,
                        'rej_kg' => 0,
                        'total_kg' => 0,
                        'pass_gross' => 0,
                        'reject_gross' => 0,
                        'et_total_gs' => 0
                    ]
                ];
            }
            
            // Format date as DD-MM-YYYY
            if ($row['date'] instanceof DateTime) {
                $row['date'] = $row['date']->format('d-m-Y');
            } else {
                $row['date'] = date('d-m-Y', strtotime($row['date']));
            }
            
            // Add to operator group
            $operatorData[$opId]['rows'][] = $row;
            
            // Update operator totals
            foreach ($operatorData[$opId]['totals'] as $k => $v) {
                $operatorData[$opId]['totals'][$k] += $row[$k];
            }
            
            // Update grand totals
            foreach ($grandTotals as $k => $v) {
                $grandTotals[$k] += $row[$k];
            }
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'operator') {
    // Operator Performance Report
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "[date] = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT mc_no, op_id, SUM(total_kg) AS kg, SUM(et_total_gs) AS et_totalgs
            FROM electronic_batch_entry $whereSql
            GROUP BY mc_no, op_id
            ORDER BY mc_no, op_id";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['kg'=>0,'et_totalgs'=>0];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}
elseif ($selectedReport == 'batch_issue') {
    // Batch Material Issue Report - Updated fields and structure
    $params = [];
    $where = [];
    if ($filterBatch) { 
        $where[] = "batch_number = ?"; 
        $params[] = $filterBatch; 
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
            lot_no,
            bin_no,
            SUM(total_kg) AS qty_kg,
            AVG(avg_wt) AS avg_wt,
            SUM(pass_gross + reject_gross) AS gross
        FROM electronic_batch_entry
        $whereSql
        GROUP BY lot_no, bin_no
        ORDER BY lot_no, bin_no";
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    $totals = ['qty_kg'=>0, 'avg_wt'=>0, 'gross'=>0];
    $summaryRows = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach($totals as $k=>$v) $totals[$k] += $row[$k];
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    // Calculate average weight properly
    $totals['avg_wt'] = count($summaryRows) > 0 ? $totals['avg_wt'] / count($summaryRows) : 0;
}
elseif ($selectedReport == 'forward') {
    // Forward Entry Report: Show all entries where forward = 1
    $params = [];
    $where = ["forward = 1"];
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT [date], batch_number, forward, month, shift, mc_no, op_id, lot_no, bin_no, pass_kg, rej_kg, avg_wt, pass_gross, reject_gross, et_total_gs, product_type, op_name, total_kg, created_at
            FROM electronic_batch_entry
            $whereSql
            ORDER BY [date] DESC, batch_number";
    $stmt = sqlsrv_query($conn, $sql, $params);
    $summaryRows = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format date as DD-MM-YYYY
            if ($row['date'] instanceof DateTime) {
                $row['date'] = $row['date']->format('d-m-Y');
            } else {
                $row['date'] = date('d-m-Y', strtotime($row['date']));
            }
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Export to Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="electronic_summary.xls"');
    echo "<table border='1'>";
    if ($selectedReport == 'shift') {
        echo "<tr><th>Shift</th><th>Lot No</th><th>Product</th><th>PASS KG</th><th>REJ. KG</th><th>TOTAL KG</th><th>PASS GROSS</th><th>REJECT GROSS</th><th>ET_totalGS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['shift']}</td>
                <td>{$row['lot_no']}</td>
                <td>" . ($row['product_type'] ?? '') . "</td>
                <td>{$row['pass_kg']}</td>
                <td>{$row['rej_kg']}</td>
                <td>{$row['total_kg']}</td>
                <td>{$row['pass_gross']}</td>
                <td>{$row['reject_gross']}</td>
                <td>" . ($row['et_total_gs'] ?? '') . "</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#f9f9c5;'>
            <td colspan='3'>Grand Total</td>
            <td>{$totals['pass_kg']}</td>
            <td>{$totals['rej_kg']}</td>
            <td>{$totals['total_kg']}</td>
            <td>{$totals['pass_gross']}</td>
            <td>{$totals['reject_gross']}</td>
            <td>{$totals['et_total_gs']}</td>
        </tr>";
    } elseif ($selectedReport == 'monthly') {
        echo "<tr><th>DATE</th><th>I</th><th>II</th><th>III</th><th>TOTAL</th><th>PASS</th><th>Rej</th><th>REJ%</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['date']}</td>
                <td>" . round($row['I'],2) . "</td>
                <td>" . round($row['II'],2) . "</td>
                <td>" . round($row['III'],2) . "</td>
                <td>" . round($row['total'],2) . "</td>
                <td>" . round($row['pass'],2) . "</td>
                <td>" . round($row['rej'],2) . "</td>
                <td>{$row['rej_percent']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td>TOTAL</td>
            <td>" . round($grandTotal['I'],2) . "</td>
            <td>" . round($grandTotal['II'],2) . "</td>
            <td>" . round($grandTotal['III'],2) . "</td>
            <td>" . round($grandTotal['total'],2) . "</td>
            <td>" . round($grandTotal['pass'],2) . "</td>
            <td>" . round($grandTotal['rej'],2) . "</td>
            <td>{$grandTotal['rej_percent']}</td>
        </tr>";
    } elseif ($selectedReport == 'monthly_machine') {
        echo "<tr><th>DATE</th>";
        foreach ($machines as $mc) {
            echo "<th>" . htmlspecialchars($mc) . "</th>";
        }
        echo "<th>TOTAL</th></tr>";
        foreach ($machineDays as $date => $mcData) {
            echo "<tr>
                <td>" . htmlspecialchars($date) . "</td>";
                $rowTotal = 0;
                foreach ($machines as $mc) {
                    $qty = $mcData[$mc] ?? 0;
                    echo "<td>" . round($qty,2) . "</td>";
                    $rowTotal += $qty;
                }
                echo "<td>" . round($rowTotal,2) . "</td>
            </tr>";
        }
    } elseif ($selectedReport == 'lot') {
        echo "<tr><th>LOT NO.</th><th>PASS KG</th><th>REJ. KG</th><th>TOTAL KG</th><th>PASS GROSS</th><th>REJECT GROSS</th><th>ET_totalGS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['lot_no']}</td>
                <td>{$row['pass_kg']}</td>
                <td>{$row['rej_kg']}</td>
                <td>{$row['total_kg']}</td>
                <td>{$row['pass_gross']}</td>
                <td>{$row['reject_gross']}</td>
                <td>{$row['et_total_gs']}</td>
            </tr>";
        }
        echo "<tr>
            <td><strong>Grand Total</strong></td>
            <td>" . ($totals['pass_kg'] ?? 0) . "</td>
            <td>" . ($totals['rej_kg'] ?? 0) . "</td>
            <td>" . ($totals['total_kg'] ?? 0) . "</td>
            <td>" . ($totals['pass_gross'] ?? 0) . "</td>
            <td>" . ($totals['reject_gross'] ?? 0) . "</td>
            <td>" . ($totals['et_total_gs'] ?? 0) . "</td>
        </tr>";
    } elseif ($selectedReport == 'bin') {
        echo "<tr><th>LOT NO.</th><th>BIN NO.</th><th>PASS KG</th><th>REJ. KG</th><th>TOTAL KG</th><th>PASS GROSS</th><th>REJECT GROSS</th><th>ET_totalGS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['lot_no']}</td>
                <td>{$row['bin_no']}</td>
                <td>{$row['pass_kg']}</td>
                <td>{$row['rej_kg']}</td>
                <td>{$row['total_kg']}</td>
                <td>{$row['pass_gross']}</td>
                <td>{$row['reject_gross']}</td>
                <td>{$row['et_total_gs']}</td>
            </tr>";
        }
        echo "<tr>
            <td colspan='2'><strong>Grand Total</strong></td>
            <td>" . ($totals['pass_kg'] ?? 0) . "</td>
            <td>" . ($totals['rej_kg'] ?? 0) . "</td>
            <td>" . ($totals['total_kg'] ?? 0) . "</td>
            <td>" . ($totals['pass_gross'] ?? 0) . "</td>
            <td>" . ($totals['reject_gross'] ?? 0) . "</td>
            <td>" . ($totals['et_total_gs'] ?? 0) . "</td>
        </tr>";
    } elseif ($selectedReport == 'product') {
        echo "<tr><th>LOT NO.</th><th>PRODUCT</th><th>RECD KG</th><th>PASS KG</th><th>ISSUED KG</th><th>TEST_STOCK KG</th><th>UNTESTED</th><th>AVG.WT.</th><th>TESTED GROSS</th><th>UNTESED GROSS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['lot_no']}</td>
                <td>{$row['product']}</td>
                <td>{$row['recd_kg']}</td>
                <td>{$row['pass_kg']}</td>
                <td>{$row['issued_kg']}</td>
                <td>{$row['test_stock_kg']}</td>
                <td>{$row['untested']}</td>
                <td>{$row['avg_wt']}</td>
                <td>{$row['tested_gross']}</td>
                <td>{$row['untested_gross']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td colspan='8'>Total</td>
            <td>" . number_format($totals['tested_gross'],2) . "</td>
            <td>" . number_format($totals['untested_gross'],2) . "</td>
        </tr>";
    } elseif ($selectedReport == 'tested') {
        echo "<tr><th>Summary</th><th>Tested</th><th>Untested</th><th>Total</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['summary']}</td>
                <td>{$row['tested']}</td>
                <td>{$row['untested']}</td>
                <td>{$row['total']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td>Total</td>
            <td>{$totals['tested']}</td>
            <td>{$totals['untested']}</td>
            <td>{$totals['total']}</td>
        </tr>";
    } elseif ($selectedReport == 'datewise') {
        echo "<tr><th>OP_ID</th><th>M/C NO.</th><th>LOT NO.</th><th>BIN NO.</th><th>PASS KG</th><th>REJ. KG</th><th>TOTAL KG</th><th>PASS GROSS</th><th>REJECT GROSS</th><th>ET_totalGS</th></tr>";
        foreach ($operatorData as $opId => $opData) {
            foreach ($opData['rows'] as $row) {
                echo "<tr>
                    <td>{$row['op_id']}</td>
                    <td>{$row['mc_no']}</td>
                    <td>{$row['lot_no']}</td>
                    <td>{$row['bin_no']}</td>
                    <td>" . number_format($row['pass_kg'],2) . "</td>
                    <td>" . number_format($row['rej_kg'],2) . "</td>
                    <td>" . number_format($row['total_kg'],2) . "</td>
                    <td>" . number_format($row['pass_gross'],2) . "</td>
                    <td>" . number_format($row['reject_gross'],2) . "</td>
                    <td>" . number_format($row['et_total_gs'],2) . "</td>
                </tr>";
            }
            // Operator subtotal
            echo "<tr style='font-weight:bold;background:#f0f8ff;'>
                <td>{$opId} Total</td>
                <td colspan='3'></td>
                <td>" . number_format($opData['totals']['pass_kg'],2) . "</td>
                <td>" . number_format($opData['totals']['rej_kg'],2) . "</td>
                <td>" . number_format($opData['totals']['total_kg'],2) . "</td>
                <td>" . number_format($opData['totals']['pass_gross'],2) . "</td>
                <td>" . number_format($opData['totals']['reject_gross'],2) . "</td>
                <td>" . number_format($opData['totals']['et_total_gs'],2) . "</td>
            </tr>";
        }
        // Grand total
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td colspan='4'>Grand Total</td>
            <td>" . number_format($grandTotals['pass_kg'],2) . "</td>
            <td>" . number_format($grandTotals['rej_kg'],2) . "</td>
            <td>" . number_format($grandTotals['total_kg'],2) . "</td>
            <td>" . number_format($grandTotals['pass_gross'],2) . "</td>
            <td>" . number_format($grandTotals['reject_gross'],2) . "</td>
            <td>" . number_format($grandTotals['et_total_gs'],2) . "</td>
        </tr>";
    } elseif ($selectedReport == 'operator') {
        echo "<tr><th>M/C NO.</th><th>OP_ID</th><th>KG</th><th>ET_totalGS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['mc_no']}</td>
                <td>{$row['op_id']}</td>
                <td>{$row['kg']}</td>
                <td>{$row['et_totalgs']}</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td colspan='2'>Total</td>
            <td>{$totals['kg']}</td>
            <td>{$totals['et_totalgs']}</td>
        </tr>";
    } elseif ($selectedReport == 'batch_issue') {
        echo "<tr><th>LOT NO</th><th>BIN</th><th>QTY. IN KG</th><th>AVG WT</th><th>GROSS</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['lot_no']}</td>
                <td>{$row['bin_no']}</td>
                <td>" . number_format($row['qty_kg'],2) . "</td>
                <td>" . number_format($row['avg_wt'],2) . "</td>
                <td>" . number_format($row['gross'],2) . "</td>
            </tr>";
        }
        echo "<tr style='font-weight:bold;background:#e0ffe0;'>
            <td colspan='2'>Total</td>
            <td>" . number_format($totals['qty_kg'],2) . "</td>
            <td>" . number_format($totals['avg_wt'],2) . "</td>
            <td>" . number_format($totals['gross'],2) . "</td>
        </tr>";
    } elseif ($selectedReport == 'forward') {
        echo "<tr><th>DATE</th><th>BATCH NO.</th><th>FORWARD</th><th>MONTH</th><th>SHIFT</th><th>MC NO.</th><th>OP_ID</th><th>LOT NO.</th><th>BIN NO.</th><th>PASS KG</th><th>REJ KG</th><th>AVG WT</th><th>PASS GROSS</th><th>REJECT GROSS</th><th>ET_TOTAL_GS</th><th>PRODUCT TYPE</th><th>OP NAME</th><th>TOTAL KG</th><th>CREATED AT</th></tr>";
        foreach ($summaryRows as $row) {
            echo "<tr>
                <td>{$row['date']}</td>
                <td>{$row['batch_number']}</td>
                <td>" . ($row['forward'] ? 'Yes' : 'No') . "</td>
                <td>{$row['month']}</td>
                <td>{$row['shift']}</td>
                <td>{$row['mc_no']}</td>
                <td>{$row['op_id']}</td>
                <td>{$row['lot_no']}</td>
                <td>{$row['bin_no']}</td>
                <td>{$row['pass_kg']}</td>
                <td>{$row['rej_kg']}</td>
                <td>{$row['avg_wt']}</td>
                <td>{$row['pass_gross']}</td>
                <td>{$row['reject_gross']}</td>
                <td>{$row['et_total_gs']}</td>
                <td>{$row['product_type']}</td>
                <td>{$row['op_name']}</td>
                <td>{$row['total_kg']}</td>
                <td>";
            if ($row['created_at'] instanceof DateTime) {
                echo $row['created_at']->format('d-m-Y H:i:s');
            } else {
                echo date('d-m-Y H:i:s', strtotime($row['created_at']));
            }
            echo "</td>
            </tr>";
        }
    }
    echo "</table>";
    exit;
}
include '../Includes/sidebar.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Electronic Summary</title>
    <link rel="stylesheet" href="../asset/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        .main-content { margin-left: 240px; padding: 32px 24px; }
        .summary-filter-form { display: flex; gap: 16px; margin-bottom: 24px; align-items: flex-end; flex-wrap: wrap; }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            overflow: visible;
            display: table;
        }

        .forward-table-wrapper {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }

        /* Optional: Make header sticky for better UX */
        .forward-table-wrapper .summary-table th {
            position: sticky;
            top: 0;
            background: #f1f3f9;
            z-index: 2;
        }

        .summary-table th, .summary-table td { padding: 10px 12px; border: 1px solid #e5e7eb; }
        .summary-table th { background: #f1f3f9; color: #333; font-weight: 600; }
        .summary-table tr:hover { background: #e8f0fe; }
        .summary-title { font-size: 1.4rem; font-weight: bold; margin-bottom: 18px; color: #4f42c1; }
        .filter-btn { background: #4f42c1; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; }
        .filter-btn:hover { background: #3b32a8; }
        .form-control { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .report-header { font-weight: bold; font-size: 1.1rem; margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-radius: 4px; }
    </style>
</head>
<body>
<div class="main-content">
    <div class="summary-title"><i class="fa fa-chart-bar"></i> Electronic Summary</div>
    
    <form class="summary-filter-form" method="get">
        <div>
            <label for="report_type">Report Type</label>
            <select name="report_type" id="report_type" class="form-control" onchange="this.form.submit()">
                <?php foreach($reportTypes as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php if($selectedReport==$key) echo 'selected'; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($selectedReport == 'monthly' || $selectedReport == 'monthly_machine'): ?>
            <div>
                <label for="month">Month</label>
                <input type="month" name="month" id="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="form-control">
            </div>
        <?php endif; ?>
        
        <?php if ($selectedReport == 'shift' || $selectedReport == 'operator' || $selectedReport == 'datewise'): ?>
            <div>
                <label for="entryDate">Date</label>
                <input type="date" name="entryDate" id="entryDate" value="<?php echo htmlspecialchars($filterDate); ?>" class="form-control">
            </div>
        <?php endif; ?>
        
        <?php if ($selectedReport == 'shift' || $selectedReport == 'operator' || $selectedReport == 'datewise'): ?>
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
        
        <?php if ($selectedReport == 'lot' || $selectedReport == 'bin' || $selectedReport == 'shift' || $selectedReport == 'product' || $selectedReport == 'forward'): ?>
            <div>
                <label for="lot_no">Lot No</label>
                <input type="text" name="lot_no" id="lot_no" value="<?php echo htmlspecialchars($filterLot); ?>" class="form-control">
            </div>
        <?php endif; ?>
        
        <?php if ($selectedReport == 'batch_issue'): ?>
            <div>
                <label for="batch_no">Batch No</label>
                <input type="text" name="batch_no" id="batch_no" value="<?php echo htmlspecialchars($filterBatch); ?>" class="form-control">
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
    <?php if ($selectedReport == 'forward'): ?>
        
    <?php endif; ?>
    
    <?php if ($selectedReport == 'datewise'): ?>
       
    <?php endif; ?>

    <?php if ($selectedReport == 'batch_issue'): ?>
        
    <?php endif; ?>

    <!-- Monthly Report -->
    <?php if ($selectedReport == 'monthly'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>I</th>
                    <th>II</th>
                    <th>III</th>
                    <th>TOTAL</th>
                    <th>PASS</th>
                    <th>Rej</th>
                    <th>REJ%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['date']); ?></td>
                        <td><?php echo number_format($row['I'],2); ?></td>
                        <td><?php echo number_format($row['II'],2); ?></td>
                        <td><?php echo number_format($row['III'],2); ?></td>
                        <td><?php echo number_format($row['total'],2); ?></td>
                        <td><?php echo number_format($row['pass'],2); ?></td>
                        <td><?php echo number_format($row['rej'],2); ?></td>
                        <td><?php echo $row['rej_percent']; ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Grand Total Row -->
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td>TOTAL</td>
                    <td><?php echo number_format($grandTotal['I'],2); ?></td>
                    <td><?php echo number_format($grandTotal['II'],2); ?></td>
                    <td><?php echo number_format($grandTotal['III'],2); ?></td>
                    <td><?php echo number_format($grandTotal['total'],2); ?></td>
                    <td><?php echo number_format($grandTotal['pass'],2); ?></td>
                    <td><?php echo number_format($grandTotal['rej'],2); ?></td>
                    <td><?php echo $grandTotal['rej_percent']; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Monthly Machine wise report -->
    <?php if ($selectedReport == 'monthly_machine'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>DATE</th>
                    <?php foreach ($machines as $mc): ?>
                        <th><?php echo htmlspecialchars($mc); ?></th>
                    <?php endforeach; ?>
                    <th>TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($machineDays as $date => $mcData): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($date); ?></td>
                        <?php $rowTotal = 0; foreach ($machines as $mc): ?>
                            <td><?php echo isset($mcData[$mc]) ? round($mcData[$mc],2) : '0'; $rowTotal += $mcData[$mc] ?? 0; ?></td>
                        <?php endforeach; ?>
                        <td><?php echo round($rowTotal,2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Shift Report -->
    <?php if ($selectedReport == 'shift'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>SHIFT</th>
                    <th>LOT NO.</th>
                    <th>PRODUCT</th>
                    <th>PASS KG</th>
                    <th>REJ. KG</th>
                    <th>TOTAL KG</th>
                    <th>PASS GROSS</th>
                    <th>REJECT GROSS</th>
                    <th>ET_totalGS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['shift']); ?></td>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['rej_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['total_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['reject_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['et_total_gs']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#f9f9c5;">
                    <td colspan="3">Grand Total</td>
                    <td><?php echo $totals['pass_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['rej_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['total_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['pass_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['reject_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['et_total_gs'] ?? 0; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Lot Wise Report -->
    <?php if ($selectedReport == 'lot'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>LOT NO.</th>
                    <th>PASS KG</th>
                    <th>REJ. KG</th>
                    <th>TOTAL KG</th>
                    <th>PASS GROSS</th>
                    <th>REJECT GROSS</th>
                    <th>ET_totalGS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['rej_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['total_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['reject_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['et_total_gs']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#f9f9c5;">
                    <td>Grand Total</td>
                    <td><?php echo $totals['pass_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['rej_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['total_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['pass_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['reject_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['et_total_gs'] ?? 0; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Bin Wise Report -->
    <?php if ($selectedReport == 'bin'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>LOT NO.</th>
                    <th>BIN NO.</th>
                    <th>PASS KG</th>
                    <th>REJ. KG</th>
                    <th>TOTAL KG</th>
                    <th>PASS GROSS</th>
                    <th>REJECT GROSS</th>
                    <th>ET_totalGS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['rej_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['total_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['reject_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['et_total_gs']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#f9f9c5;">
                    <td colspan="2">Grand Total</td>
                    <td><?php echo $totals['pass_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['rej_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['total_kg'] ?? 0; ?></td>
                    <td><?php echo $totals['pass_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['reject_gross'] ?? 0; ?></td>
                    <td><?php echo $totals['et_total_gs'] ?? 0; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Product Wise Report -->
    <?php if ($selectedReport == 'product'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>LOT NO.</th>
                    <th>PRODUCT</th>
                    <th>RECD KG</th>
                    <th>PASS KG</th>
                    <th>ISSUED KG</th>
                    <th>TEST_STOCK KG</th>
                    <th>UNTESTED</th>
                    <th>AVG.WT.</th>
                    <th>TESTED GROSS</th>
                    <th>UNTESED GROSS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['product']); ?></td>
                        <td><?php echo htmlspecialchars($row['recd_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['pass_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['issued_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['test_stock_kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['untested']); ?></td>
                        <td><?php echo htmlspecialchars($row['avg_wt']); ?></td>
                        <td><?php echo htmlspecialchars($row['tested_gross']); ?></td>
                        <td><?php echo htmlspecialchars($row['untested_gross']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td colspan="8">Total</td>
                    <td><?php echo number_format($totals['tested_gross'],2); ?></td>
                    <td><?php echo number_format($totals['untested_gross'],2); ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Product Wise Tested Untested -->
    <?php if ($selectedReport == 'tested'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>Summary</th>
                    <th>Tested</th>
                    <th>Untested</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['summary']); ?></td>
                        <td><?php echo htmlspecialchars($row['tested']); ?></td>
                        <td><?php echo htmlspecialchars($row['untested']); ?></td>
                        <td><?php echo htmlspecialchars($row['total']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td>Total</td>
                    <td><?php echo $totals['tested'] ?? 0; ?></td>
                    <td><?php echo $totals['untested'] ?? 0; ?></td>
                    <td><?php echo $totals['total'] ?? 0; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Date Wise Production Report -->
    <?php if ($selectedReport == 'datewise'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>OP_ID</th>
                    <th>M/C NO.</th>
                    <th>LOT NO.</th>
                    <th>BIN NO.</th>
                    <th>PASS KG</th>
                    <th>REJ. KG</th>
                    <th>TOTAL KG</th>
                    <th>PASS GROSS</th>
                    <th>REJECT GROSS</th>
                    <th>ET_totalGS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($operatorData as $opId => $opData): ?>
                    <?php foreach ($opData['rows'] as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['op_id']); ?></td>
                            <td><?php echo htmlspecialchars($row['mc_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                            <td><?php echo number_format($row['pass_kg'], 2); ?></td>
                            <td><?php echo number_format($row['rej_kg'], 2); ?></td>
                            <td><?php echo number_format($row['total_kg'], 2); ?></td>
                            <td><?php echo number_format($row['pass_gross'], 2); ?></td>
                            <td><?php echo number_format($row['reject_gross'], 2); ?></td>
                            <td><?php echo number_format($row['et_total_gs'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Operator Subtotal Row -->
                    <tr style="font-weight:bold;background:#f0f8ff;">
                        <td><?php echo htmlspecialchars($opId); ?> Total</td>
                        <td colspan="3"></td>
                        <td><?php echo number_format($opData['totals']['pass_kg'], 2); ?></td>
                        <td><?php echo number_format($opData['totals']['rej_kg'], 2); ?></td>
                        <td><?php echo number_format($opData['totals']['total_kg'], 2); ?></td>
                        <td><?php echo number_format($opData['totals']['pass_gross'], 2); ?></td>
                        <td><?php echo number_format($opData['totals']['reject_gross'], 2); ?></td>
                        <td><?php echo number_format($opData['totals']['et_total_gs'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php // Grand Total Row ?>
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td colspan="4">Grand Total</td>
                    <td><?php echo number_format($grandTotals['pass_kg'], 2); ?></td>
                    <td><?php echo number_format($grandTotals['rej_kg'], 2); ?></td>
                    <td><?php echo number_format($grandTotals['total_kg'], 2); ?></td>
                    <td><?php echo number_format($grandTotals['pass_gross'], 2); ?></td>
                    <td><?php echo number_format($grandTotals['reject_gross'], 2); ?></td>
                    <td><?php echo number_format($grandTotals['et_total_gs'], 2); ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Operator Performance Report -->
    <?php if ($selectedReport == 'operator'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>M/C NO.</th>
                    <th>OP_ID</th>
                    <th>KG</th>
                    <th>ET_totalGS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['mc_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['op_id']); ?></td>
                        <td><?php echo htmlspecialchars($row['kg']); ?></td>
                        <td><?php echo htmlspecialchars($row['et_totalgs']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td colspan="2">Total</td>
                    <td><?php echo $totals['kg'] ?? 0; ?></td>
                    <td><?php echo $totals['et_totalgs'] ?? 0; ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Batch Material Issue Report -->
    <?php if ($selectedReport == 'batch_issue'): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>LOT NO</th>
                    <th>BIN</th>
                    <th>QTY. IN KG</th>
                    <th>AVG WT</th>
                    <th>GROSS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                        <td><?php echo number_format($row['qty_kg'], 2); ?></td>
                        <td><?php echo number_format($row['avg_wt'], 2); ?></td>
                        <td><?php echo number_format($row['gross'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Grand Total Row -->
                <tr style="font-weight:bold;background:#e0ffe0;">
                    <td colspan="2">Total</td>
                    <td><?php echo number_format($totals['qty_kg'], 2); ?></td>
                    <td><?php echo number_format($totals['avg_wt'], 2); ?></td>
                    <td><?php echo number_format($totals['gross'], 2); ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Forward Entry Report -->
    <?php if ($selectedReport == 'forward'): ?>
        <div class="forward-table-wrapper">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>BATCH NO.</th>
                        <th>FORWARD</th>
                        <th>MONTH</th>
                        <th>SHIFT</th>
                        <th>MC NO.</th>
                        <th>OP_ID</th>
                        <th>LOT NO.</th>
                        <th>BIN NO.</th>
                        <th>PASS KG</th>
                        <th>REJ KG</th>
                        <th>AVG WT</th>
                        <th>PASS GROSS</th>
                        <th>REJECT GROSS</th>
                        <th>ET_TOTAL_GS</th>
                        <th>PRODUCT TYPE</th>
                        <th>OP NAME</th>
                        <th>TOTAL KG</th>
                        <th>CREATED AT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaryRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                            <td><?php echo htmlspecialchars($row['batch_number']); ?></td>
                            <td><?php echo $row['forward'] ? 'Yes' : 'No'; ?></td>
                            <td><?php echo htmlspecialchars($row['month']); ?></td>
                            <td><?php echo htmlspecialchars($row['shift']); ?></td>
                            <td><?php echo htmlspecialchars($row['mc_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['op_id']); ?></td>
                            <td><?php echo htmlspecialchars($row['lot_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['bin_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['pass_kg']); ?></td>
                            <td><?php echo htmlspecialchars($row['rej_kg']); ?></td>
                            <td><?php echo htmlspecialchars($row['avg_wt']); ?></td>
                            <td><?php echo htmlspecialchars($row['pass_gross']); ?></td>
                            <td><?php echo htmlspecialchars($row['reject_gross']); ?></td>
                            <td><?php echo htmlspecialchars($row['et_total_gs']); ?></td>
                            <td><?php echo htmlspecialchars($row['product_type']); ?></td>
                            <td><?php echo htmlspecialchars($row['op_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['total_kg']); ?></td>
                            <td>
                                <?php
                                if ($row['created_at'] instanceof DateTime) {
                                    echo htmlspecialchars($row['created_at']->format('d-m-Y H:i:s'));
                                } else {
                                    echo htmlspecialchars(date('d-m-Y H:i:s', strtotime($row['created_at'])));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (empty($summaryRows) && empty($machineDays)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <i class="fa fa-info-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
            <p>No data found for the selected criteria.</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>