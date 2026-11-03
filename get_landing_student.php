<?php
// Fetch a landing registration by student_number for auto-fill in manage_student.php
error_reporting(0);
ini_set('display_errors', 0);

require_once("landing_db.php");

header('Content-Type: application/json');

$student_number = trim($_GET['student_number'] ?? ($_POST['student_number'] ?? ''));

if ($student_number === '') {
    echo json_encode(['status' => 'error', 'message' => 'Student number is required']);
    exit;
}

try {
    $db = landing_db();
    $esc = $db->escapeString($student_number);
    $row = $db->query("SELECT * FROM `landing_registrations` WHERE student_number = '{$esc}' LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        echo json_encode(['status' => 'success', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'not_found', 'message' => 'No landing registration found for this student number']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
