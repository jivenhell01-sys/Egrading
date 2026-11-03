<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start output buffering to catch any unexpected output
ob_start();

require_once('DBConnection.php');
require_once('Actions.php');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_id'] <= 0) {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied. Please login first.']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Get POST data (class_id may be a single id or 'all' for every class)
$publish_all = isset($_POST['class_id']) && (strtolower(trim((string)$_POST['class_id'])) === 'all');
$class_id = $publish_all ? 0 : (isset($_POST['class_id']) ? intval($_POST['class_id']) : 0);
$publish_type = isset($_POST['publish_type']) ? $_POST['publish_type'] : 'all';

if (!$publish_all && $class_id <= 0) {
    // Clean output buffer to ensure clean JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid class ID.']);
    exit;
}

// Initialize database connections
$conn = new DBConnection();
$actions = new Actions();
$published_by = $_SESSION['admin_id'];

// Function to get equivalent grade from transmutation table (top level:
// publish_class_grades() below runs once per class, so this must NOT live inside it)
if (!function_exists('get_equivalent_from_transmutation')) {
function get_equivalent_from_transmutation($grade) {
    global $conn;
    
    if ($grade === 'N/A' || !is_numeric($grade)) {
        return 'N/A';
    }
    
    $grade = round(floatval($grade), 2);
    
    // Get the matching range from the active (CHED/DEPED) transmutation table
    static $trans_system = null;
    if ($trans_system === null) { $trans_system = get_active_transmutation_system($conn); }
    $transmutation_query = $conn->query("SELECT grade, `from`, `to` FROM `transmutation_table` WHERE `system` = '{$trans_system}' ORDER BY `from` ASC");
    $equivalent = 5.0; // Default numeric value if no range matches

    while ($row = $transmutation_query->fetchArray(SQLITE3_ASSOC)) {
        $from = floatval($row['from']);
        $to   = floatval($row['to']);

        if ($grade >= $from && $grade <= $to) {
            $equivalent = floatval($row['grade']);
            break;
        }
    }

    // DepEd transmuted grades (60-100 scale) pass through untouched.
    if ($equivalent > 5.5) {
        return number_format($equivalent, 1);
    }

    // If equivalent is 5.0 or above, treat as DROPPED
    if ($equivalent >= 5.0) {
        return '5.0';
    }

    return number_format($equivalent, 1);
}
}

