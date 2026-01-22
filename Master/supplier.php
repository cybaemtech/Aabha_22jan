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

$showForm = isset($_GET['add']);
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Auto-generate Supplier ID (SQLSRV)
$supplierId = null;
if ($showForm) {
    $result = sqlsrv_query($conn, "SELECT MAX(CAST(supplier_id AS INT)) AS max_id FROM suppliers");
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $supplierId = $row['max_id'] ? $row['max_id'] + 1 : 1;
}

// Fetch materials for dropdown (SQLSRV)
$materialsStmt = sqlsrv_query($conn, "SELECT material_id, material_description FROM materials ORDER BY material_description ASC");
$materials = [];
if ($materialsStmt) {
    while ($mat = sqlsrv_fetch_array($materialsStmt, SQLSRV_FETCH_ASSOC)) {
        $materials[] = $mat;
    }
    sqlsrv_free_stmt($materialsStmt);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'] ?? null;
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $approve_status = $_POST['approve_status'] ?? '';
    $material_ids = $_POST['material_ids'] ?? [];

    // Validation
    if (empty($supplier_name)) {
        $_SESSION['error'] = "Supplier name is required!";
    } elseif (empty($address)) {
        $_SESSION['error'] = "Address is required!";
    } elseif (empty($city)) {
        $_SESSION['error'] = "City is required!";
    } elseif (empty($postal_code)) {
        $_SESSION['error'] = "Postal code is required!";
    } elseif (empty($country)) {
        $_SESSION['error'] = "Country is required!";
    } elseif (empty($approve_status)) {
        $_SESSION['error'] = "Approval status is required!";
    } elseif (empty($material_ids)) {
        $_SESSION['error'] = "At least one material must be selected!";
    } else {
        $material_ids_str = implode(',', $material_ids);

        // Check for duplicate supplier name (SQLSRV)
        $checkSql = "SELECT COUNT(*) as count FROM suppliers WHERE supplier_name = ? AND supplier_id != ?";
        $checkParams = array($supplier_name, $supplier_id);
        $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);
        $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        if ($checkRow['count'] > 0) {
            $_SESSION['error'] = "Supplier name already exists!";
        } else {
            // Check if supplier exists for update (SQLSRV)
            $checkExistSql = "SELECT id FROM suppliers WHERE supplier_id = ?";
            $checkExistStmt = sqlsrv_query($conn, $checkExistSql, array($supplier_id));
            $existingRow = sqlsrv_fetch_array($checkExistStmt, SQLSRV_FETCH_ASSOC);

            if ($existingRow) {
                // Update existing supplier
                $updateSql = "UPDATE suppliers SET supplier_name=?, contact_name=?, address=?, city=?, postal_code=?, country=?, phone=?, email=?, approve_status=?, material_id=? WHERE supplier_id=?";
                $updateParams = array($supplier_name, $contact_name, $address, $city, $postal_code, $country, $phone, $email, $approve_status, $material_ids_str, $supplier_id);
                $stmt = sqlsrv_query($conn, $updateSql, $updateParams);
            } else {
                // Insert new supplier - FIX: add delete_id column with default value 0
                $insertSql = "INSERT INTO suppliers (supplier_id, supplier_name, contact_name, address, city, postal_code, country, phone, email, approve_status, material_id, delete_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                $insertParams = array($supplier_id, $supplier_name, $contact_name, $address, $city, $postal_code, $country, $phone, $email, $approve_status, $material_ids_str);
                $stmt = sqlsrv_query($conn, $insertSql, $insertParams);
            }

            // --- FIX: Show SQLSRV error if query fails ---
            if ($stmt) {
                $_SESSION['message'] = "Supplier saved successfully!";
                header("Location: supplier.php");
                exit;
            } else {
                $errors = sqlsrv_errors();
                $_SESSION['error'] = "Error saving supplier! " . ($errors ? $errors[0]['message'] : '');
            }
        }
    }
}

// Handle delete and update messages
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $_SESSION['message'] = "Supplier deleted successfully!";
}

if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "Supplier updated successfully!";
}

include '../Includes/sidebar.php';

// Get statistics (SQLSRV)
$totalSuppliersStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM suppliers");
$totalSuppliers = sqlsrv_fetch_array($totalSuppliersStmt, SQLSRV_FETCH_ASSOC)['count'];

$approvedSuppliersStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM suppliers WHERE approve_status = 'Approval'");
$approvedSuppliers = sqlsrv_fetch_array($approvedSuppliersStmt, SQLSRV_FETCH_ASSOC)['count'];

$conditionalSuppliersStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM suppliers WHERE approve_status = 'Conditional'");
$conditionalSuppliers = sqlsrv_fetch_array($conditionalSuppliersStmt, SQLSRV_FETCH_ASSOC)['count'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Supplier Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to supplier page */
        .select2-container--default .select2-selection--multiple {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 8px 12px;
            min-height: 50px;
            background: #f8f9fa;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 15px;
            padding: 5px 10px;
            margin: 3px;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 5px;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #ff6b6b;
        }
        
        .approval-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-approval {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-conditional {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
        
        .material-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .material-pill {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-truck"></i>
            Supplier Master
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

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalSuppliers; ?></div>
                <div class="stats-label">Total Suppliers</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $approvedSuppliers; ?></div>
                <div class="stats-label">Approved</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $conditionalSuppliers; ?></div>
                <div class="stats-label">Conditional</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo count($materials); ?></div>
                <div class="stats-label">Available Materials</div>
            </div>
        </div>
    </div>

    <!-- Add New Supplier Button -->
    <?php if (!$showForm): ?>
        <div class="mb-4">
            <a href="?add=1" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New Supplier
            </a>
        </div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <!-- Add Supplier Form -->
        <div class="form-container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    Add New Supplier
                </div>
                <div class="card-body">
                    <form id="supplierForm" method="post">
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-hashtag input-icon"></i>
                                        Supplier ID
                                    </label>
                                    <input type="text" class="form-control" name="supplier_id" value="<?php echo $supplierId; ?>" readonly>
                                    <div class="help-text">Auto-generated unique identifier</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-building input-icon"></i>
                                        Supplier Name <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="supplier_name" required placeholder="Enter supplier name">
                                    <div class="help-text">Enter the official supplier company name</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-user input-icon"></i>
                                        Contact Person Name
                                    </label>
                                    <input type="text" class="form-control" name="contact_name" placeholder="Enter contact person name">
                                    <div class="help-text">Primary contact person at the supplier</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-check-circle input-icon"></i>
                                        Approval Status <span class="required">*</span>
                                    </label>
                                    <select class="form-select" name="approve_status" required>
                                        <option value="">Select Status</option>
                                        <option value="Conditional">Conditional</option>
                                        <option value="Approval">Approved</option>
                                    </select>
                                    <div class="help-text">Current approval status of the supplier</div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Address Information
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-home input-icon"></i>
                                        Address <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="address" required placeholder="Enter complete address">
                                    <div class="help-text">Full street address of the supplier</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-city input-icon"></i>
                                        City <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="city" required placeholder="Enter city">
                                    <div class="help-text">City where supplier is located</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-mail-bulk input-icon"></i>
                                        Postal Code <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="postal_code" required placeholder="Enter postal code">
                                    <div class="help-text">ZIP or postal code</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-flag input-icon"></i>
                                        Country <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="country" required placeholder="Enter country">
                                    <div class="help-text">Country where supplier is located</div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-phone"></i>
                                Contact Information
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-phone input-icon"></i>
                                        Phone Number
                                    </label>
                                    <input type="tel" class="form-control" name="phone" placeholder="Enter phone number">
                                    <div class="help-text">Primary contact phone number</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-envelope input-icon"></i>
                                        Email Address
                                    </label>
                                    <input type="email" class="form-control" name="email" placeholder="Enter email address">
                                    <div class="help-text">Primary contact email address</div>
                                </div>
                            </div>
                        </div>

                        <!-- Materials Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-boxes"></i>
                                Materials Supplied
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-cube input-icon"></i>
                                        Materials <span class="required">*</span>
                                    </label>
                                    <select class="form-control material-select2" name="material_ids[]" multiple required>
                                        <?php foreach ($materials as $mat): ?>
                                            <option value="<?= htmlspecialchars($mat['material_id']) ?>">
                                                <?= htmlspecialchars($mat['material_id']) ?> - <?= htmlspecialchars($mat['material_description']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">Select all materials this supplier can provide</div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='supplier.php'">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Supplier
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Search and Supplier List -->
        <!-- Search Form -->
        <div class="search-container">
            <form method="get" class="d-flex align-items-center gap-3">
                <div class="search-input-group flex-grow-1">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search suppliers by ID or name..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <?php if ($search): ?>
                    <a href="supplier.php" class="btn btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Supplier List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Supplier List
                <?php if ($search): ?>
                    <span class="badge bg-light text-dark ms-2">Search: "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 120px;">Actions</th>
                                <th style="width: 80px;">Sr. No.</th>
                                <th style="width: 100px;">Supplier ID</th>
                                <th>Supplier Name</th>
                                <th>Contact Person</th>
                                <th>Address</th>
                                <th>City</th>
                                <th>Country</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Materials</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $query = "SELECT * FROM suppliers";
                            if (!empty($search)) {
                                $like = "%$search%";
                                $stmt = sqlsrv_query($conn, "SELECT * FROM suppliers WHERE 
                                    supplier_id LIKE ? OR supplier_name LIKE ? ORDER BY id DESC", array($like, $like));
                            } else {
                                $stmt = sqlsrv_query($conn, $query . " ORDER BY id DESC");
                            }

                            if ($stmt && sqlsrv_has_rows($stmt)):
                                $sr = 1;
                                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)):
                                    // Parse materials
                                    $materialsList = $row['material_id'];
                                    $materialsArray = explode(',', $materialsList);
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <div class="action-buttons d-flex justify-content-center">
                                            <a href="supplier_edit.php?id=<?php echo $row['id']; ?>" class="text-primary" title="Edit Supplier">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="supplier_delete.php?id=<?php echo $row['id']; ?>" class="text-danger" title="Delete Supplier" onclick="return confirm('Are you sure you want to delete this supplier?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $sr++; ?></strong></td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($row['supplier_id']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['supplier_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['contact_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['address']); ?></td>
                                    <td><?php echo htmlspecialchars($row['city']); ?></td>
                                    <td><?php echo htmlspecialchars($row['country']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td>
                                        <div class="material-pills">
                                            <?php foreach ($materialsArray as $matId): ?>
                                                <?php if (trim($matId)): ?>
                                                    <span class="material-pill"><?php echo htmlspecialchars(trim($matId)); ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $status = $row['approve_status'];
                                        $badgeClass = $status == 'Approval' ? 'badge-approval' : 'badge-conditional';
                                        ?>
                                        <span class="approval-badge <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i>
                                            <h5>No Suppliers Found</h5>
                                            <p>No suppliers match your search criteria.</p>
                                            <?php if ($search): ?>
                                                <a href="supplier.php" class="btn btn-primary">
                                                    <i class="fas fa-list me-2"></i>View All Suppliers
                                                </a>
                                            <?php else: ?>
                                                <a href="?add=1" class="btn btn-success">
                                                    <i class="fas fa-plus me-2"></i>Add First Supplier
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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

    // Initialize Select2 for materials
    $(document).ready(function() {
        $('.material-select2').select2({
            placeholder: "Select Materials",
            allowClear: true,
            width: '100%',
            theme: 'default'
        });
    });

    // Form validation
    document.getElementById('supplierForm')?.addEventListener('submit', function(e) {
        const supplierName = document.querySelector('input[name="supplier_name"]').value.trim();
        const address = document.querySelector('input[name="address"]').value.trim();
        const city = document.querySelector('input[name="city"]').value.trim();
        const postalCode = document.querySelector('input[name="postal_code"]').value.trim();
        const country = document.querySelector('input[name="country"]').value.trim();
        const approveStatus = document.querySelector('select[name="approve_status"]').value;
        const materials = $('.material-select2').val();
        
        if (!supplierName) {
            alert('❌ Please enter supplier name!');
            e.preventDefault();
            return false;
        }
        
        if (!address) {
            alert('❌ Please enter address!');
            e.preventDefault();
            return false;
        }
        
        if (!city) {
            alert('❌ Please enter city!');
            e.preventDefault();
            return false;
        }
        
        if (!postalCode) {
            alert('❌ Please enter postal code!');
            e.preventDefault();
            return false;
        }
        
        if (!country) {
            alert('❌ Please enter country!');
            e.preventDefault();
            return false;
        }
        
        if (!approveStatus) {
            alert('❌ Please select approval status!');
            e.preventDefault();
            return false;
        }
        
        if (!materials || materials.length === 0) {
            alert('❌ Please select at least one material!');
            e.preventDefault();
            return false;
        }
        
        return confirm(`✅ Are you sure you want to save supplier "${supplierName}"?`);
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Enhanced delete confirmation
    function confirmDelete(supplierName) {
        return confirm(`⚠️ Are you sure you want to delete supplier "${supplierName}"?\n\nThis action cannot be undone.`);
    }
</script>
</body>
</html>
