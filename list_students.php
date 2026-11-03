<?php
require_once('DBConnection.php');

// Query to get all students
$result = $conn->query("SELECT student_id, name, student_number FROM student_list LIMIT 10");

if ($result) {
    echo "<h2>Students in Database</h2>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>Student Number</th></tr>";
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['student_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['student_number']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "Error: " . $conn->lastErrorMsg();
}

// Check if the database file exists
$db_file = __DIR__ . '/db/grading_db.db';
echo "<p>Database file: " . (file_exists($db_file) ? 'Exists' : 'Does not exist') . "</p>";
?>
