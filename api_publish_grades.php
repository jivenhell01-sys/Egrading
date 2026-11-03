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
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($class_id <= 0 || empty($action)) {
        throw new Exception('Missing required parameters');
    }

    // Initialize database connections
    $conn = new DBConnection();
    $actions = new Actions();

    if ($action === 'publish') {
        // Get class information
        $class_query = $conn->query("SELECT c.*, s.name as subject_name 
                                    FROM class_list c 
                                    JOIN subjects s ON c.subject_id = s.subject_id 
                                    WHERE c.class_id = $class_id 
                                    LIMIT 1");
        $class_info = $class_query->fetchArray(SQLITE3_ASSOC);

        if (!$class_info) {
            throw new Exception('Class not found');
        }

        // Get all students in this class
        $students_query = $conn->query("SELECT student_id, student_number, name 
                                       FROM student_list 
                                       WHERE class_id = $class_id 
                                       ORDER BY name ASC");

        if (!$students_query) {
            throw new Exception('No students found in this class');
        }

        $published_count = 0;
        $updated_count = 0;

        // Get current admin ID from session
        $published_by = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : 1;

        while ($student = $students_query->fetchArray(SQLITE3_ASSOC)) {
            $student_id = $student['student_id'];
            
            // Get all quarters for this class
            $quarters_query = $conn->query("SELECT DISTINCT quarter FROM assessment_list 
                                          WHERE class_id = $class_id 
                                          ORDER BY quarter");
            
            if ($quarters_query) {
                while ($quarter_row = $quarters_query->fetchArray(SQLITE3_ASSOC)) {
                    $quarter = intval($quarter_row['quarter']);
                    
                    // Get initial grade from student_initial_grade table
                    $initial_grade_query = $conn->query("SELECT initial_grade 
                                                        FROM student_initial_grade 
                                                        WHERE student_id = $student_id 
                                                        AND class_id = $class_id 
                                                        AND quarter = $quarter 
                                                        LIMIT 1");
                    
                    $initial_grade_data = $initial_grade_query->fetchArray(SQLITE3_ASSOC);
                    $initial_grade = $initial_grade_data ? floatval($initial_grade_data['initial_grade']) : 0;
                    
                    if ($initial_grade > 0) {
                        // Apply transmutation using transmutation_table
                        $transmuted_grade = $initial_grade;
                        
                        // Get equivalent grade from the active (CHED/DEPED) transmutation table
                        static $trans_system = null;
                        if ($trans_system === null) { $trans_system = get_active_transmutation_system($conn); }
                        $equivalent_query = $conn->query("SELECT grade 
                                                         FROM transmutation_table 
                                                         WHERE `system` = '{$trans_system}'
                                                         AND $initial_grade BETWEEN `from` AND `to` 
                                                         LIMIT 1");
                        
                        $equivalent_data = $equivalent_query->fetchArray(SQLITE3_ASSOC);
                        $equivalent_grade = $equivalent_data ? floatval($equivalent_data['grade']) : 5.0;
                        
                        // Determine remarks based on equivalent grade
                        $remarks = remarks_for_grade($equivalent_grade, $trans_system);
                        
                        // Check if grade already exists in published_grades
                        $existing_query = $conn->query("SELECT id 
                                                       FROM published_grades 
                                                       WHERE class_id = $class_id 
                                                       AND student_id = $student_id 
                                                       AND quarter = $quarter 
                                                       LIMIT 1");
                        
                        $existing = $existing_query->fetchArray(SQLITE3_ASSOC);
                        
                        if ($existing) {
                            // Update existing record
                            $update_query = $conn->query("UPDATE published_grades 
                                                         SET initial_grade = $initial_grade, 
                                                             equivalent_grade = $equivalent_grade, 
                                                             remarks = '$remarks', 
                                                             published_by = $published_by, 
                                                             published_date = CURRENT_TIMESTAMP,
                                                             semester = '{$class_info['semester']}'
                                                         WHERE class_id = $class_id 
                                                         AND student_id = $student_id 
                                                         AND quarter = $quarter");
                            
                            if ($update_query) {
                                $updated_count++;
                            }
                        } else {
                            // Insert new record
                            $insert_query = $conn->query("INSERT INTO published_grades 
                                                         (class_id, student_id, quarter, initial_grade, equivalent_grade, remarks, published_by, semester) 
                                                         VALUES ($class_id, $student_id, $quarter, $initial_grade, $equivalent_grade, '$remarks', $published_by, '{$class_info['semester']}')");
                            
                            if ($insert_query) {
                                $published_count++;
                            }
                        }
                    }
                }
            }
        }

        $response['success'] = true;
        $response['message'] = "Published $published_count new grades and updated $updated_count existing grades for {$class_info['subject_name']} - Grade {$class_info['grade']}-{$class_info['section']}";
        $response['data'] = [
            'published_count' => $published_count,
            'updated_count' => $updated_count,
            'class_info' => $class_info
        ];

    } else {
        throw new Exception('Invalid action specified');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Publish grades error: " . $e->getMessage());
}

// Send JSON response
echo json_encode($response);
?>
