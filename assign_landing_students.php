<?php
// Assign landing registrations to a faculty's class
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$__alr = ($_SESSION['role'] ?? '');
$__alp = [];
if (!empty($_SESSION['allowed_pages'])) { $__d = json_decode($_SESSION['allowed_pages'], true); if (is_array($__d)) $__alp = $__d; }
if (!isset($_SESSION['admin_id']) || ($__alr !== 'admin' && !in_array('landing_registrations', $__alp, true))) {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { $input = $_POST; }

$year = trim($input['year'] ?? '');
$sections = $input['sections'] ?? [];
$faculty_id = (int)($input['faculty_id'] ?? 0);
$class_id = (int)($input['class_id'] ?? 0);

if ($year === '' || empty($sections) || !is_array($sections) || $faculty_id <= 0 || $class_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Year, sections, faculty and class are required']);
    exit;
}
// Sanitize sections
$sections = array_values(array_filter(array_map('trim', $sections), fn($v) => $v !== ''));
if (empty($sections)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid sections selected']);
    exit;
}

require_once("landing_db.php");
require_once("landing_irregular.php");

try {
    // Resolve faculty DB
    $main_db_path = __DIR__ . '/db/grading_db.db';
    $main = new SQLite3($main_db_path);
    $main->enableExceptions(true);
    $frow = $main->query("SELECT admin_id, fullname, role, db_path FROM admin_list WHERE admin_id = '{$faculty_id}' AND role = 'faculty' LIMIT 1")->fetchArray(SQLITE3_ASSOC);
    if (!$frow) {
        $main->close();
        echo json_encode(['status' => 'error', 'message' => 'Faculty not found']);
        exit;
    }
    // A College Dean / Program Head may only assign to faculty of their own
    // department. The dropdown in landing_registrations.php is filtered the
    // same way, but that is only the UI - this is the check that counts.
    if (!function_exists('session_course_scope')) {
        require_once("departments.php");
    }
    $assignScope = session_course_scope();
    if (!empty($assignScope['scoped'])) {
        $hasProgram = false;
        $colQ = $main->query("PRAGMA table_info(admin_list)");
        while ($c = $colQ->fetchArray(SQLITE3_ASSOC)) {
            if ($c['name'] === 'program') { $hasProgram = true; break; }
        }
        if ($hasProgram) {
            $progOf = strtoupper(trim((string)$main->querySingle("SELECT `program` FROM admin_list WHERE admin_id = " . (int)$faculty_id)));
            if ($progOf !== $assignScope['department']) {
                $main->close();
                echo json_encode(['status' => 'error', 'message' => 'You can only assign students to faculty of your own department (' . $assignScope['department'] . ').']);
                exit;
            }
        }
    }
    $main->close();
    $faculty_db_path = null;
    $db_path = $frow['db_path'] ?? '';
    if (!empty($db_path) && file_exists($db_path)) {
        $faculty_db_path = $db_path;
    } elseif (!empty($db_path) && !preg_match('/\.db$/', $db_path)) {
        $dir = __DIR__ . '/db/' . basename($db_path);
        if (is_dir($dir)) {
            $files = glob($dir . '/*.db');
            if (!empty($files)) {
                $files = array_filter($files, fn($f)=>stripos(basename($f),'backup')===false && stripos(basename($f),'_old')===false);
                if (!empty($files)) { usort($files, fn($a,$b)=>filemtime($b) <=> filemtime($a)); $faculty_db_path = $files[0]; }
            }
        }
    } elseif (!empty($db_path)) {
        $candidate = __DIR__ . '/db/' . basename($db_path);
        if (file_exists($candidate)) $faculty_db_path = $candidate;
    }
    if (!$faculty_db_path || !file_exists($faculty_db_path)) {
        $faculty_db_path = $main_db_path;
    }

    // Verify class exists in faculty DB and get its subject code + year for passed check
    $fdb = new SQLite3($faculty_db_path);
    $fdb->enableExceptions(true);
    $fdb->exec("PRAGMA busy_timeout = 5000");
    $fdb->exec("PRAGMA journal_mode = WAL");
    $exists = $fdb->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = '{$class_id}'");
    if (!$exists) {
        $fdb->close();
        echo json_encode(['status' => 'error', 'message' => 'Selected class not found for this faculty']);
        exit;
    }
    $subjectForBulk = null; $yearForBulk = null; $gradeForBulk = ''; $sectionForBulk = '';
    $srBulk = $fdb->query("SELECT c.course_no as code, s.name as sname, c.year, c.grade, c.section FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id WHERE c.class_id='{$class_id}' LIMIT 1");
    if($srBulk && ($rowBulk=$srBulk->fetchArray(SQLITE3_ASSOC))){
        $subjectForBulk=trim($rowBulk['code'] ?? $rowBulk['sname'] ?? '');
        $yearForBulk=trim($rowBulk['year'] ?? '');
        if($subjectForBulk==='') $subjectForBulk=$rowBulk['sname'] ?? '';
        $gradeForBulk=strtolower(trim($rowBulk['grade'] ?? ''));
        $sectionForBulk=strtolower(trim($rowBulk['section'] ?? ''));
    }
    $targetPairBulk = $gradeForBulk . '||' . $sectionForBulk;
    $prevYearBulk=null;
    if(!empty($yearForBulk) && preg_match('/(\d{4})\s*-\s*(\d{4})/', $yearForBulk, $mBulk)) $prevYearBulk=((int)$mBulk[1]-1).'-'.((int)$mBulk[2]-1);
    $hasPassedBulkCache=[];
    $hasPassedForBulk = function($sn, $subj) use (&$hasPassedBulkCache, $prevYearBulk){
        if(empty($sn) || empty($subj)) return false;
        $key=strtolower(trim($sn)).'|'.strtolower(trim($subj)).'|'.($prevYearBulk??'');
        if(isset($hasPassedBulkCache[$key])) return $hasPassedBulkCache[$key];
        $hasPassedBulkCache[$key]=false;
        $pats=[__DIR__.'/db/backups/*/*.db', __DIR__.'/db/backups/*/*/*.db', __DIR__.'/db/previous_database/*.db', __DIR__.'/db/grading_dbold.db', __DIR__.'/db/*.db'];
        $seen=[];
        foreach($pats as $pat){ foreach(glob($pat) as $bf){ if(isset($seen[$bf])) continue; $seen[$bf]=true; if(basename($bf)==='landing_db.db') continue; if(strpos($bf, DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR)!==false && preg_match('#/db/[^/]+/[^/]+\.db$#',$bf)) continue;
            try{
                $bdb=new SQLite3($bf); $bdb->enableExceptions(true);
                $escNum=$bdb->escapeString($sn); $escSub=$bdb->escapeString($subj);
                $sql="SELECT pg.remarks, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(c.course_no) COLLATE NOCASE='{$escSub}'";
                if($prevYearBulk) $sql.=" AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYearBulk)."'";
                $sql.=" LIMIT 1";
                $q=$bdb->query($sql);
                if($q && ($r=$q->fetchArray(SQLITE3_ASSOC))){
                    $rem=strtoupper(trim($r['remarks']??'')); if($rem==='PASSED' || $rem==='PASSED WITH DISTINCTION' || $rem==='PASSED WITH HONORS'){ $hasPassedBulkCache[$key]=true; $bdb->close(); return true; }
                } else {
                    $sql2="SELECT pg.remarks, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN subjects s ON c.subject_id=s.subject_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(s.name) COLLATE NOCASE='{$escSub}'";
                    if($prevYearBulk) $sql2.=" AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYearBulk)."'";
                    $sql2.=" LIMIT 1";
                    $q2=$bdb->query($sql2);
                    if($q2 && ($r2=$q2->fetchArray(SQLITE3_ASSOC))){
                        $rem2=strtoupper(trim($r2['remarks']??'')); if($rem2==='PASSED'){ $hasPassedBulkCache[$key]=true; $bdb->close(); return true; }
                    }
                }
                $bdb->close();
            }catch(Exception $e){}
        }}
        return false;
    };
    // Get target columns in faculty student_list
    $target_cols = [];
    $col_qry = $fdb->query("PRAGMA table_info('student_list')");
    while ($col = $col_qry->fetchArray(SQLITE3_ASSOC)) { $target_cols[] = $col['name']; }
    if (!in_array('student_number', $target_cols) || !in_array('name', $target_cols)) {
        $fdb->close();
        echo json_encode(['status' => 'error', 'message' => 'Faculty database student_list is not compatible']);
        exit;
    }
    $field_map = ['student_number','name','middlename','firstname','lastname','name_extension','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','device_id','device_name','course','year','section','civil_status','family_status','special_sector'];

    // Fetch matching landing students
    $ldb = landing_db();
    $escYear = $ldb->escapeString($year);
    $escSections = array_map(fn($s) => "'" . $ldb->escapeString($s) . "'", $sections);
    $inClause = implode(',', $escSections);
    $res = $ldb->query("SELECT * FROM landing_registrations WHERE year = '{$escYear}' AND section IN ({$inClause}) ORDER BY name ASC");
    if (!$res) {
        $fdb->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to query landing registrations']);
        exit;
    }
    $candidates = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $candidates[] = $row; }
    if (empty($candidates)) {
        $fdb->close();
        echo json_encode(['status' => 'error', 'message' => 'No students found for the selected year and sections']);
        exit;
    }

    $imported = 0;
    $skipped = 0;
    $skippedSection = 0;
    $alreadyPassedSkipped = 0;
    // One section per student: grade+section pairs already holding each
    // candidate number in the faculty DB (single query for the batch).
    $enrolledPairsBulk = [];
    $inNums = [];
    foreach ($candidates as $crow) {
        $nn = strtolower(trim((string)($crow['student_number'] ?? '')));
        if ($nn !== '') $inNums[$nn] = true;
    }
    if (!empty($inNums)) {
        $inSql = [];
        foreach (array_keys($inNums) as $nn) $inSql[] = "'" . $fdb->escapeString($nn) . "'";
        $prBulk = $fdb->query("SELECT TRIM(LOWER(sl.student_number)) AS sn, TRIM(LOWER(cl.grade)) AS g, TRIM(LOWER(cl.section)) AS s FROM student_list sl JOIN class_list cl ON cl.class_id = sl.class_id WHERE TRIM(LOWER(sl.student_number)) IN (" . implode(',', $inSql) . ")");
        if ($prBulk) { while ($prow = $prBulk->fetchArray(SQLITE3_ASSOC)) { if (!empty($prow['sn'])) $enrolledPairsBulk[$prow['sn']][$prow['g'] . '||' . $prow['s']] = true; } }
    }
    foreach ($candidates as $row) {
        $numKey = strtolower(trim((string)($row['student_number'] ?? '')));
        $already = $fdb->querySingle("SELECT COUNT(*) FROM student_list WHERE class_id = '{$class_id}' AND TRIM(LOWER(student_number)) = '" . $fdb->escapeString($numKey) . "'");
        if ($already > 0) { $skipped++; continue; }
        if(!empty($subjectForBulk) && $hasPassedForBulk($row['student_number'] ?? '', $subjectForBulk)){ $alreadyPassedSkipped++; continue; }
        // One section per student (regulars only): already enrolled in a
        // different grade+section => skip. Same grade+section stays
        // assignable (multi-subject within the section).
        if (!landing_row_is_irregular($row) && isset($enrolledPairsBulk[$numKey])) {
            $elsewhere = false;
            foreach ($enrolledPairsBulk[$numKey] as $pair => $_) { if ($pair !== $targetPairBulk) { $elsewhere = true; break; } }
            if ($elsewhere) { $skippedSection++; continue; }
        }
        $cols = ['class_id'];
        $vals = ["'{$class_id}'"];
        foreach ($field_map as $field) {
            if (in_array($field, $target_cols) && array_key_exists($field, $row)) {
                $cols[] = "`{$field}`";
                $vals[] = "'" . $fdb->escapeString((string)($row[$field] ?? '')) . "'";
            }
        }
        if ($fdb->exec("INSERT INTO student_list (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")) {
            $imported++;
            $lid = (int)$row['id'];
            @$ldb->exec("UPDATE landing_registrations SET status = 'enrolled', updated_at = CURRENT_TIMESTAMP WHERE id = '{$lid}'");
        }
    }
    $fdb->close();

    $msg = "{$imported} student(s) assigned to {$frow['fullname']}'s class.";
    if ($skipped > 0) $msg .= " {$skipped} already existed and were skipped.";
    if ($skippedSection > 0) $msg .= " {$skippedSection} already enrolled in another section and were skipped.";
    if ($alreadyPassedSkipped > 0) $msg .= " {$alreadyPassedSkipped} already passed {$subjectForBulk} in {$prevYearBulk} and were not added.";
    echo json_encode(['status' => 'success', 'message' => $msg, 'imported' => $imported, 'skipped' => $skipped, 'skipped_section' => $skippedSection]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
