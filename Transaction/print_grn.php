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

if (!isset($_GET['grn_id']) || empty($_GET['grn_id'])) {
    die('GRN ID is required');
}

$grn_header_id = intval($_GET['grn_id']);

try {
    // Fetch GRN Header with minimal gate entry data
    $headerQuery = "
        SELECT 
            gh.grn_header_id,
            gh.grn_date,
            gh.grn_no,
            gh.po_no,
            gh.tear_damage_leak,
            gh.damage_remark,
            gh.labeling,
            gh.labeling_remark,
            gh.packing,
            gh.packing_remark,
            gh.cert_analysis,
            gh.cert_analysis_remark,
            gh.created_at,
            g.id as gate_entry_id,
            g.invoice_number,
            g.vehicle_number,
            g.entry_date,
            s.supplier_name
        FROM grn_header gh
        LEFT JOIN gate_entries g ON gh.gate_entry_id = g.id
        LEFT JOIN suppliers s ON g.supplier_id = s.id
        WHERE gh.grn_header_id = ? AND (gh.delete_id IS NULL OR gh.delete_id = 0)
    ";
    $params = [$grn_header_id];
    $stmt = sqlsrv_query($conn, $headerQuery, $params);
    if ($stmt === false) {
        die('Database error: ' . print_r(sqlsrv_errors(), true));
    }
    $header = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$header) {
        die('GRN not found or has been deleted');
    }

    // Fetch Quantity Details
    $quantityQuery = "SELECT * FROM grn_quantity_details WHERE grn_header_id = ? ORDER BY quantity_id";
    $stmtQty = sqlsrv_query($conn, $quantityQuery, $params);
    $quantityDetails = [];
    while ($row = sqlsrv_fetch_array($stmtQty, SQLSRV_FETCH_ASSOC)) {
        $quantityDetails[] = $row;
    }

    // Fetch Weight Details
    $weightQuery = "SELECT * FROM grn_weight_details WHERE grn_header_id = ? ORDER BY weight_id";
    $stmtWeight = sqlsrv_query($conn, $weightQuery, $params);
    $weightDetails = [];
    while ($row = sqlsrv_fetch_array($stmtWeight, SQLSRV_FETCH_ASSOC)) {
        $weightDetails[] = $row;
    }

    // Calculate totals
    $totalOrderedQty = 0;
    $totalActualQty = 0;
    $totalGrossWeight = 0;
    $totalActualWeight = 0;

    foreach ($quantityDetails as $qty) {
        $totalOrderedQty += floatval($qty['ordered_qty']);
        $totalActualQty += floatval($qty['actual_qty']);
    }

    foreach ($weightDetails as $weight) {
        $totalGrossWeight += floatval($weight['gross_weight']);
        $totalActualWeight += floatval($weight['actual_weight']);
    }

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Helper function to format status
function formatStatus($status) {
    if (!$status) return '<span style="color: #666;">N/A</span>';
    
    $statusLower = strtolower($status);
    $color = '#666';
    $bgColor = '#f8f9fa';
    
    if (strpos($statusLower, 'good') !== false || strpos($statusLower, 'ok') !== false) {
        $color = '#155724';
        $bgColor = '#d4edda';
    } elseif (strpos($statusLower, 'poor') !== false || strpos($statusLower, 'damaged') !== false) {
        $color = '#721c24';
        $bgColor = '#f8d7da';
    } elseif (strpos($statusLower, 'available') !== false) {
        $color = '#155724';
        $bgColor = '#d4edda';
    } elseif (strpos($statusLower, 'not available') !== false) {
        $color = '#856404';
        $bgColor = '#fff3cd';
    }
    
    return "<span style=\"background-color: {$bgColor}; color: {$color}; padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: bold;\">{$status}</span>";
}

