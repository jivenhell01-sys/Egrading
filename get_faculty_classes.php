<?php
// Returns classes for a given faculty (from their assigned DB)
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$__glr = ($_SESSION['role'] ?? '');
$__glp = [];
if (!empty($_SESSION['allowed_pages'])) { $__d = json_decode($_SESSION['allowed_pages'], true); if (is_array($__d)) $__glp = $__d; }
if (!isset($_SESSION['admin_id']) || ($__glr !== 'admin' && !in_array('landing_registrations', $__glp, true))) {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
if ($faculty_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Faculty ID is required']);
    exit;
}

try {
    $main_db_path = __DIR__ . '/db/grading_db.db';
    $main = new SQLite3($main_db_path);
    $main->enableExceptions(true);
    $frow = $main->query("SELECT admin_id, fullname, role, db_path FROM admin_list WHERE admin_id = '{$faculty_id}' AND role = 'faculty' LIMIT 1")->fetchArray(SQLITE3_ASSOC);
    $main->close();
    if (!$frow) {
        echo json_encode(['status' => 'error', 'message' => 'Faculty not found']);
        exit;
    }
    // Resolve faculty DB path
    $faculty_db_path = null;
    $db_path = $frow['db_path'] ?? '';
    if (!empty($db_path) && file_exists($db_path)) {
        $faculty_db_path = $db_path;
    } elseif (!empty($db_path) && !preg_match('/\.db$/', $db_path)) {
        $dir = __DIR__ . '/db/' . basename($db_path);
        if (is_dir($dir)) {
            $files = glob($dir . '/*.db');
            if (!empty($files)) {
                // Current DB only - newest, exclude backups (never use oldest term)
                $files = array_filter($files, fn($f)=>stripos(basename($f),'backup')===false && stripos(basename($f),'_old')===false);
                if (!empty($files)) { usort($files, fn($a,$b)=>filemtime($b) <=> filemtime($a)); $faculty_db_path = $files[0]; }
            }
        }
    } elseif (!empty($db_path)) {
        $candidate = __DIR__ . '/db/' . basename($db_path);
        if (file_exists($candidate)) $faculty_db_path = $candidate;
    }
    if (!$faculty_db_path || !file_exists($faculty_db_path)) {
        // Fallback to main DB if faculty has no separate DB yet (e.g., newly created)
        $faculty_db_path = $main_db_path;
    }

    $fdb = new SQLite3($faculty_db_path);
    $fdb->enableExceptions(true);
    $fdb->exec("PRAGMA busy_timeout = 5000");
    $classes = [];
    $qry = $fdb->query("SELECT c.class_id, c.grade, c.section, c.semester, c.year, s.name as subject_name FROM class_list c LEFT JOIN subjects s ON c.subject_id = s.subject_id ORDER BY c.grade ASC, c.section ASC, s.name ASC");
    if ($qry) {
        while ($row = $qry->fetchArray(SQLITE3_ASSOC)) {
            $display = ($row['subject_name'] ?? 'Subject') . ' - ' . ($row['grade'] ?? '') . ' - ' . ($row['section'] ?? '') . ' (' . ($row['semester'] ?? '') . ' ' . ($row['year'] ?? '') . ')';
            $classes[] = [
                'class_id' => (int)$row['class_id'],
                'grade' => $row['grade'],
                'section' => $row['section'],
                'semester' => $row['semester'],
                'year' => $row['year'],
                'subject_name' => $row['subject_name'],
                'display_text' => trim($display)
            ];
        }
    }
    $fdb->close();
    echo json_encode(['status' => 'success', 'classes' => $classes]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
