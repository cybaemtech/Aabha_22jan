<?php
// Server configuration file
// This file should be customized for each deployment environment

// Detect if we're running on a server or local environment
$isLocal = (
    $_SERVER['SERVER_NAME'] === 'localhost' || 
    $_SERVER['SERVER_NAME'] === '127.0.0.1' || 
    strpos($_SERVER['SERVER_NAME'], 'localhost') !== false ||
    $_SERVER['HTTP_HOST'] === 'localhost'
);

// Database configuration based on environment
if ($isLocal) {
    // Local development configuration
    $db_config = [
        "serverName" => "DESKTOP-VGR9FA0\\MSSQLSERVER01",
        "database" => "aabha",
        "username" => "CybeamUser",
        "password" => "Cybeam@909"
    ];
} else {
    // Server/Production configuration
    // You'll need to update these values for your actual server
    $db_config = [
        "serverName" => "your_server_name_here",
        "database" => "aabha",
        "username" => "your_username_here", 
        "password" => "your_password_here"
    ];
}

// Application settings
$app_config = [
    'base_url' => $isLocal ? '/Aabha/' : '/',
    'debug_mode' => $isLocal,
    'session_timeout' => 3600, // 1 hour
    'upload_path' => __DIR__ . '/uploads/',
    'temp_path' => __DIR__ . '/temp/'
];

// Error reporting based on environment
if ($app_config['debug_mode']) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}
?>