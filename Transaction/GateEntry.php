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

// Set timezone to Asia/Kolkata for all date/time operations
date_default_timezone_set('Asia/Kolkata');

// --- MOVE ALL FORM HANDLING AND REDIRECTS HERE ---
// Handle Add/Edit Form and form submit logic
$isEdit = isset($_GET['edit']);
$entry = [
    'id' => '',
    'entry_date' => date('Y-m-d'),
    // Set default entry_time as per Asia/Kolkata
    'entry_time' => date('H:i'),
    'invoice_number' => '',
    'invoice_date' => date('Y-m-d'),
    'vehicle_number' => '',
    'transporter' => '',
    'supplier_id' => '',
    'no_of_package' => '',
    'remark' => ''
];

// Fetch suppliers for dropdown (SQLSRV)
$suppliersStmt = sqlsrv_query($conn, "SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
$suppliers = [];
if ($suppliersStmt) {
    while ($sup = sqlsrv_fetch_array($suppliersStmt, SQLSRV_FETCH_ASSOC)) {
        $suppliers[] = $sup;
    }
    sqlsrv_free_stmt($suppliersStmt);
}

// If editing, fetch existing data (SQLSRV)
if ($isEdit) {
    $stmt = sqlsrv_query($conn, "SELECT * FROM gate_entries WHERE id = ?", array($_GET['edit']));
    $editEntry = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($editEntry) {
        // Overwrite $entry with fetched data for edit case
        foreach ($entry as $key => $val) {
            if (isset($editEntry[$key])) {
                // Handle DateTime objects from SQLSRV
                if ($editEntry[$key] instanceof DateTime) {
                    if (strpos($key, 'date') !== false) {
                        $entry[$key] = $editEntry[$key]->format('Y-m-d');
                    } elseif (strpos($key, 'time') !== false) {
                        $entry[$key] = $editEntry[$key]->format('H:i');
                    }
                } else {
                    $entry[$key] = $editEntry[$key];
                }
            }
        }
        $entry['id'] = $editEntry['id'];
    } else {
        header("Location: GateEntryLookup.php");
        exit;
    }
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entry_date = $_POST['entry_date'];
    $entry_time = $_POST['entry_time'];
    $invoice_number = trim($_POST['invoice_number']);
    $invoice_date = $_POST['invoice_date'];
    $vehicle_number = trim($_POST['vehicle_number']);
    $transporter = trim($_POST['transporter']);
    $supplier_id = intval($_POST['supplier_id']);
    $no_of_package = intval($_POST['no_of_package']);
    $remark = trim($_POST['remark']);

    // Validation
    if (empty($invoice_number)) {
        $_SESSION['error'] = "Invoice number is required!";
    } elseif (empty($supplier_id)) {
        $_SESSION['error'] = "Supplier is required!";
    } elseif (empty($no_of_package)) {
        $_SESSION['error'] = "Number of packages is required!";
    } else {
        if ($isEdit) {
            $updateSql = "UPDATE gate_entries SET entry_date=?, entry_time=?, invoice_number=?, invoice_date=?, vehicle_number=?, transporter=?, supplier_id=?, no_of_package=?, remark=? WHERE id=?";
            $updateParams = array($entry_date, $entry_time, $invoice_number, $invoice_date, $vehicle_number, $transporter, $supplier_id, $no_of_package, $remark, $entry['id']);
            $stmt = sqlsrv_query($conn, $updateSql, $updateParams);

            if ($stmt) {
                $_SESSION['message'] = "Gate entry updated successfully!";
                header("Location: GateEntryLookup.php?updated=1");
                exit;
            } else {
                $_SESSION['error'] = "Error updating gate entry!";
            }
        } else {
            // Check for duplicate invoice number
            $checkSql = "SELECT COUNT(*) as count FROM gate_entries WHERE invoice_number = ? AND is_deleted = 0";
            $checkStmt = sqlsrv_query($conn, $checkSql, array($invoice_number));
            $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

            if ($checkRow['count'] > 0) {
                $_SESSION['error'] = "Invoice number already exists!";
            } else {
                // Provide a value for gate_entry_id (use next available integer)
                $getMaxIdSql = "SELECT ISNULL(MAX(CAST(gate_entry_id AS INT)), 0) AS max_id FROM gate_entries";
                $maxIdStmt = sqlsrv_query($conn, $getMaxIdSql);
                $maxIdRow = sqlsrv_fetch_array($maxIdStmt, SQLSRV_FETCH_ASSOC);
                $nextGateEntryId = $maxIdRow['max_id'] + 1;

                $insertSql = "INSERT INTO gate_entries (entry_date, entry_time, invoice_number, invoice_date, vehicle_number, transporter, supplier_id, no_of_package, gate_entry_id, remark, created_at, delete_id, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 0, 0)";
                $insertParams = array($entry_date, $entry_time, $invoice_number, $invoice_date, $vehicle_number, $transporter, $supplier_id, $no_of_package, $nextGateEntryId, $remark);
                $stmt = sqlsrv_query($conn, $insertSql, $insertParams);

                if ($stmt) {
                    $_SESSION['message'] = "Gate entry added successfully!";
                    header("Location: GateEntryLookup.php?added=1");
                    exit;
                } else {
                    $errors = sqlsrv_errors();
                    $_SESSION['error'] = "Error adding gate entry! " . ($errors ? $errors[0]['message'] : '');
                }
            }
        }
    }
}

// Only include sidebar and output HTML after all possible redirects
include '../Includes/sidebar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Gate Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .form-container {
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
            background: #ffffff;
        }

        .card-header {
            background: #4f42c1;
            color: white;
            padding: 24px;
            font-size: 1.25rem;
            font-weight: 600;
            border: none;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-body {
            padding: 30px 40px;
        }

        .form-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .form-section:hover {
            border-color: #4f42c1;
            box-shadow: 0 4px 12px rgba(79, 66, 193, 0.05);
        }

        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-title i {
            color: #4f42c1;
        }

        .form-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            transition: all 0.2s;
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #4f42c1;
            box-shadow: 0 0 0 4px rgba(79, 66, 193, 0.1);
        }

        .readonly-field {
            background-color: #f1f5f9 !important;
            border-color: #e2e8f0 !important;
            color: #64748b;
        }

        .current-time-badge {
            background: rgba(79, 66, 193, 0.1);
            color: #4f42c1;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 8px;
        }

        .form-actions {
            margin-top: 40px;
            display: flex;
            justify-content: flex-end;
            gap: 16px;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #4f42c1;
            border: none;
            box-shadow: 0 4px 12px rgba(79, 66, 193, 0.2);
        }

        .btn-primary:hover {
            background: #4338ca;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(79, 66, 193, 0.3);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .help-text {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .required {
            color: #ef4444;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-sign-in-alt"></i>
            <?php echo $isEdit ? 'Edit' : 'Add New'; ?> Gate Entry
        </h1>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Gate Entry Form -->
    <div class="form-container">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?>"></i>
                <?php echo $isEdit ? 'Edit' : 'Add New'; ?> Gate Entry
            </div>
            <div class="card-body">
                <form method="post" id="gateEntryForm" autocomplete="off">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Entry Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calendar input-icon"></i>
                                    Entry Date
                                </label>
                                <input type="date" class="form-control readonly-field" name="entry_date" value="<?php echo htmlspecialchars($entry['entry_date']); ?>">
                                <div class="help-text">Entry date is automatically/manually set to today</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-clock input-icon"></i>
                                    Entry Time <span class="current-time-badge">Current: <?php echo date('H:i'); ?> (Asia/Kolkata)</span>
                                </label>
                                <input type="time" class="form-control" name="entry_time" value="<?php echo htmlspecialchars($entry['entry_time']); ?>" required>
                                <div class="help-text">Entry time (Asia/Kolkata). You can manually change this value.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-file-invoice"></i>
                            Invoice Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-hashtag input-icon"></i>
                                    Invoice Number <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" name="invoice_number" value="<?php echo htmlspecialchars($entry['invoice_number']); ?>" required placeholder="Enter invoice number">
                                <div class="help-text">Enter the supplier's invoice number</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-calendar-check input-icon"></i>
                                    Invoice Date <span class="required">*</span>
                                </label>
                                <input type="date" class="form-control" name="invoice_date" value="<?php echo htmlspecialchars($entry['invoice_date']); ?>" required>
                                <div class="help-text">Date mentioned on the invoice</div>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicle & Transport Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-truck"></i>
                            Vehicle & Transport Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-truck input-icon"></i>
                                    Vehicle Number
                                </label>
                                <input type="text" class="form-control" name="vehicle_number" value="<?php echo htmlspecialchars($entry['vehicle_number']); ?>" placeholder="Enter vehicle number" style="text-transform: uppercase;">
                                <div class="help-text">Vehicle registration number</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-shipping-fast input-icon"></i>
                                    Transporter
                                </label>
                                <input type="text" class="form-control" name="transporter" value="<?php echo htmlspecialchars($entry['transporter']); ?>" placeholder="Enter transporter name">
                                <div class="help-text">Name of the transport company</div>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier & Package Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-building"></i>
                            Supplier & Package Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-building input-icon"></i>
                                    Supplier <span class="required">*</span>
                                </label>
                                <select class="form-select" name="supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach($suppliers as $sup): ?>
                                        <option value="<?php echo $sup['id']; ?>" <?php if($sup['id']==$entry['supplier_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">Select the supplier from approved list</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-boxes input-icon"></i>
                                    Number of Packages Received <span class="required">*</span>
                                </label>
                                <input type="number" class="form-control" name="no_of_package" value="<?php echo htmlspecialchars($entry['no_of_package']); ?>" required min="1" placeholder="Enter number of packages">
                                <div class="help-text">Total packages received from supplier</div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-sticky-note"></i>
                            Additional Information
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-comment input-icon"></i>
                                    Remark
                                </label>
                                <textarea class="form-control" name="remark" rows="3" placeholder="Enter any additional remarks or notes"><?php echo htmlspecialchars($entry['remark']); ?></textarea>
                                <div class="help-text">Optional remarks or special instructions</div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='GateEntryLookup.php'">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i><?php echo $isEdit ? 'Update' : 'Save'; ?> Gate Entry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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

    // Form validation
    document.getElementById('gateEntryForm').addEventListener('submit', function(e) {
        const invoiceNumber = document.querySelector('input[name="invoice_number"]').value.trim();
        const supplierId = document.querySelector('select[name="supplier_id"]').value;
        const noOfPackage = document.querySelector('input[name="no_of_package"]').value.trim();
        
        if (!invoiceNumber) {
            alert('❌ Please enter invoice number!');
            e.preventDefault();
            return false;
        }
        
        if (!supplierId) {
            alert('❌ Please select a supplier!');
            e.preventDefault();
            return false;
        }
        
        if (!noOfPackage || noOfPackage < 1) {
            alert('❌ Please enter valid number of packages!');
            e.preventDefault();
            return false;
        }
        
        const action = <?php echo $isEdit ? "'update'" : "'add'"; ?>;
        return confirm(`✅ Are you sure you want to ${action} this gate entry?`);
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Vehicle number auto uppercase
    document.querySelector('input[name="vehicle_number"]').addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });

    // Update current time display every minute
    setInterval(function() {
        const now = new Date();
        const timeString = now.toTimeString().substr(0, 5);
        const badge = document.querySelector('.current-time-badge');
        if (badge) {
            badge.textContent = 'Current: ' + timeString;
        }
    }, 60000);
</script>
</body>
</html>