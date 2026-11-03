<?php
require_once('DBRecords.php');

$conn = new DBRecords();

// Get filter parameters
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';

// Build the query
$query = "SELECT c.*, s.name as subject_name 
          FROM class_list c 
          JOIN subjects s ON c.subject_id = s.subject_id 
          WHERE 1=1";

$params = [];

if (!empty($semester)) {
    $query .= " AND c.semester = :semester";
    $params[':semester'] = $semester;
}

if (!empty($year)) {
    $query .= " AND c.year = :year";
    $params[':year'] = $year;
}

// Add ORDER BY
$query .= " ORDER BY c.year DESC, c.semester, c.grade, c.section ASC";

// Prepare and execute the query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$result = $stmt->execute();

// Fetch the results
$classes = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $classes[] = [
        'class_id' => $row['class_id'],
        'course_no' => $row['course_no'],
        'grade' => $row['grade'],
        'section' => $row['section'],
        'semester' => ucfirst($row['semester']),
        'year' => $row['year'],
        'subject_name' => $row['subject_name']
    ];
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'classes' => $classes
]);
?>