$currentDate = date('d/m/Y');
$currentTime = date('h:i A');
?>
<!DOCTYPE html>
<html>
<head>
    <title>GRN Print - <?php echo htmlspecialchars($header['grn_no']); ?></title>
    <style>
        @page {
            size: A4;
            margin: 0.5in;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 20px;
        }
        
        .print-header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        
        .print-header h1 {
            font-size: 24px;
            font-weight: bold;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            color: #2c3e50;
        }
        
        .company-info {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .header-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .grn-number {
            font-size: 18px;
            font-weight: bold;
            color: #e74c3c;
        }
        
        .print-date {
            font-size: 12px;
            color: #666;
        }
        
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section-title {
            background-color: #34495e;
            color: white;
            padding: 10px 15px;
            margin: 0 0 15px 0;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        .info-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-cell {
            display: table-cell;
            width: 25%;
            padding: 12px;
            border: 1px solid #000;
            vertical-align: top;
        }
        
        .info-label {
            font-weight: bold;
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 12px;
            font-weight: bold;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .table th,
        .table td {
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: left;
            vertical-align: top;
            font-size: 10px;
        }
        
        .table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
            font-size: 11px;
        }
        
        .table .text-right {
            text-align: right;
        }
        
        .table .text-center {
            text-align: center;
        }
        
        .total-row {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        .signatures {
            margin-top: 40px;
            display: table;
            width: 100%;
            page-break-inside: avoid;
        }
        
        .signature-cell {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding: 20px 10px;
            border: 1px solid #000;
            height: 80px;
            vertical-align: bottom;
        }
        
        .signature-line {
            border-top: 2px solid #000;
            margin-top: 50px;
            padding-top: 10px;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <!-- Print Header -->
    <div class="print-header">
        <h1>Goods Received Note</h1>
        <div class="company-info">
            <strong>Aabha Contraceptive</strong><br>
        </div>
        <div class="header-info">
            <div class="grn-number">GRN No: <?php echo htmlspecialchars($header['grn_no']); ?></div>
            <div class="print-date">
                <strong>Print Date:</strong> <?php echo $currentDate; ?><br>
                <strong>Print Time:</strong> <?php echo $currentTime; ?>
            </div>
        </div>
    </div>

    <!-- 1. GRN INFORMATION -->
    <div class="section">
        <div class="section-title">📋 GRN Information</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-cell">
                    <div class="info-label">GRN ID</div>
                    <div class="info-value"><?php echo $header['grn_header_id']; ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">GRN Date</div>
                    <div class="info-value">
                        <?php
                        if (!empty($header['grn_date'])) {
                            if ($header['grn_date'] instanceof DateTime) {
                                echo $header['grn_date']->format('d/m/Y');
                            } else {
                                echo htmlspecialchars($header['grn_date']);
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
                <div class="info-cell">
                    <div class="info-label">PO Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($header['po_no']) ?: 'N/A'; ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Gate Entry ID</div>
                    <div class="info-value"><?php echo $header['gate_entry_id']; ?></div>
                </div>
            </div>
            <div class="info-row">
                <div class="info-cell">
                    <div class="info-label">Invoice Number</div>
                    <div class="info-value" style="color: #e74c3c;"><?php echo htmlspecialchars($header['invoice_number']); ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Supplier Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($header['supplier_name']); ?></div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Entry Date</div>
                    <div class="info-value">
                        <?php
                        if (!empty($header['entry_date'])) {
                            if ($header['entry_date'] instanceof DateTime) {
                                echo $header['entry_date']->format('d/m/Y');
                            } else {
                                echo htmlspecialchars($header['entry_date']);
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
                <div class="info-cell">
                    <div class="info-label">Vehicle Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($header['vehicle_number']) ?: 'N/A'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. VISUAL INSPECTION -->
    <div class="section">
        <div class="section-title">🔍 Visual Inspection</div>
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 25%;">Inspection Type</th>
                    <th style="width: 20%;">Status</th>
                    <th style="width: 55%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Tear/Damage/Leak</strong></td>
                    <td><?php echo formatStatus($header['tear_damage_leak']); ?></td>
                    <td><?php echo htmlspecialchars($header['damage_remark']) ?: '<em style="color: #666;">No remarks</em>'; ?></td>
                </tr>
                <tr>
                    <td><strong>Labeling</strong></td>
                    <td><?php echo formatStatus($header['labeling']); ?></td>
                    <td><?php echo htmlspecialchars($header['labeling_remark']) ?: '<em style="color: #666;">No remarks</em>'; ?></td>
                </tr>
                <tr>
                    <td><strong>Packing</strong></td>
                    <td><?php echo formatStatus($header['packing']); ?></td>
                    <td><?php echo htmlspecialchars($header['packing_remark']) ?: '<em style="color: #666;">No remarks</em>'; ?></td>
                </tr>
                <tr>
                    <td><strong>Certificate Analysis</strong></td>
                    <td><?php echo formatStatus($header['cert_analysis']); ?></td>
                    <td><?php echo htmlspecialchars($header['cert_analysis_remark']) ?: '<em style="color: #666;">No remarks</em>'; ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- 3. ITEM WEIGHT DETAILS -->
    <div class="section">
        <div class="section-title">⚖️ Item Weight Details</div>
        <?php if (!empty($weightDetails)): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Sr.</th>
                    <th>Drum Number</th>
                    <th>Gross Weight (kg)</th>
                    <th>Actual Weight (kg)</th>
                    <th>Difference (kg)</th>
                    <th>Checked By</th>
                    <th>Verified By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weightDetails as $index => $weight): ?>
                <?php 
                $difference = floatval($weight['gross_weight']) - floatval($weight['actual_weight']);
                $diffColor = $difference > 0 ? '#dc3545' : ($difference < 0 ? '#28a745' : '#666');
                ?>
                <tr>
                    <td class="text-center"><?php echo $index + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($weight['drum_number']); ?></strong></td>
                    <td class="text-right"><?php echo number_format($weight['gross_weight'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($weight['actual_weight'], 2); ?></td>
                    <td class="text-right" style="color: <?php echo $diffColor; ?>; font-weight: bold;">
                        <?php echo number_format($difference, 2); ?>
                    </td>
                    <td><?php echo htmlspecialchars($weight['checked_by']) ?: '-'; ?></td>
                    <td><?php echo htmlspecialchars($weight['verified_by']) ?: '-'; ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2" class="text-right"><strong>TOTAL:</strong></td>
                    <td class="text-right"><strong><?php echo number_format($totalGrossWeight, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($totalActualWeight, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($totalGrossWeight - $totalActualWeight, 2); ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-data">No weight details available for this GRN.</div>
        <?php endif; ?>
    </div>

    <!-- 4. QUANTITY VERIFICATION -->
    <div class="section">
        <div class="section-title">📦 Quantity Verification</div>
        <?php if (!empty($quantityDetails)): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Sr.</th>
                    <th>Material ID</th>
                    <th>Material Name</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th>Batch No</th>
                    <th>Box No</th>
                    <th>Packing</th>
                    <th>Ordered</th>
                    <th>Actual</th>
                    <th>Checked By</th>
                    <th>Verified By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quantityDetails as $index => $qty): ?>
                <tr>
                    <td class="text-center"><?php echo $index + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($qty['material_id']); ?></strong></td>
                    <td><?php echo htmlspecialchars($qty['material']); ?></td>
                    <td><?php echo htmlspecialchars($qty['material_type']); ?></td>
                    <td><?php echo htmlspecialchars($qty['unit']) ?: '-'; ?></td>
                    <td><?php echo htmlspecialchars($qty['batch_no']) ?: '-'; ?></td>
                    <td><?php echo htmlspecialchars($qty['box_no']) ?: '-'; ?></td>
                    <td><?php echo htmlspecialchars($qty['packing_details']); ?></td>
                    <td class="text-right"><?php echo $qty['ordered_qty']; ?></td>
                    <td class="text-right"><?php echo $qty['actual_qty']; ?></td>
                    <td><?php echo htmlspecialchars($qty['checked_by']); ?></td>
                    <td><?php echo htmlspecialchars($qty['verified_by']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="8" class="text-right"><strong>TOTAL:</strong></td>
                    <td class="text-right"><strong><?php echo number_format($totalOrderedQty, 2); ?></strong></td>
                    <td class="text-right"><strong><?php echo number_format($totalActualQty, 2); ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-data">No quantity verification data available for this GRN.</div>
        <?php endif; ?>
    </div>

    <!-- 5. Prepared By : Received By : -->
    <div class="section" style="margin-top:30px;">
        <table style="width:100%;border:none;">
            <tr>
                <td style="width:50%;text-align:left;font-weight:bold;">Prepared By :</td>
                <td style="width:50%;text-align:right;font-weight:bold;">Received By :</td>
            </tr>
        </table>
    </div>

    <!-- Footer -->


    <script>
        // Auto-trigger print dialog when page loads
        window.onload = function() {
            window.print();
        };
        
        // Close window after printing (optional)
        window.onafterprint = function() {
            window.close();
        };
    </script>
</body>
</html>