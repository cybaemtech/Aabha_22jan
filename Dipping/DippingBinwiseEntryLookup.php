<?php
// Regular page processing starts here
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
// Session handling

if (!isset($_SESSION['operator_id'])) {
    header("Location: ../includes/index.php");
    exit;
}

// Connect DB

include '../Includes/sidebar.php';

// Get record to edit
$editData = null;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $sql = "SELECT * FROM dipping_binwise_entry WHERE id = ?";
    $params = array($id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt && sqlsrv_fetch($stmt)) {
        $editData = [];
        foreach (sqlsrv_field_metadata($stmt) as $field) {
            $colName = $field['Name'];
            $editData[$colName] = sqlsrv_get_field($stmt, array_search($colName, array_column(sqlsrv_field_metadata($stmt), 'Name')));
        }
    }
}

// Handle form submission for updating shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['CONTENT_TYPE'])) {
    $shift = $_POST['shift'];
    $sql = "UPDATE dipping_binwise_entry SET shift = ? WHERE id = ?";
    $params = array($shift, $id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        header("Location: DippingBinwiseEntryLookup.php?updated=1");
        exit;
    }
}

// Statistics queries
$totalEntries = 0;
$todayEntries = 0;
$pendingForward = 0;

$sql1 = "SELECT COUNT(*) as count FROM dipping_binwise_entry WHERE forward_request = 0";
$stmt1 = sqlsrv_query($conn, $sql1);
if ($stmt1 && sqlsrv_fetch($stmt1)) {
    $totalEntries = sqlsrv_get_field($stmt1, 0);
}

$sql2 = "SELECT COUNT(*) as count FROM dipping_binwise_entry WHERE forward_request = 0 AND CAST(entry_date AS DATE) = CAST(GETDATE() AS DATE)";
$stmt2 = sqlsrv_query($conn, $sql2);
if ($stmt2 && sqlsrv_fetch($stmt2)) {
    $todayEntries = sqlsrv_get_field($stmt2, 0);
}

