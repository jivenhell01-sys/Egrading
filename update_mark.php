<?php
// Start session and include necessary files
session_start();
require_once('DBConnection.php');

// Enable error reporting for debugging
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

// Initialize response
$response = ['status' => 'error', 'message' => ''];

try {
    // Get POST data
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : null;
    $assessment_id = isset($_POST['assessment_id']) ? intval($_POST['assessment_id']) : null;
    $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : null;
    $quarter = isset($_POST['quarter']) ? intval($_POST['quarter']) : null;
    // An empty/blank mark means "not yet graded" - it is valid and must not be
    // coerced to 0. isset() is false for null but true for '', so check both.
    $mark_raw = isset($_POST['mark']) ? trim((string)$_POST['mark']) : null;
    $is_blank_mark = ($mark_raw === null || $mark_raw === '');
    $mark = $is_blank_mark ? null : floatval($mark_raw);

    // Log the received data
    error_log("Received mark update request: " . print_r($_POST, true));

    // Validate required fields (mark may be blank, but must be present-or-blank
    // rather than absent entirely)
    if ($student_id === null || $assessment_id === null || $mark_raw === null) {
        $missing = [];
        if ($student_id === null) $missing[] = 'student_id';
        if ($assessment_id === null) $missing[] = 'assessment_id';
        if ($mark_raw === null) $missing[] = 'mark';

        throw new Exception('Missing required parameters: ' . implode(', ', $missing));
    }

    // Initialize database connection
    $conn = new DBConnection();
    if (!$conn) {
        throw new Exception('Failed to connect to database');
    }

    // Check if the mark already exists
    $stmt = $conn->prepare("SELECT * FROM `mark_list` WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
    if (!$stmt) {
        throw new Exception('Failed to prepare select statement: ' . $conn->lastErrorMsg());
    }
    
    $stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
    $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    if ($result === false) {
        throw new Exception('Failed to execute select statement: ' . $conn->lastErrorMsg());
    }
    
    $check = $result->fetchArray(SQLITE3_ASSOC);

    if ($is_blank_mark) {
        // Blank clears the score: delete the row so the cell renders empty
        // again. An absent row is what "not yet graded" means everywhere else.
        if ($check) {
            $stmt = $conn->prepare("DELETE FROM `mark_list` WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
            if (!$stmt) {
                throw new Exception('Failed to prepare delete statement: ' . $conn->lastErrorMsg());
            }
            $stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
            $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);
            if ($stmt->execute() === false) {
                throw new Exception('Failed to clear mark: ' . $conn->lastErrorMsg());
            }
            $response = ['status' => 'success', 'message' => 'Score cleared'];
        } else {
            $response = ['status' => 'success', 'message' => 'Score already blank'];
        }
    } elseif ($check) {
        // Update existing mark
        $stmt = $conn->prepare("UPDATE `mark_list` SET `mark` = :mark, `updated_at` = CURRENT_TIMESTAMP WHERE `student_id` = :student_id AND `assessment_id` = :assessment_id");
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement: ' . $conn->lastErrorMsg());
        }
        $stmt->bindValue(':mark', $mark, SQLITE3_FLOAT);
        $stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
        $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if ($result === false) {
            throw new Exception('Failed to execute update: ' . $conn->lastErrorMsg());
        }
        
        $response = ['status' => 'success', 'message' => 'Mark updated successfully'];
    } else {
        // Insert new mark
        $stmt = $conn->prepare("INSERT INTO `mark_list` (`student_id`, `assessment_id`, `mark`) VALUES (:student_id, :assessment_id, :mark)");
        if (!$stmt) {
            throw new Exception('Failed to prepare insert statement: ' . $conn->lastErrorMsg());
        }
        
        $stmt->bindValue(':student_id', $student_id, SQLITE3_INTEGER);
        $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);
        $stmt->bindValue(':mark', $mark, SQLITE3_FLOAT);
        
        $result = $stmt->execute();
        if ($result === false) {
            throw new Exception('Failed to execute insert: ' . $conn->lastErrorMsg());
        }
        
        $response = ['status' => 'success', 'message' => 'Mark added successfully'];
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('Error in update_mark.php: ' . $e->getMessage());
    error_log('Backtrace: ' . print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true));
}

// Log the response for debugging
error_log("Sending response: " . json_encode($response));

// Return JSON response
echo json_encode($response);
exit;