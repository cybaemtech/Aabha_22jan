<?php
// filepath: c:\xampp\htdocs\Aabha\Includes\db_connect.php

// Prevent multiple inclusions
if (defined('DB_CONNECT_INCLUDED')) {
    return;
}
define('DB_CONNECT_INCLUDED', true);

// Include SQL Server function definitions for IntelliSense (development only)
if (!extension_loaded('sqlsrv')) {
    // Only include if the extension is not loaded (for IntelliSense support)
    include_once __DIR__ . '/sqlsrv_functions.php';
}

// Database configuration - Environment-aware configuration
// Detect if we're on the client server or development environment
$isProductionServer = (gethostname() === 'AABHA-SERVER' || $_SERVER['SERVER_NAME'] === 'AABHA-SERVER');

if ($isProductionServer) {
    // Production/Client Server Configuration
    $serverConfigs = [
        // Config 1: Local server connection
        [
            "serverName" => "localhost",
            "options" => [
                "Database" => "aabha",
                "Uid" => "CybaemUser",
                "PWD" => "Cybaem@123",
                "CharacterSet" => "UTF-8",
                "TrustServerCertificate" => true,
                "LoginTimeout" => 30,
                "ConnectRetryCount" => 3
            ]
        ],
        // Config 2: Named instance fallback
        [
            "serverName" => "AABHA-SERVER\\SQLEXPRESS",
            "options" => [
                "Database" => "aabha",
                "Uid" => "CybaemUser",
                "PWD" => "Cybaem@123",
                "CharacterSet" => "UTF-8",
                "TrustServerCertificate" => true,
                "LoginTimeout" => 30,
                "ConnectRetryCount" => 3
            ]
        ]
    ];
} else {
    // Development Environment Configuration (current XAMPP setup)
    $serverConfigs = [
        // Config 1: Original development configuration
        [
            "serverName" => "DESKTOP-VGR9FA0\\MSSQLSERVER01",
            "options" => [
                "Database" => "aabha",
                "Uid" => "CybeamUser",
                "PWD" => "Cybeam@909",
                "CharacterSet" => "UTF-8",
                "TrustServerCertificate" => true,
                "LoginTimeout" => 30,
                "ConnectRetryCount" => 3
            ]
        ],
        // Config 2: Development localhost
        [
            "serverName" => "localhost",
            "options" => [
                "Database" => "aabha",
                "Uid" => "CybeamUser",
                "PWD" => "Cybeam@909",
                "CharacterSet" => "UTF-8",
                "TrustServerCertificate" => true,
                "LoginTimeout" => 30,
                "ConnectRetryCount" => 3
            ]
        ],
        // Config 3: Development sa account
        [
            "serverName" => "localhost",
            "options" => [
                "Database" => "aabha",
                "Uid" => "sa",
                "PWD" => "CybeamUser@909",
                "CharacterSet" => "UTF-8",
                "TrustServerCertificate" => true,
                "LoginTimeout" => 30,
                "ConnectRetryCount" => 3
            ]
        ]
    ];
}

$conn = false;
$connectionError = "";

// Try each configuration
foreach ($serverConfigs as $index => $config) {
    // Log environment info for debugging
    error_log("DB Connect - Environment: " . ($isProductionServer ? 'PRODUCTION' : 'DEVELOPMENT'));
    error_log("DB Connect - Hostname: " . gethostname());
    error_log("DB Connect - Trying config " . ($index + 1) . ": " . $config["serverName"]);
    
    $conn = sqlsrv_connect($config["serverName"], $config["options"]);
    
    if ($conn !== false) {
        // Connection successful
        error_log("DB Connect - SUCCESS with config " . ($index + 1));
        if (isset($_GET['debug'])) {
            echo "✅ Connected successfully with config " . ($index + 1) . ": " . $config["serverName"] . " (" . ($isProductionServer ? 'PRODUCTION' : 'DEVELOPMENT') . " mode)<br>";
        }
        break;
    } else {
        // Store error for the last attempt
        $errors = sqlsrv_errors();
        $connectionError = "Config " . ($index + 1) . " failed: ";
        if ($errors) {
            foreach ($errors as $error) {
                $connectionError .= $error['message'] . " ";
            }
        }
        error_log("DB Connect - " . $connectionError);
    }
}

// If all connections failed
if ($conn === false) {
    error_log("Database connection failed: " . $connectionError);
    
    // For debugging (remove in production)
    if (isset($_GET['debug'])) {
        die("❌ Database connection failed: " . $connectionError);
    } else {
        die("❌ Database connection failed. Please contact administrator.");
    }
}

// Function to safely close connection - only declare if not already declared

?>
