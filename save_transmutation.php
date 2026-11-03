<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once('DBConnection.php');

header('Content-Type: application/json');

try {
    $conn = new DBConnection();
    ensure_transmutation_systems($conn);

    if (!isset($_POST['changes'])) {
        throw new Exception('No changes data received');
    }
    
    $changes = json_decode($_POST['changes'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }
    
    if (empty($changes)) {
        throw new Exception('No changes to process');
    }
    
    // Start transaction using SQLite3's exec method
    $conn->exec('BEGIN TRANSACTION');
    
    foreach ($changes as $change) {
        $id = $change['id'];
        $updates = $change['changes'];
        
        $setParts = [];
        $params = [];
        
        foreach ($updates as $field => $value) {
            // Only editable grade columns may change; rows stay in their system.
            if (!in_array($field, ['from', 'to', 'grade'], true)) {
                continue;
            }
            $setParts[] = "`$field` = ?";
            $params[] = $value;
        }

        if (empty($setParts)) {
            continue;
        }
        
        $params[] = $id; // for WHERE clause
        
        $sql = "UPDATE `transmutation_table` SET " . implode(', ', $setParts) . " WHERE `trans_id` = ?";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->lastErrorMsg());
        }
        
        // Bind parameters
        for ($i = 0; $i < count($params); $i++) {
            $stmt->bindValue($i + 1, $params[$i]);
        }
        
        $result = $stmt->execute();
        
        if (!$result) {
            throw new Exception('Update failed for ID ' . $id);
        }
    }
    
    // Commit transaction
    $conn->exec('COMMIT');
    
    echo json_encode([
        'success' => true,
        'message' => 'Transmutation table updated successfully',
        'updated_count' => count($changes)
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
