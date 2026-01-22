<?php
$customSessionPath = dirname(__DIR__) . '/temp';
if (!is_dir($customSessionPath)) {
    @mkdir($customSessionPath, 0777, true);
}
if (is_writable($customSessionPath)) {
    ini_set('session.save_path', $customSessionPath);
}
session_start();
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';

if (!isset($_SESSION['operator_id'])) {
     header("Location: ../index.php");
    exit;
}

$operatorId = $_SESSION['operator_id'];

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $file = $_FILES['profile_photo'];
    $uploadMessage = '';
    $uploadType = 'danger';

    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        // Simple validation - just check file extension and add basic file header validation
        if (in_array($ext, $allowed) && isValidImageFile($file['tmp_name'])) {
            if ($file['size'] <= 5242880) {
                $uploadDir = '../uploads/';
                
                // Create directory with proper permissions if it doesn't exist
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        $uploadMessage = 'Failed to create upload directory!';
                        $uploadType = 'danger';
                    } else {
                        // Set proper permissions after creating
                        chmod($uploadDir, 0755);
                    }
                }
                
                // Check if directory is writable
                if (!is_writable($uploadDir)) {
                    $uploadMessage = 'Upload directory is not writable! Please contact your administrator.';
                    $uploadType = 'danger';
                } else {
                    $newName = 'profile_' . $operatorId . '.' . $ext;
                    $uploadPath = $uploadDir . $newName;

                    // Delete older profile photos for this user
                    $oldFiles = glob($uploadDir . 'profile_' . $operatorId . '.*');
                    foreach ($oldFiles as $oldFile) {
                        if ($oldFile !== $uploadPath) @unlink($oldFile);
                    }

                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        // Set proper file permissions
                        chmod($uploadPath, 0644);
                        
                        // SQLSRV update
                        $sql = "UPDATE users SET profile_photo = ? WHERE operator_id = ?";
                        $params = [$newName, $operatorId];
                        $stmt = sqlsrv_query($conn, $sql, $params);

                        if ($stmt) {
                            $uploadMessage = 'Profile photo updated successfully!';
                            $uploadType = 'success';
                            sqlsrv_free_stmt($stmt);
                        } else {
                            $uploadMessage = 'Database update failed!';
                            if (($errors = sqlsrv_errors()) !== null) {
                                foreach ($errors as $error) {
                                    $uploadMessage .= "<br>SQLSTATE: " . $error['SQLSTATE'];
                                    $uploadMessage .= "<br>Code: " . $error['code'];
                                    $uploadMessage .= "<br>Message: " . $error['message'];
                                }
                            }
                        }
                    } else {
                        $uploadMessage = 'Failed to upload file! Check directory permissions.';
                    }
                }
            } else {
                $uploadMessage = 'File size too large! Maximum 5MB allowed.';
            }
        } else {
            $uploadMessage = 'Invalid file type! Only JPG, JPEG, PNG, and WEBP images are allowed.';
        }
    } else {
        $uploadMessage = 'Upload error occurred!';
    }
}

// Simple function to validate if file is actually an image
function isValidImageFile($filePath) {
    $imageInfo = @getimagesize($filePath);
    return $imageInfo !== false;
}

// Fetch user info from SQL Server
$sql = "
    SELECT u.operator_id, u.user_name, u.email, d.department_name, u.profile_photo
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.operator_id = ?";
$params = [$operatorId];
$stmt = sqlsrv_query($conn, $sql, $params);

$opId = $userName = $email = $deptName = $profilePhoto = '';
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $opId = $row['operator_id'];
    $userName = $row['user_name'];
    $email = $row['email'];
    $deptName = $row['department_name'];
    $profilePhoto = $row['profile_photo'];
    sqlsrv_free_stmt($stmt);
}

