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

// Check if user is logged in and has faculty role
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'faculty') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

require_once '../DBConnection.php';

function createBackup() {
    try {
        $faculty_username = $_SESSION['username'];
        $current_db_path = $_SESSION['db_file'] ?? (__DIR__ . '/../db/grading_db.db');
        
        // Verify current database exists
        if (!file_exists($current_db_path)) {
            return ['status' => 'error', 'message' => 'Current database file not found'];
        }
        
        // Create backup directory if it doesn't exist
        $backup_dir = __DIR__ . '/../db/faculty_backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        // Generate backup filename with timestamp
        $timestamp = date('Y-m-d_H-i-s');
        $backup_filename = $faculty_username . '_' . $timestamp . '.db';
        $backup_path = $backup_dir . '/' . $backup_filename;
        
        // Copy the database file
        if (copy($current_db_path, $backup_path)) {
            // Verify the backup was created successfully
            if (file_exists($backup_path) && filesize($backup_path) > 0) {
                // Log the backup operation
                error_log("Faculty backup created: {$backup_filename} by user {$faculty_username}");
                
                // Check if data deletion is requested
                $delete_data = $_POST['delete_data'] ?? false;
                if ($delete_data) {
                    $delete_result = deleteFacultyData($current_db_path);
                    if ($delete_result['status'] === 'error') {
                        // If deletion fails, remove the backup and return error
                        unlink($backup_path);
                        return $delete_result;
                    }
                    // Include deletion details in response
                    return [
                        'status' => 'success', 
                        'message' => 'Backup created and data deleted successfully',
                        'backup_file' => $backup_filename,
                        'backup_size' => filesize($backup_path),
                        'data_deleted' => $delete_data,
                        'deleted_tables' => $delete_result['deleted_tables'] ?? [],
                        'tables_count' => $delete_result['tables_count'] ?? 0
                    ];
                }
                
                return [
                    'status' => 'success', 
                    'message' => 'Backup created successfully',
                    'backup_file' => $backup_filename,
                    'backup_size' => filesize($backup_path),
                    'data_deleted' => $delete_data
                ];
            } else {
                // Clean up failed backup
                if (file_exists($backup_path)) {
                    unlink($backup_path);
                }
                return ['status' => 'error', 'message' => 'Backup file verification failed'];
            }
        } else {
            return ['status' => 'error', 'message' => 'Failed to create backup copy'];
        }
        
    } catch (Exception $e) {
        error_log("Backup creation error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Backup creation failed: ' . $e->getMessage()];
    }
}

function deleteFacultyData($db_path) {
    try {
        $faculty_username = $_SESSION['username'];
        
        // Connect to the database with proper locking
        $db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $db->exec("PRAGMA foreign_keys = ON;");
        $db->exec("PRAGMA busy_timeout = 5000;"); // Wait up to 5 seconds for lock
        
        // Get all tables in the database (excluding system tables)
        $tables_result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $all_tables = [];
        while ($row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
            $all_tables[] = $row['name'];
        }
        
        // Tables to preserve (exclude from deletion)
        $preserve_tables = ['admin_list', 'registered_devices', 'transmutation_table'];
        
        // Tables to delete data from
        $tables_to_clear = array_diff($all_tables, $preserve_tables);
        
        $deleted_tables = [];
        $errors = [];
        
        // Begin transaction for atomic operation
        $db->exec("BEGIN TRANSACTION");
        
        foreach ($tables_to_clear as $table) {
            try {
                // Check if table exists
                $table_exists = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$table}'");
                if ($table_exists > 0) {
                    // Check if table has data
                    $count_result = $db->querySingle("SELECT COUNT(*) FROM {$table}");
                    if ($count_result > 0) {
                        // Delete all data from the table
                        $db->exec("DELETE FROM {$table}");
                        $deleted_tables[] = $table;
                        
                        // Reset auto-increment if the table has an auto-increment primary key
                        $db->exec("DELETE FROM sqlite_sequence WHERE name='{$table}'");
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Error clearing table {$table}: " . $e->getMessage();
            }
        }
        
        // Commit or rollback based on errors
        if (!empty($errors)) {
            $db->exec("ROLLBACK");
            $db->close();
            error_log("Data deletion errors for {$faculty_username}: " . implode('; ', $errors));
            return [
                'status' => 'error', 
                'message' => 'Data deletion failed: ' . implode('; ', $errors),
                'deleted_tables' => $deleted_tables,
                'errors' => $errors
            ];
        } else {
            $db->exec("COMMIT");
            $db->close();
            error_log("Faculty data deleted successfully by {$faculty_username}. Tables cleared: " . implode(', ', $deleted_tables));
            
            return [
                'status' => 'success', 
                'message' => 'Faculty data deleted successfully',
                'deleted_tables' => $deleted_tables,
                'tables_count' => count($deleted_tables)
            ];
        }
        
    } catch (Exception $e) {
        error_log("Faculty data deletion error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Failed to delete faculty data: ' . $e->getMessage()];
    }
}

function deleteBackup() {
    try {
        $faculty_username = $_SESSION['username'];
        $filename = $_POST['filename'] ?? '';
        
        // Validate filename format (supports both regular and pre-restore backups)
        if (!preg_match('/^' . preg_quote($faculty_username, '/') . '_(pre_restore_)?[\d]{4}-[\d]{2}-[\d]{2}_[\d]{2}-[\d]{2}-[\d]{2}\.db$/', $filename)) {
            return ['status' => 'error', 'message' => 'Invalid backup filename'];
        }
        
        $backup_path = __DIR__ . '/../db/faculty_backups/' . $filename;
        
        // Verify file exists and is within the backup directory
        if (!file_exists($backup_path)) {
            return ['status' => 'error', 'message' => 'Backup file not found'];
        }
        
        $real_backup_dir = realpath(__DIR__ . '/../db/faculty_backups');
        $real_backup_path = realpath($backup_path);
        
        if (strpos($real_backup_path, $real_backup_dir) !== 0) {
            return ['status' => 'error', 'message' => 'Invalid file path'];
        }
        
        // Delete the backup file
        if (unlink($backup_path)) {
            error_log("Faculty backup deleted: {$filename} by user {$faculty_username}");
            return ['status' => 'success', 'message' => 'Backup deleted successfully'];
        } else {
            return ['status' => 'error', 'message' => 'Failed to delete backup file'];
        }
        
    } catch (Exception $e) {
        error_log("Backup deletion error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Backup deletion failed: ' . $e->getMessage()];
    }
}

function listBackups() {
    try {
        $faculty_username = $_SESSION['username'];
        $backup_dir = __DIR__ . '/../db/faculty_backups';
        
        if (!is_dir($backup_dir)) {
            return ['status' => 'success', 'backups' => []];
        }
        
        $backups = [];
        $files = glob($backup_dir . '/' . $faculty_username . '_*.db');
        
        foreach ($files as $file) {
            $filename = basename($file);
            $timestamp = preg_replace('/^' . $faculty_username . '_|\.db$/', '', $filename);
            
            $backups[] = [
                'filename' => $filename,
                'timestamp' => $timestamp,
                'filepath' => $file,
                'size' => filesize($file),
                'created' => filemtime($file)
            ];
        }
        
        // Sort by creation time (newest first)
        usort($backups, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        return ['status' => 'success', 'backups' => $backups];
        
    } catch (Exception $e) {
        error_log("Backup listing error: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Failed to list backups: ' . $e->getMessage()];
    }
}

// Main request handling
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'backup':
        echo json_encode(createBackup());
        break;
        
    case 'delete':
        echo json_encode(deleteBackup());
        break;
        
    case 'list':
        echo json_encode(listBackups());
        break;
        
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>