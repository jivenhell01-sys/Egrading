<?php
require_once('DBConnection.php');
require_once('classes/PredictionService.php');

header('Content-Type: application/json');

// Check if user is logged in
if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0){
    // User is logged in, continue
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$predictionService = new PredictionService();
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($action) {
        case 'predict_class':
            $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
            if ($classId <= 0) {
                throw new Exception('Class ID is required');
            }
            
            $predictions = $predictionService->predictStudentGrade($classId);
            echo json_encode([
                'success' => true,
                'data' => $predictions
            ]);
            break;
            
        case 'class_stats':
            $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
            if ($classId <= 0) {
                throw new Exception('Class ID is required');
            }
            
            $stats = $predictionService->getClassPredictionStats($classId);
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        case 'compare_students':
            $classId = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
            $studentIds = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
            
            if ($classId <= 0 || empty($studentIds)) {
                throw new Exception('Class ID and student IDs are required');
            }
            
            $comparison = $predictionService->compareStudents($classId, $studentIds);
            echo json_encode([
                'success' => true,
                'data' => $comparison
            ]);
            break;
            
        case 'predict_student':
            $classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
            $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
            
            if ($classId <= 0 || $studentId <= 0) {
                throw new Exception('Class ID and Student ID are required');
            }
            
            $predictions = $predictionService->predictStudentGrade($classId, $studentId);
            echo json_encode([
                'success' => true,
                'data' => $predictions
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