$sql3 = "SELECT COUNT(*) as count FROM dipping_binwise_entry WHERE forward_request = 0";
$stmt3 = sqlsrv_query($conn, $sql3);
if ($stmt3 && sqlsrv_fetch($stmt3)) {
    $pendingForward = sqlsrv_get_field($stmt3, 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Dipping Binwise Entry Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to lookup page */
        .print-btn {
            background: linear-gradient(45deg, #6f42c1, #e83e8c);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(111, 66, 193, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .action-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .print-icon-btn {
            background: linear-gradient(45deg, #6f42c1, #e83e8c);
            border: none;
            color: white;
            padding: 6px 8px;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .print-icon-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(111, 66, 193, 0.4);
            color: white;
        }
        
        .action-icons {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
        }
        
        .action-icons .btn-link {
            padding: 6px;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .action-icons .text-primary:hover {
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd !important;
        }
        
        .action-icons .text-info:hover {
            background: rgba(13, 202, 240, 0.1);
            color: #0dcaf0 !important;
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
        
        .bin-number-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .shift-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .shift-I { background: linear-gradient(45deg, #28a745, #20c997); color: white; }
        .shift-II { background: linear-gradient(45deg, #ffc107, #e0a800); color: white; }
        .shift-III { background: linear-gradient(45deg, #dc3545, #c82333); color: white; }
        
        .weight-value {
            color: #28a745;
            font-weight: 600;
        }
        
        .machine-number {
            font-family: monospace;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-search"></i>
                Dipping Binwise Entry Lookup
            </h1>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['updated'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Record updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $totalEntries; ?></div>
                    <div class="stats-label">Total Entries</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $todayEntries; ?></div>
                    <div class="stats-label">Today's Entries</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $pendingForward; ?></div>
                    <div class="stats-label">Pending Forward</div>
                </div>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <div class="search-title">
                <i class="fas fa-filter"></i>
                Filter Entries
            </div>
            
            <form method="get" action="" id="searchForm">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="fas fa-search input-icon"></i>
                            Lot No. / Machine No.
                        </label>
                        <input type="text" class="form-control" name="search_text" placeholder="Enter lot or machine number" value="<?= isset($_GET['search_text']) ? htmlspecialchars($_GET['search_text']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">
                            <i class="fas fa-clock input-icon"></i>
                            Shift
                        </label>
                        <select class="form-control" name="search_shift">
                            <option value="">All Shifts</option>
                            <option value="I" <?= (isset($_GET['search_shift']) && $_GET['search_shift'] == 'I') ? 'selected' : '' ?>>Shift I</option>
                            <option value="II" <?= (isset($_GET['search_shift']) && $_GET['search_shift'] == 'II') ? 'selected' : '' ?>>Shift II</option>
                            <option value="III" <?= (isset($_GET['search_shift']) && $_GET['search_shift'] == 'III') ? 'selected' : '' ?>>Shift III</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">
                            <i class="fas fa-calendar-alt input-icon"></i>
                            Entry Date
                        </label>
                        <input type="date" class="form-control" name="search_entry_date" value="<?= isset($_GET['search_entry_date']) ? htmlspecialchars($_GET['search_entry_date']) : '' ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Search
                            </button>
                            <?php if (!empty($_GET['search_text']) || !empty($_GET['search_shift']) || !empty($_GET['search_entry_date'])): ?>
                                <a href="DippingBinwiseEntryLookup.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            <?php endif; ?>
                            <a href="DippingBinwiseEntry.php" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>Add New
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Export Buttons -->
        <div class="export-buttons">
            
            <button class="btn print-btn" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i>Export Excel
            </button>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table me-2"></i>Entry Records
                <?php if (!empty($_GET['search_text']) || !empty($_GET['search_shift'])): ?>
                    <span class="badge bg-light text-dark ms-2">Filtered Results</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0" id="entriesTable">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 120px;">
                                    <div class="d-flex align-items-center justify-content-center gap-2">
                                        <input type="checkbox" id="selectAll" />
                                        <span>Select</span>
                                        <i class="fas fa-print text-muted"></i>
                                    </div>
                                </th>
                                <th style="width: 80px;">Sr. No.</th>
                                <th>Bin No</th>
                                <th>Entry Date</th>
                                <th>Shift</th>
                                <th>Lot No</th>
                                <th>Start Time</th>
                                <th>Finish Time</th>
                                <th>Weight (Kg)</th>
                                <th>Avg WT (G)</th>
                                <th>Gross Qty</th>
                                <th>Supervisor</th>
                                <th>Product Type</th>
                                <th>Machine No</th>
                                <th>Product</th>
                                <th class="text-center">Edit</th>
                            </tr>
                        </thead>
                        <tbody>
<?php
// Connect DB (if not already connected)
include '../Includes/db_connect.php';

$where = [];
$params = [];

// Filter logic
if (!empty($_GET['search_text'])) {
    $search = '%' . $_GET['search_text'] . '%';
    $where[] = "(lot_no LIKE ? OR machine_no LIKE ?)";
    $params[] = $search;
    $params[] = $search;
}

if (!empty($_GET['search_shift'])) {
    $where[] = "shift = ?";
    $params[] = $_GET['search_shift'];
}

if (!empty($_GET['search_entry_date'])) {
    $where[] = "CAST(entry_date AS DATE) = ?";
    $params[] = $_GET['search_entry_date'];
}

// Always filter forward_request = 0
$where[] = "forward_request = 0";

// Build SQL
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM dipping_binwise_entry $whereSql ORDER BY id DESC";

// Prepare & execute
$stmt = sqlsrv_prepare($conn, $sql, $params);
if (!$stmt || !sqlsrv_execute($stmt)) {
    echo "<tr><td colspan='16' class='text-danger'>Error executing query.</td></tr>";
    exit;
}

$sr = 1;
$hasRows = false;
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $hasRows = true;
    $shiftClass = 'shift-' . $row['shift'];
    
    // Handle SQLSRV date/time objects
    $entryDate = isset($row['entry_date']) && $row['entry_date'] instanceof DateTime ? $row['entry_date']->format('d-m-Y') : '';
    $binStart = isset($row['bin_start_time']) && $row['bin_start_time'] instanceof DateTime ? $row['bin_start_time']->format('H:i:s') : '';
    $binFinish = isset($row['bin_finish_time']) && $row['bin_finish_time'] instanceof DateTime ? $row['bin_finish_time']->format('H:i:s') : '';
    
    echo "<tr>
        <td class='text-center'>
            <div class='action-cell'>
                <input type='checkbox' class='row-checkbox' value='{$row['id']}' />
                <button type='button' class='print-icon-btn' onclick='printEntryDetails({$row['id']})' title='Print Entry Details'>
                    <i class='fas fa-print'></i>
                </button>
            </div>
        </td>
        <td><strong>{$sr}</strong></td>
        <td><span class='bin-number-badge'>" . htmlspecialchars($row['bin_no']) . "</span></td>
        <td>" . htmlspecialchars($entryDate) . "</td>
        <td><span class='shift-badge {$shiftClass}'>Shift " . htmlspecialchars($row['shift']) . "</span></td>
        <td>" . htmlspecialchars($row['lot_no']) . "</td>
        <td>" . htmlspecialchars($binStart) . "</td>
        <td>" . htmlspecialchars($binFinish) . "</td>
        <td><span class='weight-value'>" . number_format($row['wt_kg'], 2) . "</span></td>
        <td><span class='weight-value'>" . number_format($row['avg_wt'], 2) . "</span></td>
        <td><span class='weight-value'>" . number_format($row['gross'], 2) . "</span></td>
        <td>" . htmlspecialchars($row['supervisor']) . "</td>
        <td>" . htmlspecialchars($row['product_type']) . "</td>
        <td><span class='machine-number'>" . htmlspecialchars($row['machine_no']) . "</span></td>
        <td>" . htmlspecialchars($row['product']) . "</td>
        <td class='text-center'>
            <a href='DippingBinwiseEntry.php?id={$row['id']}' class='btn btn-link text-primary p-0' title='Edit Entry'>
                <i class='fas fa-edit'></i>
            </a>
        </td>
    </tr>";
    $sr++;
}

if (!$hasRows) {
    echo "<tr><td colspan='16' class='text-center text-muted py-4'>
        <i class='fas fa-inbox fa-3x mb-3 d-block'></i>
        <h5>No entries found</h5>
        <p>No entries match your search criteria.</p>
        <a href='DippingBinwiseEntry.php' class='btn btn-success'>
            <i class='fas fa-plus me-2'></i>Add New Entry
        </a>
    </td></tr>";
}
?>

</tbody>

                    </table>
                </div>
            </div>
        </div>

        <!-- Forward Button -->
        <div class="mt-4">
            <button type="button" class="btn btn-success btn-lg" id="forwardSelectedBtn" disabled onclick="return false;">
                <i class="fas fa-arrow-right me-2"></i>Forward Selected
            </button>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store row data for print access
        const rowDataCache = {};
        
        // Function to format date from 'Y-m-d H:i:s' to 'd-m-Y'
        function formatDateForDisplay(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString; // Return original if invalid
            
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}-${month}-${year}`;
        }
        
        // Cache all row data on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add test function to window for debugging
            window.testForwardAPI = async function() {
                try {
                    console.log('Testing forward API...');
                    const response = await fetch('forward_requests', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({forward_ids: [1, 2]})
                    });
                    
                    const text = await response.text();
                    console.log('Test response:', text);
                    
                    const data = JSON.parse(text);
                    console.log('Test parsed:', data);
                    return data;
                } catch (error) {
                    console.error('Test error:', error);
                    return error;
                }
            };
            
            <?php
// Prepare and execute your query again (or reuse if already fetched earlier)
$where = ["forward_request = 0"];
$sql = "SELECT * FROM dipping_binwise_entry WHERE " . implode(" AND ", $where);
$stmt = sqlsrv_query($conn, $sql);

if ($stmt && sqlsrv_has_rows($stmt)) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to string
        foreach (['entry_date', 'bin_start_time', 'bin_finish_time'] as $field) {
            if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                $row[$field] = $row[$field]->format('Y-m-d H:i:s');
            }
        }

        echo "rowDataCache[{$row['id']}] = " . json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";\n";
    }
}
?>

        });

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

        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Checkbox functionality
        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('selectAll');
            const forwardBtn = document.getElementById('forwardSelectedBtn');

            function updateSelectAllState() {
                const checkboxes = document.querySelectorAll('.row-checkbox');
                const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
                
                selectAll.checked = checkboxes.length === checkedBoxes.length && checkboxes.length > 0;
                selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;
                
                // Enable/disable forward button based on selection
                forwardBtn.disabled = checkedBoxes.length === 0;
                
                // Update button text with count
                if (checkedBoxes.length > 0) {
                    forwardBtn.innerHTML = `<i class="fas fa-arrow-right me-2"></i>Forward Selected (${checkedBoxes.length})`;
                } else {
                    forwardBtn.innerHTML = `<i class="fas fa-arrow-right me-2"></i>Forward Selected`;
                }
            }

            if (selectAll) {
                // Toggle all row checkboxes when Select All is clicked
                selectAll.addEventListener('change', function () {
                    const isChecked = this.checked;
                    document.querySelectorAll('.row-checkbox').forEach(cb => {
                        cb.checked = isChecked;
                    });
                    updateSelectAllState();
                });
            }

            // Each row checkbox should update the Select All status
            document.addEventListener('change', function (e) {
                if (e.target.classList.contains('row-checkbox')) {
                    updateSelectAllState();
                }
            });

            // Initialize the correct state on page load
            updateSelectAllState();

            // Handle forward button click - SINGLE CLEAN HANDLER
            document.getElementById('forwardSelectedBtn').onclick = async function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const selected = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
                console.log('Selected entries:', selected);
                
                if (selected.length === 0) {
                    alert('Please select at least one entry to forward.');
                    return false;
                }

                if (!confirm(`Are you sure you want to forward ${selected.length} selected request(s)?`)) {
                    return false;
                }

                // Update button state
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Forwarding...';

                try {
                    console.log('Sending POST request...');
                    
                    const response = await fetch('forward_requests', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({forward_ids: selected})
                    });
                    
                    console.log('Response status:', response.status);
                    console.log('Response ok:', response.ok);
                    
                    const text = await response.text();
                    console.log('Response text:', text);
                    
                    const data = JSON.parse(text);
                    console.log('Parsed data:', data);
                    
                    if (data.success) {
                        alert(`${data.count} request(s) forwarded successfully!`);
                        location.reload();
                    } else {
                        throw new Error(data.message || 'Unknown error');
                    }
                    
                } catch (error) {
                    console.error('Forward error:', error);
                    alert('Error: ' + error.message);
                    
                    // Reset button
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-arrow-right me-2"></i>Forward Selected';
                }
                
                return false;
            };
        });

        // Enhanced Print Functions
        function printTable() {
            const printWindow = window.open('', '_blank');
            const tableContent = document.getElementById('entriesTable').outerHTML;
            
            printWindow.document.write(`
                <html>
                <head>
                    <title>Dipping Binwise Entry Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; font-size: 12px; }
                        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
                        th { background: #f0f0f0; font-weight: bold; }
                        .action-cell, .row-checkbox, .print-icon-btn { display: none; }
                        @media print { .no-print { display: none; } }
                    </style>
                </head>
                <body>
                    <h2>Dipping Binwise Entry Report</h2>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    ${tableContent}
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.print();
        }

        function printEntryDetails(entryId) {
            const data = rowDataCache[entryId];
            if (!data) {
                alert('Entry data not found');
                return;
            }
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Entry Receipt - ${entryId}</title>
                    <style>
                        body { 
                            font-family: 'Courier New', monospace; 
                            margin: 10px; 
                            font-size: 12px;
                            line-height: 1.3;
                            color: #000;
                        }
                        .receipt {
                            width: 300px;
                            margin: 0 auto;
                            border: 1px dashed #000;
                            padding: 15px;
                            background: white;
                        }
                        .header {
                            text-align: center;
                            border-bottom: 1px dashed #000;
                            padding-bottom: 8px;
                            margin-bottom: 10px;
                        }
                        .company {
                            font-weight: bold;
                            font-size: 14px;
                        }
                        .dept {
                            font-size: 10px;
                            margin-top: 2px;
                        }
                        .row {
                            display: flex;
                            justify-content: space-between;
                            margin: 3px 0;
                            border-bottom: 1px dotted #ccc;
                            padding-bottom: 2px;
                        }
                        .label {
                            font-weight: bold;
                            width: 45%;
                        }
                        .value {
                            text-align: right;
                            width: 50%;
                        }
                        .section {
                            margin: 8px 0;
                            padding: 5px 0;
                            border-top: 1px dashed #000;
                        }
                        .section-title {
                            text-align: center;
                            font-weight: bold;
                            font-size: 11px;
                            margin-bottom: 5px;
                            text-decoration: underline;
                        }
                        .footer {
                            text-align: center;
                            font-size: 9px;
                            margin-top: 10px;
                            padding-top: 8px;
                            border-top: 1px dashed #000;
                        }
                        .id-header {
                            text-align: center;
                            font-weight: bold;
                            font-size: 13px;
                            margin-bottom: 8px;
                        }
                        @media print {
                            body { margin: 0; }
                            .receipt { border: 1px solid #000; }
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt">
                        <div class="header">
                            <div class="company">AABHA MFG.</div>
                            <div class="dept">DIPPING DEPT.</div>
                        </div>
                        
                        <div class="id-header">ENTRY ID: ${entryId}</div>
                        
                        <div class="row">
                            <span class="label">Date:</span>
                            <span class="value">${formatDateForDisplay(data.entry_date)}</span>
                        </div>
                        
                        <div class="row">
                            <span class="label">Shift:</span>
                            <span class="value">${data.shift || 'N/A'}</span>
                        </div>
                        
                        <div class="row">
                            <span class="label">Lot No.:</span>
                            <span class="value">${data.lot_no || 'N/A'}</span>
                        </div>
                        
                        <div class="row">
                            <span class="label">Bin No.:</span>
                            <span class="value">${data.bin_no || 'N/A'}</span>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">PRODUCT INFO</div>
                            <div class="row">
                                <span class="label">Type:</span>
                                <span class="value">${data.product_type || 'N/A'}</span>
                            </div>
                            <div class="row">
                                <span class="label">Product:</span>
                                <span class="value">${data.product || 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">MACHINE & TIME</div>
                            <div class="row">
                                <span class="label">M/C No.:</span>
                                <span class="value">${data.machine_no || 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">PRODUCTION</div>
                            <div class="row">
                                <span class="label">Net Wt:</span>
                                <span class="value">${data.wt_kg ? parseFloat(data.wt_kg).toFixed(2) + ' kg' : 'N/A'}</span>
                            </div>
                            <div class="row">
                                <span class="label">Avg Wt:</span>
                                <span class="value">${data.avg_wt ? parseFloat(data.avg_wt).toFixed(2) + ' g' : 'N/A'}</span>
                            </div>
                            <div class="row">
                                <span class="label">Gross:</span>
                                <span class="value">${data.gross ? parseFloat(data.gross).toFixed(2) : 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div class="row">
                            <span class="label">Supervisor:</span>
                            <span class="value">${data.supervisor || 'N/A'}</span>
                        </div>
                        
                        <div class="footer">
                            Generated: ${new Date().toLocaleDateString()}<br>
                            Time: ${new Date().toLocaleTimeString()}<br>
                            System Generated - No Signature Required
                        </div>
                    </div>
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.print();
        }

        function exportToExcel() {
            const table = document.getElementById('entriesTable');
            let csvContent = '';
            
            // Get headers (skip first and last columns - checkbox and actions)
            const headers = table.querySelectorAll('thead th');
            const headerRow = Array.from(headers).slice(1, -1).map(header => header.textContent.trim()).join(',');
            csvContent += headerRow + '\n';
            
            // Get data rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.cells.length > 1) { // Skip no-data row
                    const cells = row.querySelectorAll('td');
                    const rowData = Array.from(cells).slice(1, -1).map(cell => { // Skip checkbox and actions columns
                        return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                    }).join(',');
                    csvContent += rowData + '\n';
                }
            });
            
            // Download file
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'dipping_binwise_entries_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>