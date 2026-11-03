<?php
// Set content type to JSON first thing
header('Content-Type: application/json');

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

require_once('DBConnection.php');

// Initialize response array
$response = ['status' => 'error', 'message' => 'Invalid request'];

try {
    // Check if required parameters are set
    if (!isset($_POST['student_id']) || !isset($_POST['class_id']) || !isset($_POST['date']) || !isset($_POST['status']) || !isset($_POST['quarter'])) {
        throw new Exception('Missing required parameters');
    }

    $studentId = intval($_POST['student_id']);
    $classId = intval($_POST['class_id']);
    $date = $_POST['date'];
    $status = $_POST['status'];
    $quarter = intval($_POST['quarter']);
    $oldStatus = $_POST['old_status'] ?? null;

    // Validate date format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    // Create database connection
    $db = new DBConnection();

    // Get the current date in the same format as the date column in the database
    $currentDate = date('Y-m-d');

    // Check if the attendance date matches the current date
    if ($date !== $currentDate) {
        $response = [
            'status' => 'success', 
            'message' => 'No mark adjustment needed for past/future dates',
            'show_alert' => true,
            'alert_type' => 'info'
        ];
        echo json_encode($response);
        exit;
    }

    // Get the component_id for 'Attendance'
    $attendanceComponentId = null;
    $componentResult = $db->query("SELECT component_id FROM grading_components WHERE LOWER(name) = 'attendance'");
    
    if ($componentResult && $componentRow = $componentResult->fetchArray(SQLITE3_ASSOC)) {
        $attendanceComponentId = $componentRow['component_id'];
    } else {
        throw new Exception('Attendance component not found in grading components');
    }

    // Use the provided quarter parameter
    // Get all assessments for the class that are marked as 'Attendance' component and match the quarter
    $assessments = [];
    $stmt = $db->prepare("SELECT * FROM assessment_list 
                         WHERE class_id = :class_id 
                         AND component_id = :component_id
                         AND quarter = :quarter");
    $stmt->bindValue(':class_id', $classId, SQLITE3_INTEGER);
    $stmt->bindValue(':component_id', $attendanceComponentId, SQLITE3_INTEGER);
    $stmt->bindValue(':quarter', $quarter, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    
    if (!$result) {
        throw new Exception('Failed to retrieve assessments for class ' . $classId);
    }
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $assessments[] = $row;
    }
    
    foreach ($assessments as $assessment) {
        $assessmentId = $assessment['assessment_id'];

        // Get the current mark for this student and assessment
        $markQuery = $db->query("SELECT * FROM mark_list 
                                WHERE student_id = '{$studentId}' 
                                AND assessment_id = '{$assessmentId}'");
        
        $currentMark = 0;
        $markExists = false;
        
        if ($markQuery && $markRow = $markQuery->fetchArray(SQLITE3_ASSOC)) {
            $currentMark = floatval($markRow['mark']);
            $markExists = true;
        }

        $newMark = $currentMark;
        $shouldUpdate = false;

        // Only proceed if mark is >= 1 or we're setting a positive status
        if ($currentMark >= 1 || in_array($status, ['present', 'excused'])) {
            // Calculate mark adjustment based on status change
            if (in_array($status, ['present', 'excused']) && in_array($oldStatus, ['absent', 'late', null])) {
                // Increment mark by 1 for present/excused status
                $newMark = $currentMark + 1;
                $shouldUpdate = true;
            } elseif ($date === $currentDate && $oldStatus === 'present' && !in_array($status, ['present', 'excused'])) {
                // Decrement mark by 1 only for current date if old status was present
                $newMark = max(0, $currentMark - 1);
                $shouldUpdate = true;
            }

            // Update or insert the mark
            if ($shouldUpdate) {
                if ($markExists) {
                    $stmt = $db->prepare("UPDATE mark_list SET mark = :mark 
                                         WHERE student_id = :student_id 
                                         AND assessment_id = :assessment_id");
                } else {
                    $stmt = $db->prepare("INSERT INTO mark_list (student_id, assessment_id, mark) 
                                         VALUES (:student_id, :assessment_id, :mark)");
                }
                
                $stmt->bindValue(':student_id', $studentId, SQLITE3_INTEGER);
                $stmt->bindValue(':assessment_id', $assessmentId, SQLITE3_INTEGER);
                $stmt->bindValue(':mark', $newMark, SQLITE3_FLOAT);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update mark for assessment ' . $assessmentId);
                }
            }
        }
    }

    $response = ['status' => 'success', 'message' => 'Marks updated successfully'];

} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

// Ensure we only output JSON
echo json_encode($response);

?>
