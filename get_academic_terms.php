<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON content type header
header('Content-Type: application/json');

// Include database connection
require_once 'DBConnection.php';

try {
        // First try the main database
    $conn = new DBConnection();
    
    if (!$conn) {
        throw new Exception("Failed to connect to main database");
    }

    // Check if class_list exists in the main database
    $tableCheck = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='class_list'");
    if (!$tableCheck->fetchArray()) {
        // If not found in main database, try the backup database
        $backupDb = new SQLite3('c:/xampp/htdocs/gradlng_system/db/backups/Administrator/admin_2025-12-15_14-07-17.db');
        
        if (!$backupDb) {
            throw new Exception("Failed to connect to backup database");
        }
        
        // Check if class_list exists in the backup database
        $tableCheck = $backupDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='class_list'");
        if (!$tableCheck->fetchArray()) {
            throw new Exception("Table 'class_list' not found in main or backup database");
        }
        
        // Use the backup database connection
        $conn = $backupDb;
    }

    // Query to get unique year and semester combinations
    $query = "SELECT DISTINCT year, semester FROM class_list ORDER BY year DESC, 
             CASE semester 
                 WHEN '1st Semester' THEN 1 
                 WHEN '2nd Semester' THEN 2 
                 WHEN 'Summer' THEN 3 
                 ELSE 4 
             END";
    
    $result = $conn->query($query);
    
    if ($result === false) {
        throw new Exception("Query failed: " . $conn->lastErrorMsg());
    }

    $academic_terms = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($row['year']) && !empty($row['semester'])) {
            $year = $row['year'];
            $semester = $row['semester'];
            $academic_terms[] = [
                'year' => $year,
                'semester' => $semester,
                'label' => "$semester SY $year"
            ];
        }
    }

    // Group by year
    $grouped_terms = [];
    foreach ($academic_terms as $term) {
        $year = $term['year'];
        if (!isset($grouped_terms[$year])) {
            $grouped_terms[$year] = [];
        }
        $grouped_terms[$year][] = $term;
    }

    // Output as JSON
    echo json_encode([
        'success' => true,
        'terms' => $grouped_terms,
        'debug' => [
            'term_count' => count($academic_terms),
            'years_found' => array_keys($grouped_terms)
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
