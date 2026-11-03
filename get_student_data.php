<?php
// Suppress warnings and errors
error_reporting(0);
ini_set('display_errors', 0);

require_once("DBConnection.php");
require_once("landing_db.php");

header('Content-Type: application/json');

// Get parameters (landing has its own database -
// no faculty or class selection involved)
$student_number = $_GET['student_number'] ?? '';
$timezone = $_GET['timezone'] ?? '';
$screen_resolution = $_GET['screen_resolution'] ?? '';

$conn = landing_db();

// Get current device information
function getCurrentDeviceInfo() {
    // Get more stable browser and device information
    $screen_resolution = $_GET['screen_resolution'] ?? 'unknown';
    $timezone = $_GET['timezone'] ?? 'unknown';
    
    // Use only stable characteristics that don't change frequently
    $device_info = [
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'http_accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'timezone' => $timezone,
        'screen_resolution' => $screen_resolution,
        'device_name' => gethostbyaddr($_SERVER['REMOTE_ADDR'])
    ];
    
    // Create a more stable device ID using only consistent characteristics
    $device_id = md5(json_encode($device_info));
    
    return [
        'device_id' => $device_id,
        'device_name' => $device_info['device_name'],
        'device_info' => $device_info
    ];
}

// Check if security is enabled
function isSecurityEnabled($conn) {
    try {
        $query = "SELECT security_enabled FROM `security_settings` ORDER BY id DESC LIMIT 1";
        $result = $conn->query($query);
        $setting = $result->fetchArray(SQLITE3_ASSOC);
        
        // Default to enabled if no setting found
        return $setting ? (bool)$setting['security_enabled'] : true;
    } catch (Exception $e) {
        // Default to enabled on error
        return true;
    }
}

// Get student number from GET request
$student_number = $_GET['student_number'] ?? '';

if (empty($student_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student number is required'
    ]);
    exit;
}

try {
    // Get current device information
    $device_info = getCurrentDeviceInfo();
    $current_device_id = $device_info['device_id'];
    $current_device_name = $device_info['device_name'];
    
    // Get student data with device information
    $query = "SELECT * FROM `landing_registrations`
              WHERE student_number = '$student_number'";
    
    $result = $conn->query($query);
    $student = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($student) {
        // Check if security is enabled
        $security_enabled = isSecurityEnabled($conn);
        
        // Debug: Log device comparison
        error_log("Device Comparison Debug:");
        error_log("Security enabled: " . ($security_enabled ? 'true' : 'false'));
        error_log("Stored device_id: " . ($student['device_id'] ?? 'NULL'));
        error_log("Current device_id: " . $current_device_id);
        error_log("Current device_name: " . $current_device_name);
        error_log("Student: " . $student['student_number'] . " - " . $student['name']);
        
        // If security is disabled, allow access to all students
        if (!$security_enabled) {
            echo json_encode([
                'status' => 'success',
                'data' => $student,
                'debug' => [
                    'security_enabled' => false,
                    'device_check_bypassed' => true,
                    'message' => 'Security is disabled - access granted',
                    'current_device_name' => $current_device_name
                ]
            ]);
        }
        // If security is enabled, require exact device match
        elseif (isset($student['device_id']) && $student['device_id'] !== null && $student['device_id'] !== '' && $student['device_id'] === $current_device_id) {
            echo json_encode([
                'status' => 'success',
                'data' => $student,
                'debug' => [
                    'security_enabled' => true,
                    'stored_device_id' => $student['device_id'],
                    'current_device_id' => $current_device_id,
                    'current_device_name' => $current_device_name,
                    'stored_device_name' => $student['device_name'] ?? 'unknown',
                    'device_match' => true
                ]
            ]);
        } else {
            // Device mismatch or no device ID - deny access
            $reason = !isset($student['device_id']) ? 'No device ID found' : 
                     ($student['device_id'] === null ? 'Device ID is NULL' : 
                     ($student['device_id'] === '' ? 'Device ID is empty' : 'Device mismatch'));
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Access denied. This student data is protected by device recognition. You can only view/edit data submitted from this device.',
                'error_code' => 'DEVICE_MISMATCH',
                'debug' => [
                    'security_enabled' => true,
                    'stored_device_id' => $student['device_id'] ?? 'NULL',
                    'current_device_id' => $current_device_id,
                    'current_device_name' => $current_device_name,
                    'stored_device_name' => $student['device_name'] ?? 'unknown',
                    'device_match' => false,
                    'reason' => $reason,
                    'student_name' => $student['name'],
                    'student_number' => $student['student_number']
                ]
            ]);
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Student not found'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
