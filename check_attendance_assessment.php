<?php
require_once('DBConnection.php');

header('Content-Type: application/json');

$response = [
    'status' => 'error',
    'is_attendance' => false,
    'message' => 'Invalid request'
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!isset($_POST['assessment_id']) || empty($_POST['assessment_id'])) {
        throw new Exception('Assessment ID is required');
    }

    $assessment_id = $_POST['assessment_id'];
    
    // Connect to the database
    $conn = new DBConnection();
    
    // Check if this is an attendance assessment
    $query = "SELECT a.*, gc.name as component_name 
              FROM assessment_list a 
              INNER JOIN grading_components gc ON a.component_id = gc.component_id 
              WHERE a.assessment_id = :assessment_id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':assessment_id', $assessment_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $is_attendance = (stripos($row['component_name'], 'attendance') !== false);
        
        $response = [
            'status' => 'success',
            'is_attendance' => $is_attendance,
            'message' => 'Successfully checked assessment type'
        ];
    } else {
        throw new Exception('Assessment not found');
    }
    
} catch (Exception $e) {
    $response = [
        'status' => 'error',
        'is_attendance' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
?>
