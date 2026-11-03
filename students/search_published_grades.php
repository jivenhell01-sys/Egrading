<?php
// Search for published grades for student number 23-09888
$target_student = '23-09888';
echo "<h2>Searching Published Grades for Student: $target_student</h2>";

// Function to search for published grades in a database
function searchPublishedGrades($db_file, $student_number) {
    $results = [];
    
    try {
        $conn = new PDO('sqlite:' . $db_file);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if required tables exist
        $tables_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('student_list', 'published_grades')");
        if ($tables_check) {
            $tables = $tables_check->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) >= 2) {
                // First find the student
                $student_query = $conn->prepare("SELECT s.student_id, s.name, s.student_number 
                                                 FROM student_list s 
                                                 WHERE s.student_number = ? 
                                                 LIMIT 1");
                
                $student_query->execute([$student_number]);
                $student = $student_query->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    // Now search for published grades for this student
                    $grades_query = $conn->prepare("SELECT pg.*, 
                                                         c.grade as class_grade, c.section, c.semester, c.year,
                                                         (c.grade || ' - ' || c.section) as class_name,
                                                         s.name as subject_name,
                                                         s.subject_id
                                                  FROM published_grades pg 
                                                  JOIN class_list c ON pg.class_id = c.class_id 
                                                  JOIN subjects s ON c.subject_id = s.subject_id 
                                                  WHERE pg.student_id = ? AND pg.published = 1
                                                  ORDER BY c.year DESC, c.semester, pg.quarter");
                    
                    $grades_query->execute([$student['student_id']]);
                    $grade_records = $grades_query->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($grade_records)) {
                        $results = [
                            'database' => basename($db_file),
                            'student' => $student,
                            'grades' => $grade_records,
                            'found' => true,
                            'grade_count' => count($grade_records)
                        ];
                    } else {
                        $results = [
                            'database' => basename($db_file),
                            'student' => $student,
                            'found' => false,
                            'message' => 'Student found but no published grades'
                        ];
                    }
                } else {
                    $results = [
                        'database' => basename($db_file),
                        'found' => false,
                        'message' => 'Student not found in this database'
                    ];
                }
            } else {
                $results = [
                    'database' => basename($db_file),
                    'found' => false,
                    'message' => 'Required tables not found'
                ];
            }
        }
        
        $conn = null;
    } catch (Exception $e) {
        $results = [
            'database' => basename($db_file),
            'found' => false,
            'error' => $e->getMessage()
        ];
    }
    
    return $results;
}

// List all databases to search
$databases_to_search = [
    __DIR__ . '/db/grading_db.db',
    __DIR__ . '/db/alex/alex_2026-2027.db'
];

// Add faculty databases
$faculty_dbs = glob(__DIR__ . '/db/*.db');
foreach ($faculty_dbs as $db_file) {
    if (basename($db_file) !== 'grading_db.db' && !str_contains($db_file, 'alex')) {
        $databases_to_search[] = $db_file;
    }
}

// Add backup databases
$backup_paths = [
    __DIR__ . '/db/backups/Administrator/',
    __DIR__ . '/db/backups/faculty_backups/'
];

foreach ($backup_paths as $backup_path) {
    if (is_dir($backup_path)) {
        $backup_dbs = glob($backup_path . '*.db');
        $databases_to_search = array_merge($databases_to_search, $backup_dbs);
    }
}

echo "<h3>Databases to search: " . count($databases_to_search) . "</h3>";

$found_count = 0;
$total_grades = 0;
$total_searched = 0;

foreach ($databases_to_search as $db_file) {
    if (file_exists($db_file)) {
        $total_searched++;
        $result = searchPublishedGrades($db_file, $target_student);
        
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<h4>Database: " . (isset($result['database']) ? $result['database'] : basename($db_file)) . "</h4>";
        
        if (isset($result['found']) && $result['found']) {
            $found_count++;
            $total_grades += $result['grade_count'];
            
            echo "<p style='color: green; font-weight: bold;'>✓ PUBLISHED GRADES FOUND!</p>";
            echo "<p><strong>Student:</strong> " . htmlspecialchars($result['student']['name']) . " (" . htmlspecialchars($result['student']['student_number']) . ")</p>";
            echo "<p><strong>Published grades found:</strong> " . $result['grade_count'] . "</p>";
            
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
            echo "<tr style='background-color: #f0f0f0;'>";
            echo "<th>Subject</th><th>Class</th><th>Year</th><th>Semester</th><th>Quarter</th><th>Grade</th><th>Remarks</th><th>Date Published</th>";
            echo "</tr>";
            
            foreach ($result['grades'] as $grade) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($grade['subject_name']) . "</td>";
                echo "<td>" . htmlspecialchars($grade['class_name']) . "</td>";
                echo "<td>" . htmlspecialchars($grade['year']) . "</td>";
                echo "<td>" . htmlspecialchars($grade['semester']) . "</td>";
                echo "<td>" . htmlspecialchars($grade['quarter'] ?? 'N/A') . "</td>";
                echo "<td style='text-align: center; font-weight: bold;'>" . number_format($grade['transmuted_grade'], 1) . "</td>";
                echo "<td>" . htmlspecialchars($grade['remarks'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($grade['date_published'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            if (isset($result['error'])) {
                echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($result['error']) . "</p>";
            } else {
                echo "<p style='color: orange;'>✗ " . htmlspecialchars($result['message'] ?? 'No published grades found') . "</p>";
            }
        }
        
        echo "</div>";
    } else {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<h4>Database: " . basename($db_file) . "</h4>";
        echo "<p style='color: red;'>✗ File not found</p>";
        echo "</div>";
    }
}

echo "<h3>Summary:</h3>";
echo "<p><strong>Total databases searched:</strong> $total_searched</p>";
echo "<p><strong>Databases with published grades:</strong> $found_count</p>";
echo "<p><strong>Total published grades found:</strong> $total_grades</p>";

if ($found_count === 0) {
    echo "<p style='color: red; font-weight: bold;'>No published grades found for student $target_student in any database.</p>";
} else {
    echo "<p style='color: green; font-weight: bold;'>Found $total_grades published grade(s) for student $target_student across $found_count database(s).</p>";
}
?>
