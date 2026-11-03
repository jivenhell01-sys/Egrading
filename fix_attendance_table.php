<?php
require_once('DBConnection.php');

// Set content type to JSON
header('Content-Type: application/json');

try {
    $db = new DBConnection();
    
    // Check if the reasons column exists
    $tableInfo = $db->query("PRAGMA table_info(attendance)");
    $reasonsColumnExists = false;
    
    while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'reasons') {
            $reasonsColumnExists = true;
            break;
        }
    }
    
    // Add the reasons column if it doesn't exist
    if (!$reasonsColumnExists) {
        $db->exec("ALTER TABLE attendance ADD COLUMN reasons TEXT DEFAULT NULL");
        echo json_encode(['status' => 'success', 'message' => 'Added reasons column to attendance table']);
    } else {
        echo json_encode(['status' => 'info', 'message' => 'Reasons column already exists in attendance table']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
