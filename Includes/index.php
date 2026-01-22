<?php
include 'db_connect.php'; // Fixed path - added 'Includes/' folder
session_start();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operator_id = trim($_POST['operator_id']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE operator_id = ?";
    $params = array($operator_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);

    if ($stmt && sqlsrv_execute($stmt)) {
        $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id']; // integer primary key from users table
            $_SESSION['operator_id'] = $user['operator_id']; // string, for display only
            $_SESSION['user_name'] = $user['user_name'];
            $_SESSION['user_role'] = $user['user_role'] ?? 'user';
            $_SESSION['department_id'] = $user['department_id'] ?? null;
            
            // Parse menu permissions
            $menu_permissions = [];
            if (!empty($user['menu_permission'])) {
                $permissions = explode(',', $user['menu_permission']);
                foreach ($permissions as $permission) {
                    $parts = explode(':', trim($permission));
                    if (count($parts) == 2) {
                        $menu_permissions[$parts[0]] = explode('|', $parts[1]);
                    }
                }
            }
            $_SESSION['menu_permissions'] = $menu_permissions;
            
            header("Location: ../master/dashboard.php");
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
        </div>
    </div>
</div>

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
</script>
</body>
</html>
