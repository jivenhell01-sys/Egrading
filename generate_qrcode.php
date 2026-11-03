<?php
// Simple QR code generation with student data
require_once('DBConnection.php');

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set content type
header('Content-Type: image/png');

// Get student ID
if (!isset($_GET['student_id'])) {
    die('No student ID provided');
}

$student_id = $_GET['student_id'];

// Get student details
try {
    $stmt = $conn->prepare("SELECT * FROM student_list WHERE student_id = :student_id");
    $stmt->bindValue(':student_id', $student_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $student = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    // Prepare data for QR code
    $data = "Student ID: " . $student['student_id'] . "\n";
    $data .= "Name: " . $student['name'] . "\n";
    $data .= "Student Number: " . $student['student_number'] . "\n";
    if (isset($student['class_id'])) {
        $data .= "Class: " . $student['class_id'];
    }
    
    // Try local QR code generation first
    if (file_exists('includes/phpqrcode/qrlib.php')) {
        require_once('includes/phpqrcode/qrlib.php');
        QRcode::png($data, null, QR_ECLEVEL_L, 10, 2);
        exit;
    }
    
    // Fallback to Google Charts API
    $url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($data) . '&choe=UTF-8';
    $image = @file_get_contents($url);
    
    if ($image === false) {
        // If file_get_contents fails, try cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $image = curl_exec($ch);
        curl_close($ch);
    }
    
    echo $image;
    
} catch (Exception $e) {
    // Show error message
    header('Content-Type: text/plain');
    die('Error: ' . $e->getMessage());
}
?>
