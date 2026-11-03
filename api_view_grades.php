<?php
require_once('DBConnection.php');
require_once('Actions.php');

header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get POST data
    $student_number = isset($_POST['student_number']) ? trim($_POST['student_number']) : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (empty($student_number) || empty($action)) {
        throw new Exception('Missing required parameters');
    }

    // Validate student number format
    if (!preg_match('/^\d{2}-\d{5}$/', $student_number)) {
        throw new Exception('Invalid student number format');
    }

    // Initialize database connections
    $conn = new DBConnection();
    $actions = new Actions();

    if ($action === 'view_grades') {
        // Find the student first
        $student_query = $conn->query("SELECT s.student_id, s.name, s.student_number, 
                                             c.class_id, c.grade, c.section, 
                                             sub.name as subject_name, sub.subject_id,
                                             (c.grade || ' - ' || c.section) as class_name
                                      FROM student_list s 
                                      JOIN class_list c ON s.class_id = c.class_id 
                                      JOIN subjects sub ON c.subject_id = sub.subject_id 
                                      WHERE s.student_number = '$student_number' 
                                      LIMIT 1");

        $student = $student_query->fetchArray(SQLITE3_ASSOC);

        if (!$student) {
            throw new Exception('Student not found with the provided student number');
        }

        // Get all published grades for this student and calculate averages by class
        $grades_query = $conn->query("SELECT pg.class_id, 
                                             c.grade, c.section, c.semester, c.year,
                                             (c.grade || ' - ' || c.section) as class_name,
                                             s.name as subject_name,
                                             s.subject_id
                                      FROM published_grades pg 
                                      JOIN class_list c ON pg.class_id = c.class_id 
                                      JOIN subjects s ON c.subject_id = s.subject_id 
                                      WHERE pg.student_id = {$student['student_id']} 
                                      GROUP BY pg.class_id, c.grade, c.section, c.semester, c.year, s.name, s.subject_id
                                      ORDER BY c.year DESC, c.semester");

        $grades = [];
        
        if ($grades_query) {
            while ($class_info = $grades_query->fetchArray(SQLITE3_ASSOC)) {
                $class_id = $class_info['class_id'];
                
                // Get all individual grades for this class and student
                $individual_grades_query = $conn->query("SELECT quarter, initial_grade, equivalent_grade 
                                                        FROM published_grades 
                                                        WHERE student_id = {$student['student_id']} 
                                                        AND class_id = $class_id 
                                                        ORDER BY quarter");
                
                $individual_grades = [];
                $equivalent_grades = [];
                $quarters = [];
                
                if ($individual_grades_query) {
                    while ($grade = $individual_grades_query->fetchArray(SQLITE3_ASSOC)) {
                        if ($grade['initial_grade'] > 0) {
                            $individual_grades[] = floatval($grade['initial_grade']);
                            $equivalent_grades[] = floatval($grade['equivalent_grade']);
                            $quarters[] = intval($grade['quarter']);
                        }
                    }
                }
                
                if (!empty($equivalent_grades)) {
                    // Calculate average of transmuted grades instead of initial grades
                    $average_equivalent = array_sum($equivalent_grades) / count($equivalent_grades);
                    
                    // Calculate average of initial grades for reference (not used for transmutation)
                    $average_initial = array_sum($individual_grades) / count($individual_grades);
                    
                    // Determine remarks based on average of transmuted grades
                    // (stored values may be CHED 1.0-5.0 or DepEd 60-100 scale)
                    $remarks = remarks_for_grade($average_equivalent, 'AUTO');
                    
                    $grades[] = [
                        'class_id' => intval($class_id),
                        'class_name' => $class_info['class_name'],
                        'subject_name' => $class_info['subject_name'],
                        'average_initial_grade' => $average_initial, // Keep for reference
                        'average_equivalent_grade' => $average_equivalent, // Now calculated from transmuted grades
                        'remarks' => $remarks,
                        'individual_grades_count' => count($individual_grades),
                        'individual_grades' => $individual_grades,
                        'individual_equivalents' => $equivalent_grades,
                        'quarters' => $quarters,
                        'semester' => $class_info['semester'],
                        'year' => $class_info['year'],
                        'student_name' => $student['name'],
                        'student_middlename' => $student['middlename']
                    ];
                }
            }
        }

        if (empty($grades)) {
            throw new Exception('No published grades found for this student');
        }

        $response['success'] = true;
        $response['message'] = 'Grades retrieved successfully';
        $response['data'] = $grades;

    } else {
        throw new Exception('Invalid action specified');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("View grades error: " . $e->getMessage());
}

// Send JSON response
echo json_encode($response);
?>
