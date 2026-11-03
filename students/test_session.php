<?php
// Simple session and database test
session_start();
echo "<h2>Session and Database Test</h2>";

// Check current session
echo "<h3>Current Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Test student number
$student_number = '23-09888';
echo "<h3>Testing Student: $student_number</h3>";

// Include database functions
require_once __DIR__ . '/../DBConnection.php';

// Test direct database access
try {
    $db_path = __DIR__ . '/../db/backups/Administrator/admin_2026-03-08_05-24-09.db';
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Find student
    $stmt = $conn->prepare("SELECT s.student_id, s.name, s.student_number 
                                     FROM student_list s 
                                     WHERE s.student_number = ? 
                                     LIMIT 1");
    
    $stmt->execute([$student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo "<p style='color: green;'>✓ Student found in database</p>";
        echo "<p><strong>Student ID:</strong> " . $student['student_id'] . "</p>";
        
        // Get published grades
        $grades_stmt = $conn->prepare("SELECT pg.*, 
                                             c.grade as class_grade, c.section, c.semester, c.year,
                                             (c.grade || ' - ' || c.section) as class_name,
                                             s.name as subject_name,
                                             s.subject_id
                                      FROM published_grades pg 
                                      JOIN class_list c ON pg.class_id = c.class_id 
                                      JOIN subjects s ON c.subject_id = s.subject_id 
                                      WHERE pg.student_id = ? 
                                      ORDER BY c.year DESC, c.semester, pg.quarter");
        
        $grades_stmt->execute([$student['student_id']]);
        $grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h4>Published Grades Found:</h4>";
        echo "<p><strong>Count:</strong> " . count($grades) . "</p>";
        
        if (!empty($grades)) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Quarter</th><th>Grade</th><th>Remarks</th></tr>";
            
            foreach ($grades as $grade) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($grade['quarter']) . "</td>";
                echo "<td>" . number_format($grade['equivalent_grade'], 1) . "</td>";
                echo "<td>" . htmlspecialchars($grade['remarks']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>No published grades found</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Student not found in database</p>";
    }
    
    $conn = null;
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
