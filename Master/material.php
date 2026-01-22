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

// Auto-generate Material ID (SQLSRV)
$nextMaterialId = null;
if (isset($_GET['add'])) {
    $result = sqlsrv_query($conn, "SELECT MAX(CAST(material_id AS INT)) AS max_id FROM materials");
    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    $nextMaterialId = $row['max_id'] ? $row['max_id'] + 1 : 1;
}

// Handle form submit with validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fetch latest material_id at submit time
    $res = sqlsrv_query($conn, "SELECT MAX(CAST(material_id AS INT)) AS max_id FROM materials");
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $newMaterialId = $row['max_id'] ? $row['max_id'] + 1 : 1;

    $material_description = trim($_POST['material_description']);
    $unit_of_measurement = trim($_POST['unit_of_measurement']);
    $material_type = trim($_POST['material_type']);
    $status_remark = trim($_POST['status_remark']);

    // Validation
    if (empty($material_description)) {
        $_SESSION['error'] = "Material description is required!";
    } elseif (empty($material_type)) {
        $_SESSION['error'] = "Material type is required!";
    } else {
        // Check for duplicate material description (SQLSRV)
        $checkSql = "SELECT COUNT(*) as count FROM materials WHERE material_description = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, array($material_description));
        $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        if ($checkRow['count'] > 0) {
            $_SESSION['error'] = "Material description already exists!";
        } else {
            $insertSql = "INSERT INTO materials (material_id, material_description, unit_of_measurement, material_type, status_remark) VALUES (?, ?, ?, ?, ?)";
            $insertParams = array($newMaterialId, $material_description, $unit_of_measurement, $material_type, $status_remark);
            $stmt = sqlsrv_query($conn, $insertSql, $insertParams);

            if ($stmt) {
                $_SESSION['message'] = "Material added successfully!";
                header("Location: material.php");
                exit;
            } else {
                $_SESSION['error'] = "Error adding material!";
            }
        }
    }
}

// Handle messages from other operations
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $_SESSION['message'] = "Material deleted successfully!";
}

if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "Material updated successfully!";
}

include '../Includes/sidebar.php';

