<?php
// Admin endpoint for the Landing Registrations page: clicking a student's
// Status badge shows every subject corresponding to their section (regular
// block subjects across live user DBs) plus their picked irregular subjects,
// each marked enrolled / pending (waiting to be assigned) / passed.
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

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Registration id is required']);
    exit;
}

try {
    $db = landing_db();
    $row = $db->query("SELECT * FROM landing_registrations WHERE id='{$id}'")->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Registration not found']);
        exit;
    }
    $num = trim((string)($row['student_number'] ?? ''));
    $secLower = strtolower(trim($row['section'] ?? ''));
    $courseLower = strtolower(trim($row['course'] ?? ''));
    $isIrr = landing_row_is_irregular($row);
    $entries = $isIrr ? irregular_entries_of($row) : [];

    // Live user databases only (main + faculty) — skipped: backups, previous,
    // old and landing DBs. These are the assignable class offerings.
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
    foreach ($live as $d) {
        try {
            $fdb = new SQLite3($d['path']);
            $fdb->enableExceptions(true);
            $fdb->exec("PRAGMA busy_timeout = 5000");
            $hasClass = $fdb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='class_list'");
            $hasSubj = $fdb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='subjects'");
            if (!$hasClass || !$hasSubj) { $fdb->close(); continue; }
            $enrolledIds = [];
            if ($num !== '') {
                $escNum = $fdb->escapeString($num);
                $er = $fdb->query("SELECT class_id FROM student_list WHERE student_number='{$escNum}'");
                if ($er) { while ($erow = $er->fetchArray(SQLITE3_ASSOC)) $enrolledIds[(int)$erow['class_id']] = true; }
            }
            $cr = $fdb->query("SELECT c.class_id, c.grade, c.section, c.course_no, s.name AS subject_name, c.semester, c.year FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id");
            if ($cr) {
                while ($c = $cr->fetchArray(SQLITE3_ASSOC)) {
                    $cSec = strtolower(trim($c['section'] ?? ''));
                    $cGrade = strtolower(trim($c['grade'] ?? ''));
                    $cCode = strtolower(trim($c['course_no'] ?? ''));
                    $cName = strtolower(trim($c['subject_name'] ?? ''));
                    if (($cCode === '' && $cName === '') || $cSec === '') continue;
                    $gradeOk = ($cGrade === '' || $courseLower === '' || $cGrade === $courseLower);
                    $isRegular = ($secLower !== '' && $cSec === $secLower && $gradeOk);
                    $irrEntry = null;
                    if ($isIrr && $gradeOk) {
                        $irrEntry = irregular_match_for_class($row, $cSec, $cCode, $cName);
                    }
                    if (!$isRegular && !$irrEntry) continue;
                    $key = $d['path'] . '|' . (int)$c['class_id'];
                    if (isset($seen[$key])) continue;
                    $code = trim($c['course_no'] ?? '');
                    if ($code === '') $code = trim($c['subject_name'] ?? '');
                    $state = 'pending';
                    if (isset($enrolledIds[(int)$c['class_id']])) $state = 'enrolled';
                    elseif ($num !== '' && $code !== '' && landing_has_passed_anywhere($num, $code)) $state = 'passed';
                    $seen[$key] = count($subjects);
                    $src = $isRegular ? 'Regular' : '';
                    if ($irrEntry) $src .= ($src === '' ? '' : ' + ') . 'Irregular';
                    $subjects[] = [
                        'code' => trim($c['course_no'] ?? ''),
                        'name' => trim($c['subject_name'] ?? ''),
                        'section' => trim($c['section'] ?? ''),
                        'program' => trim($c['grade'] ?? ''),
                        'semester' => trim($c['semester'] ?? ''),
                        'acad_year' => trim($c['year'] ?? ''),
                        'faculty' => $d['faculty'] ?? $d['label'] ?? '',
                        'source' => $src,
                        'state' => $state,
                    ];
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
    $counts = ['total' => count($subjects), 'enrolled' => 0, 'pending' => 0, 'passed' => 0];
    foreach ($subjects as $s) { if (isset($counts[$s['state']])) $counts[$s['state']]++; }

    echo json_encode([
        'status' => 'success',
        'student' => [
            'id' => (int)($row['id'] ?? 0),
            'student_number' => $row['student_number'] ?? '',
            'name' => $row['name'] ?? '',
            'course' => $row['course'] ?? '',
            'year' => $row['year'] ?? '',
            'section' => $row['section'] ?? '',
            'is_irregular' => $isIrr ? 1 : 0,
            'overall_status' => $row['status'] ?? 'pending',
            'irregular_entries' => $entries,
        ],
        'counts' => $counts,
        'subjects' => $subjects,
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
