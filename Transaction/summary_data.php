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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material QC Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .summary-table th, .summary-table td {
            text-align: center;
            vertical-align: middle;
        }
        .summary-title {
            background: #444;
            color: #fff;
            font-weight: bold;
            padding: 10px 15px;
            margin-bottom: 0;
            border-radius: 4px 4px 0 0;
        }
    </style>
</head>
<body>
<div class="main-content">
    <div class="container-fluid">
        <div class="card shadow-sm mt-4">
            <div class="summary-title">Material QC Summary</div>
            <div class="card-body">
                <form class="row g-2 mb-3" method="get" action="">
                    <div class="col-md-3">
                        <input type="text" name="material_id" class="form-control" placeholder="Material ID" value="<?= htmlspecialchars($_GET['material_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="material" class="form-control" placeholder="Material Description" value="<?= htmlspecialchars($_GET['material'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="summary_data.php" class="btn btn-secondary">Clear</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped summary-table">
                        <thead class="table-dark">
                            <tr>
                                <th>Sr. No.</th>
                                <th>Material ID</th>
                                <th>Material Description</th>
                                <th>Unit</th>
                                <th>Material Type</th>
                                <th>Ordered Qty</th>
                                <th>Actual Qty</th>
                                <th>Accepted Qty (QC)</th>
                                <th>Rejected Qty</th>
                            </tr>
                        </thead>
                        <tbody>
<?php
$where = [];
if (!empty($_GET['material_id'])) {
    $mid = mysqli_real_escape_string($conn, $_GET['material_id']);
    $where[] = "gq.material_id LIKE '%$mid%'";
}
if (!empty($_GET['material'])) {
    $mat = mysqli_real_escape_string($conn, $_GET['material']);
    $where[] = "gq.material LIKE '%$mat%'";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT 
            gq.material_id,
            gq.material,
            gq.unit,
            gq.material_type,
            SUM(COALESCE(gq.ordered_qty,0)) AS total_ordered_qty,
            SUM(COALESCE(gq.actual_qty,0)) AS total_actual_qty,
            SUM(COALESCE(qc.accepted_qty,0)) AS total_accepted_qty,
            SUM(COALESCE(gq.actual_qty,0) - COALESCE(qc.accepted_qty,0)) AS total_rejected_qty
        FROM grn_quantity_details gq
        LEFT JOIN qc_quantity_details qc ON gq.quantity_id = qc.grn_quantity_id
        $whereSql
        GROUP BY gq.material_id, gq.material, gq.unit, gq.material_type
        ORDER BY gq.material_id";

$result = mysqli_query($conn, $sql);
$sr = 1;
$totalAccepted = 0;
if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $totalAccepted += $row['total_accepted_qty'];
        echo "<tr>
            <td>{$sr}</td>
            <td>{$row['material_id']}</td>
            <td>{$row['material']}</td>
            <td>{$row['unit']}</td>
            <td>{$row['material_type']}</td>
            <td>{$row['total_ordered_qty']}</td>
            <td>{$row['total_actual_qty']}</td>
            <td class='text-success fw-bold'>{$row['total_accepted_qty']}</td>
            <td class='text-danger fw-bold'>{$row['total_rejected_qty']}</td>
        </tr>";
        $sr++;
    }
    echo "<tr>
        <td colspan='7' class='text-end fw-bold'>Total Accepted Qty (QC)</td>
        <td class='text-success fw-bold'>{$totalAccepted}</td>
        <td></td>
    </tr>";
} else {
    echo "<tr><td colspan='9' class='text-center text-danger fw-bold'>No data found</td></tr>";
}
?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>