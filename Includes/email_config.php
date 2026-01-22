<?php
/**
 * Email Configuration for Aabha Contraceptive System
 * Configure your email settings here for forgot password functionality
 */

// Email Configuration
$email_config = [
    // Basic SMTP Settings (for production use) - not used when 'smtp_enabled' is false
    'smtp_enabled' => false, // Set to true to use SMTP instead of PHP mail()
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // 'tls' or 'ssl'
    'smtp_auth' => true,
    'smtp_username' => 'your-email@gmail.com', // Change this to your Gmail (if you enable SMTP)
    'smtp_password' => 'your-app-password', // Change this to your Gmail app password (if SMTP)

    // Email From Settings
    // Using noreply@gmail.com as requested. Note: some mail providers may flag emails whose From mismatch the sending host.
    'from_email' => 'noreply@gmail.com',
    'from_name' => 'Aabha Contraceptive System',
    'reply_to' => 'support@aabha.com',

    // Email Templates
    'subject_template' => 'Password Reset Request - Aabha Contraceptive System',

    // Security Settings
    'token_expiry_hours' => 1, // Token expires after 1 hour
    'max_reset_attempts' => 3, // Max reset attempts per day per email

    // Development Settings
    'debug_mode' => true, // Set to false in production
    'log_emails' => true, // Log email activities
    // Fall back: when mail() fails, write the email content to disk (useful for local XAMPP)
    'write_emails_to_file' => true,
    // Absolute or relative path where fallback emails will be written
    'email_save_path' => __DIR__ . '/../tmp/emails',
    'test_mode' => true, // Deprecated flag kept for compatibility
];

// For development/testing with local PHP mail()
// Make sure your local server has mail() function configured
// For XAMPP: Enable sendmail in php.ini

// Gmail SMTP Setup Instructions:
// 1. Enable 2-Factor Authentication on your Gmail account
// 2. Generate an App Password: Google Account > Security > App passwords
// 3. Use the app password in 'smtp_password' above
// 4. Set 'smtp_enabled' to true

// Alternative SMTP Services:
// - SendGrid: smtp.sendgrid.net:587
// - Mailgun: smtp.mailgun.org:587
// - Amazon SES: email-smtp.region.amazonaws.com:587

return $email_config;
?>