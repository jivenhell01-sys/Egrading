<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once 'DBConnection.php';

$conn = new DBConnection();

// Get list of all tables
$tables = [];
$result = $conn->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $tables[] = $row['name'];
}

// Get schema for each table
$schema = [];
foreach ($tables as $table) {
    $result = $conn->query("PRAGMA table_info($table)");
    $columns = [];
    while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $col;
    }
    $schema[$table] = $columns;
}

// Output as JSON
header('Content-Type: application/json');
echo json_encode([
    'tables' => $tables,
    'schema' => $schema
], JSON_PRETTY_PRINT);
