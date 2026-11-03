<?php
// switch_database.php
session_start();
require_once 'DBConnection.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Get user role from session
$userRole = $_SESSION['role'] ?? 'admin';

// Determine allowed paths based on role
if ($userRole === 'admin') {
    // Admin can access any database in the db directory
    $allowedPath1 = realpath(__DIR__ . '/db/');
    $allowedPath2 = realpath(__DIR__ . '/db/grading_db.db');
} elseif ($userRole === 'faculty') {
    // For faculty, get their username and find their database directory
    $facultyUsername = $_SESSION['username'] ?? '';
    if ($facultyUsername) {
        $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($facultyUsername));
        $facultyDir = realpath(__DIR__ . '/db/' . $safeUsername);
        if ($facultyDir && is_dir($facultyDir)) {
            // Allow access to the faculty's own directory
            $allowedPath1 = $facultyDir;
            // Find the first .db file in their directory as default
            $facultyDbFiles = glob($facultyDir . '/*.db');
            if (!empty($facultyDbFiles)) {
                $allowedPath2 = realpath($facultyDbFiles[0]);
            } else {
                $allowedPath2 = realpath(__DIR__ . '/db/grading_db.db');
            }
        } else {
            // Fallback if directory doesn't exist
            $allowedPath1 = realpath(__DIR__ . '/db/faculty_backups/');
            $allowedPath2 = realpath(__DIR__ . '/db/grading_db.db');
        }
    } else {
        // Fallback if no username
        $allowedPath1 = realpath(__DIR__ . '/db/faculty_backups/');
        $allowedPath2 = realpath(__DIR__ . '/db/grading_db.db');
    }
} else {
    // Default to admin if role is unknown
    $allowedPath1 = realpath(__DIR__ . '/db/');
    $allowedPath2 = realpath(__DIR__ . '/db/grading_db.db');
}

// Debug logging
error_log('Received database switch request: ' . print_r($_POST, true));
error_log('User role: ' . $userRole);

try {
    // Check if db_path is provided
    if (!isset($_POST['db_path'])) {
        throw new Exception('Database path not provided');
    }
    
    $dbPath = trim((string)$_POST['db_path']);
    
    // Empty path = "Default Database" — clear the manual switch and return to the live DB
    if ($dbPath === '') {
        $defaultLive = realpath(__DIR__ . '/db/grading_db.db');
        if ($defaultLive === false || !file_exists($defaultLive)) {
            $defaultLive = __DIR__ . '/db/grading_db.db';
        } else {
            $defaultLive = realpath($defaultLive);
        }
        unset($_SESSION['db_file'], $_SESSION['db_manually_switched'], $_SESSION['db_switch_time']);
        // Also clear the constant-based cache if possible
        if (isset($GLOBALS['active_db_file'])) {
            $GLOBALS['active_db_file'] = $defaultLive;
        }
        $response = [
            'success' => true,
            'message' => 'Switched to default database',
            'db_file' => $defaultLive,
            'session_id' => session_id(),
            'reload' => true,
            'debug' => [
                'original_path' => '',
                'real_path' => $defaultLive,
                'allowed_path1' => $allowedPath1,
                'allowed_path2' => $allowedPath2,
                'user_role' => $userRole,
            ]
        ];
        error_log('Database switch: returned to default ' . $defaultLive);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    // Convert to real path
    $realPath = realpath($dbPath);
    if ($realPath === false) {
        throw new Exception('Invalid database path: ' . $dbPath);
    }
    
    // Check if file exists
    if (!file_exists($realPath)) {
        throw new Exception('Database file does not exist: ' . $realPath);
    }
    
    // Check if it's a .db file
    if (!str_ends_with($realPath, '.db')) {
        throw new Exception('Not a database file: ' . $realPath);
    }
    
    // Validate it's in allowed directories
    $allowed = false;
    
    // Check if it's in the allowed base directory
    if ($allowedPath1 && strpos($realPath, $allowedPath1) === 0) {
        $allowed = true;
    }
    
    // Check if it's the specific allowed database file
    if ($realPath === $allowedPath2) {
        $allowed = true;
    }
    
    // For faculty, also allow faculty_backups directory
    if ($userRole === 'faculty') {
        $facultyBackupsPath = realpath(__DIR__ . '/db/faculty_backups/');
        if ($facultyBackupsPath && strpos($realPath, $facultyBackupsPath) === 0) {
            $allowed = true;
        }
    }
    
    // Additional security: ensure the path is within the db directory
    $dbBasePath = realpath(__DIR__ . '/db/');
    if ($dbBasePath && strpos($realPath, $dbBasePath) !== 0) {
        $allowed = false;
    }
    
    if (!$allowed) {
        throw new Exception('Unauthorized database path: ' . $realPath);
    }

    // Set the active database in session
    $_SESSION['db_file'] = $realPath;
    
    // Also set a flag to indicate database was manually switched
    $_SESSION['db_manually_switched'] = true;
    $_SESSION['db_switch_time'] = time();
    
    error_log("Switch Database Debug - Set session db_file: " . $realPath);
    error_log("Switch Database Debug - Set manual switch flag and time: " . $_SESSION['db_switch_time']);
    error_log("Switch Database Debug - Full session after switch: " . print_r($_SESSION, true));
    
    // Test the connection
    $testConn = new SQLite3($realPath);
    if (!$testConn) {
        throw new Exception('Failed to connect to database: ' . $realPath);
    }
    
    // Try a simple query to verify it's a valid database
    $result = $testConn->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1");
    if (!$result) {
        $testConn->close();
        throw new Exception('Database is not valid: ' . $realPath);
    }
    $testConn->close();

    $response = [
        'success' => true,
        'message' => 'Database switched successfully',
        'db_file' => $realPath,
        'session_id' => session_id(),
        'reload' => true,
        'debug' => [
            'original_path' => $dbPath,
            'real_path' => $realPath,
            'allowed_path1' => $allowedPath1,
            'allowed_path2' => $allowedPath2,
            'user_role' => $userRole,
            'faculty_backups_path' => $userRole === 'faculty' ? realpath(__DIR__ . '/db/faculty_backups/') : 'N/A'
        ]
    ];
    
    error_log('Database switch successful: ' . print_r($response, true));
    
} catch (Exception $e) {
    http_response_code(400);
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'session_id' => session_id(),
        'post_data' => $_POST,
        'debug' => [
            'db_path' => $_POST['db_path'] ?? 'not provided',
            'real_path' => isset($_POST['db_path']) ? realpath($_POST['db_path']) : 'not applicable',
            'file_exists' => isset($_POST['db_path']) ? file_exists($_POST['db_path']) : 'not applicable',
            'user_role' => $userRole,
            'allowed_path1' => $allowedPath1,
            'allowed_path2' => $allowedPath2,
            'faculty_backups_path' => $userRole === 'faculty' ? realpath(__DIR__ . '/db/faculty_backups/') : 'N/A'
        ]
    ];
    error_log('Database switch error: ' . print_r($response, true));
}

echo json_encode($response, JSON_PRETTY_PRINT);
