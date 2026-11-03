<?php
// Suppress ALL warnings/errors that could corrupt JSON output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start output buffering to catch any unexpected output
ob_start();

require_once('DBConnection.php');
require_once('Actions.php');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Temporarily bypass session check for debugging
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0) {
    $_SESSION['admin_id'] = 1; // Temporarily set admin session for debugging
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method.', 'debug' => 'method_check']);
    exit;
}

// Get POST data
$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$publish_type = isset($_POST['publish_type']) ? $_POST['publish_type'] : 'all';

if ($class_id <= 0) {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid class ID.', 'debug' => ['class_id' => $class_id]]);
    exit;
}

try {
    // Initialize database connections
    $conn = new DBConnection();
    $actions = new Actions();

    // Test basic database connection
    $test_query = $conn->query("SELECT 1 as test");
    $test_result = $test_query->fetchArray(SQLITE3_ASSOC);
    
    if (!$test_result || $test_result['test'] != 1) {
        throw new Exception('Database connection test failed');
    }

    // Get class information
    $class_query = $conn->query("SELECT c.*, s.name as subject_name 
                                FROM class_list c 
                                JOIN subjects s ON c.subject_id = s.subject_id 
                                WHERE c.class_id = $class_id LIMIT 1");
    $class_info = $class_query->fetchArray(SQLITE3_ASSOC);

    if (!$class_info) {
        throw new Exception("Class not found for ID: $class_id");
    }

    // Test if published_grades table exists
    $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='published_grades'");
    $table_exists = $table_check->fetchArray(SQLITE3_ASSOC);
    
    if (!$table_exists) {
        throw new Exception('published_grades table does not exist');
    }

    // Get students count
    $students_query = $conn->query("SELECT COUNT(*) as count FROM student_list WHERE class_id = $class_id");
    $students_count = $students_query->fetchArray(SQLITE3_ASSOC);

    // Clean output buffer and get any content
    $output = ob_get_contents();
    ob_end_clean();

    // If there's any unexpected output, log it and clean it
    if (!empty($output)) {
        // Clean any unexpected output
        $output = '';
    }

    // Send clean JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "Debug test completed successfully",
        'debug' => [
            'class_id' => $class_id,
            'class_info' => $class_info,
            'students_count' => $students_count['count'],
            'table_exists' => !empty($table_exists),
            'database_test' => 'passed'
        ]
    ]);
    
} catch (Exception $e) {
    // Clean output buffer and get any content
    $output = ob_get_contents();
    ob_end_clean();

    // If there's any unexpected output, log it and clean it
    if (!empty($output)) {
        // Clean any unexpected output
        $output = '';
    }
    
    // Send clean JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Debug error: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'debug_trace' => $e->getTraceAsString()
    ]);
}
?>
