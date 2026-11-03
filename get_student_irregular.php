<?php
// Students page: per-student irregular detail (called with ?student_number=).
// Reuses the same class/subject list + passed logic as
// get_landing_subject_status.php, scoped to the logged-in user's database.
while (ob_get_level()) @ob_end_clean();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
require_once("landing_db.php");
require_once("landing_enrollments.php");
require_once("landing_irregular.php");

$sn = trim((string)($_GET['student_number'] ?? $_POST['student_number'] ?? ''));
if ($sn === '') {
    echo json_encode(['status' => 'error', 'message' => 'Student number is required']);
    exit;
}

try {
    $ldb = landing_db();
    $row = $ldb->query("SELECT * FROM landing_registrations WHERE student_number='" . $ldb->escapeString($sn) . "'")->fetchArray(SQLITE3_ASSOC);
    $isIrr = $row && landing_row_is_irregular($row);
    $entries = $isIrr ? irregular_entries_of($row) : [];

    // Live user databases only (main + faculty) — same filter as
    // get_landing_subject_status.php (the assignable offerings).
    $live = [];
    if (function_exists('landing_user_databases')) {
        foreach (landing_user_databases() as $d) {
            $p = str_replace('\\', '/', (string)($d['path'] ?? ''));
            if ($p === '') continue;
            if (strpos($p, '/db/backups/') !== false) continue;
            if (strpos($p, '/faculty_backups/') !== false) continue;
            if (strpos($p, '/previous_database/') !== false) continue;
            if (basename($p) === 'grading_dbold.db') continue;
            if (basename($p) === 'landing_db.db') continue;
            if (!is_file($d['path'])) continue;
            $live[] = $d;
        }
    }

    $subjects = [];
    $seen = [];
    if ($isIrr) {
        foreach ($live as $d) {
            try {
                $fdb = new SQLite3($d['path']);
                $fdb->enableExceptions(true);
                $fdb->exec("PRAGMA busy_timeout = 5000");
                $hasClass = $fdb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='class_list'");
                $hasSubj = $fdb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='subjects'");
                if (!$hasClass || !$hasSubj) { $fdb->close(); continue; }
                $enrolledIds = [];
                $er = $fdb->query("SELECT class_id FROM student_list WHERE student_number='" . $fdb->escapeString($sn) . "'");
                if ($er) { while ($erow = $er->fetchArray(SQLITE3_ASSOC)) $enrolledIds[(int)$erow['class_id']] = true; }
                $cr = $fdb->query("SELECT c.class_id, c.grade, c.section, c.course_no, s.name AS subject_name, c.semester, c.year FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id");
                if ($cr) {
                    while ($c = $cr->fetchArray(SQLITE3_ASSOC)) {
                        $cCode = strtolower(trim($c['course_no'] ?? ''));
                        $cName = strtolower(trim($c['subject_name'] ?? ''));
                        $cSec = strtolower(trim($c['section'] ?? ''));
                        if (($cCode === '' && $cName === '') || $cSec === '') continue;
                        foreach ($entries as $e) {
                            if (!irregular_entry_matches_class($e, $cSec, $cCode, $cName)) continue;
                            $key = $d['path'] . '|' . (int)$c['class_id'];
                            if (isset($seen[$key])) break;
                            $code = trim($c['course_no'] ?? '');
                            if ($code === '') $code = trim($c['subject_name'] ?? '');
                            $state = 'pending';
                            if (isset($enrolledIds[(int)$c['class_id']])) $state = 'enrolled';
                            elseif (landing_has_passed_anywhere($sn, $code)) $state = 'passed';
                            $seen[$key] = true;
                            $subjects[] = [
                                'code' => trim($c['course_no'] ?? ''),
                                'name' => trim($c['subject_name'] ?? ''),
                                'section' => trim($c['section'] ?? ''),
                                'program' => trim($c['grade'] ?? ''),
                                'semester' => trim($c['semester'] ?? ''),
                                'acad_year' => trim($c['year'] ?? ''),
                                'faculty' => $d['faculty'] ?? $d['label'] ?? '',
                                'state' => $state,
                            ];
                            break;
                        }
                    }
                }
                $fdb->close();
            } catch (Exception $e) { continue; }
        }
        usort($subjects, function($a, $b) {
            $c = strcasecmp($a['section'], $b['section']);
            if ($c !== 0) return $c;
            return strcasecmp($a['code'], $b['code']);
        });
    }

    echo json_encode([
        'status' => 'success',
        'student_number' => $sn,
        'is_irregular' => $isIrr ? 1 : 0,
        'entries' => $entries,
        'subjects' => $subjects,
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
