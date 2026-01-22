<?php  
// Check if db_connect is already included to avoid duplicate inclusion
if (!isset($conn)) {
    // Use relative path based on where sidebar.php is located (in Includes folder)
    include 'db_connect.php';
}

// Define the complete menu structure with page mappings
$menuStructure = [
    'dashboard' => [
        'name' => 'Dashboard',
        'icon' => 'fa-tachometer-alt',
        'pages' => [
            'dashboard' => ['name' => 'Dashboard', 'file' => 'dashboard.php', 'path' => '../Master/dashboard.php']
        ]
    ],
    'admin' => [
        'name' => 'Admin',
        'icon' => 'fa-user-shield',
        'pages' => [
            'user_registration' => ['name' => 'User Registration', 'file' => 'user_registrationLookup.php', 'path' => '../Admin/user_registrationLookup.php'],
            'batch_creation' => ['name' => 'Batch Creation', 'file' => 'BatchCreationEntryLookup.php', 'path' => '../Admin/BatchCreationEntryLookup.php'],
            'check_by' => ['name' => 'CheckBy Creation', 'file' => 'CheckBy.php', 'path' => '../Admin/CheckBy.php']
        ]
    ],
    'master' => [
        'name' => 'Master Data',
        'icon' => 'fa-cogs',
        'pages' => [
            'department' => ['name' => 'Department', 'file' => 'department.php', 'path' => '../Master/department.php'],
            'machine' => ['name' => 'Machine', 'file' => 'machine.php', 'path' => '../Master/machine.php'],
            'material' => ['name' => 'Material', 'file' => 'material.php', 'path' => '../Master/material.php'],
            'supplier' => ['name' => 'Supplier', 'file' => 'supplier.php', 'path' => '../Master/supplier.php'],
            'product' => ['name' => 'Product', 'file' => 'product.php', 'path' => '../Master/product.php']
        ]
    ],
    'transaction' => [
        'name' => 'Transaction',
        'icon' => 'fa-exchange-alt',
        'pages' => [
            'store_entry' => ['name' => 'Store Entry', 'file' => 'StoreEntryLookup.php', 'path' => '../Transaction/StoreEntryLookup.php'],
            'gate_entry' => ['name' => 'Gate Entry', 'file' => 'GateEntryLookup.php', 'path' => '../Transaction/GateEntryLookup.php'],
            'grn_entry' => ['name' => 'GRN Entry', 'file' => 'GRNEntryLookup.php', 'path' => '../Transaction/GRNEntryLookup.php'],
            'qc_page' => ['name' => 'QC Page', 'file' => 'QCPageLookup.php', 'path' => '../Transaction/QCPageLookup.php'],
            'store_stock' => ['name' => 'Store Stock Available', 'file' => 'StoreStockAvailable.php', 'path' => '../Transaction/StoreStockAvailable.php'],
            'issuer_material' => ['name' => 'Issuer Entry Page', 'file' => 'issuerMaterialLookup.php', 'path' => '../Dipping/issuerMaterialLookup.php']
        ]
    ],
    'dipping' => [
        'name' => 'Dipping Process',
        'icon' => 'fa-vial',
        'pages' => [
            'lot_creation' => ['name' => 'Lot Creation', 'file' => 'lotcreation.php', 'path' => '../Dipping/lotcreation.php'],
            'dipping_entry' => ['name' => 'Dipping Entry Page', 'file' => 'DippingBinwiseEntryLookup.php', 'path' => '../Dipping/DippingBinwiseEntryLookup.php'],
            'summary' => ['name' => 'Summary', 'file' => 'DippingSummary.php', 'path' => '../Dipping/DippingSummary.php']
        ]
    ],
    'electronic' => [
        'name' => 'Electronic Testing',
        'icon' => 'fa-microchip',
        'pages' => [
            'operator' => ['name' => 'Operator', 'file' => 'operatorLookup.php', 'path' => '../Electronic/operatorLookup.php'],
            // 'operator_presence' => ['name' => 'Operator Presence', 'file' => 'Operator_presenty.php', 'path' => '../Electronic/Operator_presenty.php'],
            'electronic_batch' => ['name' => 'Electronic Batch Entry', 'file' => 'ElectronicBatchEntryLookup.php', 'path' => '../Electronic/ElectronicBatchEntryLookup.php'],
            'summary' => ['name' => 'Summary', 'file' => 'ElectronicSummary.php', 'path' => '../Electronic/ElectronicSummary.php']
        ]
    ],
    'sealing' => [
        'name' => 'Sealing Process',
        'icon' => 'fa-lock',
        'pages' => [
            'sealing_entry' => ['name' => 'Sealing Entry', 'file' => 'sealing_lookup.php', 'path' => '../Sealing/sealing_lookup.php'],
            'flavour_supervisor' => ['name' => 'Flavour Supervisor', 'file' => 'addFlavoure_Supervisor.php', 'path' => '../Sealing/addFlavoure_Supervisor.php'],
            'summary' => ['name' => 'Summary', 'file' => 'SealingSummary.php', 'path' => '../Sealing/SealingSummary.php']
        ]
    ],
    'material_issue' => [
        'name' => 'Material Issue Note',
        'icon' => 'fa-file-signature',
        'pages' => [
            'material_issue_note' => ['name' => 'Material Issue Note', 'file' => 'MaterialIssueNotePageLookup.php', 'path' => '../Dipping/MaterialIssueNotePageLookup.php']
        ]
    ]
];

