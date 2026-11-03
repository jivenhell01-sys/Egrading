<?php
// Check the structure of published_grades table in the admin backup database
$db_path = __DIR__ . '/db/backups/Administrator/admin_2026-03-08_05-24-09.db';

try {
    $conn = new PDO('sqlite:' . $db_path);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Checking Published Grades Table Structure</h2>";
    echo "<h3>Database: admin_2026-03-08_05-24-09.db</h3>";
    
    // Check if published_grades table exists
    $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='published_grades'");
    if ($table_check && $table_check->fetch()) {
        echo "<p>✓ published_grades table exists</p>";
        
        // Get table structure
        $columns = $conn->query("PRAGMA table_info(published_grades)")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Table Structure:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Column</th><th>Type</th><th>Not Null</th><th>Default</th><th>PK</th></tr>";
        
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['name']}</td>";
            echo "<td>{$col['type']}</td>";
            echo "<td>" . ($col['notnull'] ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($col['dflt_value'] ?? 'NULL') . "</td>";
            echo "<td>" . ($col['pk'] ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Check if there are any records
        $count = $conn->query("SELECT COUNT(*) FROM published_grades")->fetchColumn();
        echo "<h3>Records:</h3>";
        echo "<p>Total records in published_grades: $count</p>";
        
        if ($count > 0) {
            echo "<h3>Sample Records:</h3>";
            $sample = $conn->query("SELECT * FROM published_grades LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre>" . print_r($sample, true) . "</pre>";
            
            // Check for student 23-09888 specifically
            echo "<h3>Searching for Student 23-09888:</h3>";
            
            // First get student ID
            $student_query = $conn->prepare("SELECT student_id FROM student_list WHERE student_number = ?");
            $student_query->execute(['23-09888']);
            $student = $student_query->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                echo "<p>Student ID found: " . $student['student_id'] . "</p>";
                
                // Search for published grades without the published column
                $grades_query = $conn->prepare("SELECT pg.*, 
                                                     c.grade as class_grade, c.section, c.semester, c.year,
                                                     (c.grade || ' - ' || c.section) as class_name,
                                                     s.name as subject_name,
                                                     s.subject_id
                                              FROM published_grades pg 
                                              JOIN class_list c ON pg.class_id = c.class_id 
                                              JOIN subjects s ON c.subject_id = s.subject_id 
                                              WHERE pg.student_id = ?
                                              ORDER BY c.year DESC, c.semester");
                
                $grades_query->execute([$student['student_id']]);
                $grade_records = $grades_query->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($grade_records)) {
                    echo "<p style='color: green; font-weight: bold;'>✓ Found " . count($grade_records) . " published grade records!</p>";
                    
                    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
                    echo "<tr style='background-color: #f0f0f0;'>";
                    echo "<th>Subject</th><th>Class</th><th>Year</th><th>Semester</th><th>Quarter</th><th>Grade</th><th>Remarks</th>";
                    echo "</tr>";
                    
                    foreach ($grade_records as $grade) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($grade['subject_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($grade['class_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($grade['year']) . "</td>";
                        echo "<td>" . htmlspecialchars($grade['semester']) . "</td>";
                        echo "<td>" . htmlspecialchars($grade['quarter'] ?? 'N/A') . "</td>";
                        echo "<td style='text-align: center; font-weight: bold;'>" . number_format($grade['transmuted_grade'], 1) . "</td>";
                        echo "<td>" . htmlspecialchars($grade['remarks'] ?? 'N/A') . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<p style='color: orange;'>✗ No published grades found for this student</p>";
                }
            } else {
                echo "<p style='color: red;'>✗ Student not found</p>";
            }
        }
        
    } else {
        echo "<p style='color: red;'>✗ published_grades table does not exist</p>";
    }
    
    $conn = null;
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
