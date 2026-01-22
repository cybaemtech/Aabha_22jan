<?php
/**
 * SQL Server PHP Extension Function Definitions for IntelliSense
 * This file provides function definitions to help IDEs recognize SQL Server functions
 * Do not include this file in production - it's only for development IntelliSense
 */

if (!function_exists('sqlsrv_query')) {
    /**
     * Prepares and executes a query
     * @param resource $conn The connection resource
     * @param string $sql The SQL query string
     * @param array|null $params Parameters for the query
     * @param array|null $options Query options
     * @return resource|false Returns a query resource on success, false on failure
     */
    function sqlsrv_query($conn, $sql, $params = null, $options = null) {
        // This is just for IntelliSense - actual implementation is in the SQL Server extension
        return false;
    }
}

if (!function_exists('sqlsrv_connect')) {
    /**
     * Opens a connection to a Microsoft SQL Server database
     * @param string $serverName The server name
     * @param array $connectionInfo Connection information
     * @return resource|false Returns a connection resource on success, false on failure
     */
    function sqlsrv_connect($serverName, $connectionInfo = null) {
        return false;
    }
}

if (!function_exists('sqlsrv_fetch_array')) {
    /**
     * Returns a row as an array
     * @param resource $stmt The statement resource
     * @param int $fetchType The fetch type
     * @param int $row The row to fetch
     * @param int $offset The offset
     * @return array|null|false Returns an array on success, null if no more rows, false on error
     */
    function sqlsrv_fetch_array($stmt, $fetchType = SQLSRV_FETCH_BOTH, $row = null, $offset = null) {
        return false;
    }
}

if (!function_exists('sqlsrv_prepare')) {
    /**
     * Prepares a query for execution
     * @param resource $conn The connection resource
     * @param string $sql The SQL query string
     * @param array|null $params Parameters for the query
     * @param array|null $options Query options
     * @return resource|false Returns a statement resource on success, false on failure
     */
    function sqlsrv_prepare($conn, $sql, $params = null, $options = null) {
        return false;
    }
}

if (!function_exists('sqlsrv_execute')) {
    /**
     * Executes a prepared statement
     * @param resource $stmt The statement resource
     * @return bool Returns true on success, false on failure
     */
    function sqlsrv_execute($stmt) {
        return false;
    }
}

if (!function_exists('sqlsrv_free_stmt')) {
    /**
     * Frees statement resources
     * @param resource $stmt The statement resource
     * @return bool Returns true on success, false on failure
     */
    function sqlsrv_free_stmt($stmt) {
        return false;
    }
}

if (!function_exists('sqlsrv_errors')) {
    /**
     * Returns error and warning information about the last SQLSRV operation performed
     * @param int $errorsOrWarnings Determines whether error information, warning information, or both are returned
     * @return array|null Returns an array of arrays containing error information
     */
    function sqlsrv_errors($errorsOrWarnings = null) {
        return null;
    }
}

if (!function_exists('sqlsrv_close')) {
    /**
     * Closes an open connection
     * @param resource $conn The connection resource
     * @return bool Returns true on success, false on failure
     */
    function sqlsrv_close($conn) {
        return false;
    }
}

// Define SQLSRV constants if they don't exist
if (!defined('SQLSRV_FETCH_ASSOC')) {
    define('SQLSRV_FETCH_ASSOC', 2);
}
if (!defined('SQLSRV_FETCH_NUMERIC')) {
    define('SQLSRV_FETCH_NUMERIC', 3);
}
if (!defined('SQLSRV_FETCH_BOTH')) {
    define('SQLSRV_FETCH_BOTH', 4);
}
if (!defined('SQLSRV_ERR_ERRORS')) {
    define('SQLSRV_ERR_ERRORS', 0);
}
if (!defined('SQLSRV_ERR_WARNINGS')) {
    define('SQLSRV_ERR_WARNINGS', 1);
}
if (!defined('SQLSRV_ERR_ALL')) {
    define('SQLSRV_ERR_ALL', 2);
}
?>