// Get user permissions from database
$userPermissions = [];
if (isset($_SESSION['operator_id'])) {
    $operatorId = $_SESSION['operator_id'];
    $sql = "SELECT menu_permission FROM users WHERE operator_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$operatorId]);
    
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $rawPerm = $row['menu_permission'];
        $userPermissions = json_decode($rawPerm, true) ?: [];
    }
}


// Helper function to check if user has permission for a specific submenu
function hasSubmenuPermission($submenuKey, $userPermissions) {
    return array_key_exists($submenuKey, $userPermissions);
}

// Helper function to get allowed submenus for a menu category
function getAllowedSubmenus($menuKey, $menuStructure, $userPermissions) {
    $allowedSubmenus = [];
    
    if (!isset($menuStructure[$menuKey])) {
        return $allowedSubmenus;
    }
    
    foreach ($menuStructure[$menuKey]['pages'] as $pageKey => $pageInfo) {
        $submenuKey = $menuKey . '_' . $pageKey;
        if (hasSubmenuPermission($submenuKey, $userPermissions)) {
            $allowedSubmenus[$pageKey] = $pageInfo;
        }
    }
    
    return $allowedSubmenus;
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Determine which menus should be open based on current page
$isMenuOpen = [];
foreach ($menuStructure as $menuKey => $menuInfo) {
    $isMenuOpen[$menuKey] = false;
    foreach ($menuInfo['pages'] as $pageKey => $pageInfo) {
        if ($currentPage === $pageInfo['file']) {
            $isMenuOpen[$menuKey] = true;
            break;
        }
    }
}

// Fetch user and department info
$userName = 'Guest';
$deptName = '';
$profilePhoto = '';

if (isset($_SESSION['operator_id'])) {
    $operatorId = $_SESSION['operator_id'];
    $sql = "SELECT u.user_name, d.department_name, u.profile_photo
            FROM users u
            JOIN departments d ON u.department_id = d.id
            WHERE u.operator_id = ?";
    $params = array($operatorId);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $userName = $row['user_name'];
        $deptName = $row['department_name'];
        $profilePhoto = $row['profile_photo'];
    }
}

// Determine profile image path
$profileImg = ($profilePhoto && file_exists("../uploads/$profilePhoto"))
    ? "../uploads/$profilePhoto"
    : "../asset/admin.png";
?>

<!-- Keep your existing CSS styles -->
<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f6f9fc;
}
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 240px;
    height: 100vh;
    background: #fff;
    color: #222;
    border-right: 1px solid #e5e7eb;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease-in-out;
    transform: translateX(0);
    overflow-y: auto;
    scroll-behavior: smooth;
}

.sidebar.hide {
    transform: translateX(-100%);
}

