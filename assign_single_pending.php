<?php
// Assign a single pending landing student to a class
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$landing_id = (int)($_POST['landing_id'] ?? 0);
$class_id = (int)($_POST['class_id'] ?? 0);

if ($landing_id <= 0 || $class_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Landing ID and Class ID are required']);
    exit;
}

require_once("landing_db.php");
require_once("landing_irregular.php");
require_once("DBConnection.php");

try {
    // Fetch landing student
    $ldb = landing_db();
    $row = $ldb->query("SELECT * FROM landing_registrations WHERE id = '{$landing_id}' LIMIT 1")->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Student not found in landing registrations']);
        exit;
    }
    // NOTE: no landing `status` gate here on purpose — a student marked
    // 'enrolled' (e.g. already in GE111) must still be assignable to their
    // other pending subject classes (e.g. ENGL 111). The per-class duplicate
    // check below is the only guard that matters.

    // Use the current DB connection (respects faculty DB switching via session db_file)
    // For admin, $conn is main DB; for faculty, it's their DB. We want to insert into the class's DB.
    // The class_id belongs to whatever DB the admin is currently viewing. Since admin can see all classes,
    // we need to determine which DB the class belongs to. For simplicity, use $conn (main or faculty DB as per session)
    // and also check main DB.
    $conn = new DBConnection();

    // Verify class exists + resolve its grade/section
    $class_exists = $conn->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = '{$class_id}'");
    if (!$class_exists) {
        echo json_encode(['status' => 'error', 'message' => 'Class not found']);
        exit;
    }
    $classRow = $conn->querySingle("SELECT grade, section FROM class_list WHERE class_id = '{$class_id}'", true);
    $targetPair = strtolower(trim((string)($classRow['grade'] ?? ''))) . '||' . strtolower(trim((string)($classRow['section'] ?? '')));

    // Check duplicate student_number in same class (case-insensitive)
    $numKey = strtolower(trim((string)($row['student_number'] ?? '')));
    $already = $conn->querySingle("SELECT COUNT(*) FROM student_list WHERE class_id = '{$class_id}' AND TRIM(LOWER(student_number)) = '" . $conn->escapeString($numKey) . "'");
    if ($already > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Student already exists in this class']);
        exit;
    }

    // One section per student (regulars only): refuse when already enrolled
    // in a different grade+section. Same grade+section stays assignable
    // (multi-subject within the section). Irregulars are exempt — their
    // cross-section subject matches are the point of that flow.
    if (!landing_row_is_irregular($row) && $numKey !== '') {
        $other = $conn->query("SELECT TRIM(cl.grade) AS g, TRIM(cl.section) AS s FROM student_list sl JOIN class_list cl ON cl.class_id = sl.class_id WHERE TRIM(LOWER(sl.student_number)) = '" . $conn->escapeString($numKey) . "' LIMIT 5");
        if ($other) {
            while ($orow = $other->fetchArray(SQLITE3_ASSOC)) {
                $pair = strtolower(trim((string)($orow['g'] ?? ''))) . '||' . strtolower(trim((string)($orow['s'] ?? '')));
                if ($pair !== $targetPair) {
                    echo json_encode(['status' => 'error', 'message' => 'Student is already enrolled in ' . trim(($orow['g'] ?? '') . ' - ' . ($orow['s'] ?? '')) . ' and cannot be assigned to another section']);
                    exit;
                }
            }
        }
    }

    // Build insert data - map landing fields to student_list columns that exist
    $target_cols = [];
    $col_qry = $conn->query("PRAGMA table_info('student_list')");
    while ($col = $col_qry->fetchArray(SQLITE3_ASSOC)) { $target_cols[] = $col['name']; }

    $field_map = ['student_number','name','middlename','firstname','lastname','name_extension','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','device_id','device_name','course','year','section','civil_status','family_status','special_sector'];
    $cols = ['class_id'];
    $vals = ["'{$class_id}'"];
    foreach ($field_map as $field) {
        if (in_array($field, $target_cols) && array_key_exists($field, $row)) {
            $cols[] = "`{$field}`";
            $vals[] = "'" . $conn->escapeString((string)($row[$field] ?? '')) . "'";
        }
    }
    // Ensure name is set
    if (!in_array('name', $cols) && in_array('name', $target_cols)) {
        $cols[] = "`name`";
        $vals[] = "'" . $conn->escapeString($row['name'] ?? '') . "'";
    }

    $sql = "INSERT INTO student_list (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    if ($conn->exec($sql)) {
        // Mark landing as enrolled
        @$ldb->exec("UPDATE landing_registrations SET status = 'enrolled', updated_at = CURRENT_TIMESTAMP WHERE id = '{$landing_id}'");
        echo json_encode(['status' => 'success', 'message' => 'Student ' . $row['name'] . ' assigned to class successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to assign student: ' . $conn->lastErrorMsg()]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
