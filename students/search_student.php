<?php
// Search for student number 23-09888 across all databases
$target_student = '23-09888';
echo "<h2>Searching for Student Number: $target_student</h2>";

// Function to search a database for the student
function searchDatabaseForStudent($db_file, $student_number) {
    $results = [];
    
    try {
        $conn = new PDO('sqlite:' . $db_file);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if student_list table exists
        $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='student_list'");
        if ($table_check && $table_check->fetch()) {
            $stmt = $conn->prepare("SELECT s.*, c.grade as class_grade, c.section, 
                                         sub.name as subject_name, sub.subject_id,
                                         (c.grade || ' - ' || c.section) as class_name
                                  FROM student_list s 
                                  LEFT JOIN class_list c ON s.class_id = c.class_id 
                                  LEFT JOIN subjects sub ON c.subject_id = sub.subject_id 
                                  WHERE s.student_number = ? 
                                  LIMIT 5");
            
            $stmt->execute([$student_number]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($students)) {
                $results = [
                    'database' => basename($db_file),
                    'students' => $students,
                    'found' => true
                ];
            }
        }
        
        $conn = null;
    } catch (Exception $e) {
        $results = [
            'database' => basename($db_file),
            'error' => $e->getMessage(),
            'found' => false
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
$total_searched = 0;

foreach ($databases_to_search as $db_file) {
    if (file_exists($db_file)) {
        $total_searched++;
        $result = searchDatabaseForStudent($db_file, $target_student);
        
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<h4>Database: " . (isset($result['database']) ? $result['database'] : basename($db_file)) . "</h4>";
        
        if (isset($result['found']) && $result['found']) {
            $found_count++;
            echo "<p style='color: green; font-weight: bold;'>✓ STUDENT FOUND!</p>";
            
            foreach ($result['students'] as $student) {
                echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr><td><strong>Student ID:</strong></td><td>" . htmlspecialchars($student['student_id']) . "</td></tr>";
                echo "<tr><td><strong>Name:</strong></td><td>" . htmlspecialchars($student['name']) . "</td></tr>";
                echo "<tr><td><strong>Student Number:</strong></td><td>" . htmlspecialchars($student['student_number']) . "</td></tr>";
                echo "<tr><td><strong>Class:</strong></td><td>" . htmlspecialchars($student['class_name'] ?? 'N/A') . "</td></tr>";
                echo "<tr><td><strong>Subject:</strong></td><td>" . htmlspecialchars($student['subject_name'] ?? 'N/A') . "</td></tr>";
                echo "<tr><td><strong>Grade:</strong></td><td>" . htmlspecialchars($student['class_grade'] ?? 'N/A') . "</td></tr>";
                echo "<tr><td><strong>Section:</strong></td><td>" . htmlspecialchars($student['section'] ?? 'N/A') . "</td></tr>";
                echo "</table><br>";
            }
        } else {
            if (isset($result['error'])) {
                echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($result['error']) . "</p>";
            } else {
                echo "<p style='color: orange;'>✗ Student not found</p>";
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
echo "<p><strong>Databases where student was found:</strong> $found_count</p>";

if ($found_count === 0) {
    echo "<p style='color: red; font-weight: bold;'>Student number $target_student was NOT found in any database.</p>";
} else {
    echo "<p style='color: green; font-weight: bold;'>Student number $target_student was found in $found_count database(s).</p>";
}
?>