.sidebar-header {
    padding: 24px 18px 12px 18px;
    border-bottom: 1px solid #f1f1f1;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
}
.sidebar-header img {
    width: 50px;
    height: 50px;
    object-fit: contain;
}
.sidebar-header span {
    font-weight: bold;
    font-size: 1.2rem;
    color: #3b82f6;
    letter-spacing: 1px;
}
.sidebar-menu {
    flex: 1;
    padding: 18px 0 0 0;
}
.sidebar-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.sidebar-menu li {
    margin-bottom: 4px;
    position: relative;
}
.sidebar-menu a,
.sidebar-main-menu {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 24px;
    color: #222;
    text-decoration: none;
    font-size: 1rem;
    transition: background 0.2s, color 0.2s;
    border-radius: 0;
}
.sidebar-menu a.active,
.sidebar-menu a:hover,
.sidebar-main-menu:hover {
    background: #e8f0fe;
    color: rgb(79 66 193);
}
.sidebar-main-menu.active,
.sidebar-menu a.active {
    background-color: #4f42c1;
    color: #fff;
    font-weight: 600;
}
.submenu {
    display: none;
    padding-left: 20px;
    background-color: #f1f3f9;
    transition: all 0.3s ease;
}
.submenu.show {
    display: block;
}
.submenu li a {
    padding: 10px 40px;
    background-color: #f1f3f9;
    color: #333;
    display: flex;
    align-items: center;
}
.submenu li a:hover {
    background-color: #e0e7ff;
    color: #111;
}
.sidebar-main-menu {
    cursor: pointer;
}
.sidebar-main-menu .icon {
    font-size: 1.2em;
    width: 22px;
    text-align: center;
}
.dropdown-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
}
.sidebar-main-menu.active .dropdown-arrow i {
    transform: rotate(180deg);
}
.menu-label {
    flex: 1;
    white-space: normal;
    overflow-wrap: break-word;
    font-size: 0.95rem;
    line-height: 1.2;
}

#sidebar-toggle {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: background 0.2s;
    margin-right: 10px;
}
#sidebar-toggle:hover {
    background: #f3f4f6;
}

.sidebar-footer {
    padding: 16px 18px 12px 18px;
    border-top: 1px solid #f1f1f1;
    font-size: 0.95em;
    color: #888;
    background: #fff;
}

@media (max-width: 900px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-header {
        margin-left: 0 !important;
        padding-left: 12px !important;
    }
    
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .main-footer {
        left: 0 !important;
        width: 100% !important;
    }
}

.main-header {
    margin-left: 240px;
    height: 70px;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    z-index: 900;
    position: relative;
    transition: margin-left 0.3s ease-in-out;
}
.main-content {
    margin-left: 240px;
    padding: 32px 32px 60px 32px;
    min-height: calc(100vh - 70px - 40px);
    background: #f6f9fc;
    transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out;
}
.sidebar.hide ~ .main-content,
.main-content.sidebar-collapsed {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100vw !important;
}
.main-footer {
    position: fixed;
    left: 240px;
    bottom: 0;
    width: calc(100% - 240px);
    transition: left 0.3s ease-in-out, width 0.3s ease-in-out;
    z-index: 900;
}

.sidebar.hide ~ .main-footer,
.main-footer.sidebar-collapsed {
    left: 0;
    width: 100%;
}

.main-header.sidebar-collapsed {
    margin-left: 0 !important;
}

.main-content.sidebar-collapsed {
    margin-left: 0 !important;
    width: 100% !important;
}

.main-footer.sidebar-collapsed {
    left: 0 !important;
    width: 100% !important;
}

.sidebar.show {
    transform: translateX(0) !important;
}

/* Smooth transitions for all elements */
.sidebar, .main-header, .main-content, .main-footer {
    transition: all 0.3s ease-in-out;
}
</style>

