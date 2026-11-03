<?php
// Bulk assign all pending landing students for a section to a class
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin','faculty'])) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Please login.']);
    exit;
}

$section = trim($_POST['section'] ?? ($_GET['section'] ?? ''));
$grade = trim($_POST['grade'] ?? ($_GET['grade'] ?? ''));
$class_id = (int)($_POST['class_id'] ?? ($_GET['class_id'] ?? 0));

if ($section === '' || $class_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Section and class are required']);
    exit;
}

    require_once("landing_db.php");
    require_once("landing_irregular.php");

try {
    // Verify class exists (in current DB context - main or faculty DB as per session)
    require_once("DBConnection.php");
    $conn = new DBConnection();
    $exists = $conn->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = '{$class_id}'");
    if (!$exists) {
        echo json_encode(['status' => 'error', 'message' => 'Class not found']);
        exit;
    }
    // Resolve subject CODE + NAME + section for this class (same code, previous school year passed => enrolled to avoid repeating)
    $subjectNameForClass = null; $currentYearForClass=null;
    $classCodeForMatch = ''; $classNameForMatch = ''; $classSectionForMatch = ''; $classGradeForMatch = '';
    $sr = $conn->query("SELECT c.course_no as subject_code, s.name as subject_name, c.year, c.grade, c.section FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id WHERE c.class_id='{$class_id}' LIMIT 1");
    if($sr && ($srow=$sr->fetchArray(SQLITE3_ASSOC))){ $subjectNameForClass=trim($srow['subject_code'] ?? $srow['subject_name'] ?? ''); if(empty($subjectNameForClass) && !empty($srow['subject_name'])) $subjectNameForClass=$srow['subject_name']; $currentYearForClass=trim($srow['year'] ?? ''); $classCodeForMatch=strtolower(trim($srow['subject_code'] ?? '')); $classNameForMatch=strtolower(trim($srow['subject_name'] ?? '')); $classSectionForMatch=strtolower(trim($srow['section'] ?? $section)); $classGradeForMatch=strtolower(trim($srow['grade'] ?? '')); }
    $targetPairBulk = $classGradeForMatch . '||' . $classSectionForMatch;
    $hasPassedCache=[];
    $hasPassedInBackup = function($student_number, $subject) use (&$hasPassedCache, $currentYearForClass) {
        if(empty($student_number) || empty($subject)) return false;
        $key=strtolower(trim($student_number)).'|'.strtolower(trim($subject)).'|'.($currentYearForClass??'');
        if(isset($hasPassedCache[$key])) return $hasPassedCache[$key];
        $hasPassedCache[$key]=false;
        $prevYear=null;
        if(!empty($currentYearForClass) && preg_match('/(\d{4})\s*-\s*(\d{4})/', $currentYearForClass, $m)) $prevYear=((int)$m[1]-1).'-'.((int)$m[2]-1);
        $patterns=[__DIR__.'/db/backups/*/*.db', __DIR__.'/db/backups/*/*/*.db', __DIR__.'/db/previous_database/*.db', __DIR__.'/db/grading_dbold.db', __DIR__.'/db/*.db'];
        $seen=[];
        foreach($patterns as $pat){ foreach(glob($pat) as $bf){ if(isset($seen[$bf])) continue; $seen[$bf]=true; if(basename($bf)==='landing_db.db') continue; if(strpos($bf, DIRECTORY_SEPARATOR.'db'.DIRECTORY_SEPARATOR)!==false && preg_match('#/db/[^/]+/[^/]+\.db$#',$bf)) continue;
            try{
                $bdb=new SQLite3($bf); $bdb->enableExceptions(true);
                $escNum=$bdb->escapeString($student_number); $escSub=$bdb->escapeString($subject);
                $sql="SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(c.course_no) COLLATE NOCASE='{$escSub}'";
                if($prevYear) $sql.=" AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYear)."'";
                $sql.=" LIMIT 1";
                $q=$bdb->query($sql);
                if(!$q || !($row=$q->fetchArray(SQLITE3_ASSOC))){
                    $sql2="SELECT pg.remarks, pg.average_grade, pg.average_equivalent, pg.equivalent_grade, c.year FROM published_grades pg JOIN class_list c ON pg.class_id=c.class_id JOIN subjects s ON c.subject_id=s.subject_id JOIN student_list sl ON pg.student_id=sl.student_id WHERE sl.student_number='{$escNum}' AND TRIM(s.name) COLLATE NOCASE='{$escSub}'";
                    if($prevYear) $sql2.=" AND TRIM(c.year) COLLATE NOCASE='".$bdb->escapeString($prevYear)."'";
                    $sql2.=" LIMIT 1";
                    $q2=$bdb->query($sql2);
                    if($q2 && ($row2=$q2->fetchArray(SQLITE3_ASSOC))) $row=$row2; else { $bdb->close(); continue; }
                }
                if($row){
                    $rem=strtoupper(trim($row['remarks']??'')); $avg=$row['average_grade'] ?? $row['average_equivalent'] ?? $row['equivalent_grade'] ?? null;
                    if($rem==='PASSED' || $rem==='PASSED WITH DISTINCTION' || $rem==='PASSED WITH HONORS'){ $hasPassedCache[$key]=true; $bdb->close(); return true; }
                    if($avg!==null && is_numeric($avg)){ $avgf=floatval($avg); if($avgf>=1.0 && $avgf<=3.0){ $hasPassedCache[$key]=true; $bdb->close(); return true; } $eq=trim($row['equivalent_grade']??''); if(is_numeric($eq) && floatval($eq)>=1.0 && floatval($eq)<=3.0){ $hasPassedCache[$key]=true; $bdb->close(); return true; } }
                }
                $bdb->close();
            }catch(Exception $e){}
        }}
        return false;
    };

    // Fetch pending students for this section (and grade if provided).
    // Regular rows match by their own section; irregular rows match by their
    // own section too, plus by a picked entry for this class — the same set
    // get_pending_students.php lists, so Assign All assigns exactly that set.
    $ldb = landing_db();
    $escSection = $ldb->escapeString($section);
    // NOTE: no landing `status` filter here on purpose — rows marked 'enrolled'
    // (already in one subject class) must still be assignable to their other
    // pending subject classes. The per-class $already check below skips true dupes.
    if ($grade !== '') {
        $escGrade = $ldb->escapeString($grade);
        $sql = "SELECT * FROM landing_registrations WHERE section = '{$escSection}' AND course COLLATE NOCASE = '{$escGrade}' ORDER BY name ASC";
    } else {
        $sql = "SELECT * FROM landing_registrations WHERE section = '{$escSection}' ORDER BY name ASC";
    }
    $res = $ldb->query($sql);
    $candidates = [];
    $candidateIds = [];
    if ($res) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $candidates[] = $row; $candidateIds[(int)($row['id'] ?? 0)] = true; }
    }
    if ($grade !== '') {
        $sqlIrr = "SELECT * FROM landing_registrations WHERE is_irregular = 1 AND course COLLATE NOCASE = '{$escGrade}' ORDER BY name ASC";
    } else {
        $sqlIrr = "SELECT * FROM landing_registrations WHERE is_irregular = 1 ORDER BY name ASC";
    }
    $resIrr = $ldb->query($sqlIrr);
    if ($resIrr) {
        while ($row = $resIrr->fetchArray(SQLITE3_ASSOC)) {
            if (isset($candidateIds[(int)($row['id'] ?? 0)])) continue;
            if (!irregular_match_for_class($row, $classSectionForMatch, $classCodeForMatch, $classNameForMatch)) continue;
            $candidates[] = $row;
        }
    }
    if (empty($candidates)) {
        echo json_encode(['status' => 'error', 'message' => 'No pending students found for section ' . $section]);
        exit;
    }

    // Get target columns
    $target_cols = [];
    $col_qry = $conn->query("PRAGMA table_info('student_list')");
    while ($col = $col_qry->fetchArray(SQLITE3_ASSOC)) { $target_cols[] = $col['name']; }
    $field_map = ['student_number','name','middlename','firstname','lastname','name_extension','birthdate','age','gender','contact_number','email','barangay','municipality','province','zipcode','device_id','device_name','course','year','section','civil_status','family_status','special_sector'];

    $imported = 0;
    $skipped = 0;
    $skippedSection = 0;
    $alreadyPassed = 0;
    $irrAssigned = 0;
    // One section per student: grade+section pairs already holding each
    // candidate number (single query for the whole batch).
    $enrolledPairsBulk = [];
    if (!empty($candidates)) {
        $inNums = [];
        foreach ($candidates as $crow) {
            $nn = strtolower(trim((string)($crow['student_number'] ?? '')));
            if ($nn !== '') $inNums[$nn] = true;
        }
        if (!empty($inNums)) {
            $inSql = [];
            foreach (array_keys($inNums) as $nn) $inSql[] = "'" . $conn->escapeString($nn) . "'";
            $prBulk = $conn->query("SELECT TRIM(LOWER(sl.student_number)) AS sn, TRIM(LOWER(cl.grade)) AS g, TRIM(LOWER(cl.section)) AS s FROM student_list sl JOIN class_list cl ON cl.class_id = sl.class_id WHERE TRIM(LOWER(sl.student_number)) IN (" . implode(',', $inSql) . ")");
            if ($prBulk) { while ($prow = $prBulk->fetchArray(SQLITE3_ASSOC)) { if (!empty($prow['sn'])) $enrolledPairsBulk[$prow['sn']][$prow['g'] . '||' . $prow['s']] = true; } }
        }
    }
    foreach ($candidates as $row) {
        $rowIsIrr = landing_row_is_irregular($row);
        $numKey = strtolower(trim((string)($row['student_number'] ?? '')));
        $already = $conn->querySingle("SELECT COUNT(*) FROM student_list WHERE class_id = '{$class_id}' AND TRIM(LOWER(student_number)) = '" . $conn->escapeString($numKey) . "'");
        if ($already > 0) { $skipped++; continue; }
        // One section per student (regulars only): already enrolled in a
        // different grade+section => skip. Same grade+section stays
        // assignable (multi-subject within the section).
        if (!$rowIsIrr && isset($enrolledPairsBulk[$numKey])) {
            $elsewhere = false;
            foreach ($enrolledPairsBulk[$numKey] as $pair => $_) { if ($pair !== $targetPairBulk) { $elsewhere = true; break; } }
            if ($elsewhere) { $skippedSection++; continue; }
        }
        // If previously passed this subject in backup DBs, consider enrolled but do not add (already passed)
        if ($subjectNameForClass && $hasPassedInBackup($row['student_number'] ?? '', $subjectNameForClass)) { $alreadyPassed++; continue; }
        $cols = ['class_id'];
        $vals = ["'{$class_id}'"];
        foreach ($field_map as $field) {
            if (in_array($field, $target_cols) && array_key_exists($field, $row)) {
                $cols[] = "`{$field}`";
                $vals[] = "'" . $conn->escapeString((string)($row[$field] ?? '')) . "'";
            }
        }
        if ($conn->exec("INSERT INTO student_list (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")) {
            $imported++;
            if ($rowIsIrr) {
                $irrAssigned++;
                // Irregulars stay assignable to their other picked subjects
                // (per-class queries ignore stored status for them), but once
                // every picked subject AND the regular section classes are
                // handled anywhere, resolve the pending status so admin
                // counts/badges clear.
                if (irregular_fully_handled($row)) {
                    $lid2 = (int)$row['id'];
                    if ($lid2 > 0) @$ldb->exec("UPDATE landing_registrations SET status='enrolled', updated_at=CURRENT_TIMESTAMP WHERE id='{$lid2}'");
                }
            } else {
                // Update global status to enrolled so landing view shows enrolled, but per-class pending (get_pending_students.php) still shows for other subjects via per-class check (not status)
                $lid = (int)$row['id'];
                @$ldb->exec("UPDATE landing_registrations SET status = 'enrolled', updated_at = CURRENT_TIMESTAMP WHERE id = '{$lid}'");
            }
        }
    }

    $msg = "{$imported} student(s) assigned to class.";
    if ($irrAssigned > 0) $msg .= " Including {$irrAssigned} irregular student(s).";
    if ($skipped > 0) $msg .= " {$skipped} already existed and were skipped.";
    if ($skippedSection > 0) $msg .= " {$skippedSection} already enrolled in another section and were skipped.";
    if ($alreadyPassed > 0) $msg .= " {$alreadyPassed} already passed this subject and were not added.";
    echo json_encode(['status' => 'success', 'message' => $msg, 'imported' => $imported, 'skipped' => $skipped, 'skipped_section' => $skippedSection]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
