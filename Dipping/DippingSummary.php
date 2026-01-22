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

$reportTypes = [
    'shift' => 'Shift Report',
    'lot' => 'Lot Summary',
    'binwise' => 'Bin Wise Lot Summary',
    'product_type' => 'Product Type Report',
    'transfer' => 'Material Transfer Report',
    'issue' => 'Material Issue Summary',
    'forward' => 'Forward Entry Report',
    'monthly' => 'Month Wise Report'
];
$selectedReport = $_GET['report_type'] ?? 'shift';

$dippingDeptId = null;
$deptSql = "SELECT dept_id FROM departments WHERE department_name = 'Dipping'";
$deptStmt = sqlsrv_query($conn, $deptSql);
if ($deptStmt && ($deptRow = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC))) {
    $dippingDeptId = $deptRow['dept_id'];
}
if ($deptStmt) sqlsrv_free_stmt($deptStmt);

$supervisorList = [];
if ($dippingDeptId !== null) {
    $sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND department_id = ? AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
    $stmt = sqlsrv_query($conn, $sql, [$dippingDeptId]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $supervisorList[] = $row['grn_checked_by'];
        }
        sqlsrv_free_stmt($stmt);
    }
}

$filterDate = $_GET['entryDate'] ?? '';
$filterShift = $_GET['shift'] ?? '';
$filterSupervisor = $_GET['supervisor'] ?? '';
$filterLot = $_GET['lot_no'] ?? '';
$filterProductType = $_GET['product_type_filter'] ?? '';
$filterYear = $_GET['year'] ?? date('Y');
$filterMonth = $_GET['month'] ?? '';

