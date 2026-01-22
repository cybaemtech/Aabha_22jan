<?php
include 'Includes/db_connect.php';

echo "<h2>Complete Forward Analysis</h2>";

// Check all batches and their forward status
echo "<h3>All Electronic Batch Entries - Forward Status</h3>";
$allSql = "SELECT batch_number, COUNT(*) as total_entries, 
           SUM(CASE WHEN forward = 1 THEN 1 ELSE 0 END) as forwarded_entries,
           SUM(CASE WHEN forward = 0 THEN 1 ELSE 0 END) as not_forwarded_entries,
           SUM(CASE WHEN forward = 1 THEN pass_kg ELSE 0 END) as forwarded_pass_kg,
           SUM(CASE WHEN forward = 0 THEN pass_kg ELSE 0 END) as not_forwarded_pass_kg,
           SUM(pass_kg) as total_pass_kg
           FROM electronic_batch_entry 
           WHERE batch_number IS NOT NULL
           GROUP BY batch_number
           ORDER BY batch_number";

$allStmt = sqlsrv_query($conn, $allSql);
if ($allStmt) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr>";
    echo "<th>Batch</th>";
    echo "<th>Total Entries</th>";
    echo "<th>Forwarded</th>";
    echo "<th>Not Forwarded</th>";
    echo "<th>Forwarded Pass KG</th>";
    echo "<th>Not Forwarded Pass KG</th>";
    echo "<th>Total Pass KG</th>";
    echo "</tr>";
    
    while ($row = sqlsrv_fetch_array($allStmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['batch_number']}</td>";
        echo "<td>{$row['total_entries']}</td>";
        echo "<td style='color: green; font-weight: bold;'>{$row['forwarded_entries']}</td>";
        echo "<td style='color: red;'>{$row['not_forwarded_entries']}</td>";
        echo "<td style='color: green; font-weight: bold;'>{$row['forwarded_pass_kg']}</td>";
        echo "<td style='color: red;'>{$row['not_forwarded_pass_kg']}</td>";
        echo "<td>{$row['total_pass_kg']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    sqlsrv_free_stmt($allStmt);
}

// Now check sealing totals for each batch
echo "<h3>Sealing Entry Totals by Batch</h3>";
$sealSql = "SELECT batch_no, COUNT(*) as sealing_entries, SUM(seal_gross) as total_seal_gross
            FROM sealing_entry 
            WHERE batch_no IS NOT NULL
            GROUP BY batch_no
            ORDER BY batch_no";

$sealStmt = sqlsrv_query($conn, $sealSql);
if ($sealStmt) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Batch</th><th>Sealing Entries</th><th>Total Seal Gross</th></tr>";
    
    while ($row = sqlsrv_fetch_array($sealStmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['batch_no']}</td>";
        echo "<td>{$row['sealing_entries']}</td>";
        echo "<td>{$row['total_seal_gross']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    sqlsrv_free_stmt($sealStmt);
}

// Combined summary
echo "<h3>Batch Summary - Electronic vs Sealing</h3>";
$combinedSql = "SELECT 
    e.batch_number,
    e.forwarded_pass_kg,
    e.not_forwarded_pass_kg,
    e.total_pass_kg,
    COALESCE(s.total_seal_gross, 0) as total_seal_gross,
    (e.forwarded_pass_kg - COALESCE(s.total_seal_gross, 0)) as remaining_available,
    CASE 
        WHEN e.forwarded_pass_kg > 0 THEN 
            ROUND((COALESCE(s.total_seal_gross, 0) / e.forwarded_pass_kg) * 100, 2)
        ELSE 0 
    END as completion_percentage
FROM (
    SELECT batch_number,
           SUM(CASE WHEN forward = 1 THEN pass_kg ELSE 0 END) as forwarded_pass_kg,
           SUM(CASE WHEN forward = 0 THEN pass_kg ELSE 0 END) as not_forwarded_pass_kg,
           SUM(pass_kg) as total_pass_kg
    FROM electronic_batch_entry 
    WHERE batch_number IS NOT NULL
    GROUP BY batch_number
) e
LEFT JOIN (
    SELECT batch_no, SUM(seal_gross) as total_seal_gross
    FROM sealing_entry 
    WHERE batch_no IS NOT NULL
    GROUP BY batch_no
) s ON e.batch_number = s.batch_no
ORDER BY e.batch_number";

$combinedStmt = sqlsrv_query($conn, $combinedSql);
if ($combinedStmt) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr>";
    echo "<th>Batch</th>";
    echo "<th>Forwarded Pass KG</th>";
    echo "<th>Total Seal Gross</th>";
    echo "<th>Remaining</th>";
    echo "<th>Completion %</th>";
    echo "<th>Status</th>";
    echo "</tr>";
    
    while ($row = sqlsrv_fetch_array($combinedStmt, SQLSRV_FETCH_ASSOC)) {
        $remaining = $row['remaining_available'];
        $completion = $row['completion_percentage'];
        
        $status = '';
        $rowColor = '';
        if ($remaining < 0) {
            $status = 'OVER LIMIT';
            $rowColor = 'background-color: #ffebee;'; // Light red
        } elseif ($completion >= 100) {
            $status = 'COMPLETED';
            $rowColor = 'background-color: #e8f5e8;'; // Light green
        } elseif ($completion >= 80) {
            $status = 'NEAR COMPLETE';
            $rowColor = 'background-color: #fff3cd;'; // Light yellow
        } else {
            $status = 'IN PROGRESS';
            $rowColor = 'background-color: #cff4fc;'; // Light blue
        }
        
        echo "<tr style='$rowColor'>";
        echo "<td><strong>{$row['batch_number']}</strong></td>";
        echo "<td style='color: green; font-weight: bold;'>{$row['forwarded_pass_kg']}</td>";
        echo "<td style='color: blue; font-weight: bold;'>{$row['total_seal_gross']}</td>";
        echo "<td style='font-weight: bold; color: " . ($remaining < 0 ? 'red' : 'green') . ";'>{$remaining}</td>";
        echo "<td style='font-weight: bold;'>{$completion}%</td>";
        echo "<td style='font-weight: bold;'>{$status}</td>";
        echo "</tr>";
    }
    echo "</table>";
    sqlsrv_free_stmt($combinedStmt);
}

echo "<br><br><strong>Legend:</strong><br>";
echo "• <span style='color: green;'>Forwarded Pass KG</span>: Available for sealing (forward = 1)<br>";
echo "• <span style='color: blue;'>Total Seal Gross</span>: Already sealed<br>";
echo "• <span style='color: green;'>Remaining</span>: Available - Used<br>";
echo "• <span style='color: red;'>Negative Remaining</span>: Over limit (sealed more than available)<br>";

echo "<br><br><a href='Sealing/sealing.php'>Test Sealing Form</a>";
?>