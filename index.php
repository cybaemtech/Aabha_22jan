<?php
// Include server configuration
if (file_exists('server_config.php')) {
    include 'server_config.php';
} else {
    // Default configuration if server_config doesn't exist
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
}

// Include centralized session management
include 'Includes/session_manager.php';

// Check for timeout parameter and other messages
$error = '';
$success = '';

if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error = 'Your session has expired. Please login again.';
} elseif (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_token':
            $error = 'Invalid or expired password reset link.';
            break;
        case 'expired_token':
            $error = 'Password reset link has expired. Please request a new one.';
            break;
        case 'system_error':
            $error = 'System error occurred. Please try again later.';
            break;
    }
} elseif (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'password_reset':
            $success = 'Password reset successfully! You can now login with your new password.';
            break;
    }
}

// Include database connection with error handling
$conn = null;
try {
    if (file_exists('Includes/db_connect.php')) {
        include 'Includes/db_connect.php';
    } else {
        throw new Exception("Database connection file not found");
    }
    
    // Check if database connection is established
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    
    // Show friendly error message
    $error = "System temporarily unavailable. Please try again later.";
    
    // In debug mode, show more details
    if (isset($app_config) && $app_config['debug_mode']) {
        $error .= " (Debug: " . $e->getMessage() . ")";
    }
}

$error = '';

