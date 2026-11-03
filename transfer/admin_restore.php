<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Check if user is logged in and has admin role
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

require_once '../DBConnection.php';

function restoreBackup() {
    try {
        $admin_username = $_SESSION['username'];
        $filename = $_POST['filename'] ?? '';
        
        // Validate filename format (regular backups only)
        if (!preg_match('/^' . preg_quote($admin_username, '/') . '_[\d]{4}-[\d]{2}-[\d]{2}_[\d]{2}-[\d]{2}-[\d]{2}\.db$/', $filename)) {
            return ['status' => 'error', 'message' => 'Invalid backup filename'];
        }
        
        $backup_path = __DIR__ . '/../db/backups/Administrator/' . $filename;
        
        // Verify backup file exists and is within the backup directory
        if (!file_exists($backup_path)) {
            return ['status' => 'error', 'message' => 'Backup file not found'];
        }
        
        $real_backup_dir = realpath(__DIR__ . '/../db/backups/Administrator');
        $real_backup_path = realpath($backup_path);
        
        if (strpos($real_backup_path, $real_backup_dir) !== 0) {
            return ['status' => 'error', 'message' => 'Invalid file path'];
        }
        
        // Verify backup file is a valid SQLite database
        if (filesize($backup_path) === 0) {
            return ['status' => 'error', 'message' => 'Backup file is empty or corrupted'];
        }
        
        // Test if the backup file is a valid SQLite database
        $test_db = new SQLite3($backup_path);
        $result = $test_db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table'");
        $test_db->close();
        
        if ($result === false) {
            return ['status' => 'error', 'message' => 'Backup file is not a valid SQLite database'];
        }
        
        // Determine current database path
        $current_db_path = $_SESSION['db_file'] ?? (__DIR__ . '/../db/grading_db.db');
        
        // Perform the restore operation
        if (copy($backup_path, $current_db_path)) {
            // Verify the restore was successful
            if (file_exists($current_db_path) && filesize($current_db_path) > 0) {
                // Test the restored database
                try {
                    $test_db = new SQLite3($current_db_path);
                    $test_db->exec("PRAGMA integrity_check;");
                    $test_db->close();
                    
                    // Log the restore operation
                    error_log("Admin database restored: {$filename} by user {$admin_username}");
                    
                    // Delete the restored backup file after successful restore
                    if (file_exists($backup_path)) {
                        unlink($backup_path);
                        error_log("Restored backup file deleted: {$filename}");
                    }
                    
                    return [
                        'status' => 'success', 
                        'message' => 'Database restored successfully and backup file deleted',
                        'restored_from' => $filename,
                        'backup_deleted' => true
                    ];
                    
                } catch (Exception $e) {
                    // Restore failed, no pre-restore backup to recover from
                    return ['status' => 'error', 'message' => 'Restored database failed integrity check. Please restore from another backup.'];
                }
            } else {
                return ['status' => 'error', 'message' => 'Database restore verification failed.'];
            }
        } else {
            return ['status' => 'error', 'message' => 'Failed to restore database'];
        }
        
    } catch (Exception $e) {
        error_log("Admin database restore error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Database restore failed: ' . $e->getMessage()];
    }
}

function verifyBackup() {
    try {
        $admin_username = $_SESSION['username'];
        $filename = $_POST['filename'] ?? '';
        
        // Validate filename format (regular backups only)
        if (!preg_match('/^' . preg_quote($admin_username, '/') . '_[\d]{4}-[\d]{2}-[\d]{2}_[\d]{2}-[\d]{2}-[\d]{2}\.db$/', $filename)) {
            return ['status' => 'error', 'message' => 'Invalid backup filename'];
        }
        
        $backup_path = __DIR__ . '/../db/backups/Administrator/' . $filename;
        
        if (!file_exists($backup_path)) {
            return ['status' => 'error', 'message' => 'Backup file not found'];
        }
        
        // Verify backup file integrity
        try {
            $db = new SQLite3($backup_path);
            
            // Check database integrity
            $integrity_result = $db->querySingle("PRAGMA integrity_check");
            if ($integrity_result !== 'ok') {
                $db->close();
                return ['status' => 'error', 'message' => 'Database integrity check failed'];
            }
            
            // Get table information
            $tables = [];
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $tables[] = $row['name'];
            }
            
            // Get record counts for main tables
            $record_counts = [];
            $main_tables = ['admin_list', 'students', 'classes', 'subjects', 'attendance', 'marks'];
            foreach ($main_tables as $table) {
                if (in_array($table, $tables)) {
                    $count = $db->querySingle("SELECT COUNT(*) FROM {$table}");
                    $record_counts[$table] = $count;
                }
            }
            
            $db->close();
            
            return [
                'status' => 'success',
                'message' => 'Backup verified successfully',
                'file_size' => filesize($backup_path),
                'tables' => $tables,
                'record_counts' => $record_counts
            ];
            
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Backup verification failed: ' . $e->getMessage()];
        }
        
    } catch (Exception $e) {
        error_log("Admin backup verification error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Backup verification failed: ' . $e->getMessage()];
    }
}

function getRestoreHistory() {
    try {
        $admin_username = $_SESSION['username'];
        $backup_dir = __DIR__ . '/../db/backups/Administrator';
        
        if (!is_dir($backup_dir)) {
            return ['status' => 'success', 'history' => []];
        }
        
        $history = [];
        $files = glob($backup_dir . '/' . $admin_username . '_*.db');
        
        foreach ($files as $file) {
            $filename = basename($file);
            $timestamp = preg_replace('/^' . $admin_username . '_|\.db$/', '', $filename);
            
            $history[] = [
                'filename' => $filename,
                'timestamp' => $timestamp,
                'filepath' => $file,
                'size' => filesize($file),
                'created' => filemtime($file),
                'is_pre_restore' => false
            ];
        }
        
        // Sort by creation time (newest first)
        usort($history, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        return ['status' => 'success', 'history' => $history];
        
    } catch (Exception $e) {
        error_log("Admin restore history error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Failed to get restore history: ' . $e->getMessage()];
    }
}

// Main request handling
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'restore':
        echo json_encode(restoreBackup());
        break;
        
    case 'verify':
        echo json_encode(verifyBackup());
        break;
        
    case 'history':
        echo json_encode(getRestoreHistory());
        break;
        
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>