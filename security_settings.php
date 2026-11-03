<?php
// Security settings management API
require_once("DBConnection.php");

// Start session to get user information
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters for cross-domain access
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // Allow all domains
        'secure' => false, // Allow HTTP (ngrok)
        'httponly' => true,
        'samesite' => 'Lax' // Allow cross-site requests
    ]);
    session_start();
}

// Function to get appropriate database connection based on user role
function getSecurityDatabaseConnection() {
    global $conn;
    
    // Check if user has manually switched database recently (within last hour)
    $useMainDb = true;
    if (isset($_SESSION['db_manually_switched']) && $_SESSION['db_manually_switched'] === true &&
        isset($_SESSION['db_switch_time']) && (time() - $_SESSION['db_switch_time']) < 3600) {
        $useMainDb = false;
        error_log("Security Settings - User has manually switched database, respecting manual selection");
    }
    
    if ($useMainDb) {
        // Use main grading database for security settings
        try {
            error_log("Security Settings - Forcing use of main grading database for all users");
            return new SQLite3('db/grading_db.db', SQLITE3_OPEN_READWRITE);
        } catch (Exception $e) {
            error_log("Security Settings - Failed to connect to main grading database: " . $e->getMessage());
            return $conn; // Fallback to global connection
        }
    } else {
        // Use the user's current database for security settings
        error_log("Security Settings - Using user's current database for security settings");
        return $conn;
    }
}

// Get the appropriate database connection
$security_conn = getSecurityDatabaseConnection();

// Suppress warnings and errors
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Handle GET request - get current security setting
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = "SELECT security_enabled FROM `security_settings` ORDER BY id DESC LIMIT 1";
        error_log("Security Settings - Executing query: " . $query);
        
        $result = $security_conn->query($query);
        
        if ($setting = $result->fetchArray()) {
            $security_enabled = (bool)$setting['security_enabled'];
            error_log("Security Settings - Found setting: " . ($security_enabled ? 'true' : 'false'));
            
            echo json_encode([
                'status' => 'success',
                'security_enabled' => $security_enabled
            ]);
        } else {
            // Insert default setting and return it
            error_log("Security Settings - No setting found, inserting default");
            $insert_query = "INSERT INTO `security_settings` (security_enabled) VALUES (1)";
            $security_conn->exec($insert_query);
            
            echo json_encode([
                'status' => 'success',
                'security_enabled' => true,
                'message' => 'Default security setting created'
            ]);
        }
    } catch (Exception $e) {
        error_log("Security Settings - GET Error: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to get security setting: ' . $e->getMessage()
        ]);
    }
}

// Handle POST request - update security setting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security_enabled = $_POST['security_enabled'] ?? null;
    
    if ($security_enabled === null) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Security enabled parameter is required'
        ]);
        exit;
    }
    
    try {
        // Update the most recent security setting record
        $query = "UPDATE `security_settings` SET security_enabled = $security_enabled, updated_at = CURRENT_TIMESTAMP WHERE id = (SELECT id FROM `security_settings` ORDER BY id DESC LIMIT 1)";
        $result = $security_conn->exec($query);
        
        if ($result && $security_conn->changes() > 0) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Security setting updated successfully',
                'security_enabled' => (bool)$security_enabled,
                'changes' => $security_conn->changes()
            ]);
        } else {
            // If no records were updated, try to insert a new one
            $insert_query = "INSERT INTO `security_settings` (security_enabled) VALUES ($security_enabled)";
            $insert_result = $security_conn->exec($insert_query);
            
            if ($insert_result) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Security setting created successfully',
                    'security_enabled' => (bool)$security_enabled
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Failed to update or create security setting'
                ]);
            }
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update security setting: ' . $e->getMessage()
        ]);
    }
}
?>
