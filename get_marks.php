<?php
session_start();
require_once('DBConnection.php');

$db = new DBConnection();
$response = ['status' => 'error', 'marks' => []];

try {
    if (!isset($_GET['class_id']) || !isset($_GET['assessment_id'])) {
        throw new Exception('Missing required parameters');
    }

    $class_id = $db->escapeString($_GET['class_id']);
    $assessment_id = $db->escapeString($_GET['assessment_id']);
    
    $marks = [];
    $query = $db->query("SELECT * FROM mark_list WHERE assessment_id = '{$assessment_id}'");
    
    if ($query) {
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $marks[] = [
                'student_id' => $row['student_id'],
                'mark' => $row['mark']
            ];
        }
    } else {
        throw new Exception('Failed to fetch marks: ' . $db->lastErrorMsg());
    }
    
    $response = [
        'status' => 'success',
        'marks' => $marks
    ];
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>
