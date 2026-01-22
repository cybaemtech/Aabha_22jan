<?php
/**
 * Centralized Session Configuration
 * Include this file at the top of every PHP file that needs session management
 */

// Prevent multiple inclusions
if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

// Configure session settings before starting session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_secure', 0); // Set to 1 for HTTPS
ini_set('session.gc_maxlifetime', 3600); // 1 hour
ini_set('session.cookie_lifetime', 3600); // 1 hour

// Set custom session path
$customSessionPath = dirname(__DIR__) . '/temp';
if (!is_dir($customSessionPath)) {
    if (!@mkdir($customSessionPath, 0755, true)) {
        error_log("Failed to create session directory: " . $customSessionPath);
        // Fallback to system temp
        $customSessionPath = sys_get_temp_dir() . '/aabha_sessions';
        if (!is_dir($customSessionPath)) {
            @mkdir($customSessionPath, 0755, true);
        }
    }
}

if (is_dir($customSessionPath) && is_writable($customSessionPath)) {
    ini_set('session.save_path', $customSessionPath);
} else {
    error_log("Session directory not writable: " . $customSessionPath);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (!session_start()) {
        error_log("Failed to start session");
        die("Session initialization failed. Please contact administrator.");
    }
}

// Session security and timeout check
function checkSessionSecurity() {
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = time();
    } else if (time() - $_SESSION['regenerated'] > 300) { // Regenerate every 5 minutes
        session_regenerate_id(true);
        $_SESSION['regenerated'] = time();
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity'])) {
        $timeout = 3600; // 1 hour
        if (time() - $_SESSION['last_activity'] > $timeout) {
            // Session expired
            session_unset();
            session_destroy();
            
            // Clear session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            header("Location: " . getBaseUrl() . "index.php?timeout=1");
            exit;
        }
    }
    
    $_SESSION['last_activity'] = time();
}

// Helper function to get base URL
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    
    // Determine base path
    if (strpos($path, '/Aabha') !== false) {
        $basePath = '/Aabha/';
    } else {
        $basePath = '/';
    }
    
    return $basePath;
}

// Helper function to check if user is logged in
function requireLogin() {
    if (!isset($_SESSION['operator_id'])) {
        header("Location: " . getBaseUrl() . "index.php");
        exit;
    }
    
    // Run security checks
    checkSessionSecurity();
}

// Helper function for safe logout
function logoutUser() {
    // Destroy all session data
    session_unset();
    session_destroy();
    
    // Clear session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    header("Location: " . getBaseUrl() . "index.php");
    exit;
}
?>