// If there was a database connection issue, display it
if (!$conn && !isset($error)) {
    $error = "System temporarily unavailable. Please try again later.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $operator_id = trim($_POST['operator_id']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE operator_id = ?";
    $params = array($operator_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);

    if ($stmt && sqlsrv_execute($stmt)) {
        $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['operator_id'] = $user['operator_id'];
            $_SESSION['user_name'] = $user['user_name'];
            $_SESSION['user_role'] = $user['user_role'] ?? 'user';
            $_SESSION['department_id'] = $user['department_id'] ?? null;
            
            // Parse menu permissions properly based on your database format
            $menu_permissions = [];
            if (!empty($user['menu_permission'])) {
                $rawPermissions = trim($user['menu_permission']);
                
                // Check if it's JSON format
                $decodedPermissions = json_decode($rawPermissions, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPermissions)) {
                    // Handle JSON format like: {"dashboard":{"read":true},"admin":{"read":true}}
                    foreach ($decodedPermissions as $menu => $permissions) {
                        if (is_array($permissions) && isset($permissions['read']) && $permissions['read'] === true) {
                            $menu_permissions[] = strtolower(str_replace(' ', '_', $menu));
                        }
                    }
                } else {
                    // Handle simple string formats
                    if (strtolower($rawPermissions) === 'all') {
                        $menu_permissions = ['all'];
                    } else {
                        // Handle comma-separated format like: "dashboard,admin,master"
                        $permissions = explode(',', $rawPermissions);
                        foreach ($permissions as $permission) {
                            $cleanPermission = strtolower(trim($permission));
                            if (!empty($cleanPermission)) {
                                $menu_permissions[] = $cleanPermission;
                            }
                        }
                    }
                }
            }
            
            $_SESSION['menu_permissions'] = $menu_permissions;
            
            // Use flexible redirect that works on both local and server
            $redirect_url = 'Master/dashboard.php';
            
            // If base_url is configured, use it
            if (isset($app_config['base_url']) && $app_config['base_url'] !== '/') {
                $redirect_url = $app_config['base_url'] . $redirect_url;
            }
            
            header("Location: $redirect_url");
            exit;
        } else {
            $error = "Invalid Operator ID or Password.";
        }
        sqlsrv_free_stmt($stmt);
    } else {
        $error = "Database error occurred. Please try again.";
        error_log("SQLSRV Error: " . print_r(sqlsrv_errors(), true));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Aabha Contraceptive</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: url('asset/home-page-img.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Segoe UI', sans-serif;
            animation: panBg 30s linear infinite;
        }

        @keyframes panBg {
            0% { background-position: center center; }
            50% { background-position: center top; }
            100% { background-position: center center; }
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.57);
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            animation: fadeInDown 1s ease;
        }

        @keyframes fadeInDown {
            0% {
                opacity: 0;
                transform: translateY(-50px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            color: white;
            text-align: center;
            padding: 1.2rem;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .btn-custom {
            background-color: #6610f2;
            border: none;
            transition: 0.3s ease;
        }

        .btn-custom:hover {
            background-color: #4b0ecf;
            transform: translateY(-2px);
        }

        .form-control:focus {
            box-shadow: 0 0 5px rgba(102, 16, 242, 0.5);
            border-color: #6610f2;
        }

        .alert {
            border-radius: 10px;
        }

        .welcome {
            font-size: 1.1rem;
        }
        
        .forgot-password {
            text-align: center;
            margin-top: 15px;
        }
        
        .forgot-password a {
            color: #6610f2;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        /* Forgot Password Modal Styles */
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #6610f2, #0d6efd);
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        
        .btn-send-reset {
            background: linear-gradient(135deg, #6610f2, #0d6efd);
            border: none;
            transition: all 0.3s ease;
        }
        
        .btn-send-reset:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 16, 242, 0.3);
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="card">
        <div class="card-header">
            <div class="welcome">
                <strong>Welcome, Aabha Contraceptive</strong><br>
                <small>Please login to continue</small>
            </div>
        </div>
        <div class="card-body px-4 py-3">
            <?php if ($error): ?>
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-user me-2"></i>Operator ID
                    </label>
                    <input type="text" class="form-control" name="operator_id" required 
                           placeholder="Enter Operator ID" value="<?php echo htmlspecialchars($_POST['operator_id'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-lock me-2"></i>Password
                    </label>
                    <input type="password" class="form-control" id="password" name="password" required 
                           placeholder="Enter Password">
                </div>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-custom btn-lg text-white">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                </div>
            </form>
            
            <div class="forgot-password">
                <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                    <i class="fas fa-key me-1"></i>Forgot Password?
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Forgot Password Modal -->
<div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="forgotPasswordModalLabel">
                    <i class="fas fa-key me-2"></i>Reset Password
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="forgotPasswordAlert"></div>
                
                <p class="text-muted mb-3">
                    Enter your email address and we'll send you a link to reset your password.
                </p>
                
                <form id="forgotPasswordForm">
                    <div class="mb-3">
                        <label for="resetEmail" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Address
                        </label>
                        <input type="email" class="form-control" id="resetEmail" name="email" required 
                               placeholder="Enter your email address">
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-send-reset btn-primary" id="sendResetBtn">
                            <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Add loading state to login button
document.querySelector('form').addEventListener('submit', function() {
    const button = this.querySelector('button[type="submit"]');
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging in...';
    button.disabled = true;
});

// Clear any previous error on new input
document.querySelectorAll('input').forEach(input => {
    input.addEventListener('input', function() {
        const alert = document.querySelector('.alert-danger');
        if (alert) {
            alert.style.display = 'none';
        }
    });
});

// Forgot Password Form Handler
document.getElementById('forgotPasswordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = this;
    const email = document.getElementById('resetEmail').value;
    const submitBtn = document.getElementById('sendResetBtn');
    const alertDiv = document.getElementById('forgotPasswordAlert');
    
    // Validate email
    if (!email || !email.includes('@')) {
        showAlert('danger', 'Please enter a valid email address.');
        return;
    }
    
    // Show loading state
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('api/forgot_password.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ email: email })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('success', data.message);
            form.reset();
            
            // Auto close modal after 3 seconds
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal'));
                modal.hide();
            }, 3000);
        } else {
            showAlert('danger', data.message || 'An error occurred. Please try again.');
        }
        
    } catch (error) {
        console.error('Forgot password error:', error);
        showAlert('danger', 'Network error. Please check your connection and try again.');
    }
    
    // Reset button state
    submitBtn.innerHTML = originalBtnText;
    submitBtn.disabled = false;
});

function showAlert(type, message) {
    const alertDiv = document.getElementById('forgotPasswordAlert');
    const iconClass = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
    
    alertDiv.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="${iconClass} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
}

// Clear alert when modal is opened
document.getElementById('forgotPasswordModal').addEventListener('shown.bs.modal', function() {
    document.getElementById('forgotPasswordAlert').innerHTML = '';
    document.getElementById('forgotPasswordForm').reset();
});
</script>
</body>
</html>
