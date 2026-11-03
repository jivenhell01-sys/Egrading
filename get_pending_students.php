<?php
// Returns pending landing registrations for a given section (and optionally grade/course)
while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

$section = trim($_GET['section'] ?? '');
$grade = trim($_GET['grade'] ?? '');
$class_id = (int)($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));

if ($section === '') {
    echo json_encode(['status' => 'error', 'message' => 'Section is required']);
    exit;
}

require_once("landing_db.php");
require_once("landing_irregular.php");

try {
    $db = landing_db();

    // Build set of student_numbers already enrolled in this specific class (per-subject, current DB)
    $enrolledInClass = [];
    // Map: student_number -> set of "grade||section" pairs they are enrolled
    // in (any subject class, current DB). Regular students must exist in only
    // one grade+section: multi-subject inside their section stays allowed,
    // but they are hidden from every other section's popup.
    $enrolledPairs = [];
    $subjectNameForClass = null;
    $classCodeForMatch = '';
    $classNameForMatch = '';
    $currentYearForClass = null;
    $currentSectionForClass = null;
    $currentGradeForClass = null;

    if ($class_id > 0) {
        try {
            require_once("DBConnection.php");
            $cdb = new DBConnection();
            $er = $cdb->query("SELECT student_number FROM student_list WHERE class_id = '{$class_id}'");
            if ($er) { while($erow = $er->fetchArray(SQLITE3_ASSOC)){ if(!empty($erow['student_number'])) $enrolledInClass[strtolower(trim($erow['student_number']))]=true; } }
            $sr = $cdb->query("SELECT c.course_no as subject_code, s.name as subject_name, c.year, c.grade, c.section FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id WHERE c.class_id='{$class_id}' LIMIT 1");
            if($sr && ($srow=$sr->fetchArray(SQLITE3_ASSOC))){
                $subjectNameForClass=trim($srow['subject_code'] ?? $srow['subject_name'] ?? '');
                if(empty($subjectNameForClass) && !empty($srow['subject_name'])) $subjectNameForClass=$srow['subject_name'];
                $classCodeForMatch=strtolower(trim($srow['subject_code'] ?? ''));
                $classNameForMatch=strtolower(trim($srow['subject_name'] ?? ''));
                $currentYearForClass=trim($srow['year'] ?? '');
                $currentGradeForClass=trim($srow['grade'] ?? '');
                $currentSectionForClass=trim($srow['section'] ?? $section);
            }
            $pr = $cdb->query("SELECT TRIM(LOWER(sl.student_number)) AS sn, TRIM(LOWER(cl.grade)) AS g, TRIM(LOWER(cl.section)) AS s FROM student_list sl JOIN class_list cl ON cl.class_id = sl.class_id WHERE sl.student_number IS NOT NULL AND TRIM(sl.student_number) != ''");
            if ($pr) { while($prow = $pr->fetchArray(SQLITE3_ASSOC)){ if(!empty($prow['sn'])) $enrolledPairs[$prow['sn']][$prow['g'] . '||' . $prow['s']] = true; } }
            $cdb->close();
        } catch (Throwable $e) {}
    }
    // Canonical "this grade + section" pair for the class the popup serves.
    $targetPair = strtolower(trim((string)($currentGradeForClass ?? ''))) . '||' . strtolower(trim((string)($currentSectionForClass ?? $section)));

    // NOTE: cross-class "duplicate" scoring intentionally disabled — a student
    // enrolled in one subject class must stay assignable to their other pending
    // subject classes. Dedupe is per-class only ($enrolledInClass above).

    // Helpers: has student previously passed this subject in backup DBs? (same subject code, previous school year, final average + remarks PASSED) — to avoid repeating
    $hasPassedCache = [];
    $hasPassedInBackup = function($student_number, $subject) use (&$hasPassedCache, $currentYearForClass) {
        if(empty($student_number) || empty($subject)) return false;
        $key = strtolower(trim($student_number)).'|'.strtolower(trim($subject)).'|'.($currentYearForClass??'');
        if(isset($hasPassedCache[$key])) return $hasPassedCache[$key];
        $hasPassedCache[$key]=false;
        $prevYear = null;
        if(!empty($currentYearForClass) && preg_match('/(\d{4})\s*-\s*(\d{4})/', $currentYearForClass, $m)){
            $prevYear = ((int)$m[1]-1).'-'.((int)$m[2]-1);
        }
        $patterns = [__DIR__.'/db/backups/*/*.db', __DIR__.'/db/backups/*/*/*.db', __DIR__.'/db/previous_database/*.db', __DIR__.'/db/grading_dbold.db', __DIR__.'/db/*.db'];
        $seen=[];
        foreach($patterns as $pat){
            foreach(glob($pat) as $bf){
                if(isset($seen[$bf])) continue; $seen[$bf]=true;
                if(basename($bf)==='landing_db.db') continue;
                if(strpos($bf, DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR)!==false && preg_match('#/db/[^/]+/[^/]+\.db$#',$bf)) continue;
                try{
                    $bdb=new SQLite3($bf); $bdb->enableExceptions(true);
                    $escNum=$bdb->escapeString($student_number);
                    $escSub=$bdb->escapeString($subject);
                    $sql = "SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(c.course_no) COLLATE NOCASE='{$escSub}'";
                    if($prevYear) $sql .= " AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYear)."'";
                    $sql .= " LIMIT 1";
                    $q=$bdb->query($sql);
                    if(!$q || !($row=$q->fetchArray(SQLITE3_ASSOC))){
                        $sql2 = "SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN subjects s ON c.subject_id=s.subject_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(s.name) COLLATE NOCASE='{$escSub}'";
                        if($prevYear) $sql2 .= " AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYear)."'";
                        $sql2 .= " LIMIT 1";
                        $q2=$bdb->query($sql2);
                        if($q2 && ($row2=$q2->fetchArray(SQLITE3_ASSOC))) $row=$row2; else { $bdb->close(); continue; }
                    }
                    if($row){
                        $rem=strtoupper(trim($row['remarks']??''));
                        $avg=$row['average_grade'] ?? $row['average_equivalent'] ?? $row['equivalent_grade'] ?? null;
                        if($rem==='PASSED' || $rem==='PASSED WITH DISTINCTION' || $rem==='PASSED WITH HONORS'){
                            $hasPassedCache[$key]=true; $bdb->close(); return true;
                        }
                        if($avg!==null && is_numeric($avg)){
                            $avgf=floatval($avg);
                            if($avgf>=1.0 && $avgf<=3.0){ $hasPassedCache[$key]=true; $bdb->close(); return true; }
                            $eq=strtoupper(trim($row['equivalent_grade']??''));
                            if(is_numeric($eq) && floatval($eq)>=1.0 && floatval($eq)<=3.0){ $hasPassedCache[$key]=true; $bdb->close(); return true; }
                        }
                    }
                    $bdb->close();
                }catch(Throwable $e){}
            }
        }
        return false;
    };

    // Auto-reconcile so per-section badge in class.php is live (no admin visit needed) — best-effort
    try {
        if (!function_exists('landing_numbers_enrolled_anywhere')) { require_once("landing_enrollments.php"); }
        $enrolledNums = [];
        $e_qry = $db->query("SELECT id, student_number FROM landing_registrations WHERE status = 'enrolled'");
        if ($e_qry) { while ($er = $e_qry->fetchArray(SQLITE3_ASSOC)) { if (!empty($er['student_number'])) $enrolledNums[$er['student_number']] = (int)$er['id']; } }
        if (!empty($enrolledNums)) {
            $still = array_flip(landing_numbers_enrolled_anywhere(array_keys($enrolledNums)));
            foreach ($enrolledNums as $num => $rid) {
                if (!isset($still[$num])) { $rid = (int)$rid; try { @$db->exec("UPDATE landing_registrations SET status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = '{$rid}'"); } catch (Throwable $e) {} }
            }
        }
        // Reverse: fully-placed rows stuck at 'pending' (e.g. added manually via
        // Students page) are marked 'enrolled' — best-effort, never fatal.
        if (function_exists('landing_mark_fully_placed_enrolled')) { try { landing_mark_fully_placed_enrolled($db); } catch (Throwable $e) {} }
    } catch (Throwable $re) {}

    // Use a column list that works even if the DB hasn't been migrated yet (no is_irregular column on old prod DB)
    $colsFull = "id, student_number, name, lastname, firstname, middlename, name_extension, course, year, section, birthdate, gender, contact_number, email, status, is_irregular, irregular_subjects";
    $colsFallback = "id, student_number, name, lastname, firstname, middlename, name_extension, course, year, section, birthdate, gender, contact_number, email, status";
    $cols = $colsFull;
    // Quick probe: if is_irregular column missing, fall back
    try {
        $probe = $db->query("SELECT is_irregular FROM landing_registrations LIMIT 0");
        if (!$probe) $cols = $colsFallback;
    } catch (Throwable $e) { $cols = $colsFallback; }

    $escSection = $db->escapeString($section);
    $matchSecLower = strtolower(trim($currentSectionForClass ?? $section));

    $sql = "SELECT {$cols} FROM landing_registrations WHERE section = '{$escSection}' ORDER BY name ASC";
    if ($grade !== '') {
        $escGrade = $db->escapeString($grade);
        $sql = "SELECT {$cols} FROM landing_registrations WHERE section = '{$escSection}' AND course COLLATE NOCASE = '{$escGrade}' ORDER BY name ASC";
    }

    $res = null;
    try { $res = $db->query($sql); } catch (Throwable $e) { $res = null; }
    // If colsFull failed, retry with fallback
    if (!$res && $cols === $colsFull) {
        $cols = $colsFallback;
        $sql = str_replace($colsFull, $colsFallback, $sql);
        try { $res = $db->query($sql); } catch (Throwable $e) { $res = null; }
    }

    $students = [];
    $addedIds = [];
    if ($res) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (!empty($enrolledInClass) && isset($enrolledInClass[strtolower(trim($row['student_number'] ?? ''))])) continue;
            // One section per student (regulars only): already enrolled in a
            // different grade+section => excluded from this popup. Same
            // grade+section stays listed (multi-subject within the section).
            // Irregular rows are exempt — cross-section subject matches are
            // their purpose (per-class exclusion above still applies to them).
            if (!empty($enrolledPairs) && function_exists('landing_row_is_irregular') && !landing_row_is_irregular($row)) {
                $snKey = strtolower(trim($row['student_number'] ?? ''));
                if (isset($enrolledPairs[$snKey])) {
                    foreach ($enrolledPairs[$snKey] as $pair => $_) {
                        if ($pair !== $targetPair) continue 2;
                    }
                }
            }
            if ($subjectNameForClass && $hasPassedInBackup($row['student_number'] ?? '', $subjectNameForClass)) continue;
            $row['is_irregular'] = function_exists('landing_row_is_irregular') && landing_row_is_irregular($row) ? 1 : 0;
            $row['irregular_match'] = $row['is_irregular'] && function_exists('irregular_match_for_class') ? irregular_match_for_class($row, $matchSecLower, $classCodeForMatch, $classNameForMatch) : null;
            // Ensure expected keys exist for JS even on fallback DB
            if (!isset($row['is_irregular'])) $row['is_irregular'] = 0;
            if (!isset($row['irregular_subjects'])) $row['irregular_subjects'] = null;
            $students[] = $row;
            $addedIds[(int)($row['id'] ?? 0)] = true;
        }
    }

    $sqlIrr = "SELECT {$cols} FROM landing_registrations WHERE is_irregular = 1 ORDER BY name ASC";
    if ($grade !== '') {
        $escGrade = isset($escGrade) ? $escGrade : $db->escapeString($grade);
        $sqlIrr = "SELECT {$cols} FROM landing_registrations WHERE is_irregular = 1 AND course COLLATE NOCASE = '{$escGrade}' ORDER BY name ASC";
    }
    // Only run irregular query if the column exists
    if ($cols === $colsFull) {
        $resIrr = null;
        try { $resIrr = $db->query($sqlIrr); } catch (Throwable $e) { $resIrr = null; }
        if ($resIrr) {
            while ($row = $resIrr->fetchArray(SQLITE3_ASSOC)) {
                if (isset($addedIds[(int)($row['id'] ?? 0)])) continue;
                if (!function_exists('irregular_match_for_class')) continue;
                $match = irregular_match_for_class($row, $matchSecLower, $classCodeForMatch, $classNameForMatch);
                if (!$match) continue;
                if (!empty($enrolledInClass) && isset($enrolledInClass[strtolower(trim($row['student_number'] ?? ''))])) continue;
                if ($subjectNameForClass && $hasPassedInBackup($row['student_number'] ?? '', $subjectNameForClass)) continue;
                $row['is_irregular'] = 1;
                $row['irregular_match'] = $match;
                $students[] = $row;
                $addedIds[(int)($row['id'] ?? 0)] = true;
            }
        }
    }

    // NOTE: 75% cross-class duplicate auto-dismiss intentionally disabled (see
    // above) — enrolled-elsewhere students remain assignable to pending subjects.
    echo json_encode(['status' => 'success', 'students' => $students, 'count' => count($students)]);
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Server error: '.$e->getMessage()]);
}
?>