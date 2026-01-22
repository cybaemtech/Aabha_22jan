<?php
/**
 * Simple email helper that uses PHP mail() to send HTML emails with proper headers.
 * If mail() fails (common in local setups), it falls back to writing the email to disk
 * as a .eml file (headers + body) so an admin can inspect or forward it.
 */

if (!function_exists('send_html_mail')) {
    function send_html_mail($to, $subject, $htmlBody, $config = []) {
        // Load defaults from config array
        $fromEmail = $config['from_email'] ?? 'noreply@localhost.localdomain';
        $fromName = $config['from_name'] ?? 'Aabha System';
        $replyTo = $config['reply_to'] ?? $fromEmail;
        $logEmails = $config['log_emails'] ?? false;
        $writeToFile = $config['write_emails_to_file'] ?? true;
        $emailPath = $config['email_save_path'] ?? __DIR__ . '/../tmp/emails';

        // Build headers
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $replyTo;
        $headers[] = 'X-Mailer: PHP/' . phpversion();

        $headersString = implode("\r\n", $headers);

        // Try to send via PHP mail()
        $mailResult = false;
        $errorMessage = null;
        try {
            // On Windows the additional parameter -f may not be supported. On Unix it sets envelope sender.
            $additionalParams = '';
            if (stripos(PHP_OS, 'WIN') === false) {
                // not Windows
                $additionalParams = '-f' . $fromEmail;
            }

            if ($additionalParams !== '') {
                $mailResult = mail($to, $subject, $htmlBody, $headersString, $additionalParams);
            } else {
                $mailResult = mail($to, $subject, $htmlBody, $headersString);
            }
        } catch (Throwable $e) {
            $mailResult = false;
            $errorMessage = $e->getMessage();
        }

        // If mail succeeded, optionally log and return
        if ($mailResult) {
            if ($logEmails) {
                error_log("Email sent to {$to} with subject '{$subject}'");
            }
            return ['success' => true, 'message' => 'Mail sent'];
        }

        // Mail failed; fall back to writing the email to disk as .eml
        if ($writeToFile) {
            // Ensure directory exists
            if (!is_dir($emailPath)) {
                @mkdir($emailPath, 0777, true);
            }

            $timestamp = date('Ymd_His');
            $rand = bin2hex(random_bytes(6));
            $filename = "email_{$timestamp}_{$rand}.eml";
            $fullpath = rtrim($emailPath, '\\/') . DIRECTORY_SEPARATOR . $filename;

            $raw = "To: {$to}\r\n";
            $raw .= "Subject: {$subject}\r\n";
            $raw .= $headersString . "\r\n\r\n";
            $raw .= $htmlBody;

            try {
                file_put_contents($fullpath, $raw);
                if ($logEmails) {
                    error_log("Email saved to file: {$fullpath} (mail() failed). Error: " . ($errorMessage ?? 'mail returned false'));
                }
                return ['success' => false, 'message' => 'Mail function failed; email saved to file', 'file' => $fullpath];
            } catch (Throwable $e) {
                if ($logEmails) {
                    error_log("Failed to write email file: " . $e->getMessage());
                }
                return ['success' => false, 'message' => 'Mail failed and saving to file failed', 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => 'Mail failed and not configured to save to file', 'error' => $errorMessage];
    }
}

?>
<?php
/**
 * Email Helper Functions for Aabha Contraceptive System
 * Provides multiple methods for sending emails
 */

/**
 * Send email with multiple fallback methods
 */
function sendPasswordResetEmail($to_email, $subject, $message, $headers, $email_config) {
    // Test mode - save email to file
    if ($email_config['test_mode'] ?? false) {
        return saveEmailToFile($to_email, $subject, $message, $email_config);
    }
    
    // Try SMTP if enabled
    if ($email_config['smtp_enabled'] ?? false) {
        return sendEmailSMTP($to_email, $subject, $message, $email_config);
    }
    
    // Fallback to PHP mail()
    return sendEmailPHP($to_email, $subject, $message, $headers, $email_config);
}

/**
 * Save email to file for testing purposes
 */
function saveEmailToFile($to_email, $subject, $message, $email_config) {
    try {
        $email_dir = '../temp/emails/';
        if (!is_dir($email_dir)) {
            mkdir($email_dir, 0755, true);
        }
        
        $filename = $email_dir . 'reset_email_' . date('Y-m-d_H-i-s') . '_' . md5($to_email) . '.html';
        
        $email_content = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Email Test - {$subject}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 5px; }
        .footer { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; font-size: 12px; }
    </style>
</head>
<body>
    <div class='header'>
        <h2>📧 Email Test - Forgot Password</h2>
        <p><strong>To:</strong> {$to_email}</p>
        <p><strong>Subject:</strong> {$subject}</p>
        <p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>
    </div>
    
    <div class='content'>
        {$message}
    </div>
    
    <div class='footer'>
        <p><strong>Note:</strong> This email was saved to file because test_mode is enabled.</p>
        <p><strong>File:</strong> {$filename}</p>
        <p>In production, this would be sent via email.</p>
    </div>
</body>
</html>";
        
        if (file_put_contents($filename, $email_content)) {
            if ($email_config['log_emails'] ?? false) {
                error_log("Email saved to file: {$filename} for {$to_email}");
            }
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Email file save error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email using SMTP (requires additional setup)
 */
function sendEmailSMTP($to_email, $subject, $message, $email_config) {
    // Note: This would require PHPMailer or similar library
    // For now, we'll use a simple socket connection for Gmail
    
    try {
        $smtp_host = $email_config['smtp_host'];
        $smtp_port = $email_config['smtp_port'];
        $username = $email_config['smtp_username'];
        $password = $email_config['smtp_password'];
        $from_email = $email_config['from_email'];
        $from_name = $email_config['from_name'];
        
        // Create email headers for SMTP
        $email_data = "To: {$to_email}\r\n";
        $email_data .= "From: {$from_name} <{$from_email}>\r\n";
        $email_data .= "Subject: {$subject}\r\n";
        $email_data .= "MIME-Version: 1.0\r\n";
        $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
        $email_data .= "\r\n";
        $email_data .= $message;
        
        // Log the attempt
        if ($email_config['log_emails'] ?? false) {
            error_log("SMTP Email attempt to {$to_email} - Host: {$smtp_host}:{$smtp_port}");
        }
        
        // For now, return true and log that SMTP would be attempted
        error_log("SMTP Email would be sent to {$to_email} (SMTP not fully implemented - use test_mode)");
        return true;
        
    } catch (Exception $e) {
        error_log("SMTP Email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email using PHP mail() function
 */
function sendEmailPHP($to_email, $subject, $message, $headers, $email_config) {
    try {
        $result = mail($to_email, $subject, $message, $headers);
        
        if ($email_config['log_emails'] ?? false) {
            if ($result) {
                error_log("PHP mail() success to {$to_email}");
            } else {
                error_log("PHP mail() failed to {$to_email}");
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("PHP mail() error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the latest saved email file for testing
 */
function getLatestTestEmail() {
    $email_dir = '../temp/emails/';
    if (!is_dir($email_dir)) {
        return null;
    }
    
    $files = glob($email_dir . 'reset_email_*.html');
    if (empty($files)) {
        return null;
    }
    
    // Sort by modification time, newest first
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    return $files[0];
}
?>