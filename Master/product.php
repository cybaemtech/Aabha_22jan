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

// Handle form submit BEFORE including sidebar.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id']);
    $product_description = trim($_POST['product_description']);
    $product_type = trim($_POST['product_type']);

    // Validation
    if (empty($product_description)) {
        $_SESSION['error'] = "Product description is required!";
    } elseif (empty($product_type)) {
        $_SESSION['error'] = "Product type is required!";
    } else {
        // Check for duplicate product description (SQLSRV)
        $checkSql = "SELECT COUNT(*) as count FROM products WHERE product_description = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, array($product_description));
        $checkRow = $checkStmt ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;

        if ($checkRow && $checkRow['count'] > 0) {
            $_SESSION['error'] = "Product description already exists!";
        } else {
            $insertSql = "INSERT INTO products (product_id, product_description, product_type) VALUES (?, ?, ?)";
            $insertParams = array($product_id, $product_description, $product_type);
            $stmt = sqlsrv_query($conn, $insertSql, $insertParams);

            if ($stmt) {
                $_SESSION['message'] = "Product added successfully!";
                header("Location: product.php");
                exit;
            } else {
                $_SESSION['error'] = "Error adding product!";
            }
        }
    }
}

// Handle messages
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $_SESSION['message'] = "Product deleted successfully!";
}
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $_SESSION['message'] = "Product updated successfully!";
}
if (isset($_GET['error']) && $_GET['error'] == 1) {
    $_SESSION['message'] = "Error occurred while processing request!";
}

// Auto-generate Product ID (SQLSRV) - FIXED LINE
$result = sqlsrv_query($conn, "SELECT MAX(product_id) AS max_id FROM products");
$row = $result ? sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC) : null;
$nextProductId = ($row && $row['max_id'] !== null) ? (int)$row['max_id'] + 1 : 1;

// Search functionality (SQLSRV)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE 
        CAST(product_id AS VARCHAR) LIKE ? OR 
        product_description LIKE ? OR 
        product_type LIKE ?";
    $searchParam = "%$search%";
    $params = array($searchParam, $searchParam, $searchParam);
}
$querySql = "SELECT * FROM products $where ORDER BY product_id ASC";
$products = [];
$stmt = sqlsrv_query($conn, $querySql, $params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $products[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get statistics (SQLSRV)
$totalProductsStmt = sqlsrv_query($conn, "SELECT COUNT(*) as count FROM products");
$totalProducts = $totalProductsStmt ? sqlsrv_fetch_array($totalProductsStmt, SQLSRV_FETCH_ASSOC)['count'] : 0;

$productTypesStmt = sqlsrv_query($conn, "SELECT COUNT(DISTINCT product_type) as count FROM products");
$productTypes = $productTypesStmt ? sqlsrv_fetch_array($productTypesStmt, SQLSRV_FETCH_ASSOC)['count'] : 0;

// NOW include sidebar.php after all header() calls are done
include '../Includes/sidebar.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .product-type-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-box-open"></i>
            Product Master
        </h1>
    </div>

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

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $totalProducts; ?></div>
                <div class="stats-label">Total Products</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-number"><?php echo $productTypes; ?></div>
                <div class="stats-label">Product Types</div>
            </div>
        </div>
    </div>

    <?php if (!isset($_GET['add'])): ?>
        <div class="mb-4">
            <a href="?add=1" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New Product
            </a>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['add'])): ?>
        <div class="form-container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    Add New Product
                </div>
                <div class="card-body">
                    <form method="post" id="productForm">
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Product Information
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-hashtag input-icon"></i>
                                        Product ID
                                    </label>
                                    <input type="text" class="form-control" name="product_id" value="<?php echo $nextProductId; ?>" readonly>
                                    <div class="help-text">Auto-generated unique identifier</div>
                                </div>
                                <div class="form-group position-relative">
                                    <label class="form-label">
                                        <i class="fas fa-clipboard-list input-icon"></i>
                                        Product Description <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="product_description" id="product_description" required autocomplete="off" placeholder="Enter product description">
                                    <div class="help-text">Enter a unique product description</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group position-relative">
                                    <label class="form-label">
                                        <i class="fas fa-tags input-icon"></i>
                                        Product Type <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="product_type" id="product_type" required autocomplete="off" placeholder="e.g., Electronics, Clothing, Food">
                                    <div class="help-text">Category or type of the product</div>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='product.php'">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!isset($_GET['add'])): ?>
        <div class="search-container">
            <form method="get" class="d-flex align-items-center gap-3">
                <div class="search-input-group flex-grow-1">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control" name="search" placeholder="Search products by ID, description, or type..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
                <?php if ($search): ?>
                    <a href="product.php" class="btn btn-outline-danger">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list me-2"></i>Product List
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
                                <th style="width: 100px;">Product ID</th>
                                <th>Product Description</th>
                                <th>Product Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) > 0): ?>
                                <?php $sr = 1; foreach($products as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <div class="action-buttons d-flex justify-content-center">
                                            <a href="product_edit.php?id=<?php echo $row['id']; ?>" class="text-primary" title="Edit Product">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="product_delete.php?id=<?php echo $row['id']; ?>" class="text-danger" title="Delete Product" onclick="return confirmDelete('<?php echo htmlspecialchars($row['product_description']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $sr++; ?></strong></td>
                                    <td><span class="badge bg-primary"><?php echo $row['product_id']; ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($row['product_description']); ?></strong></td>
                                    <td>
                                        <span class="product-type-badge">
                                            <?php echo htmlspecialchars($row['product_type']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i>
                                            <h5>No Products Found</h5>
                                            <p>No products match your search criteria.</p>
                                            <?php if ($search): ?>
                                                <a href="product.php" class="btn btn-primary">
                                                    <i class="fas fa-list me-2"></i>View All Products
                                                </a>
                                            <?php else: ?>
                                                <a href="?add=1" class="btn btn-success">
                                                    <i class="fas fa-plus me-2"></i>Add First Product
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
            
            $.get('product_suggest_ajax.php', { field, query }, function(data) {
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
    document.getElementById('productForm')?.addEventListener('submit', function(e) {
        const productDescription = document.querySelector('input[name="product_description"]').value.trim();
        const productType = document.querySelector('input[name="product_type"]').value.trim();
        const color = document.querySelector('input[name="color"]').value.trim();
        const specification = document.querySelector('input[name="specification"]').value.trim();
        
        if (!productDescription) {
            alert('❌ Please enter a product description!');
            e.preventDefault();
            return false;
        }
        
        if (!productType) {
            alert('❌ Please enter a product type!');
            e.preventDefault();
            return false;
        }
        
        if (!color) {
            alert('❌ Please enter a color!');
            e.preventDefault();
            return false;
        }
        
        if (!specification) {
            alert('❌ Please enter specifications!');
            e.preventDefault();
            return false;
        }
        
        return confirm(`✅ Are you sure you want to add product "${productDescription}"?`);
    });

    // Initialize autocomplete
    $(document).ready(function() {
        setupAutocomplete('#product_description', 'product_description');
        setupAutocomplete('#product_type', 'product_type');
        setupAutocomplete('#color', 'color');
        setupAutocomplete('#specification', 'specification');
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
    function confirmDelete(productName) {
        return confirm(`⚠️ Are you sure you want to delete product "${productName}"?\n\nThis action cannot be undone.`);
    }
</script>
</body>
</html>