$profileImg = ($profilePhoto && file_exists("../uploads/$profilePhoto"))
    ? "../uploads/$profilePhoto?v=" . time()
    : "../asset/admin.png";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Management - ERP System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-content {
            margin-left: 240px;
            padding: 30px;
            min-height: 100vh;
            transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out;
        }

        /* When sidebar is hidden */
        .sidebar.hide ~ .main-content,
        .main-content.sidebar-collapsed {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 35px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: slideInUp 0.6s ease-out;
        }

        .form-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px 30px;
            border-bottom: none;
        }

        .form-header h3 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-body {
            padding: 35px;
        }

        .profile-photo-section {
            text-align: center;
            margin-bottom: 40px;
            padding: 25px;
            background: linear-gradient(145deg, #f8f9ff, #ffffff);
            border-radius: 12px;
            border: 2px dashed #e1e8ff;
            transition: all 0.3s ease;
        }

        .profile-photo-section:hover {
            border-color: #667eea;
            background: linear-gradient(145deg, #f5f7ff, #ffffff);
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #667eea;
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.3);
            margin-bottom: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 20px 45px rgba(102, 126, 234, 0.4);
        }

        .upload-section {
            margin-top: 20px;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
            max-width: 300px;
            margin-bottom: 15px;
        }

        .file-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }

        .file-input-label {
            display: block;
            padding: 12px 25px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            text-align: center;
            border: none;
            width: 100%;
        }

        .file-input-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .upload-info {
            font-size: 0.85rem;
            color: #666;
            margin-top: 10px;
            padding: 8px;
            background: #fff3cd;
            border-radius: 6px;
            border-left: 3px solid #ffc107;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control-custom {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            color: #333;
            font-weight: 500;
        }

        .form-control-custom:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .input-icon {
            color: #667eea;
            font-size: 1.1rem;
        }

        .btn-section {
            text-align: center;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid #f1f3f4;
        }

        .btn-custom {
            padding: 15px 40px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            margin: 0 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-upload {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }

        .btn-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .btn-back {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
            color: white;
        }

        .alert-custom {
            border: none;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
            animation: slideInDown 0.5s ease-out;
        }

        .alert-success {
            background: linear-gradient(45deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: linear-gradient(45deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Animations */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .form-body {
                padding: 25px;
            }
            
            .profile-details {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .profile-avatar {
                width: 120px;
                height: 120px;
            }
            
            .page-title {
                font-size: 1.6rem;
            }
        }

        @media (max-width: 600px) {
            .main-content {
                padding: 15px;
            }
            
            .form-body {
                padding: 20px;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
            }
            
            .page-title {
                font-size: 1.4rem;
            }
            
            .btn-custom {
                padding: 12px 30px;
                font-size: 0.9rem;
            }
        }

        /* Sidebar responsive adjustments */
        @media (max-width: 900px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-user-cog"></i>
                Profile Management
            </h1>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($uploadMessage)): ?>
            <div class="alert alert-<?= $uploadType ?> alert-custom">
                <i class="fas <?= $uploadType == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i>
                <?= htmlspecialchars($uploadMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Profile Form -->
        <div class="profile-form-container">
            <div class="form-header">
                <h3>
                    <i class="fas fa-user"></i>
                    Personal Information
                </h3>
            </div>
            
            <div class="form-body">
                <!-- Profile Photo Section -->
                <div class="profile-photo-section">
                    <h5 class="mb-3" style="color: #667eea; font-weight: 600;">
                        <i class="fas fa-camera me-2"></i>Profile Photo
                    </h5>
                    
                    <img src="<?= htmlspecialchars($profileImg) ?>" alt="Profile" class="profile-avatar" id="profileImage" onclick="document.getElementById('profilePhotoInput').click()">
                    
                    <div class="upload-section">
                        <form method="post" enctype="multipart/form-data" id="uploadForm">
                            <div class="file-input-wrapper">
                                <input type="file" name="profile_photo" accept="image/jpeg,image/jpg,image/png,image/webp" class="file-input" id="profilePhotoInput" onchange="handleFileSelect(this)">
                                <label for="profilePhotoInput" class="file-input-label">
                                    <i class="fas fa-cloud-upload-alt me-2"></i>
                                    Choose New Photo
                                </label>
                            </div>
                            
                            <div class="upload-info">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Requirements:</strong> JPG, PNG, WEBP only • Max 5MB • Square images recommended
                            </div>
                            
                            <button type="submit" class="btn-custom btn-upload mt-3" id="uploadBtn" style="display: none;">
                                <i class="fas fa-upload"></i>
                                Upload Photo
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Profile Details Form -->
                <form>
                    <div class="profile-details">
                        <!-- Operator ID -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-id-badge input-icon"></i>
                                Operator ID
                            </label>
                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($opId) ?>" readonly>
                        </div>

                        <!-- Full Name -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user input-icon"></i>
                                Full Name
                            </label>
                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($userName) ?>" readonly>
                        </div>

                        <!-- Email Address -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope input-icon"></i>
                                Email Address
                            </label>
                            <input type="email" class="form-control-custom" value="<?= htmlspecialchars($email) ?>" readonly>
                        </div>

                        <!-- Department -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-building input-icon"></i>
                                Department
                            </label>
                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($deptName ?: 'Not Assigned') ?>" readonly>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="btn-section">
                        <a href="javascript:history.back();" class="btn-custom btn-back">
                            <i class="fas fa-arrow-left"></i>
                            Go Back
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle file selection with validation
        function handleFileSelect(input) {
            const uploadBtn = document.getElementById('uploadBtn');
            const label = document.querySelector('.file-input-label');
            const profileImage = document.getElementById('profileImage');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileName = file.name;
                const fileSize = file.size;
                const fileType = file.type;
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                if (!allowedTypes.includes(fileType)) {
                    alert('Invalid file type! Please select a JPG, PNG, or WEBP image.');
                    input.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (fileSize > 5242880) {
                    alert('File too large! Please select an image smaller than 5MB.');
                    input.value = '';
                    return;
                }
                
                // Update UI
                label.innerHTML = `<i class="fas fa-file-image me-2"></i>${fileName}`;
                uploadBtn.style.display = 'inline-flex';
                
                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    profileImage.src = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                label.innerHTML = '<i class="fas fa-cloud-upload-alt me-2"></i>Choose New Photo';
                uploadBtn.style.display = 'none';
            }
        }

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

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>