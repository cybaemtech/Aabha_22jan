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
    <title>Store Stock Available</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to store stock page */
        .stock-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .stock-info-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stock-info-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        
        .search-title {
            color: #495057;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stock-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-accepted {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-rejected {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        
        .badge-pending {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .material-id-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .ar-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .box-number {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .quantity-accepted {
            color: #28a745;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .quantity-rejected {
            color: #dc3545;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .weight-info {
            color: #6c757d;
            font-weight: 600;
        }
        
        .total-row {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            font-weight: 700;
            border-top: 3px solid #667eea;
        }
        
        .total-row td {
            color: #495057;
            font-size: 1.1rem;
        }
        
        .no-data-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .no-data-icon {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 15px;
        }
        
        .supplier-name {
            font-weight: 600;
            color: #495057;
        }
        
        .material-type-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-raw {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
        }
        
        .badge-packing {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .badge-misc {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }

        /* Enhanced table styling */
        .stock-table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* Custom scrollable table wrapper */
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        /* Sticky header styling */
        .stock-table thead th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 10;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
            padding: 15px 10px;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
        
        /* Custom scrollbar styling */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
   
        .stock-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .stock-table td {
            vertical-align: middle;
            border-color: #e9ecef;
            padding: 12px 10px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .export-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Stock Info Header -->
    <div class="stock-info-card">
        <div class="stock-info-title">
            <i class="fas fa-warehouse"></i>
            Store Stock Available
        </div>
        <div class="stock-info-subtitle">
            View and manage your store inventory with real-time stock levels
        </div>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <div class="search-title">
            <i class="fas fa-search"></i>
            Search Stock Inventory
        </div>
        
        <form class="row g-3" method="get" action="" id="searchForm">
            <div class="col-md-3">
                <label for="searchMaterialId" class="form-label">
                    <i class="fas fa-hashtag input-icon"></i>
                    Material ID
                </label>
                <input type="text" class="form-control" id="searchMaterialId" name="material_id" value="<?= htmlspecialchars($_GET['material_id'] ?? '') ?>" placeholder="Enter material ID">
            </div>
            <div class="col-md-3">
                <label for="searchMaterial" class="form-label">
                    <i class="fas fa-cube input-icon"></i>
                    Material Name
                </label>
                <input type="text" class="form-control" id="searchMaterial" name="material" value="<?= htmlspecialchars($_GET['material'] ?? '') ?>" placeholder="Enter material name">
            </div>
        
            <div class="col-md-3">
                <label for="searchBatchNo" class="form-label">
                    <i class="fas fa-barcode input-icon"></i>
                    Batch Number
                </label>
                <input type="text" class="form-control" id="searchBatchNo" name="batch_no" value="<?= htmlspecialchars($_GET['batch_no'] ?? '') ?>" placeholder="Enter batch number">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="d-flex gap-2 w-100">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                    <a href="StoreStockAvailable.php" class="btn btn-outline-danger flex-fill">
                        <i class="fas fa-times me-2"></i>Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Export Buttons -->
    <div class="export-buttons">
        <button class="btn export-btn" onclick="exportToExcel()">
            <i class="fas fa-file-excel me-2"></i>Export to Excel
        </button>
        <button class="btn export-btn" onclick="printTable()">
            <i class="fas fa-print me-2"></i>Print Report
        </button>
    </div>

    <!-- Stock Table -->
    <div class="card">
        <div class="card-header bg-white border-bottom">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <i class="fas fa-list me-2 text-primary"></i>
                    <h5 class="mb-0 fw-bold">Stock Inventory</h5>
                    <?php if (!empty($_GET['material_id']) || !empty($_GET['material']) || !empty($_GET['batch_no'])): ?>
                        <span class="badge bg-light text-dark ms-2">
                            <i class="fas fa-filter me-1"></i>Search Results Applied
                        </span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i>Scroll to view more data
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 stock-table" id="stockTable">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Sr. No.</th>
                            <th>Material ID</th>
                            <th>Material Description</th>
                            <th>Unit</th>
                            <th>Type</th>
                            <th>Supplier</th>
                            <th>Batch Number</th> <!-- Add this line -->
                            <th>AR Number</th>
                            <th>Accepted Qty</th>
                            <th>Rejected Qty</th>
                            <th>Gross Weight</th>
                            <th>Actual Weight</th>
                            <th>Box Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $where = [];
                        $params = [];
                        if (!empty($_GET['material_id'])) {
                            $where[] = "gq.material_id LIKE ?";
                            $params[] = '%' . $_GET['material_id'] . '%';
                        }
                        if (!empty($_GET['material'])) {
                            $where[] = "gq.material LIKE ?";
                            $params[] = '%' . $_GET['material'] . '%';
                        }
                     
                        if (!empty($_GET['batch_no'])) {
                            $where[] = "gq.batch_no LIKE ?";
                            $params[] = '%' . $_GET['batch_no'] . '%';
                        }
                        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                        $sql = "SELECT 
                                    gq.quantity_id,
                                    gq.material_id,
                                    gq.material,
                                    gq.unit,
                                    gq.material_type,
                                    gq.box_no,
                                    gq.batch_no, -- Add this line
                                    gq.actual_qty,
                                    qc.material_status,
                                    qc.accepted_qty,
                                    qc.ar_no,
                                    ISNULL(wt.total_gross_weight, 0) AS gross_weight,
                                    ISNULL(wt.total_actual_weight, 0) AS actual_weight,
                                    s.supplier_name
                                FROM grn_quantity_details gq
                                LEFT JOIN qc_quantity_details qc ON gq.quantity_id = qc.grn_quantity_id
                                LEFT JOIN materials m ON gq.material_id = m.material_id
                                LEFT JOIN supplier_materials sm ON sm.material_id = m.material_id
                                LEFT JOIN suppliers s ON sm.supplier_id = s.id
                                LEFT JOIN (
                                    SELECT 
                                        grn_header_id,
                                        SUM(gross_weight) AS total_gross_weight,
                                        SUM(actual_weight) AS total_actual_weight
                                    FROM grn_weight_details
                                    GROUP BY grn_header_id
                                ) wt ON gq.grn_header_id = wt.grn_header_id
                                $whereSql
                                ORDER BY gq.quantity_id";

                        $totalAcceptedQty = 0;
                        $totalRejectedQty = 0;
                        $sr = 1;
                        $result = sqlsrv_query($conn, $sql, $params);

                        if ($result && sqlsrv_has_rows($result)) {
                            while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
                                $actualQty = $row['actual_qty'] ?? 0;
                                $acceptedQty = $row['accepted_qty'] ?? 0;
                                $grossWeight = $row['gross_weight'] ?? 0;
                                $actualWeight = $row['actual_weight'] ?? 0;
                                $rejectedQty = $actualQty - $acceptedQty;
                                $arNo = $row['ar_no'] ?? '';

                                // Sum quantities if searching by material_id or material description
                                if (!empty($_GET['material_id']) || !empty($_GET['material'])) {
                                    $totalAcceptedQty += $acceptedQty;
                                    $totalRejectedQty += $rejectedQty;
                                }

                                // Determine material type badge class
                                $type = $row['material_type'];
                                $typeBadgeClass = 'badge-misc';
                                if (strpos(strtolower($type), 'raw') !== false) $typeBadgeClass = 'badge-raw';
                                elseif (strpos(strtolower($type), 'packing') !== false) $typeBadgeClass = 'badge-packing';

                                echo "<tr>
                                    <td><strong>{$sr}</strong></td>
                                    <td>
                                        <span class='material-id-badge'>{$row['material_id']}</span>
                                    </td>
                                    <td>{$row['material']}</td>
                                    <td><strong>{$row['unit']}</strong></td>
                                    <td>
                                        <span class='material-type-badge {$typeBadgeClass}'>
                                            {$type}
                                        </span>
                                    </td>
                                    <td class='supplier-name'>{$row['supplier_name']}</td>
                                    <td>"; // Batch Number column
if ($row['batch_no']) {
    echo "<span class='box-number'>{$row['batch_no']}</span>";
} else {
    echo "<span class='text-muted'>-</span>";
}
echo "</td>
                                    <td>";
if ($arNo) {
    echo "<span class='ar-number'>{$arNo}</span>";
} else {
    echo "<span class='text-muted'>-</span>";
}
echo "</td>
                                    <td class='quantity-accepted'>{$acceptedQty}</td>
                                    <td class='quantity-rejected'>{$rejectedQty}</td>
                                    <td class='weight-info'>{$grossWeight}</td>
                                    <td class='weight-info'>{$actualWeight}</td>
                                    <td>";
if ($row['box_no']) {
    echo "<span class='box-number'>{$row['box_no']}</span>";
} else {
    echo "<span class='text-muted'>-</span>";
}
echo "</td>
                                </tr>";
                                $sr++;
                            }
                            
                            // Show total row if searching by material_id or material description
                            if (!empty($_GET['material_id']) || !empty($_GET['material'])) {
                                echo "<tr class='total-row'>
                                    <td colspan='7' class='text-end'><strong>Total Quantities:</strong></td>
                                    <td class='quantity-accepted'><strong>{$totalAcceptedQty}</strong></td>
                                    <td class='quantity-rejected'><strong>{$totalRejectedQty}</strong></td>
                                    <td colspan='3'></td>
                                </tr>";
                            }
                        } else {
                            echo "<tr>
                                <td colspan='12' class='no-data-message'>
                                    <div>
                                        <i class='fas fa-inbox no-data-icon'></i>
                                        <h5>No Stock Data Found</h5>
                                        <p class='mb-0'>No stock records match your search criteria.</p>
                                    </div>
                                </td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Handle sidebar toggle
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        
        function adjustMainContent() {
            if (sidebar && sidebar.classList.contains('hide')) {
                mainContent.classList.add('sidebar-collapsed');
            } else {
                mainContent.classList.remove('sidebar-collapsed');
            }
        }
        
        adjustMainContent();
        
        if (sidebar) {
            const observer = new MutationObserver(adjustMainContent);
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }
        
        window.addEventListener('resize', adjustMainContent);
    });

    // Export to Excel function
    function exportToExcel() {
        const table = document.getElementById('stockTable');
        let csvContent = '';
        
        // Get headers
        const headers = table.querySelectorAll('thead th');
        const headerRow = Array.from(headers).map(header => header.textContent.trim()).join(',');
        csvContent += headerRow + '\n';
        
        // Get data rows
        const rows = table.querySelectorAll('tbody tr:not(.total-row)');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const rowData = Array.from(cells).map(cell => {
                return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
            }).join(',');
            csvContent += rowData + '\n';
        });
        
        // Download file
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'store_stock_' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    // Print function
    function printTable() {
        const printContent = document.getElementById('stockTable').outerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Store Stock Report</title>
                    <style>
                        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; font-weight: bold; }
                        .total-row { background-color: #f8f9fa; font-weight: bold; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <h2>Store Stock Available Report</h2>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    // Search form enhancement
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        const materialId = document.getElementById('searchMaterialId').value.trim();
        const material = document.getElementById('searchMaterial').value.trim();
        const boxNo = document.getElementById('searchBoxNo').value.trim();
        const batchNo = document.getElementById('searchBatchNo').value.trim();

        if (!materialId && !material && !boxNo && !batchNo) {
            e.preventDefault();
            alert('Please enter at least one search criterion.');
            return false;
        }
    });
</script>

</body>
</html>
