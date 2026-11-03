<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once('DBConnection.php');

header('Content-Type: application/json');

try {
    $conn = new DBConnection();
    ensure_transmutation_systems($conn);

    if (!isset($_POST['new_rows'])) {
        throw new Exception('No new rows data received');
    }
    
    $newRows = json_decode($_POST['new_rows'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (empty($newRows)) {
        throw new Exception('No new rows to add');
    }
    
    // Start transaction
    $conn->exec('BEGIN TRANSACTION');
    
    $addedRows = [];
    
    foreach ($newRows as $row) {
        // Validate required fields
        if (!isset($row['from']) || !isset($row['to']) || !isset($row['grade'])) {
            throw new Exception('Missing required fields');
        }
        
        // Validate numeric values (grade range depends on system)
        $from = floatval($row['from']);
        $to = floatval($row['to']);
        $grade = floatval($row['grade']);
        $rowSystem = strtoupper(trim($row['system'] ?? ($_POST['system'] ?? 'CHED')));
        if ($rowSystem !== 'CHED' && $rowSystem !== 'DEPED') {
            $rowSystem = 'CHED';
        }
        $gradeMin = $rowSystem === 'DEPED' ? 60 : 1;
        $gradeMax = $rowSystem === 'DEPED' ? 100 : 5.5;

        if ($from < 0 || $to < 0 || $grade < $gradeMin || $grade > $gradeMax) {
            throw new Exception('Invalid numeric values');
        }

        // Insert new row into the selected system (CHED default)
        $system = $rowSystem;
        $sql = "INSERT INTO `transmutation_table` (`from`, `to`, `grade`, `system`) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->lastErrorMsg());
        }
        
        $stmt->bindValue(1, $from);
        $stmt->bindValue(2, $to);
        $stmt->bindValue(3, $grade);
        $stmt->bindValue(4, $system, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        
        if (!$result) {
            throw new Exception('Insert failed');
        }
        
        // Get the new trans_id
        $newId = $conn->lastInsertRowID();
        
        $addedRows[] = [
            'temp_id' => $row['temp_id'],
            'new_id' => $newId,
            'from' => $from,
            'to' => $to,
            'grade' => $grade
        ];
    }
    
    // Commit transaction
    $conn->exec('COMMIT');
    
    echo json_encode([
        'success' => true,
        'message' => 'Rows added successfully',
        'added_count' => count($addedRows),
        'added_rows' => $addedRows
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
