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

// Display success/error messages
$message = '';
$messageType = '';
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = 'User deleted successfully!';
    $messageType = 'success';
} elseif (isset($_GET['error'])) {
    $message = 'Failed to delete user. Please try again.';
    $messageType = 'danger';
}

// Search logic
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];
$where = '';

if ($search !== '') {
    $where = "WHERE operator_id LIKE ? OR user_name LIKE ?";
    $likeSearch = '%' . $search . '%';
    $params = [$likeSearch, $likeSearch];
}

// Build the query
$query = "SELECT id, operator_id, user_name, email, menu_permission, profile_photo FROM users WHERE is_deleted = 0 $where ORDER BY id DESC";

// Prepare and execute
$stmt = sqlsrv_prepare($conn, $query, $params);
if (!$stmt) {
    die("Query preparation failed: " . print_r(sqlsrv_errors(), true));
}
if (!sqlsrv_execute($stmt)) {
    die("Query execution failed: " . print_r(sqlsrv_errors(), true));
}

// Fetch data
$users = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $users[] = $row;
}
$total = count($users);

// Cleanup
sqlsrv_free_stmt($stmt);
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Registration Lookup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        .main-content { margin-left: 240px; padding: 30px; min-height: 100vh; background: #f4f6f8; }
        .lookup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 30px 20px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            text-align: center;
        }
        .lookup-header h2 { font-weight: 700; font-size: 2rem; margin-bottom: 8px; }
        .lookup-header .subtitle { font-size: 1.1rem; color: #e0e0e0; }
        .search-bar { max-width: 400px; }
        .profile-img {
            width: 48px; height: 48px; border-radius: 50%; object-fit: cover;
            border: 2px solid #667eea; background: #f8f9fa;
        }
        .menu-permissions-cell { max-width: 300px; min-width: 200px; padding: 8px !important; }
        .menu-badge {
            display: inline-block; background: #e7f1ff; color: #2d3e50;
            border-radius: 12px; padding: 3px 8px; font-size: 11px; font-weight: 500;
            margin: 2px 2px; white-space: nowrap; border: 1px solid #c3d9ff;
        }
        .admin-badge { background: #d4edda !important; color: #155724 !important; border-color: #c3e6cb !important; font-weight: 600; }
        .permissions-container { display: flex; flex-wrap: wrap; gap: 3px; justify-content: flex-start; align-items: center; max-height: 80px; overflow-y: auto; padding: 2px; }
        .table thead th { color: black; font-size: 1.05em; text-align: center; background: #f8f9fa; }
        .table td, .table th { vertical-align: middle !important; text-align: center; }
        .action-icons a { margin: 0 6px; font-size: 1.1em; }
        .action-icons .fa-edit { color: #007bff; }
        .action-icons .fa-trash { color: #dc3545; }
        .record-count { font-weight: 600; color: #667eea; }
        @media (max-width: 900px) { .main-content { margin-left: 0; padding: 10px; } }
        @media (max-width: 600px) {
            .main-content { padding: 5px; }
            .lookup-header h2 { font-size: 1.2rem; }
            .profile-img { width: 36px; height: 36px; }
        }
    </style>
</head>
<body>
<div class="main-content">
    <div class="lookup-header">
        <h2><i class="fas fa-users me-2"></i>User Registration Lookup</h2>
        <div class="subtitle">View, search, and manage registered users</div>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-center">
        <form class="d-flex mb-2 mb-md-0 search-bar" method="get">
            <input type="text" class="form-control me-2" name="search" placeholder="Search by Operator ID or Name" value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a href="user_registrationLookup.php" class="btn btn-outline-danger ms-2">Clear</a>
            <?php endif; ?>
        </form>
        <div class="record-count">
            <i class="fas fa-database me-1"></i> Total Records: <span class="badge bg-primary"><?= $total ?></span>
        </div>
        <a href="user_registration.php?add=1" class="btn btn-success ms-md-2 mt-2 mt-md-0">
            <i class="fas fa-plus"></i> Add New User
        </a>
    </div>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Profile</th>
                            <th>Operator ID</th>
                            <th>User Name</th>
                            <th>Email</th>
                            <th>Menu Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                      <?php if (count($users) > 0): ?>
                        <?php foreach($users as $row): ?>
                            <tr>
                                <td class="action-icons">
                                    <a href="user_registration.php?edit=<?= urlencode($row['id']) ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                    <a href="user_delete.php?id=<?= urlencode($row['id']) ?>" title="Delete" onclick="return confirm('Are you sure you want to delete this user?');"><i class="fas fa-trash"></i></a>
                                </td>
                                <td>
                                    <?php
                                    $img = ($row['profile_photo'] && file_exists("../uploads/{$row['profile_photo']}"))
                                        ? "../uploads/{$row['profile_photo']}"
                                        : "../asset/admin.png";
                                    ?>
                                    <img src="<?= htmlspecialchars($img) ?>" class="profile-img" alt="Profile">
                                </td>
                                <td><?= htmlspecialchars($row['operator_id']) ?></td>
                                <td><?= htmlspecialchars($row['user_name']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td class="menu-permissions-cell">
                                    <div class="permissions-container">
                                        <?php
                                        $menus = array_filter(array_map('trim', explode(',', $row['menu_permission'] ?? '')));
                                        if (count($menus) === 0) {
                                            echo '<span class="text-muted">No Permissions</span>';
                                        } else {
                                            foreach($menus as $menu) {
                                                if ($menu === 'admin_all') {
                                                    echo '<span class="menu-badge admin-badge">
                                                            <i class="fas fa-crown" style="font-size: 9px;"></i> Admin
                                                          </span>';
                                                } else {
                                                    $displayName = strlen($menu) > 15 ? substr($menu, 0, 12) . '...' : $menu;
                                                    echo '<span class="menu-badge" title="'.htmlspecialchars($menu).'">'.htmlspecialchars($displayName).'</span>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-user-slash fa-2x mb-2"></i><br>
                                No users found.
                            </td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>