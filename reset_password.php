<?php
// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    header('Location: index.php?error=invalid_token');
    exit;
}

$token = $_GET['token'];

// Include database connection
try {
    include 'Includes/db_connect.php';
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Reset Password: Database connection error - " . $e->getMessage());
    header('Location: index.php?error=system_error');
    exit;
}

// Verify token
try {
    $sql = "SELECT prt.user_id, prt.expiry, prt.used, u.user_name, u.operator_id, u.email 
            FROM password_reset_tokens prt 
            INNER JOIN users u ON prt.user_id = u.id 
            WHERE prt.token = ? AND prt.used = 0";
    
    $stmt = sqlsrv_prepare($conn, $sql, [$token]);
    
    if (!$stmt || !sqlsrv_execute($stmt)) {
        throw new Exception("Database query failed");
    }
    
    $resetData = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$resetData) {
        header('Location: index.php?error=invalid_token');
        exit;
    }
    
    // Check if token has expired
    $currentTime = new DateTime();
    $expiryTime = $resetData['expiry'];
    
    if ($currentTime > $expiryTime) {
        header('Location: index.php?error=expired_token');
        exit;
    }
    
} catch (Exception $e) {
    error_log("Reset Password: Token verification error - " . $e->getMessage());
    header('Location: index.php?error=system_error');
    exit;
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = "All fields are required";
    } elseif (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match";
    } else {
        try {
            // Hash the new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update user password (using 'password' field as per database structure)
            $updateSql = "UPDATE users SET password = ? WHERE id = ?";
            $updateStmt = sqlsrv_prepare($conn, $updateSql, [$hashedPassword, $resetData['user_id']]);
            
            if (!$updateStmt || !sqlsrv_execute($updateStmt)) {
                throw new Exception("Failed to update password");
            }
            sqlsrv_free_stmt($updateStmt);
            
            // Mark token as used
            $markUsedSql = "UPDATE password_reset_tokens SET used = 1 WHERE token = ?";
            $markUsedStmt = sqlsrv_prepare($conn, $markUsedSql, [$token]);
            
            if (!$markUsedStmt || !sqlsrv_execute($markUsedStmt)) {
                throw new Exception("Failed to mark token as used");
            }
            sqlsrv_free_stmt($markUsedStmt);
            
            $success = "Password reset successfully! You can now login with your new password.";
            
        } catch (Exception $e) {
            error_log("Reset Password: Update error - " . $e->getMessage());
            $error = "An error occurred while resetting your password. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Aabha Contraceptive System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .reset-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 450px;
            margin: 80px auto;
            padding: 40px;
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #6610f2, #0d6efd);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 2rem;
        }
        
        .password-input-group {
            position: relative;
            margin-bottom: 20px;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 10;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 12px 45px 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #6610f2;
            box-shadow: 0 0 0 0.2rem rgba(102, 16, 242, 0.25);
        }
        
        .btn-reset {
            background: linear-gradient(135deg, #6610f2, #0d6efd);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 16, 242, 0.4);
        }
        
        .password-requirements {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            font-size: 0.9rem;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            margin: 5px 0;
        }
        
        .requirement i {
            margin-right: 8px;
            width: 16px;
        }
        
        .requirement.valid {
            color: #198754;
        }
        
        .requirement.invalid {
            color: #dc3545;
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-to-login a {
            color: #6610f2;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-to-login a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <div class="logo-section">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3 class="text-primary mb-2">Reset Password</h3>
                <p class="text-muted mb-0">Aabha Contraceptive System</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div class="back-to-login">
                    <a href="index.php">
                        <i class="fas fa-arrow-left me-2"></i>
                        Back to Login
                    </a>
                </div>
            <?php else: ?>
                <div class="user-info mb-3">
                    <p class="mb-1"><strong>User:</strong> <?php echo htmlspecialchars($resetData['user_name']); ?></p>
                    <p class="mb-1"><strong>Operator ID:</strong> <?php echo htmlspecialchars($resetData['operator_id']); ?></p>
                    <p class="mb-0 text-muted"><strong>Email:</strong> <?php echo htmlspecialchars($resetData['email']); ?></p>
                </div>
                
                <form method="POST" id="resetForm">
                    <div class="password-input-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                            <i class="far fa-eye" id="new_password_eye"></i>
                        </button>
                    </div>
                    
                    <div class="password-input-group">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="far fa-eye" id="confirm_password_eye"></i>
                        </button>
                    </div>
                    
                    <div class="password-requirements">
                        <h6>Password Requirements:</h6>
                        <div class="requirement" id="req_length">
                            <i class="fas fa-times"></i>
                            <span>At least 6 characters</span>
                        </div>
                        <div class="requirement" id="req_match">
                            <i class="fas fa-times"></i>
                            <span>Passwords match</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-reset" id="submitBtn" disabled>
                        <i class="fas fa-key me-2"></i>
                        Reset Password
                    </button>
                </form>
                
                <div class="back-to-login">
                    <a href="index.php">
                        <i class="fas fa-arrow-left me-2"></i>
                        Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const eye = document.getElementById(fieldId + '_eye');
            
            if (field.type === 'password') {
                field.type = 'text';
                eye.className = 'far fa-eye-slash';
            } else {
                field.type = 'password';
                eye.className = 'far fa-eye';
            }
        }
        
        function updateRequirement(reqId, isValid) {
            const req = document.getElementById(reqId);
            const icon = req.querySelector('i');
            
            if (isValid) {
                req.className = 'requirement valid';
                icon.className = 'fas fa-check';
            } else {
                req.className = 'requirement invalid';
                icon.className = 'fas fa-times';
            }
        }
        
        function validateForm() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const submitBtn = document.getElementById('submitBtn');
            
            // Check length
            const lengthValid = newPassword.length >= 6;
            updateRequirement('req_length', lengthValid);
            
            // Check match
            const matchValid = newPassword === confirmPassword && confirmPassword !== '';
            updateRequirement('req_match', matchValid);
            
            // Enable/disable submit button
            submitBtn.disabled = !(lengthValid && matchValid);
        }
        
        // Add event listeners
        document.getElementById('new_password').addEventListener('input', validateForm);
        document.getElementById('confirm_password').addEventListener('input', validateForm);
        
        // Form submission
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return;
            }
        });
    </script>
</body>
</html>