<!-- Sidebar with submenu-based permission checks -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="../asset/Abha1_new.png" alt="logo" style="width:60px; height:50px; object-fit:contain; margin-right:8px;">
        <span>AABHA_ERP</span>
    </div>
    <nav class="sidebar-menu">
        <ul>
            <?php foreach ($menuStructure as $menuKey => $menuInfo): 
                // Get allowed submenus for this menu category
                $allowedSubmenus = getAllowedSubmenus($menuKey, $menuStructure, $userPermissions);
                
                // Skip menu if user has no access to any submenus in this category
                if (empty($allowedSubmenus)) {
                    continue;
                }
                
                // If only one submenu is allowed, show it as a direct link
                if (count($allowedSubmenus) === 1) {
                    $singleSubmenu = reset($allowedSubmenus);
                    $isActive = $currentPage === $singleSubmenu['file'];
                    ?>
                    <li>
                        <a href="<?= $singleSubmenu['path']; ?>" class="<?= $isActive ? 'active' : ''; ?>">
                            <span class="icon"><i class="fas <?= $menuInfo['icon']; ?>"></i></span>
                            <span class="menu-label"><?= $singleSubmenu['name']; ?></span>
                        </a>
                    </li>
                    <?php
                } else {
                    // Multiple submenus - show as expandable menu
                    ?>
                    <li>
                        <div class="sidebar-main-menu <?= $isMenuOpen[$menuKey] ? 'active' : ''; ?>" onclick="toggleSubMenu(this)">
                            <span class="icon"><i class="fas <?= $menuInfo['icon']; ?>"></i></span>
                            <span style="flex:1;"><?= $menuInfo['name']; ?></span>
                            <span class="dropdown-arrow"><i class="fas <?= $isMenuOpen[$menuKey] ? 'fa-chevron-up' : 'fa-chevron-down'; ?>"></i></span>
                        </div>
                        <ul class="submenu <?= $isMenuOpen[$menuKey] ? 'show' : ''; ?>">
                            <?php foreach ($allowedSubmenus as $pageKey => $pageInfo): 
                                $isPageActive = $currentPage === $pageInfo['file'];
                                ?>
                                <li>
                                    <a href="<?= $pageInfo['path']; ?>" class="<?= $isPageActive ? 'active' : ''; ?>">
                                        <span class="menu-label"><?= $pageInfo['name']; ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php
                }
            endforeach; ?>
        </ul>
    </nav>
</div>

<!-- Header -->
<div class="main-header" style="display: flex; align-items: center; justify-content: space-between;
 padding: 0 32px; height: 70px; background: #fff; border-bottom: 1px solid #e5e7eb; margin-left: 240px;
 transition: margin-left 0.3s; position: relative; z-index: 1000;">
    
    <!-- Left: Sidebar Toggle -->
    <div style="display: flex; align-items: center; gap: 18px;">
        <button id="sidebar-toggle" class="btn btn-primary" style="border-radius:8px; font-weight:700; 
        padding:6px 22px; background:#f7f8fa; color:#222; border:1px solid #1e4186; box-shadow:none;
        display:flex; align-items:center;">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <!-- Right: User Info + Dropdown -->
    <div style="display: flex; align-items: center; gap: 22px; position: relative;">
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
            <span style="font-weight:500; color: black;"><?php echo htmlspecialchars($userName); ?></span>
            <span style="font-size:0.9em;color:#666;"><?php echo htmlspecialchars($deptName); ?></span>
        </span>

        <!-- Admin Icon with Dropdown Toggle -->
        <div style="position: relative;">
            <img src="<?= htmlspecialchars($profileImg) ?>" alt="Admin Icon"
                 id="profileDropdownToggle"
                 style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid #ccc; cursor: pointer;">
            
            <!-- Dropdown Menu -->
            <div id="profileDropdownMenu" style="display: none; position: absolute; right: 0; top: 48px; 
                background: white; border: 1px solid #ccc; border-radius: 6px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); 
                z-index: 999; min-width: 150px;">
                <a href="../Includes/profile_view.php" style="display: block; padding: 10px 16px; color: #333; 
                   text-decoration: none; font-size: 14px;">
                    <i class="fas fa-user"></i> Profile View
                </a>
                <a href="../Includes/logout.php" style="display: block; padding: 10px 16px; color: #333; 
                   text-decoration: none; font-size: 14px;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Sidebar submenu toggle (already present)
