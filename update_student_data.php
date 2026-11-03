<?php
// Suppress warnings and errors
error_reporting(0);
ini_set('display_errors', 0);

require_once("DBConnection.php");
require_once("landing_db.php");

header('Content-Type: application/json');

// Landing registrations live in their own database -
// no faculty or class selection involved.
$conn = landing_db();

// Get current device information
function getCurrentDeviceInfo() {
    // Get more stable browser and device information
    $screen_resolution = $_POST['screen_resolution'] ?? 'unknown';
    $timezone = $_POST['timezone'] ?? 'unknown';
    
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

// Get student data from POST request (no class selection on the landing page)
$student_number = $conn->escapeString($_POST['student_number'] ?? '');
$lastname = $conn->escapeString(trim($_POST['lastname'] ?? ''));
$firstname = $conn->escapeString(trim($_POST['firstname'] ?? ''));
$middlename = $conn->escapeString(trim($_POST['middlename'] ?? ''));
$name_extension = $conn->escapeString(trim($_POST['name_extension'] ?? ''));
$course = $conn->escapeString(trim($_POST['course'] ?? ''));
$year = $conn->escapeString(trim($_POST['year'] ?? ''));
$section_raw = strtoupper(trim($_POST['section'] ?? ''));
$section = $conn->escapeString($section_raw);
$name = $lastname . ', ' . $firstname;
if (!empty($middlename) && strtolower($middlename) !== 'none') {
    $name .= ' ' . $middlename;
}
if (!empty($name_extension) && strtolower($name_extension) !== 'none') {
    $name .= ' ' . $name_extension;
}
$birthdate = $conn->escapeString($_POST['birthdate'] ?? '');
$age = $conn->escapeString($_POST['age'] ?? '');
$gender = $conn->escapeString($_POST['gender'] ?? '');
// Additional personal information dropdowns (only allow listed values)
$CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Divorced', 'Widowed', 'Separated', 'Annulled'];
$FAMILY_STATUS_OPTIONS = ['Parent', 'Household Head', 'Dependent', 'Solo Parent'];
$SPECIAL_SECTOR_OPTIONS = ['PWD', 'Indigenous Person'];
$civil_status = in_array(trim($_POST['civil_status'] ?? ''), $CIVIL_STATUS_OPTIONS, true) ? trim($_POST['civil_status']) : '';
$family_status = in_array(trim($_POST['family_status'] ?? ''), $FAMILY_STATUS_OPTIONS, true) ? trim($_POST['family_status']) : '';
$special_sector = in_array(trim($_POST['special_sector'] ?? ''), $SPECIAL_SECTOR_OPTIONS, true) ? trim($_POST['special_sector']) : '';
$contact_number = $conn->escapeString($_POST['contact_number'] ?? '');
$email = $conn->escapeString($_POST['email'] ?? '');
$barangay = $conn->escapeString($_POST['barangay'] ?? '');
$municipality = $conn->escapeString($_POST['municipality'] ?? '');
$province = $conn->escapeString($_POST['province'] ?? '');
$zipcode = $conn->escapeString($_POST['zipcode'] ?? '');
$is_irregular = (isset($_POST['is_irregular']) && trim((string)$_POST['is_irregular']) === '1') ? 1 : 0;
$irregular_subjects_raw = trim((string)($_POST['irregular_subjects_json'] ?? ''));
$irregular_subjects = [];
$irregular_error = '';
if ($is_irregular) {
    $decoded = json_decode($irregular_subjects_raw, true);
    if (!is_array($decoded) || empty($decoded)) {
        $irregular_error = 'You marked Irregular — please add at least one subject (year, section and subject).';
    } else {
        foreach (array_slice($decoded, 0, 20) as $it) {
            if (!is_array($it)) { $irregular_error = 'Invalid irregular subject entry. Please re-pick your subjects.'; $irregular_subjects = []; break; }
            $y = trim((string)($it['year'] ?? ''));
            $s = strtoupper(trim((string)($it['section'] ?? '')));
            $c = trim((string)($it['code'] ?? ''));
            $n = trim((string)($it['name'] ?? ''));
            if (!preg_match('/^[1-6]$/', $y) || !preg_match('/^[1-6][A-Z]+$/', $s) || $s[0] !== $y || $c === '' || $n === '') {
                $irregular_error = 'Invalid irregular subject entry. Please re-pick your subjects.'; $irregular_subjects = []; break;
            }
            $irregular_subjects[] = ['year'=>$y,'section'=>$s,'code'=>mb_substr($c,0,50),'name'=>mb_substr($n,0,150)];
        }
        if ($irregular_error === '' && empty($irregular_subjects)) $irregular_error = 'You marked Irregular — please add at least one subject (year, section and subject).';
    }
} else {
    // Not irregular — force empty list
    $irregular_subjects = [];
}
$irregular_json_esc = $conn->escapeString(json_encode($irregular_subjects));

if (empty($student_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student number is required'
    ]);
    exit;
}

