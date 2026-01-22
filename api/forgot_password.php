<?php
// Set headers for API response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];

// Handle OPTIONS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST method
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Include email configuration
$email_config = [];
if (file_exists('../Includes/email_config.php')) {
    $email_config = include '../Includes/email_config.php';
}

// Include email helper functions
if (file_exists('../Includes/email_helper.php')) {
    include '../Includes/email_helper.php';
}

// Include database connection
try {
    include '../Includes/db_connect.php';
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Forgot Password API: Database connection error - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // Get and validate input data
    $rawInput = file_get_contents('php://input');
    if ($email_config['debug_mode'] ?? false) {
        error_log("Forgot Password API: Raw input - " . $rawInput);
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Forgot Password API: JSON decode error - " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()]);
        exit;
    }
    
} catch (Exception $e) {
    error_log("Forgot Password API: Input processing error - " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Error processing input data']);
    exit;
}

// Validate required fields
if (!$data || empty($data['email'])) {
    error_log("Forgot Password API: Missing email field");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

$email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    error_log("Forgot Password API: Invalid email format - " . $data['email']);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

try {
    // Check rate limiting (optional)
    $max_attempts = $email_config['max_reset_attempts'] ?? 3;
    $current_date = date('Y-m-d');
    
    // Count attempts today
    $attemptSql = "SELECT COUNT(*) as attempt_count FROM password_reset_tokens 
                   WHERE user_id IN (SELECT id FROM users WHERE email = ?) 
                   AND CAST(created_at AS DATE) = ?";
    $attemptStmt = sqlsrv_prepare($conn, $attemptSql, [$email, $current_date]);
    
    if ($attemptStmt && sqlsrv_execute($attemptStmt)) {
        $attemptResult = sqlsrv_fetch_array($attemptStmt, SQLSRV_FETCH_ASSOC);
        $todayAttempts = $attemptResult['attempt_count'] ?? 0;
        sqlsrv_free_stmt($attemptStmt);
        
        if ($todayAttempts >= $max_attempts) {
            echo json_encode([
                'success' => false, 
                'message' => 'Too many password reset attempts today. Please try again tomorrow.'
            ]);
            exit;
        }
    }
    
    // Check if user exists with this email
    $sql = "SELECT id, operator_id, user_name, email FROM users WHERE email = ?";
    $stmt = sqlsrv_prepare($conn, $sql, [$email]);
    
    if (!$stmt || !sqlsrv_execute($stmt)) {
        throw new Exception("Database query failed");
    }
    
    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$user) {
        // Don't reveal if email exists or not for security
        echo json_encode([
            'success' => true, 
            'message' => 'If this email exists in our system, you will receive a password reset link shortly.'
        ]);
        exit;
    }
    
    // Generate password reset token
    $token = bin2hex(random_bytes(32)); // 64 character token
    $expiry_hours = $email_config['token_expiry_hours'] ?? 1;
    $expiry = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hour"));
    
    // Store token in database
    $insertSql = "INSERT INTO password_reset_tokens (user_id, token, expiry, used) VALUES (?, ?, ?, 0)";
    $insertStmt = sqlsrv_prepare($conn, $insertSql, [$user['id'], $token, $expiry]);
    
    if (!$insertStmt || !sqlsrv_execute($insertStmt)) {
        throw new Exception("Failed to store reset token");
    }
    sqlsrv_free_stmt($insertStmt);
    
    // Prepare reset link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $resetLink = $protocol . "://" . $host . "/Aabha/reset_password.php?token=" . $token;
    
    // Prepare email content
    $subject = $email_config['subject_template'] ?? 'Password Reset Request - Aabha Contraceptive System';
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #0d6efd, #6610f2); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: #6610f2; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Password Reset Request</h2>
                <p>Aabha Contraceptive System</p>
            </div>
            <div class='content'>
                <h3>Hello " . htmlspecialchars($user['user_name']) . ",</h3>
                <p>We received a request to reset your password for your Aabha Contraceptive System account.</p>
                
                <p><strong>Account Details:</strong></p>
                <ul>
                    <li>Operator ID: " . htmlspecialchars($user['operator_id']) . "</li>
                    <li>Email: " . htmlspecialchars($user['email']) . "</li>
                </ul>
                
                <p>Click the button below to reset your password:</p>
                <p><a href='" . $resetLink . "' class='button'>Reset Password</a></p>
                
                <p>Or copy and paste this link into your browser:</p>
                <p><a href='" . $resetLink . "'>" . $resetLink . "</a></p>
                
                <div class='warning'>
                    <h4>Important Security Information:</h4>
                    <ul>
                        <li>This link will expire in {$expiry_hours} hour(s)</li>
                        <li>If you didn't request this reset, please ignore this email</li>
                        <li>For security, this link can only be used once</li>
                        <li>Contact your system administrator if you need assistance</li>
                    </ul>
                </div>
            </div>
            <div class='footer'>
                <p>This is an automated email from Aabha Contraceptive System. Please do not reply to this email.</p>
                <p>Generated on: " . date('Y-m-d H:i:s') . "</p>
                <p>Request from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "</p>
            </div>
        </div>
    </body>
    </html>";
    
    // Email headers and send via helper
    $from_email = $email_config['from_email'] ?? 'noreply@aabha.com';
    $from_name = $email_config['from_name'] ?? 'Aabha System';
    $reply_to = $email_config['reply_to'] ?? $from_email;

    // Use the helper to send the HTML email; fallback will save to file if mail() fails
    $sendResult = ['success' => false, 'message' => 'Not attempted'];
    if (function_exists('send_html_mail')) {
        $sendResult = send_html_mail($email, $subject, $message, $email_config);
    } else {
        // As a last resort, try PHP mail() directly with basic headers
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$from_name} <{$from_email}>" . "\r\n";
        $headers .= "Reply-To: {$reply_to}" . "\r\n";
        try {
            $mailOk = mail($email, $subject, $message, $headers);
            $sendResult = $mailOk ? ['success' => true, 'message' => 'Mail sent via mail()'] : ['success' => false, 'message' => 'mail() returned false'];
        } catch (Throwable $e) {
            $sendResult = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    if (($sendResult['success'] ?? false)) {
        if ($email_config['log_emails'] ?? false) {
            error_log("Forgot Password API: Email sent successfully to " . $email . " for user ID: " . $user['id']);
        }

        $response_message = 'Password reset link has been sent to your email address.';
        if ($email_config['write_emails_to_file'] ?? false) {
            $response_message .= ' (If mail() is not configured, the email was saved to disk.)';
        }

        echo json_encode([
            'success' => true,
            'message' => $response_message
        ]);
    } else {
        // Log the failure detail for admins
        if ($email_config['log_emails'] ?? false) {
            error_log("Forgot Password API: Email send failed for {$email}. Detail: " . ($sendResult['message'] ?? 'no detail'));
            if (!empty($sendResult['file'])) {
                error_log("Forgot Password API: Email saved to file: " . $sendResult['file']);
            }
        }

        // Don't reveal email sending failure to the user
        echo json_encode([
            'success' => true,
            'message' => 'If this email exists in our system, you will receive a password reset link shortly.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Forgot Password API: Error - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
}
?>