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

<style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f8fafc;
}

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 260px;
    height: 100vh;
    background: #ffffff;
    color: #334155;
    border-right: 1px solid #e2e8f0;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateX(0);
    overflow-y: auto;
    scroll-behavior: smooth;
    box-shadow: 4px 0 24px rgba(0, 0, 0, 0.02);
}

.sidebar.hide {
    transform: translateX(-100%);
}

.sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 12px;
    background: #ffffff;
    position: sticky;
    top: 0;
    z-index: 10;
}

.sidebar-header img {
    width: 42px;
    height: 42px;
    object-fit: contain;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.05));
}

.sidebar-header span {
    font-weight: 700;
    font-size: 1.1rem;
    color: #1e293b;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.sidebar-menu {
    flex: 1;
    padding: 20px 12px;
}

.sidebar-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin-bottom: 6px;
    position: relative;
    animation: slideIn 0.4s ease-out forwards;
    opacity: 0;
}

.sidebar-menu a,
.sidebar-main-menu {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #475569;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 500;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.sidebar-menu a:hover,
.sidebar-main-menu:hover {
    background: #f1f5f9;
    color: #4f42c1;
    transform: translateX(4px);
}

.sidebar-main-menu.active,
.sidebar-menu a.active {
    background: #4f42c1;
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(79, 66, 193, 0.2);
}

.sidebar-main-menu.active:hover,
.sidebar-menu a.active:hover {
    transform: none;
}

.submenu {
    max-height: 0;
    overflow: hidden;
    padding-left: 0;
    background-color: transparent;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    visibility: hidden;
}

.submenu.show {
    max-height: 1000px;
    opacity: 1;
    visibility: visible;
    padding-top: 4px;
    padding-bottom: 8px;
}

.submenu li {
    animation: none !important;
    opacity: 1 !important;
}

.submenu li a {
    padding: 10px 16px 10px 48px;
    background-color: transparent;
    color: #64748b;
    font-size: 0.9rem;
    border-radius: 10px;
}

.submenu li a::before {
    content: '';
    position: absolute;
    left: 24px;
    top: 50%;
    width: 6px;
    height: 6px;
    background: #cbd5e1;
    border-radius: 50%;
    transform: translateY(-50%);
    transition: all 0.3s ease;
}

.submenu li a:hover::before {
    background: #4f42c1;
    transform: translateY(-50%) scale(1.5);
}

.submenu li a.active::before {
    background: #ffffff;
}

.sidebar-main-menu .icon {
    font-size: 1.1rem;
    width: 24px;
    display: flex;
    justify-content: center;
    transition: transform 0.3s ease;
}

.dropdown-arrow {
    margin-left: auto;
    font-size: 0.8rem;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0.6;
}

.sidebar-main-menu.active .dropdown-arrow {
    transform: rotate(180deg);
    opacity: 1;
}

.main-header {
    margin-left: 260px;
    height: 72px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    z-index: 900;
    position: sticky;
    top: 0;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.main-content {
    margin-left: 260px;
    padding: 32px;
    min-height: calc(100vh - 72px);
    background: #f8fafc;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar-collapsed .main-header,
.sidebar-collapsed.main-header {
    margin-left: 0 !important;
}

.sidebar-collapsed .main-content,
.sidebar-collapsed.main-content {
    margin-left: 0 !important;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.sidebar-menu li:nth-child(1) { animation-delay: 0.05s; }
.sidebar-menu li:nth-child(2) { animation-delay: 0.1s; }
.sidebar-menu li:nth-child(3) { animation-delay: 0.15s; }
.sidebar-menu li:nth-child(4) { animation-delay: 0.2s; }
.sidebar-menu li:nth-child(5) { animation-delay: 0.25s; }
.sidebar-menu li:nth-child(6) { animation-delay: 0.3s; }

#sidebar-toggle {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    color: #475569;
}

#sidebar-toggle:hover {
    background: #f1f5f9;
    color: #4f42c1;
    border-color: #4f42c1;
}

.main-footer {
    position: fixed;
    left: 260px;
    bottom: 0;
    width: calc(100% - 260px);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 900;
    background: #4f42c1;
    color: #ffffff;
    padding: 10px 16px;
    font-size: 13px;
    text-align: center;
}

.sidebar-collapsed .main-footer,
.sidebar-collapsed.main-footer {
    left: 0 !important;
    width: 100% !important;
}

@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.show { transform: translateX(0); }
    .main-header, .main-content, .main-footer { margin-left: 0 !important; width: 100% !important; left: 0 !important; }
}
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="../asset/Abha1_new.png" alt="logo">
        <span>AABHA ERP</span>
    </div>
    <nav class="sidebar-menu">
        <ul>
            <?php foreach ($menuStructure as $menuKey => $menuInfo): 
                $allowedSubmenus = getAllowedSubmenus($menuKey, $menuStructure, $userPermissions);
                if (empty($allowedSubmenus)) continue;
                
                if (count($allowedSubmenus) === 1) {
                    $singleSubmenu = reset($allowedSubmenus);
                    $isActive = $currentPage === $singleSubmenu['file'];
                    ?>
                    <li>
                        <a href="<?= $singleSubmenu['path']; ?>" class="<?= $isActive ? 'active' : ''; ?>">
                            <span class="icon"><i class="fas <?= $menuInfo['icon']; ?>"></i></span>
                            <span><?= $singleSubmenu['name']; ?></span>
                        </a>
                    </li>
                    <?php
                } else {
                    ?>
                    <li>
                        <div class="sidebar-main-menu <?= $isMenuOpen[$menuKey] ? 'active' : ''; ?>" onclick="toggleSubMenu(this)">
                            <span class="icon"><i class="fas <?= $menuInfo['icon']; ?>"></i></span>
                            <span style="flex:1;"><?= $menuInfo['name']; ?></span>
                            <span class="dropdown-arrow"><i class="fas fa-chevron-down"></i></span>
                        </div>
                        <ul class="submenu <?= $isMenuOpen[$menuKey] ? 'show' : ''; ?>">
                            <?php foreach ($allowedSubmenus as $pageKey => $pageInfo): 
                                $isPageActive = $currentPage === $pageInfo['file'];
                                ?>
                                <li>
                                    <a href="<?= $pageInfo['path']; ?>" class="<?= $isPageActive ? 'active' : ''; ?>">
                                        <span><?= $pageInfo['name']; ?></span>
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

<div class="main-header" id="main-header">
    <div style="display: flex; align-items: center; gap: 18px;">
        <button id="sidebar-toggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div style="display: flex; align-items: center; gap: 20px;">
        <div style="text-align: right;">
            <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($userName); ?></div>
            <div style="font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($deptName); ?></div>
        </div>
        <div style="position: relative;">
            <img src="<?= htmlspecialchars($profileImg) ?>" id="profileDropdownToggle"
                 style="width: 40px; height: 40px; border-radius: 12px; object-fit: cover; border: 2px solid #e2e8f0; cursor: pointer;">
            
            <div id="profileDropdownMenu" style="display: none; position: absolute; right: 0; top: 52px; 
                background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
                z-index: 999; min-width: 180px; padding: 8px;">
                <a href="../Includes/profile_view.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; color: #475569; 
                   text-decoration: none; font-size: 14px; border-radius: 8px; transition: 0.2s;">
                    <i class="fas fa-user"></i> Profile View
                </a>
                <a href="../Includes/logout.php" style="display: flex; align-items: center; gap: 10px; padding: 12px; color: #ef4444; 
                   text-decoration: none; font-size: 14px; border-radius: 8px; transition: 0.2s;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSubMenu(element) {
    const submenu = element.nextElementSibling;
    const isShowing = submenu.classList.contains('show');
    
    // Close other menus
    document.querySelectorAll('.submenu').forEach(s => {
        if (s !== submenu) {
            s.classList.remove('show');
            s.previousElementSibling.classList.remove('active');
        }
    });

    element.classList.toggle('active', !isShowing);
    submenu.classList.toggle('show', !isShowing);
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const header = document.getElementById('main-header');
    const content = document.querySelector('.main-content');
    const footer = document.querySelector('.main-footer');
    
    const profileToggle = document.getElementById('profileDropdownToggle');
    const profileMenu = document.getElementById('profileDropdownMenu');

    sidebarToggle.addEventListener('click', () => {
        const isMobile = window.innerWidth <= 900;
        if (isMobile) {
            sidebar.classList.toggle('show');
        } else {
            sidebar.classList.toggle('hide');
            [header, content, footer].forEach(el => el?.classList.toggle('sidebar-collapsed'));
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('hide'));
        }
    });

    profileToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        profileMenu.style.display = profileMenu.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', () => {
        profileMenu.style.display = 'none';
        if (window.innerWidth <= 900) sidebar.classList.remove('show');
    });

    // Restore state
    if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 900) {
        sidebar.classList.add('hide');
        [header, content, footer].forEach(el => el?.classList.add('sidebar-collapsed'));
    }
});
</script>

<div class="main-footer">
    Designed and Developed by 
    <a href="https://cybaemtech.com/" target="_blank" style="color: #fbbf24; font-weight: 600; text-decoration: none; margin-left: 5px;">
        CybaemTech
    </a>
</div>
