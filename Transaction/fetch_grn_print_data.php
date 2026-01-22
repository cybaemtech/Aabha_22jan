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
include '../../Includes/db_connect.php';

if (isset($_POST['grn_header_id'])) {
    $grn_header_id = intval($_POST['grn_header_id']);
    
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
        
        $stmt = $conn->prepare($headerQuery);
        $stmt->bind_param("i", $grn_header_id);
        $stmt->execute();
        $headerResult = $stmt->get_result();
        $header = $headerResult->fetch_assoc();
        
        if (!$header) {
            echo json_encode(['success' => false, 'message' => 'GRN not found or has been deleted']);
            exit;
        }
        
        // Fetch Quantity Details
        $quantityQuery = "SELECT * FROM grn_quantity_details WHERE grn_header_id = ? ORDER BY quantity_id";
        $stmt = $conn->prepare($quantityQuery);
        $stmt->bind_param("i", $grn_header_id);
        $stmt->execute();
        $quantityResult = $stmt->get_result();
        $quantityDetails = [];
        while ($row = $quantityResult->fetch_assoc()) {
            $quantityDetails[] = $row;
        }
        
        // Fetch Weight Details
        $weightQuery = "SELECT * FROM grn_weight_details WHERE grn_header_id = ? ORDER BY weight_id";
        $stmt = $conn->prepare($weightQuery);
        $stmt->bind_param("i", $grn_header_id);
        $stmt->execute();
        $weightResult = $stmt->get_result();
        $weightDetails = [];
        while ($row = $weightResult->fetch_assoc()) {
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
        
        // Prepare response data
        $responseData = [
            'header' => $header,
            'quantity_details' => $quantityDetails,
            'weight_details' => $weightDetails,
            'totals' => [
                'total_ordered_qty' => number_format($totalOrderedQty, 2),
                'total_actual_qty' => number_format($totalActualQty, 2),
                'total_gross_weight' => number_format($totalGrossWeight, 2),
                'total_actual_weight' => number_format($totalActualWeight, 2),
                'weight_difference' => number_format($totalGrossWeight - $totalActualWeight, 2)
            ]
        ];
        
        echo json_encode(['success' => true, 'data' => $responseData]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'GRN Header ID is required']);
}
?>

<style>
/* Enhanced Print Styles for A4 Format */
@media print {
    @page {
        size: A4;
        margin: 0.5in;
    }
    
    body {
        font-family: Arial, sans-serif;
        font-size: 11px;
        line-height: 1.3;
        color: #000;
    }
    
    .modal-header, .modal-footer, .d-print-none, .btn, .modal-backdrop {
        display: none !important;
    }
    
    .modal-dialog {
        max-width: 100% !important;
        margin: 0 !important;
    }
    
    .modal-content {
        border: none !important;
        box-shadow: none !important;
        height: auto !important;
    }
    
    .print-header {
        text-align: center;
        border-bottom: 3px solid #000;
        padding-bottom: 15px;
        margin-bottom: 20px;
        page-break-after: avoid;
    }
    
    .print-header h2 {
        font-size: 20px;
        font-weight: bold;
        margin: 0 0 10px 0;
        text-transform: uppercase;
    }
    
    .company-info {
        margin-bottom: 15px;
        text-align: center;
    }
    
    .print-section {
        margin-bottom: 15px;
        page-break-inside: avoid;
    }
    
    .print-section h6 {
        background-color: #f0f0f0 !important;
        padding: 8px 10px;
        border: 2px solid #000;
        margin-bottom: 10px;
        font-weight: bold;
        font-size: 12px;
        text-transform: uppercase;
    }
    
    .print-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
        font-size: 10px;
    }
    
    .print-table th,
    .print-table td {
        border: 1px solid #000;
        padding: 6px 4px;
        text-align: left;
        vertical-align: top;
    }
    
    .print-table th {
        background-color: #e0e0e0 !important;
        font-weight: bold;
        text-align: center;
    }
    
    .info-grid {
        display: table;
        width: 100%;
        margin-bottom: 15px;
    }
    
    .info-row {
        display: table-row;
    }
    
    .info-item {
        display: table-cell;
        width: 25%;
        padding: 8px;
        border: 1px solid #000;
        vertical-align: top;
    }
    
    .info-label {
        font-weight: bold;
        font-size: 10px;
        margin-bottom: 3px;
        text-transform: uppercase;
    }
    
    .info-value {
        font-size: 11px;
        word-wrap: break-word;
    }
    
    .signature-section {
        margin-top: 30px;
        page-break-inside: avoid;
    }
    
    .signature-box {
        display: table-cell;
        width: 33.33%;
        text-align: center;
        padding: 20px 10px;
        border: 1px solid #000;
        height: 60px;
        vertical-align: bottom;
    }
    
    .total-row {
        background-color: #f0f0f0 !important;
        font-weight: bold;
    }
    
    .page-break {
        page-break-before: always;
    }
}

/* Screen styles for modal preview */
.print-header {
    text-align: center;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 20px;
    margin-bottom: 20px;
}

.print-header h2 {
    color: #2c3e50;
    margin-bottom: 10px;
}

.company-info {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.print-section {
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
    border-radius: 5px;
    overflow: hidden;
}

.print-section h6 {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 12px 15px;
    margin: 0 0 15px 0;
    font-weight: 600;
    font-size: 14px;
}

.print-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 15px;
}

.print-table th,
.print-table td {
    border: 1px solid #dee2e6;
    padding: 10px 8px;
    text-align: left;
}

.print-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    text-align: center;
}

.print-table tr:nth-child(even) {
    background-color: #f9f9f9;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1px;
    background-color: #dee2e6;
    border: 1px solid #dee2e6;
    margin-bottom: 20px;
}

.info-item {
    padding: 12px;
    background-color: white;
}

.info-label {
    font-weight: 600;
    color: #495057;
    font-size: 0.85rem;
    margin-bottom: 4px;
}

.info-value {
    color: #212529;
    font-size: 0.95rem;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-good { background-color: #d4edda; color: #155724; }
.status-poor { background-color: #f8d7da; color: #721c24; }
.status-damaged { background-color: #f8d7da; color: #721c24; }
.status-available { background-color: #d4edda; color: #155724; }
.status-not-available { background-color: #fff3cd; color: #856404; }

.signature-section {
    margin-top: 40px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.signature-box {
    text-align: center;
    padding: 30px 15px;
    border: 2px solid #dee2e6;
    border-radius: 5px;
    background-color: #f8f9fa;
}

.total-row {
    background-color: #e9ecef !important;
    font-weight: bold;
}
</style>