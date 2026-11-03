<?php
require_once('DBConnection.php');
require_once('classes/PredictionService.php');

// Check if user is logged in
if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0){
    // User is logged in, continue
} else {
    header("Location: login.php");
    exit;
}

$classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if ($classId > 0) {
    $predictionService = new PredictionService();
    $predictions = $predictionService->predictStudentGrade($classId);
    $classStats = $predictionService->getClassPredictionStats($classId);
    
    // Get class info
    $conn = new DBConnection();
    $classStmt = $conn->prepare("SELECT cl.*, s.name as subject_name FROM class_list cl 
                                JOIN subjects s ON cl.subject_id = s.subject_id 
                                WHERE cl.class_id = ?");
    $classStmt->bindValue(1, $classId, SQLITE3_INTEGER);
    $classResult = $classStmt->execute();
    $classInfo = $classResult->fetchArray(SQLITE3_ASSOC);
    
    // Generate CSV content
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_predictions_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper Excel display
    fwrite($output, "\xEF\xBB\xBF");
    
    // Header
    fputcsv($output, ['Student Performance Prediction Report']);
    fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Class Information
    fputcsv($output, ['Class Information']);
    fputcsv($output, ['Subject', $classInfo['subject_name']]);
    fputcsv($output, ['Course', $classInfo['course_no']]);
    fputcsv($output, ['Section', $classInfo['section']]);
    fputcsv($output, ['Year', $classInfo['year']]);
    fputcsv($output, ['Semester', $classInfo['semester']]);
    fputcsv($output, []);
    
    // Model Information
    fputcsv($output, ['Model Information']);
    fputcsv($output, ['Algorithm', 'Linear Regression']);
    fputcsv($output, ['Formula', 'Final Grade = ' . number_format($predictions['model_info']['slope'], 3) . ' × Current Performance + ' . number_format($predictions['model_info']['intercept'], 2)]);
    fputcsv($output, ['R-squared (Accuracy)', number_format($predictions['model_info']['r2'], 3)]);
    fputcsv($output, ['Training Data Size', $predictions['model_info']['training_data_size'] . ' students']);
    fputcsv($output, []);
    
    // Class Statistics
    fputcsv($output, ['Class Statistics']);
    fputcsv($output, ['Total Students', $classStats['total_students']]);
    fputcsv($output, ['Passing Students (≥75%)', $classStats['passing_students']]);
    fputcsv($output, ['At Risk Students', $classStats['at_risk_students']]);
    fputcsv($output, ['Pass Rate', $classStats['pass_rate'] . '%']);
    fputcsv($output, ['Risk Rate', $classStats['risk_rate'] . '%']);
    fputcsv($output, ['Average Predicted Grade', $classStats['average_predicted'] . '%']);
    fputcsv($output, []);
    
    // Individual Predictions
    fputcsv($output, ['Individual Student Predictions']);
    fputcsv($output, [
        'Student ID',
        'Student Name',
        'Student Number',
        'Current Performance (%)',
        'Predicted Final Grade (%)',
        'Passing Probability (%)',
        'Will Pass',
        'At Risk Status',
        'Data Completeness (%)',
        'Model Confidence (%)'
    ]);
    
    foreach ($predictions['predictions'] as $prediction) {
        fputcsv($output, [
            $prediction['student_id'],
            $prediction['name'],
            $prediction['student_number'],
            $prediction['current_performance'],
            $prediction['predicted_grade'],
            $prediction['passing_probability'],
            $prediction['will_pass'] ? 'Yes' : 'No',
            $prediction['at_risk'] ? 'Yes' : 'No',
            $prediction['data_completeness'],
            ($prediction['model_accuracy'] * 100)
        ]);
    }
    
    fputcsv($output, []);
    
    // Detailed Suggestions for At-Risk Students
    fputcsv($output, ['Improvement Suggestions for At-Risk Students']);
    fputcsv($output, ['Student Name', 'Suggestions']);
    
    foreach ($predictions['predictions'] as $prediction) {
        if ($prediction['at_risk'] && !empty($prediction['suggestions'])) {
            foreach ($prediction['suggestions'] as $suggestion) {
                fputcsv($output, [
                    $prediction['name'],
                    $suggestion
                ]);
            }
            fputcsv($output, ['', '']); // Empty row for separation
        }
    }
    
    fclose($output);
    exit;
} else {
    // No class selected, redirect back
    header("Location: prediction_dashboard.php");
    exit;
}
?>