$summaryRows = [];
if ($selectedReport == 'shift') {
    $params = [];
    $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT shift, lot_no, product_type, wt_kg, avg_wt, gross FROM dipping_binwise_entry $whereSql ORDER BY shift, lot_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
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
            if (!in_array($lotNo, $uniqueLotsPerShift[$shift], true)) {
                $shiftData[$shift][] = $row;
                $uniqueLotsPerShift[$shift][] = $lotNo;
            }
            $shiftTotals[$shift]['wt_kg'] += floatval($row['wt_kg']);
            $shiftTotals[$shift]['gross'] += floatval($row['gross']);
            $shiftTotals[$shift]['avg_wt_sum'] += floatval($row['avg_wt']);
            $shiftTotals[$shift]['count']++;
        }
        foreach ($shiftData as $shift => $rows) {
            foreach ($rows as $row) { $summaryRows[] = $row; }
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
    $params = []; $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT lot_no, product, ROUND(AVG(avg_wt), 2) as avg_wt, SUM(wt_kg) as production, SUM(wt_kg) as issued, SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as dipping FROM dipping_binwise_entry $whereSql GROUP BY lot_no, product";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $summaryRows[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'binwise') {
    $params = []; $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT bin_no, wt_kg, avg_wt, gross FROM dipping_binwise_entry $whereSql ORDER BY bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $summaryRows[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'transfer') {
    $params = []; $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT bin_no, lot_no, product_type, SUM(wt_kg) as production, SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as issued FROM dipping_binwise_entry $whereSql GROUP BY bin_no, lot_no, product_type ORDER BY bin_no";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $summaryRows[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'forward') {
    $params = []; $where = ["forward_request = 1"];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = "WHERE " . implode(" AND ", $where);
    $sql = "SELECT * FROM dipping_binwise_entry $whereSql ORDER BY entry_date DESC, bin_no ASC";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $summaryRows[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'issue') {
    $params = []; $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterLot) { $where[] = "lot_no = ?"; $params[] = $filterLot; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT lot_no, product, ROUND(AVG(avg_wt), 2) as avg_wt, SUM(wt_kg) as production, SUM(CASE WHEN forward_request = 1 THEN wt_kg ELSE 0 END) as issued FROM dipping_binwise_entry $whereSql GROUP BY lot_no, product";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['balance'] = floatval($row['production']) - floatval($row['issued']);
            $summaryRows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'product_type') {
    $params = []; $where = [];
    if ($filterDate) { $where[] = "entry_date = ?"; $params[] = $filterDate; }
    if ($filterShift) { $where[] = "shift = ?"; $params[] = $filterShift; }
    if ($filterProductType) { $where[] = "product_type = ?"; $params[] = $filterProductType; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT entry_date, product_type, shift, lot_no, bin_no, wt_kg, avg_wt, gross, supervisor FROM dipping_binwise_entry $whereSql ORDER BY entry_date DESC, product_type, shift";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $summaryRows[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
} elseif ($selectedReport == 'monthly') {
    $params = []; $where = [];
    if ($filterYear) { $where[] = "EXTRACT(YEAR FROM entry_date) = ?"; $params[] = $filterYear; }
    if ($filterMonth) { $where[] = "EXTRACT(MONTH FROM entry_date) = ?"; $params[] = $filterMonth; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT entry_date, 
                   SUM(CASE WHEN CAST(shift AS TEXT) = 'I' THEN wt_kg ELSE 0 END) as \"I\", 
                   SUM(CASE WHEN CAST(shift AS TEXT) = 'II' THEN wt_kg ELSE 0 END) as \"II\", 
                   SUM(CASE WHEN CAST(shift AS TEXT) = 'III' THEN wt_kg ELSE 0 END) as \"III\", 
                   SUM(wt_kg) as total 
            FROM dipping_binwise_entry $whereSql 
            GROUP BY entry_date ORDER BY entry_date";
    
    // Debug output (remove for production)
    // error_log("Monthly Report SQL: " . $sql);
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $formattedRow = [];
            if ($row['entry_date'] instanceof DateTime) {
                $formattedRow['date'] = $row['entry_date']->format('Y-m-d');
            } else {
                $formattedRow['date'] = date('Y-m-d', strtotime($row['entry_date']));
            }
            $formattedRow['I'] = floatval($row['I'] ?? 0);
            $formattedRow['II'] = floatval($row['II'] ?? 0);
            $formattedRow['III'] = floatval($row['III'] ?? 0);
            $formattedRow['total'] = floatval($row['total'] ?? 0);
            $summaryRows[] = $formattedRow;
        }
        sqlsrv_free_stmt($stmt);
    }
}

$totals = ['wt_kg' => 0, 'gross' => 0, 'production' => 0, 'issued' => 0, 'dipping' => 0, 'balance' => 0, 'avg_wt' => 0, 'avg_wt_count' => 0, 'total_entries' => 0];
foreach ($summaryRows as $row) {
    if ($selectedReport == 'shift' || $selectedReport == 'lot' || $selectedReport == 'binwise' || $selectedReport == 'product_type') {
        $totals['wt_kg'] += floatval($row['wt_kg'] ?? $row['production'] ?? 0);
        $totals['gross'] += floatval($row['gross'] ?? 0);
        if (isset($row['avg_wt']) && is_numeric($row['avg_wt'])) {
            $totals['avg_wt'] += floatval($row['avg_wt']); $totals['avg_wt_count']++;
        }
    }
    if ($selectedReport == 'lot' || $selectedReport == 'issue') {
        $totals['production'] += floatval($row['production'] ?? 0);
        $totals['issued'] += floatval($row['issued'] ?? 0);
        $totals['dipping'] += floatval($row['dipping'] ?? 0);
        $totals['balance'] += (floatval($row['production'] ?? 0) - floatval($row['issued'] ?? 0));
    }
    if ($selectedReport == 'monthly') {
        $totals['wt_kg'] += floatval($row['total'] ?? 0);
    }
    if ($selectedReport == 'product_type') { $totals['total_entries']++; }
}
$grandAvgWt = $totals['avg_wt_count'] ? round($totals['avg_wt'] / $totals['avg_wt_count'], 2) : 0;

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="dipping_summary.xls"');
    echo "<table border='1'>";
    echo "</table>"; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dipping Summary | AABHA ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --bg-body: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            line-height: 1.25;
            -webkit-font-smoothing: antialiased;
        }
        .main-content {
            margin-left: 260px;
            padding: 1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: contentReveal 0.6s cubic-bezier(0, 0, 0.2, 1);
            max-width: 100%;
        }
        @keyframes contentReveal {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sidebar-collapsed .main-content { margin-left: 0; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .stat-card {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: 10px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .stat-label { font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .stat-value { font-size: 1.25rem; font-weight: 800; color: var(--primary); }

        .report-card {
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .filter-header {
            padding: 0.75rem 1.25rem;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            align-items: end;
        }
        .form-group { display: flex; flex-direction: column; gap: 0.15rem; }
        .form-label { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.025em; }
        .form-input {
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.8rem;
            background: #fcfcfc;
            transition: all 0.2s;
            color: var(--text-main);
        }
        .form-input:focus { outline: none; border-color: var(--primary); ring: 2px solid rgba(99, 102, 241, 0.1); }
        
        .btn {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; border-color: var(--border); color: var(--text-main); }
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

        .report-title-bar {
            padding: 0.6rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .report-title { margin: 0; font-size: 0.85rem; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .report-title i { color: var(--primary); font-size: 0.75rem; }

        .table-container { overflow-x: auto; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        .summary-table th {
            background: #f8fafc;
            padding: 0.5rem 0.75rem;
            text-align: left;
            font-weight: 800;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .summary-table td { padding: 0.4rem 0.75rem; border-bottom: 1px solid var(--border); }
        .summary-table tbody tr { transition: background 0.1s; }
        .summary-table tbody tr:hover { background: #f1f5f9; cursor: default; }
        .subtotal-row { background: #fffbeb !important; font-weight: 800; color: #92400e; }
        .grand-total-row { background: #eff6ff !important; font-weight: 900; color: var(--primary); border-top: 2px solid var(--primary); }

        @media (max-width: 900px) {
            .main-content { padding: 0.75rem; margin-left: 0; }
            .filter-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include '../Includes/sidebar.php'; ?>
<div class="main-content">
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">Production</span>
            <span class="stat-value"><?= number_format($totals['wt_kg'] ?: $totals['production'], 2) ?> <small style="font-size: 0.5em; color: var(--text-muted)">KG</small></span>
        </div>
        <?php if($selectedReport != 'monthly'): ?>
        <div class="stat-card">
            <span class="stat-label">Avg Weight</span>
            <span class="stat-value"><?= number_format($grandAvgWt, 2) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Total Gross</span>
            <span class="stat-value"><?= number_format($totals['gross'], 2) ?></span>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <span class="stat-label">Days Logged</span>
            <span class="stat-value"><?= count($summaryRows) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Avg Daily Prod</span>
            <span class="stat-value"><?= count($summaryRows) > 0 ? number_format($totals['wt_kg'] / count($summaryRows), 2) : '0.00' ?></span>
        </div>
        <?php endif; ?>
        <?php if($selectedReport == 'issue' || $selectedReport == 'lot'): ?>
        <div class="stat-card">
            <span class="stat-label">Issued</span>
            <span class="stat-value"><?= number_format($totals['issued'], 2) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="report-card">
        <div class="filter-header">
            <form method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label class="form-label">Report</label>
                        <select name="report_type" class="form-input" onchange="this.form.submit()">
                            <?php foreach ($reportTypes as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $selectedReport == $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="entryDate" class="form-input" value="<?= $filterDate ?>">
                    </div>
                    <?php if (in_array($selectedReport, ['shift', 'product_type'])): ?>
                    <div class="form-group">
                        <label class="form-label">Shift</label>
                        <select name="shift" class="form-input">
                            <option value="">All</option>
                            <option value="I" <?= $filterShift == 'I' ? 'selected' : '' ?>>I</option>
                            <option value="II" <?= $filterShift == 'II' ? 'selected' : '' ?>>II</option>
                            <option value="III" <?= $filterShift == 'III' ? 'selected' : '' ?>>III</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array($selectedReport, ['lot', 'binwise', 'issue'])): ?>
                    <div class="form-group">
                        <label class="form-label">Lot No</label>
                        <input type="text" name="lot_no" class="form-input" placeholder="Search..." value="<?= htmlspecialchars($filterLot) ?>">
                    </div>
                    <?php endif; ?>
                    <?php if ($selectedReport == 'monthly'): ?>
                    <div class="form-group">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-input">
                            <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-input">
                            <option value="">All Months</option>
                            <?php for($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 10)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div style="display: flex; gap: 0.4rem; justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                        <a href="DippingSummary.php" class="btn btn-outline" title="Reset Filters"><i class="fas fa-sync-alt"></i></a>
                        <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-outline"><i class="fas fa-download"></i></a>
                    </div>
                </div>
            </form>
        </div>

        <div class="report-title-bar">
            <h2 class="report-title"><i class="fas fa-file-invoice"></i> <?= $reportTypes[$selectedReport] ?></h2>
            <div style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800;"><?= date('d.m.y | H:i') ?></div>
        </div>

        <div class="table-container">
            <table class="summary-table">
                <thead>
                    <?php if ($selectedReport == 'shift'): ?>
                        <tr><th>Shift</th><th>Lot No</th><th>Type</th><th>WT</th><th>AVG</th><th>GROSS</th></tr>
                    <?php elseif ($selectedReport == 'lot'): ?>
                        <tr><th>Lot</th><th>Product</th><th>Avg</th><th>Prod</th><th>Dip</th><th>Iss</th><th>Bal</th></tr>
                    <?php elseif ($selectedReport == 'binwise'): ?>
                        <tr><th>Bin</th><th>WT</th><th>AVG</th><th>GROSS</th></tr>
                    <?php elseif ($selectedReport == 'issue'): ?>
                        <tr><th>Lot</th><th>Product</th><th>Avg</th><th>Prod</th><th>Iss</th><th>Bal</th></tr>
                    <?php elseif ($selectedReport == 'product_type'): ?>
                        <tr><th>Type</th><th>Shift</th><th>Lot</th><th>Bin</th><th>WT</th><th>Avg</th><th>Gross</th><th>By</th></tr>
                    <?php elseif ($selectedReport == 'forward'): ?>
                        <tr><th>Date</th><th>Bin</th><th>Lot</th><th>WT</th><th>Avg</th><th>Gross</th><th>By</th></tr>
                    <?php elseif ($selectedReport == 'monthly'): ?>
                        <tr><th>Date</th><th>I</th><th>II</th><th>III</th><th>Total</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if (count($summaryRows)): ?>
                        <?php foreach ($summaryRows as $row): 
                            $isSub = isset($row['is_subtotal']) && $row['is_subtotal'];
                        ?>
                            <tr class="<?= $isSub ? 'subtotal-row' : '' ?>">
                                <?php if ($selectedReport == 'shift'): ?>
                                    <td><?= htmlspecialchars($row['shift']) ?></td><td><?= htmlspecialchars($row['lot_no']) ?></td><td><?= htmlspecialchars($row['product_type']) ?></td><td><?= number_format($row['wt_kg'], 2) ?></td><td><?= number_format($row['avg_wt'], 2) ?></td><td><?= number_format($row['gross'], 2) ?></td>
                                <?php elseif ($selectedReport == 'lot'): ?>
                                    <td><?= htmlspecialchars($row['lot_no']) ?></td><td><?= htmlspecialchars($row['product']) ?></td><td><?= number_format($row['avg_wt'], 2) ?></td><td><?= number_format($row['production'], 2) ?></td><td><?= number_format($row['dipping'], 2) ?></td><td><?= number_format($row['issued'], 2) ?></td><td><?= number_format($row['dipping'] - $row['issued'], 2) ?></td>
                                <?php elseif ($selectedReport == 'binwise'): ?>
                                    <td><?= htmlspecialchars($row['bin_no']) ?></td><td><?= number_format($row['wt_kg'], 2) ?></td><td><?= number_format($row['avg_wt'], 2) ?></td><td><?= number_format($row['gross'], 2) ?></td>
                                <?php elseif ($selectedReport == 'issue'): ?>
                                    <td><?= htmlspecialchars($row['lot_no']) ?></td><td><?= htmlspecialchars($row['product']) ?></td><td><?= number_format($row['avg_wt'], 2) ?></td><td><?= number_format($row['production'], 2) ?></td><td><?= number_format($row['issued'], 2) ?></td><td><?= number_format($row['production'] - $row['issued'], 2) ?></td>
                                <?php elseif ($selectedReport == 'product_type'): ?>
                                    <td><?= htmlspecialchars($row['product_type']) ?></td><td><?= htmlspecialchars($row['shift']) ?></td><td><?= htmlspecialchars($row['lot_no']) ?></td><td><?= htmlspecialchars($row['bin_no']) ?></td><td><?= number_format($row['wt_kg'], 2) ?></td><td><?= number_format($row['avg_wt'], 2) ?></td><td><?= number_format($row['gross'], 2) ?></td><td><?= htmlspecialchars($row['supervisor']) ?></td>
                                <?php elseif ($selectedReport == 'forward'): ?>
                                    <td><?php
                                        // Fix: Format DateTime for entry_date if needed
                                        $entryDate = $row['entry_date'];
                                        if ($entryDate instanceof DateTime) {
                                            echo htmlspecialchars($entryDate->format('Y-m-d'));
                                        } elseif (is_array($entryDate) && isset($entryDate['date'])) {
                                            // SQLSRV may return ['date' => ..., ...]
                                            $dateObj = date_create($entryDate['date']);
                                            echo htmlspecialchars($dateObj ? $dateObj->format('Y-m-d') : $entryDate['date']);
                                        } else {
                                            echo htmlspecialchars((string)$entryDate);
                                        }
                                    ?></td>
                                    <td><?= htmlspecialchars($row['bin_no']) ?></td>
                                    <td><?= htmlspecialchars($row['lot_no']) ?></td>
                                    <td><?= number_format($row['wt_kg'], 2) ?></td>
                                    <td><?= number_format($row['avg_wt'], 2) ?></td>
                                    <td><?= number_format($row['gross'], 2) ?></td>
                                    <td><?= htmlspecialchars($row['supervisor']) ?></td>
                                <?php elseif ($selectedReport == 'transfer'): ?>
                                    <td><?= htmlspecialchars($row['bin_no']) ?></td><td><?= htmlspecialchars($row['lot_no']) ?></td><td><?= htmlspecialchars($row['product_type']) ?></td><td><?= number_format($row['production'], 2) ?></td><td><?= number_format($row['issued'], 2) ?></td><td><?= number_format($row['production'] - $row['issued'], 2) ?></td>
                                <?php elseif ($selectedReport == 'monthly'): ?>
                                    <td><?php
                                        $dateVal = $row['date'] ?? $row['entry_date'] ?? '';
                                        if ($dateVal instanceof DateTime) {
                                            echo htmlspecialchars($dateVal->format('Y-m-d'));
                                        } elseif (is_array($dateVal) && isset($dateVal['date'])) {
                                            $dateObj = date_create($dateVal['date']);
                                            echo htmlspecialchars($dateObj ? $dateObj->format('Y-m-d') : $dateVal['date']);
                                        } else {
                                            echo htmlspecialchars((string)$dateVal);
                                        }
                                    ?></td>
                                    <td><?= number_format($row['I'], 2) ?></td>
                                    <td><?= number_format($row['II'], 2) ?></td>
                                    <td><?= number_format($row['III'], 2) ?></td>
                                    <td><?= number_format($row['total'], 2) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="grand-total-row">
                            <?php if ($selectedReport == 'shift'): ?>
                                <td colspan="3">TOTAL</td><td><?= number_format($totals['wt_kg'], 2) ?></td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['gross'], 2) ?></td>
                            <?php elseif ($selectedReport == 'lot'): ?>
                                <td colspan="2">TOTAL</td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['production'], 2) ?></td><td><?= number_format($totals['dipping'], 2) ?></td><td><?= number_format($totals['issued'], 2) ?></td><td><?= number_format($totals['dipping'] - $totals['issued'], 2) ?></td>
                            <?php elseif ($selectedReport == 'binwise'): ?>
                                <td>TOTAL</td><td><?= number_format($totals['wt_kg'], 2) ?></td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['gross'], 2) ?></td>
                            <?php elseif ($selectedReport == 'issue'): ?>
                                <td colspan="2">TOTAL</td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['production'], 2) ?></td><td><?= number_format($totals['issued'], 2) ?></td><td><?= number_format($totals['production'] - $totals['issued'], 2) ?></td>
                            <?php elseif ($selectedReport == 'product_type'): ?>
                                <td colspan="4">TOTAL (<?= $totals['total_entries'] ?>)</td><td><?= number_format($totals['wt_kg'], 2) ?></td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['gross'], 2) ?></td><td>-</td>
                            <?php elseif ($selectedReport == 'forward'): ?>
                                <td colspan="3">TOTAL</td><td><?= number_format($totals['wt_kg'], 2) ?></td><td><?= $grandAvgWt ?></td><td><?= number_format($totals['gross'], 2) ?></td><td>-</td>
                            <?php elseif ($selectedReport == 'monthly'): ?>
                                <td colspan="4">TOTAL WEIGHT</td><td><?= number_format($totals['wt_kg'], 2) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-muted); font-style: italic; font-weight: 700;">No results found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
