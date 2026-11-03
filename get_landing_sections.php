<?php
// Returns distinct sections for a given year level from landing_db
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) session_start();
// Only the authenticated landing-registrations page calls this; without the
// check the section list was readable by anyone.
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
if (!isset($_GET['year']) || trim($_GET['year']) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Year is required']);
    exit;
}
$year = trim($_GET['year']);

require_once("landing_db.php");
// College Dean / Program Head only get sections of their own department.
if (!function_exists('session_course_scope')) {
    require_once("departments.php");
}
try {
    $db = landing_db();
    $where = ["year = '" . $db->escapeString($year) . "'"];
    $scopeSql = session_course_scope_sql($db);
    if ($scopeSql !== null) {
        $where[] = $scopeSql;
    }
    $res = $db->query("SELECT section, COUNT(*) as cnt FROM landing_registrations WHERE " . implode(' AND ', $where) . " AND section IS NOT NULL AND TRIM(section) != '' GROUP BY section ORDER BY section ASC");
    $sections = [];
    $counts = [];
    if ($res) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $sections[] = $row['section'];
            $counts[$row['section']] = (int)$row['cnt'];
        }
    }
    echo json_encode(['status' => 'success', 'sections' => $sections, 'counts' => $counts]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
