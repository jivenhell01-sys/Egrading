<?php
require_once("DBConnection.php");
require_once("landing_db.php");

header('Content-Type: application/json');

// Get student number from POST request (landing has its own database -
// no faculty or class selection involved)
$student_number = $_POST['student_number'] ?? '';

if (empty($student_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student number is required'
    ]);
    exit;
}

$conn = landing_db();

try {
    // Check if student number is already registered in the landing database
    // (db/landing_db.db -> landing_registrations). Escape the input: this
    // endpoint is public and unauthenticated, so it must never interpolate
    // raw request data into SQL.
    $student_number = $conn->escapeString($student_number);
    $query = "SELECT * FROM `landing_registrations`
              WHERE student_number = '$student_number'";

    $result = $conn->query($query);
    $student = $result->fetchArray(SQLITE3_ASSOC);

    if ($student) {
        // Student number is already registered
        echo json_encode([
            'status' => 'registered',
            'message' => 'Student number is already registered',
            'data' => [
                'name' => $student['name'],
                'course' => $student['course'] ?? '',
                'year' => $student['year'] ?? '',
                'section' => $student['section'] ?? '',
                'registration_date' => $student['created_at'] ?? 'Unknown'
            ]
        ]);
    } else {
        // Student number is available for registration
        echo json_encode([
            'status' => 'available',
            'message' => 'Student number is available for registration'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