// Search functionality (SQLSRV)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = $search ? "WHERE material_id LIKE ? OR material_description LIKE ? OR material_type LIKE ?" : '';
$querySql = "SELECT * FROM materials $where ORDER BY id DESC";
$materials = [];
if ($search) {
    $searchParam = "%$search%";
    $stmt = sqlsrv_query($conn, $querySql, array($searchParam, $searchParam, $searchParam));
} else {
    $stmt = sqlsrv_query($conn, $querySql);
}
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $materials[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get statistics (SQLSRV)
$totalMaterialsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM materials");
$totalMaterials = sqlsrv_fetch_array($totalMaterialsStmt, SQLSRV_FETCH_ASSOC)['count'];

$rawMaterialsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM materials WHERE material_type = 'Raw material'");
$rawMaterials = sqlsrv_fetch_array($rawMaterialsStmt, SQLSRV_FETCH_ASSOC)['count'];

$packingMaterialsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM materials WHERE material_type = 'Packing material'");
$packingMaterials = sqlsrv_fetch_array($packingMaterialsStmt, SQLSRV_FETCH_ASSOC)['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Material Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Include the main CSS file -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Additional styles specific to material page */
        .autocomplete-list {
            position: absolute;
            z-index: 1000;
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
        }
        
        .autocomplete-list .list-group-item {
            cursor: pointer;
            border: none;
            padding: 10px 15px;
            transition: background-color 0.2s;
        }
        
        .autocomplete-list .list-group-item:hover {
            background-color: #f8f9fa;
        }
        
        .material-type-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-raw {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .badge-packing {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
        }
        
        .badge-misc {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: white;
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-cube"></i>
            Material Master
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
                <div class="stats-number"><?php echo $totalMaterials; ?></div>
                <div class="stats-label">Total Materials</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $rawMaterials; ?></div>
                <div class="stats-label">Raw Materials</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $packingMaterials; ?></div>
                <div class="stats-label">Packing Materials</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalMaterials - $rawMaterials - $packingMaterials; ?></div>
                <div class="stats-label">Miscellaneous</div>
            </div>
        </div>
    </div>

    <!-- Add New Material Button -->
    <?php if (!isset($_GET['add'])): ?>
        <div class="mb-4">
            <a href="?add=1" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New Material
            </a>
        </div>
    <?php endif; ?>

    <!-- Add Material Form -->
    <?php if (isset($_GET['add'])): ?>
        <div class="form-container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    Add New Material
                </div>
                <div class="card-body">
                    <form method="post" id="materialForm">
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Material Information
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-hashtag input-icon"></i>
                                        Material ID
                                    </label>
                                    <input type="text" class="form-control" name="material_id" value="<?php echo $nextMaterialId; ?>" readonly>
                                    <div class="help-text">Auto-generated unique identifier</div>
                                </div>

                                <div class="form-group position-relative">
                                    <label class="form-label">
                                        <i class="fas fa-clipboard-list input-icon"></i>
                                        Material Description <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="material_description" id="material_description" required autocomplete="off" placeholder="Enter material description">
                                    <div class="help-text">Enter a unique material description</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group position-relative">
                                    <label class="form-label">
                                        <i class="fas fa-ruler input-icon"></i>
                                        Unit Of Measurement
                                    </label>
                                    <input type="text" class="form-control" name="unit_of_measurement" id="unit_of_measurement" autocomplete="off" placeholder="e.g., kg, pcs, ltr">
                                    <div class="help-text">Measurement unit for this material</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-tags input-icon"></i>
                                        Material Type <span class="required">*</span>
                                    </label>
                                    <select class="form-select" name="material_type" required>
                                        <option value="">Select Material Type</option>
                                        <option value="Raw material">Raw Material</option>
                                        <option value="Packing material">Packing Material</option>
                                        <option value="Miscellaneous">Miscellaneous</option>
                                    </select>
                                    <div class="help-text">Choose the appropriate material category</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-comment input-icon"></i>
                                        Status Remark
                                    </label>
                                    <input type="text" class="form-control" name="status_remark" placeholder="Enter any status remarks or notes">
                                    <div class="help-text">Optional status information or remarks</div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='material.php'">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Material
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search and Material List -->
    <?php if (!isset($_GET['add'])): ?>
        <!-- Search Form -->
        <div class="search-container">
            <form method="get" class="d-flex align-items-center gap-3">
                <div class="search-input-group flex-grow-1">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search materials by ID, description, or type..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <?php if ($search): ?>
                    <a href="material.php" class="btn btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Material List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Material List
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
                                <th style="width: 100px;">Material ID</th>
                                <th>Material Description</th>
                                <th>Unit</th>
                                <th>Material Type</th>
                                <th>Status Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($materials) > 0): ?>
                                <?php $sr = 1; foreach($materials as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <div class="action-buttons d-flex justify-content-center">
                                            <a href="material_edit.php?id=<?php echo $row['id']; ?>" class="text-primary" title="Edit Material">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="material_delete.php?id=<?php echo $row['id']; ?>" class="text-danger" title="Delete Material" onclick="return confirmDelete('<?php echo htmlspecialchars($row['material_description']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $sr++; ?></strong></td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($row['material_id']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['material_description']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['unit_of_measurement']); ?></td>
                                    <td>
                                        <?php 
                                        $type = $row['material_type'];
                                        $badgeClass = 'badge-misc';
                                        if ($type == 'Raw material') $badgeClass = 'badge-raw';
                                        elseif ($type == 'Packing material') $badgeClass = 'badge-packing';
                                        ?>
                                        <span class="material-type-badge <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars($type); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['status_remark']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i>
                                            <h5>No Materials Found</h5>
                                            <p>No materials match your search criteria.</p>
                                            <?php if ($search): ?>
                                                <a href="material.php" class="btn btn-primary">
                                                    <i class="fas fa-list me-2"></i>View All Materials
                                                </a>
                                            <?php else: ?>
                                                <a href="?add=1" class="btn btn-success">
                                                    <i class="fas fa-plus me-2"></i>Add First Material
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

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

    // Autocomplete functionality
    function setupAutocomplete(selector, field) {
        $(selector).on('input', function() {
            const $input = $(this);
            const query = $input.val();
            $input.next('.autocomplete-list').remove();
            
            if (query.length < 1) {
                return;
            }
            
            $.get('material_suggest_ajax.php', { field, query }, function(data) {
                let list = $('<div class="autocomplete-list list-group"></div>');
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(function(item) {
                        $('<a href="#" class="list-group-item list-group-item-action"></a>')
                            .text(item)
                            .on('mousedown', function(e) {
                                e.preventDefault();
                                $input.val(item);
                                list.empty().hide();
                            })
                            .appendTo(list);
                    });
                    $input.after(list);
                    list.show();
                }
            }, 'json').fail(function() {
                // Handle AJAX error silently
                console.log('Autocomplete service unavailable');
            });
        }).on('blur', function() {
            setTimeout(() => $(this).next('.autocomplete-list').hide(), 200);
        }).on('focus', function() {
            $(this).trigger('input');
        });
    }

    // Form validation
    document.getElementById('materialForm')?.addEventListener('submit', function(e) {
        const materialDescription = document.querySelector('input[name="material_description"]').value.trim();
        const materialType = document.querySelector('select[name="material_type"]').value;
        
        if (!materialDescription) {
            alert('❌ Please enter a material description!');
            e.preventDefault();
            return false;
        }
        
        if (!materialType) {
            alert('❌ Please select a material type!');
            e.preventDefault();
            return false;
        }
        
        return confirm(`✅ Are you sure you want to add material "${materialDescription}"?`);
    });

    // Initialize autocomplete
    $(document).ready(function() {
        setupAutocomplete('#material_description', 'material_description');
        setupAutocomplete('#unit_of_measurement', 'unit_of_measurement');
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
    function confirmDelete(materialName) {
        return confirm(`⚠️ Are you sure you want to delete material "${materialName}"?\n\nThis action cannot be undone.`);
    }
</script>
</body>
</html>