if (mb_strlen($section_raw) < 2) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Section must be at least 2 characters (e.g. 1A, 2B).'
    ]);
    exit;
}
if (!preg_match('/^[1-6][A-Z]+$/', $section_raw)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Section must start with the year number (1-6) followed by letters only A-Z (e.g. 1A, 2B). Symbols are not allowed.'
    ]);
    exit;
}
if (!empty($year) && $section_raw[0] !== (string)$year) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Section must start with the selected Year Level (e.g. Year '.$year.' → '.$year.'A).'
    ]);
    exit;
}
if ($irregular_error !== '') {
    echo json_encode(['status'=>'error','message'=>$irregular_error]);
    exit;
}

try {
    // Get current device information
    $device_info = getCurrentDeviceInfo();
    $current_device_id = $conn->escapeString($device_info['device_id']);
    $current_device_name = $conn->escapeString($device_info['device_name']);
    
    // First check if student exists and was registered from this device
    $check_query = "SELECT device_id FROM `landing_registrations` WHERE student_number = '$student_number'";
    $result = $conn->query($check_query);
    $student = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Student not found'
        ]);
        exit;
    }
    
    // Check device ownership - check security setting first
    $security_enabled = isSecurityEnabled($conn);
    
    // Debug: Log security and device comparison
    $has_device_id = isset($student['device_id']) && $student['device_id'] !== null && $student['device_id'] !== '';
    error_log("Update Device Comparison Debug:");
    error_log("Security enabled: " . ($security_enabled ? 'true' : 'false'));
    error_log("Student has device ID: " . ($has_device_id ? 'YES' : 'NO'));
    error_log("Stored device_id: " . ($student['device_id'] ?? 'NULL'));
    error_log("Current device_id: " . $current_device_id);
    error_log("Current device_name: " . $current_device_name);
    error_log("Student: " . $student['student_number'] . " - " . $student['name']);
    
    // If security is disabled, allow update for all students
    if (!$security_enabled) {
        // Update student data and save device info (+ irregular)
        $query = "UPDATE `landing_registrations` SET
                  name = '$name',
                  middlename = '$middlename',
                  name_extension = '$name_extension',
                  course = '$course',
                  year = '$year',
                  section = '$section',
                  birthdate = '$birthdate',
                  age = '$age',
                  gender = '$gender',
                  civil_status = '$civil_status',
                  family_status = '$family_status',
                  special_sector = '$special_sector',
                  contact_number = '$contact_number',
                  email = '$email',
                  barangay = '$barangay',
                  municipality = '$municipality',
                  province = '$province',
                  zipcode = '$zipcode',
                  is_irregular = '{$is_irregular}',
                  irregular_subjects = '{$irregular_json_esc}',
                  device_id = '$current_device_id',
                  device_name = '$current_device_name'
                  WHERE student_number = '$student_number'";
        
        $result = $conn->exec($query);
        
        if ($result) {
            $was_first_time = ($student['device_id'] === null || $student['device_id'] === '');
            echo json_encode([
                'status' => 'success',
                'message' => 'Student data updated successfully' . ($was_first_time ? ' - Device secured!' : ''),
                'debug' => [
                    'security_enabled' => false,
                    'device_check_bypassed' => true,
                    'current_device_name' => $current_device_name,
                    'was_first_time_device_assignment' => $was_first_time
                ]
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'No changes made or student not found'
            ]);
        }
    }
    // If security is enabled, allow first-time device assignment or device match
    elseif (!isset($student['device_id']) || $student['device_id'] === null || $student['device_id'] === '') {
        // Student has no device ID yet - allow first-time assignment
        $query = "UPDATE `landing_registrations` SET
                  name = '$name',
                  middlename = '$middlename',
                  name_extension = '$name_extension',
                  course = '$course',
                  year = '$year',
                  section = '$section',
                  birthdate = '$birthdate',
                  age = '$age',
                  gender = '$gender',
                  civil_status = '$civil_status',
                  family_status = '$family_status',
                  special_sector = '$special_sector',
                  contact_number = '$contact_number',
                  email = '$email',
                  barangay = '$barangay',
                  municipality = '$municipality',
                  province = '$province',
                  zipcode = '$zipcode',
                  is_irregular = '{$is_irregular}',
                  irregular_subjects = '{$irregular_json_esc}',
                  device_id = '$current_device_id',
                  device_name = '$current_device_name'
                  WHERE student_number = '$student_number'";
        
        $result = $conn->exec($query);
        
        if ($result) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Student data updated successfully - Account secured to this device!',
                'debug' => [
                    'security_enabled' => true,
                    'current_device_name' => $current_device_name,
                    'first_time_device_assignment' => true
                ]
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'No changes made or student not found'
            ]);
        }
    }
    // If security is enabled, require exact device match for existing device IDs
    elseif (isset($student['device_id']) && $student['device_id'] !== null && $student['device_id'] !== '' && $student['device_id'] === $current_device_id) {
        // Device matches - allow update
        
        // Update student data
        $query = "UPDATE `landing_registrations` SET
                  name = '$name',
                  middlename = '$middlename',
                  name_extension = '$name_extension',
                  course = '$course',
                  year = '$year',
                  section = '$section',
                  birthdate = '$birthdate',
                  age = '$age',
                  gender = '$gender',
                  civil_status = '$civil_status',
                  family_status = '$family_status',
                  special_sector = '$special_sector',
                  contact_number = '$contact_number',
                  email = '$email',
                  barangay = '$barangay',
                  municipality = '$municipality',
                  province = '$province',
                  zipcode = '$zipcode',
                  is_irregular = '{$is_irregular}',
                  irregular_subjects = '{$irregular_json_esc}',
                  device_id = '$current_device_id',
                  device_name = '$current_device_name'
                  WHERE student_number = '$student_number'";
        
        $result = $conn->exec($query);
        
        if ($result) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Student data updated successfully',
                'debug' => [
                    'security_enabled' => true,
                    'current_device_name' => $current_device_name,
                    'device_match' => true
                ]
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'No changes made or student not found'
            ]);
        }
    } else {
        // Device mismatch or no device ID - deny access
        $reason = !isset($student['device_id']) ? 'No device ID found' : 
                 ($student['device_id'] === null ? 'Device ID is NULL' : 
                 ($student['device_id'] === '' ? 'Device ID is empty' : 'Device mismatch'));
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Access denied. This student data is protected by device recognition. You can only edit data submitted from this device.',
            'error_code' => 'DEVICE_MISMATCH',
            'debug' => [
                'security_enabled' => true,
                'stored_device_id' => $student['device_id'] ?? 'NULL',
                'current_device_id' => $current_device_id,
                'current_device_name' => $current_device_name,
                'device_match' => false,
                'reason' => $reason,
                'student_name' => $student['name'],
                'student_number' => $student['student_number']
            ]
        ]);
        exit;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
