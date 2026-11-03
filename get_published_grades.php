<?php
// Suppress any warnings/errors that could corrupt JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

require_once('DBConnection.php');
require_once('Actions.php');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (allow access for both logged in users and public access)
$allow_public = true; // Allow public access to view published grades

// Get POST data
$student_number = isset($_POST['student_number']) ? trim($_POST['student_number']) : '';
$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;

if (empty($student_number) || $class_id <= 0) {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode(['success' => false, 'message' => 'Student number and class ID are required.']);
    exit;
}

// Initialize database connection
$conn = new DBConnection();

try {
    // Get student information
    $student_query = $conn->query("SELECT s.*, c.grade, c.section, c.year, c.semester, sub.name as subject_name
                                   FROM student_list s
                                   JOIN class_list c ON s.class_id = c.class_id
                                   JOIN subjects sub ON c.subject_id = sub.subject_id
                                   WHERE s.student_number = '$student_number' 
                                   AND s.class_id = $class_id LIMIT 1");
    
    $student_info = $student_query->fetchArray(SQLITE3_ASSOC);
    
    if (!$student_info) {
        // Clean output buffer to ensure clean JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode(['success' => false, 'message' => 'Student not found in the specified class.']);
        exit;
    }
    
    // Get published grades for this student
    $grades_query = $conn->query("SELECT pg.*, al.fullname as published_by_name
                                   FROM published_grades pg
                                   LEFT JOIN admin_list al ON pg.published_by = al.admin_id
                                   WHERE pg.student_id = '{$student_info['student_id']}'
                                   AND pg.class_id = $class_id
                                   ORDER BY pg.quarter ASC");
    
    $grades = [];
    while ($row = $grades_query->fetchArray(SQLITE3_ASSOC)) {
        $grades[] = [
            'quarter' => $row['quarter'],
            'initial_grade' => $row['initial_grade'],
            'equivalent_grade' => $row['equivalent_grade'],
            'average_grade' => $row['average_grade'],
            'average_equivalent' => $row['average_equivalent'],
            'remarks' => $row['remarks'],
            'published_date' => $row['published_date'],
            'published_by' => $row['published_by_name']
        ];
    }
    
    if (empty($grades)) {
        // Clean output buffer to ensure clean JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode(['success' => false, 'message' => 'No published grades found for this student.']);
        exit;
    }
    
    // Prepare class information
    $class_info = [
        'grade' => $student_info['grade'],
        'section' => $student_info['section'],
        'subject_name' => $student_info['subject_name'],
        'year' => $student_info['year'],
        'semester' => $student_info['semester'],
        'student_name' => $student_info['name'],
        'student_number' => $student_info['student_number']
    ];
    
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'grades' => $grades,
        'class_info' => $class_info,
        'student_info' => [
            'name' => $student_info['name'],
            'student_number' => $student_info['student_number']
        ]
    ]);
    
} catch (Exception $e) {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error retrieving published grades: ' . $e->getMessage()
    ]);
}
?>
