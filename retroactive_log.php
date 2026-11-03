<?php
require_once 'DBConnection.php';

try {
    $conn = new DBConnection();
    
    // Check for existing backup files that aren't logged
    $backup_dir = __DIR__ . '/db/backups';
    $backup_file = $backup_dir . '/grading_db_latest_backup.sqlite';
    
    if (file_exists($backup_file)) {
        $file_size = filesize($backup_file);
        $file_mtime = filemtime($backup_file);
        
        // Check if this backup is already logged
        $check_log = $conn->query("SELECT COUNT(*) as count FROM transfer_logs WHERE backup_file = 'grading_db_latest_backup.sqlite'");
        $count = $check_log->fetchArray(SQLITE3_ASSOC);
        
        if ($count['count'] == 0) {
            // Log the existing backup retroactively
            $stmt = $conn->prepare("INSERT INTO transfer_logs (admin_id, backup_file, file_size, tables_transferred, status, notes, transfer_date) 
                                   VALUES (:admin_id, :backup_file, :file_size, :tables_transferred, 'completed', :notes, :transfer_date)");
            
            $stmt->bindValue(':admin_id', 1, SQLITE3_INTEGER); // Default to admin ID 1
            $stmt->bindValue(':backup_file', 'grading_db_latest_backup.sqlite', SQLITE3_TEXT);
            $stmt->bindValue(':file_size', $file_size, SQLITE3_INTEGER);
            $stmt->bindValue(':tables_transferred', 0, SQLITE3_INTEGER); // Unknown for retroactive log
            $stmt->bindValue(':notes', 'Retroactively logged existing backup file', SQLITE3_TEXT);
            $stmt->bindValue(':transfer_date', date('Y-m-d H:i:s', $file_mtime), SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                echo "✓ Successfully retroactively logged existing backup file\n";
                echo "  File: grading_db_latest_backup.sqlite\n";
                echo "  Size: " . number_format($file_size / 1024, 2) . " KB\n";
                echo "  Date: " . date('Y-m-d H:i:s', $file_mtime) . "\n";
            } else {
                echo "✗ Failed to log existing backup\n";
            }
        } else {
            echo "✓ Backup file already logged in transfer history\n";
        }
    }
    
    // Show current transfer logs
    echo "\nCurrent transfer logs:\n";
    $logs = $conn->query("SELECT tl.*, al.fullname as admin_name 
                          FROM transfer_logs tl 
                          LEFT JOIN admin_list al ON tl.admin_id = al.admin_id 
                          ORDER BY tl.transfer_date DESC");
    
    while ($row = $logs->fetchArray(SQLITE3_ASSOC)) {
        echo "  - " . $row['transfer_date'] . " | " . ($row['admin_name'] ?? 'Unknown') . " | " . $row['backup_file'] . " | " . $row['status'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
