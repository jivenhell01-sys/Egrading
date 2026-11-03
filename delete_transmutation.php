<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once('DBConnection.php');

header('Content-Type: application/json');

try {
    $conn = new DBConnection();
    ensure_transmutation_systems($conn);

    if (!isset($_POST['ids'])) {
        throw new Exception('No IDs received for deletion');
    }
    
    $ids = json_decode($_POST['ids'], true);
    
    // Debug logging
    error_log('Received IDs: ' . print_r($ids, true));
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (empty($ids)) {
        throw new Exception('No IDs to delete');
    }
    
    // Validate that all IDs are valid and convert to integers
    $validIds = [];
    foreach ($ids as $id) {
        // Clean the ID and convert to integer
        $id = trim($id);
        
        // Check if it's a valid positive integer before conversion
        if (!is_numeric($id) || $id <= 0) {
            error_log('Invalid ID found: ' . print_r($id, true));
            throw new Exception('Invalid ID format');
        }
        
        $id = (int)$id;
        $validIds[] = $id;
    }
    
    error_log('Valid IDs after conversion: ' . print_r($validIds, true));
    
    // Start transaction
    $conn->exec('BEGIN TRANSACTION');
    
    // Create placeholders for the IN clause
    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
    
    // Delete the selected rows
    $sql = "DELETE FROM `transmutation_table` WHERE `trans_id` IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->lastErrorMsg());
    }
    
    // Bind parameters
    for ($i = 0; $i < count($validIds); $i++) {
        $stmt->bindValue($i + 1, $validIds[$i]);
    }
    
    $result = $stmt->execute();
    
    if (!$result) {
        throw new Exception('Delete failed');
    }
    
    $deletedCount = $conn->changes();
    
    // Commit transaction
    $conn->exec('COMMIT');
    
    echo json_encode([
        'success' => true,
        'message' => 'Rows deleted successfully',
        'deleted_count' => $deletedCount
    ]);
    
} catch (Exception $e) {
    // Rollback transaction if it was started
    if (isset($conn)) {
        try {
            $conn->exec('ROLLBACK');
        } catch (Exception $rollbackError) {
            // Ignore rollback errors
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
