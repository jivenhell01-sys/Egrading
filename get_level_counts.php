<?php
// Returns enrolled student counts per section for current DB (not whole level)
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();
try {
    require_once("DBConnection.php");
    $conn = new DBConnection();
    $counts = [];
    $q = $conn->query("SELECT c.grade AS grade, c.section AS section, COUNT(sl.student_id) AS cnt FROM student_list sl JOIN class_list c ON sl.class_id = c.class_id GROUP BY c.grade, c.section");
    if ($q) { while ($r = $q->fetchArray(SQLITE3_ASSOC)) { $k = trim((string)($r['grade'] ?? '')) . '|' . trim((string)($r['section'] ?? '')); $counts[$k] = (int)$r['cnt']; } }
    echo json_encode(['status' => 'success', 'counts' => $counts]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'counts' => []]);
}
?>
