<?php
// Import students from grading_db.student_list into landing_db.landing_registrations.
// Idempotent: existing student_numbers are skipped (UNIQUE + INSERT OR IGNORE).
// Mapping mirrors the one-off migration: all statuses, device fields copied,
// course/year/section derived from class, new rows land as 'pending'.
//   GET  ?action=count  -> {status, missing}
//   POST ?action=import -> {status, message, inserted, skipped}
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}
$role = $_SESSION['role'] ?? '';
$allowed = [];
if (!empty($_SESSION['allowed_pages'])) {
    $d = json_decode($_SESSION['allowed_pages'], true);
    if (is_array($d)) $allowed = $d;
}
if ($role !== 'admin' && !in_array('landing_registrations', $allowed, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: landing access required']);
    exit;
}

$gradingPath = __DIR__ . '/db/grading_db.db';
if (!file_exists($gradingPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Grading database not found.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== 'count' && $action !== 'import') {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}

try {
    $g = new SQLite3($gradingPath, SQLITE3_OPEN_READONLY);
    $g->enableExceptions(true);
    require_once("landing_db.php");
    $landing_conn = landing_db();

    if ($action === 'count') {
        $missing = 0;
        $r = $g->query("SELECT student_number FROM student_list");
        while ($row = $r->fetchArray(SQLITE3_NUM)) {
            $sn = trim((string)($row[0] ?? ''));
            if ($sn === '') continue;
            $exists = (int)$landing_conn->querySingle(
                "SELECT COUNT(*) FROM landing_registrations WHERE student_number = '" . $landing_conn->escapeString($sn) . "'"
            );
            if ($exists === 0) $missing++;
        }
        $g->close();
        echo json_encode(['status' => 'success', 'missing' => $missing]);
        exit;
    }

    // ---- import ----
    $q = $g->query("SELECT s.*, c.grade, c.section AS csec
                    FROM student_list s LEFT JOIN class_list c ON s.class_id = c.class_id
                    ORDER BY s.student_id");
    $rows = [];
    while ($r = $q->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    $g->close();

    $ins = $landing_conn->prepare("INSERT OR IGNORE INTO landing_registrations
      (student_number, name, middlename, firstname, lastname, name_extension,
       birthdate, age, gender, contact_number, email, barangay, municipality, province, zipcode,
       device_id, device_name, course, year, section, status, is_irregular, irregular_subjects)
      VALUES (:sn, :name, :mid, :fn, :ln, :ext,
       :bd, :age, :gen, :cp, :em, :brgy, :mun, :prov, :zip,
       :devid, :devname, :course, :yr, :sec, 'pending', 0, '[]')");

    $inserted = 0; $skipped = 0;
    $landing_conn->exec('BEGIN IMMEDIATE');
    try {
        foreach ($rows as $s) {
            $sn = trim((string)($s['student_number'] ?? ''));
            if ($sn === '') { $skipped++; continue; }
            $sec = trim((string)($s['csec'] ?? ''));
            $yr = preg_match('/^\d+/', $sec, $m) ? $m[0] : null;
            $ins->bindValue(':sn', $sn);
            $ins->bindValue(':name', $s['name']);
            $ins->bindValue(':mid', $s['middlename']);
            $ins->bindValue(':fn', $s['firstname']);
            $ins->bindValue(':ln', $s['lastname']);
            $ins->bindValue(':ext', $s['name_extension'] ?? '');
            $ins->bindValue(':bd', $s['birthdate']);
            $ins->bindValue(':age', $s['age'], is_null($s['age']) ? SQLITE3_NULL : SQLITE3_INTEGER);
            $ins->bindValue(':gen', $s['gender']);
            $ins->bindValue(':cp', $s['contact_number']);
            $ins->bindValue(':em', $s['email']);
            $ins->bindValue(':brgy', $s['barangay']);
            $ins->bindValue(':mun', $s['municipality']);
            $ins->bindValue(':prov', $s['province']);
            $ins->bindValue(':zip', $s['zipcode']);
            $ins->bindValue(':devid', $s['device_id']);
            $ins->bindValue(':devname', $s['device_name']);
            $ins->bindValue(':course', $s['grade']);
            $ins->bindValue(':yr', $yr);
            $ins->bindValue(':sec', $sec !== '' ? $sec : null);
            $ins->execute();
            if ($landing_conn->changes() > 0) $inserted++; else $skipped++;
            $ins->reset(); $ins->clear();
        }
        $landing_conn->exec('COMMIT');
    } catch (Exception $e) {
        $landing_conn->exec('ROLLBACK');
        throw $e;
    }

    $msg = $inserted > 0
        ? $inserted . ' student(s) imported from grading.' . ($skipped > 0 ? ' ' . $skipped . ' already existed.' : '')
        : 'Nothing to import — all grading students already exist in landing.';
    echo json_encode(['status' => 'success', 'message' => $msg, 'inserted' => $inserted, 'skipped' => $skipped]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Import failed. Please try again.']);
}