// Publishes a single class. Uses its own transaction so one bad class never
// wipes out the rest in publish-all mode.
// Returns ['published' => int, 'class_info' => array, 'empty' => bool].
function publish_class_grades($conn, $class_id, $published_by) {
    $class_id = intval($class_id);

// Get class information
$class_query = $conn->query("SELECT c.*, s.name as subject_name 
                            FROM class_list c 
                            JOIN subjects s ON c.subject_id = s.subject_id 
                            WHERE c.class_id = $class_id LIMIT 1");
$class_info = $class_query ? $class_query->fetchArray(SQLITE3_ASSOC) : null;

if (!$class_info) {
    throw new Exception('Class not found (ID: ' . $class_id . ').');
}

// Define quarters
$all_quarters = [
    1 => ['name' => 'Prelim', 'key' => 'prelim'],
    2 => ['name' => 'Midterm', 'key' => 'midterm'],
    3 => ['name' => 'Pre-Final', 'key' => 'pre_final'],
    4 => ['name' => 'Final', 'key' => 'final']
];

// Get active quarters for this class
$active_quarters = [];
$quarters_result = $conn->query("SELECT DISTINCT quarter FROM assessment_list 
                               WHERE class_id = $class_id ORDER BY quarter");

if ($quarters_result) {
    while ($row = $quarters_result->fetchArray(SQLITE3_ASSOC)) {
        $q_num = intval($row['quarter']);
        if (isset($all_quarters[$q_num])) {
            $active_quarters[$q_num] = $all_quarters[$q_num];
        }
    }
}

if (empty($active_quarters)) {
    $active_quarters = $all_quarters;
}

// Get students in this class
$students = [];
$student_qry = $conn->query("SELECT * FROM student_list WHERE class_id = $class_id ORDER BY name ASC");

while($student = $student_qry->fetchArray(SQLITE3_ASSOC)){
    $students[$student['student_id']] = [
        'name' => $student['name'],
        'student_number' => $student['student_number'],
        'middle_name' => $student['middle_name']
    ];
}

if (empty($students)) {
    return ['published' => 0, 'class_info' => $class_info, 'empty' => true];
}

// Start transaction
$conn->exec("BEGIN TRANSACTION");

$published_count = 0;

try {
    // DepEd Final Grade = average of quarterly TRANSMUTED grades (DO 8);
    // CHED keeps transmute-of-average. Active system is fixed per class run.
    $depEdActive = (get_active_transmutation_system($conn) === 'DEPED');
    // Process each student
    foreach($students as $student_id => $student_data) {
        // Process each quarter
        foreach ($active_quarters as $q_num => $q_data) {
            // Get the initial grade from the database
            $initial_grade_query = $conn->query("SELECT initial_grade FROM student_initial_grade 
                WHERE student_id = '{$student_id}' 
                AND class_id = '{$class_id}'
                AND quarter = '{$q_num}'");
            
            $initial_grade_data = $initial_grade_query ? $initial_grade_query->fetchArray(SQLITE3_ASSOC) : null;
            $grade_value = $initial_grade_data ? $initial_grade_data['initial_grade'] : 0;
            
            if ($grade_value > 0) {
                // Get equivalent grade
                $equivalent = get_equivalent_from_transmutation($grade_value);
                
                // Calculate average if we have multiple quarters
                $average_grade = null;
                $average_equivalent = null;
                
                // Get all quarters for this student to calculate average
                $total_initial = 0;
                $total_transmuted = 0;
                $valid_quarters = 0;
                
                foreach ($active_quarters as $avg_q_num => $avg_q_data) {
                    $avg_grade_query = $conn->query("SELECT initial_grade FROM student_initial_grade 
                        WHERE student_id = '{$student_id}' 
                        AND class_id = '{$class_id}'
                        AND quarter = '{$avg_q_num}'");
                    
                    $avg_grade_data = $avg_grade_query ? $avg_grade_query->fetchArray(SQLITE3_ASSOC) : null;
                    if ($avg_grade_data && $avg_grade_data['initial_grade'] > 0) {
                        if ($depEdActive) {
                            $t = get_equivalent_from_transmutation($avg_grade_data['initial_grade']);
                            if (is_numeric($t)) {
                                $total_transmuted += floatval($t);
                                $valid_quarters++;
                            }
                        } else {
                            $avg_grade_value = $avg_grade_data['initial_grade'];
                            if ($avg_grade_value < 54.5) {
                                $avg_grade_value = 54.5;
                            }
                            $total_initial += $avg_grade_value;
                            $valid_quarters++;
                        }
                    }
                }
                
                if ($valid_quarters > 1) {
                    if ($depEdActive) {
                        $average_equivalent = number_format($total_transmuted / $valid_quarters, 1);
                        $average_grade = floatval($average_equivalent);
                    } else {
                        $average_initial = $total_initial / $valid_quarters;
                        $average_equivalent = get_equivalent_from_transmutation($average_initial);
                        $average_grade = $average_initial;
                    }
                } elseif ($valid_quarters === 1) {
                    // Single quarter with grades carries the average (mirrors
                    // report_of_grades) instead of leaving remarks N/A.
                    $average_equivalent = $equivalent;
                    $average_grade = $grade_value;
                }
                
                // Determine remarks
                $remarks = 'N/A';
                if ($average_equivalent !== null) {
                    $remarks = remarks_for_grade($average_equivalent, $depEdActive ? 'DEPED' : 'CHED');
                }
                
                // Check if already published
                $existing_query = $conn->query("SELECT id FROM published_grades 
                    WHERE class_id = '{$class_id}' 
                    AND student_id = '{$student_id}' 
                    AND quarter = '{$q_num}'");
                
                $existing = $existing_query ? $existing_query->fetchArray() : null;
                
                if ($existing) {
                    // Update existing record
                    $update_query = "UPDATE published_grades SET 
                        initial_grade = '{$grade_value}',
                        equivalent_grade = '{$equivalent}',
                        average_grade = " . ($average_grade ? "'{$average_grade}'" : 'NULL') . ",
                        average_equivalent = " . ($average_equivalent ? "'{$average_equivalent}'" : 'NULL') . ",
                        remarks = " . ($remarks ? "'{$remarks}'" : 'NULL') . ",
                        published_by = '{$published_by}',
                        published_date = CURRENT_TIMESTAMP,
                        academic_year = '{$class_info['year']}',
                        semester = '{$class_info['semester']}'
                        WHERE id = '{$existing['id']}'";
                    
                    $conn->exec($update_query);
                    $published_count++;
                } else {
                    // Insert new record
                    $insert_query = "INSERT INTO published_grades 
                        (class_id, student_id, quarter, initial_grade, equivalent_grade, 
                         average_grade, average_equivalent, remarks, published_by, 
                         academic_year, semester) VALUES 
                        ('{$class_id}', '{$student_id}', '{$q_num}', '{$grade_value}', 
                         '{$equivalent}', " . ($average_grade ? "'{$average_grade}'" : 'NULL') . ", 
                         " . ($average_equivalent ? "'{$average_equivalent}'" : 'NULL') . ", 
                          " . ($remarks ? "'{$remarks}'" : 'NULL') . ", 
                          '{$published_by}', '{$class_info['year']}', '{$class_info['semester']}')";
                    
                    $conn->exec($insert_query);
                    $published_count++;
                }
            }
        }
    }
    
    // Commit transaction
    $conn->exec("COMMIT");

    return ['published' => $published_count, 'class_info' => $class_info, 'empty' => false];

} catch (Exception $e) {
    // Rollback transaction
    @$conn->exec("ROLLBACK");
    throw $e;
}
} // end publish_class_grades

// Shared clean-JSON responder
function publish_send_json($payload) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

try {
    if ($publish_all) {
        // Publish every class; each class commits independently so one failure
        // never wipes out the rest. Failures are collected and reported.
        $ids = [];
        $id_qry = $conn->query("SELECT class_id FROM class_list ORDER BY class_id ASC");
        if ($id_qry) {
            while ($id_row = $id_qry->fetchArray(SQLITE3_ASSOC)) {
                $ids[] = (int)$id_row['class_id'];
            }
        }
        if (empty($ids)) {
            publish_send_json(['success' => false, 'message' => 'No classes found to publish.']);
        }
        $total = 0;
        $done = 0;
        $failed = [];
        $details = [];
        foreach ($ids as $cid) {
            try {
                $res = publish_class_grades($conn, $cid, $published_by);
                $total += $res['published'];
                $done++;
                $ci = $res['class_info'];
                $details[] = [
                    'class_id' => $cid,
                    'label' => ($ci['course_no'] ?? '') . ' - ' . ($ci['grade'] ?? '') . '-' . ($ci['section'] ?? '') . ' (' . ($ci['subject_name'] ?? '') . ')',
                    'published' => $res['published'],
                    'empty' => $res['empty'],
                ];
            } catch (Exception $e) {
                $failed[] = ['class_id' => $cid, 'error' => $e->getMessage()];
            }
        }
        $msg = "Publish All complete: {$total} grade record(s) across {$done} class(es).";
        if (!empty($failed)) {
            $msg .= " " . count($failed) . " class(es) failed.";
        }
        publish_send_json([
            'success' => empty($failed) || $total > 0,
            'message' => $msg,
            'published_count' => $total,
            'classes_published' => $done,
            'classes_failed' => $failed,
            'details' => $details,
        ]);
    }

    // Single-class mode (unchanged behavior)
    $res = publish_class_grades($conn, $class_id, $published_by);
    if ($res['empty']) {
        publish_send_json(['success' => false, 'message' => 'No students found in this class.']);
    }
    $class_info = $res['class_info'];
    $published_count = $res['published'];
    publish_send_json([
        'success' => true,
        'message' => "Successfully published {$published_count} grade records for class: {$class_info['grade']} - {$class_info['section']} ({$class_info['subject_name']})",
        'published_count' => $published_count,
        'class_info' => $class_info
    ]);

} catch (Exception $e) {
    publish_send_json([
        'success' => false,
        'message' => 'Error publishing grades: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine()
    ]);
}
?>