function toggleSubMenu(element) {
    document.querySelectorAll('.sidebar-main-menu').forEach(function(menu) {
        if (menu !== element) {
            menu.classList.remove('active');
            const submenu = menu.nextElementSibling;
            if (submenu && submenu.classList.contains('submenu')) {
                submenu.classList.remove('show');
            }
            const icon = menu.querySelector('.dropdown-arrow i');
            if (icon) {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
    });

    element.classList.toggle('active');
    const submenu = element.nextElementSibling;
    if (submenu && submenu.classList.contains('submenu')) {
        submenu.classList.toggle('show');
    }
    const icon = element.querySelector('.dropdown-arrow i');
    if (icon) {
        icon.classList.toggle('fa-chevron-down');
        icon.classList.toggle('fa-chevron-up');
    }
}

// Admin icon dropdown toggle (already present)
document.addEventListener('DOMContentLoaded', function() {
    const profileToggle = document.getElementById('profileDropdownToggle');
    const profileMenu = document.getElementById('profileDropdownMenu');

    // Toggle dropdown on icon click
    profileToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        profileMenu.style.display = (profileMenu.style.display === 'block') ? 'none' : 'block';
    });

    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!profileMenu.contains(e.target) && !profileToggle.contains(e.target)) {
            profileMenu.style.display = 'none';
        }
    });

    // **ADD THIS: Sidebar toggle functionality**
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const mainHeader = document.querySelector('.main-header');
    const mainFooter = document.querySelector('.main-footer');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            // Toggle sidebar visibility
            sidebar.classList.toggle('hide');
            
            // Toggle collapsed state for main content areas
            if (mainContent) {
                mainContent.classList.toggle('sidebar-collapsed');
            }
            if (mainHeader) {
                mainHeader.classList.toggle('sidebar-collapsed');
            }
            if (mainFooter) {
                mainFooter.classList.toggle('sidebar-collapsed');
            }
            
            // Store sidebar state in localStorage for persistence
            const isHidden = sidebar.classList.contains('hide');
            localStorage.setItem('sidebarHidden', isHidden);
        });
    }

    // **ADD THIS: Restore sidebar state on page load**
    const sidebarHidden = localStorage.getItem('sidebarHidden') === 'true';
    if (sidebarHidden && sidebar) {
        sidebar.classList.add('hide');
        if (mainContent) mainContent.classList.add('sidebar-collapsed');
        if (mainHeader) mainHeader.classList.add('sidebar-collapsed');
        if (mainFooter) mainFooter.classList.add('sidebar-collapsed');
    }

    // **ADD THIS: Handle mobile responsive behavior**
    function handleResize() {
        if (window.innerWidth <= 900) {
            // On mobile, hide sidebar by default
            sidebar.classList.add('hide');
            sidebar.classList.remove('show');
        } else {
            // On desktop, restore saved state
            const sidebarHidden = localStorage.getItem('sidebarHidden') === 'true';
            if (sidebarHidden) {
                sidebar.classList.add('hide');
            } else {
                sidebar.classList.remove('hide');
            }
        }
    }

    // Handle window resize
    window.addEventListener('resize', handleResize);
    
    // **ADD THIS: Mobile sidebar toggle (show/hide instead of collapse)**
    if (window.innerWidth <= 900) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 900 && 
                !sidebar.contains(e.target) && 
                !sidebarToggle.contains(e.target) && 
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    }
});
</script>

<!-- Footer -->
<div class="main-footer" style="
    background: rgb(79 66 193);
    color: #ffffff;
    padding: 6px 16px;
    font-size: 13px;
    text-align: center;
    font-family: 'Segoe UI', sans-serif;
">
    Designed and Developed by 
    <a href="https://cybaemtech.com/" target="_blank" style="
        color: #ffd700;
        font-weight: 600;
        text-decoration: none;
    ">
        CybaemTech
    </a> 
    &nbsp;&nbsp; | &nbsp;&nbsp; 
    <a href="#" style="color: #e0e0e0; text-decoration: none;">Terms & Conditions</a> 
    &nbsp; | &nbsp; 
    <a href="#" style="color: #e0e0e0; text-decoration: none;">Privacy & Policy</a>
</div>

