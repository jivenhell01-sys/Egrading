<?php
// AJAX endpoint for the admin Landing Registrations page:
// returns every class + faculty a student_number is enrolled in.
error_reporting(0);
ini_set('display_errors', 0);

require_once("landing_enrollments.php");
require_once("DBConnection.php"); // remarks_for_grade() helper for remarks fallback

header('Content-Type: application/json');

$student_number = trim($_GET['student_number'] ?? ($_POST['student_number'] ?? ''));

if ($student_number === '') {
    echo json_encode(['status' => 'error', 'message' => 'Student number is required']);
    exit;
}

try {
    $enrollments = find_student_enrollments($student_number);
    echo json_encode([
        'status' => 'success',
        'student_number' => $student_number,
        'count' => count($enrollments),
        'enrollments' => $enrollments
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
