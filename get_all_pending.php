<?php
// Returns all pending landing registrations grouped by section
while (ob_get_level()) @ob_end_clean();
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once("landing_db.php");
require_once("landing_irregular.php");
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$role = $_SESSION['role'] ?? '';
$admin_id = $_SESSION['admin_id'] ?? 0;

try {
    $db = landing_db();
    // Auto-reconcile stale 'enrolled' rows that are no longer in any class
    // (same logic as landing_registrations.php:19-42) so class.php badges are live without admin visit
    try {
        if (!function_exists('landing_numbers_enrolled_anywhere')) { require_once("landing_enrollments.php"); }
        $enrolledNums = [];
        $e_qry = $db->query("SELECT id, student_number FROM landing_registrations WHERE status = 'enrolled'");
        if ($e_qry) { while ($er = $e_qry->fetchArray(SQLITE3_ASSOC)) { if (!empty($er['student_number'])) $enrolledNums[$er['student_number']] = (int)$er['id']; } }
        if (!empty($enrolledNums)) {
            $still = array_flip(landing_numbers_enrolled_anywhere(array_keys($enrolledNums)));
            foreach ($enrolledNums as $num => $rid) {
                if (!isset($still[$num])) { $rid = (int)$rid; @$db->exec("UPDATE landing_registrations SET status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = '{$rid}'"); }
            }
        }
        // Reverse: fully-placed rows stuck at 'pending' are marked 'enrolled'.
        if (function_exists('landing_mark_fully_placed_enrolled')) { try { landing_mark_fully_placed_enrolled($db); } catch (Throwable $e2) {} }
    } catch (Throwable $re) { /* non-fatal */ }
    // Always filter by the CURRENT database's sections (DBConnection respects $_SESSION['db_file'])
    // e.g. if current DB is db/grading_db.db, only show pending whose section exists in grading_db.db's class_list
    // Never scan backup folders - only the active DB file.
    $allowedSections = [];
    $allowedGradeSections = []; // grade|section lower => true (per-user subject filter: only pending whose course+section matches a class the user teaches)
    $enrolledByGradeSection = []; // "grade|section" => [stuKey=>true] — per-subject enrollment check
    $enrolledClassIds = []; // stuKey => [class_id => true] — per-class enrollment check (irregular entries)
    $sectionSubjects = []; // section lower => list of subject codes in current DB (for previous-year passed check)
    $sectionYears = []; // sec lower => max year
    $classMap = []; // class_id => [sec, code, name, grade, year]
    try {
        require_once("DBConnection.php");
        $fdb = new DBConnection(); // respects $_SESSION['db_file'] = current DB for this user
        $sec_qry = $fdb->query("SELECT DISTINCT section, grade FROM class_list WHERE section IS NOT NULL AND TRIM(section) != ''");
        if ($sec_qry) {
            while ($srow = $sec_qry->fetchArray(SQLITE3_ASSOC)) {
                $secKey = strtolower(trim($srow['section']));
                $allowedSections[$secKey] = true;
                $gradeKey = strtolower(trim($srow['grade'] ?? ''));
                if ($gradeKey !== '') {
                    $allowedGradeSections[$gradeKey . '|' . $secKey] = true;
                } else {
                    // class with empty grade: fall back to section-only
                    $allowedGradeSections['|' . $secKey] = true;
                }
            }
        }
        // also allow grade-only empty fallback for section-only matching when class has no grade
        $sub_qry = $fdb->query("SELECT c.class_id, c.section, c.grade, c.course_no as subject_code, s.name as subject_name, c.year FROM class_list c JOIN subjects s ON c.subject_id=s.subject_id WHERE c.section IS NOT NULL AND TRIM(c.section)!='' AND (TRIM(c.course_no)!='' OR s.name IS NOT NULL)");
        if($sub_qry){ while($srow=$sub_qry->fetchArray(SQLITE3_ASSOC)){ $sec=strtolower(trim($srow['section'])); $code=trim($srow['subject_code'] ?? $srow['subject_name'] ?? ''); if($code==='') continue; if(!isset($sectionSubjects[$sec])) $sectionSubjects[$sec]=[]; $sectionSubjects[$sec][strtolower($code)]= $code; $yr=trim($srow['year']??''); if($yr!==''){ if(!isset($sectionYears[$sec]) || strcmp($yr,$sectionYears[$sec])>0) $sectionYears[$sec]=$yr; } $classMap[(int)$srow['class_id']] = ['sec'=>$sec, 'code'=>$code, 'name'=>trim($srow['subject_name'] ?? ''), 'grade'=>trim($srow['grade'] ?? ''), 'year'=>$yr]; } }
        // build per-grade+section enrolled map for this user's DB — if already enrolled in that specific grade+section, not pending for this user
        try {
            $enr_qry = $fdb->query("SELECT sl.student_number, c.grade, c.section FROM student_list sl JOIN class_list c ON sl.class_id=c.class_id WHERE sl.student_number IS NOT NULL AND TRIM(sl.student_number)!=''");
            if ($enr_qry) {
                while ($erow = $enr_qry->fetchArray(SQLITE3_ASSOC)) {
                    $stu = strtolower(trim($erow['student_number'] ?? ''));
                    if ($stu === '') continue;
                    $g = strtolower(trim($erow['grade'] ?? ''));
                    $s = strtolower(trim($erow['section'] ?? ''));
                    $k = $g . '|' . $s;
                    if (!isset($enrolledByGradeSection[$k])) $enrolledByGradeSection[$k] = [];
                    $enrolledByGradeSection[$k][$stu] = true;
                }
            }
        } catch (Throwable $e2) {}
        // per-class enrollment sets for irregular entry fulfillment checks
        try {
            $ec_qry = $fdb->query("SELECT student_number, class_id FROM student_list WHERE student_number IS NOT NULL AND TRIM(student_number) != '' AND class_id IS NOT NULL");
            if ($ec_qry) {
                while ($ecrow = $ec_qry->fetchArray(SQLITE3_ASSOC)) {
                    $stu = strtolower(trim($ecrow['student_number'] ?? ''));
                    if ($stu === '') continue;
                    if (!isset($enrolledClassIds[$stu])) $enrolledClassIds[$stu] = [];
                    $enrolledClassIds[$stu][(int)$ecrow['class_id']] = true;
                }
            }
        } catch (Throwable $e3) {}
        $fdb->close();
    } catch (Throwable $e) { $allowedSections = []; $allowedGradeSections=[]; $enrolledByGradeSection=[]; $enrolledClassIds=[]; $sectionSubjects=[]; $sectionYears=[]; $classMap=[]; }
    // Helper: has student passed this subject code in previous school year backup? (to avoid repeating)
    $hasPassedCache=[];
    $hasPassedInBackupGlobal = function($student_number, $subject, $prevYear=null) use (&$hasPassedCache) {
        if(empty($student_number) || empty($subject)) return false;
        $key=strtolower(trim($student_number)).'|'.strtolower(trim($subject)).'|'.($prevYear??'');
        if(isset($hasPassedCache[$key])) return $hasPassedCache[$key];
        $hasPassedCache[$key]=false;
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
    $hasFailedCache=[];
    $hasFailedInBackupGlobal = function($student_number, $subject, $prevYear=null) use (&$hasFailedCache) {
        if(empty($student_number) || empty($subject)) return false;
        $key=strtolower(trim($student_number)).'|'.strtolower(trim($subject)).'|'.($prevYear??'');
        if(isset($hasFailedCache[$key])) return $hasFailedCache[$key];
        $hasFailedCache[$key]=false;
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
                $rem=strtoupper(trim($row['remarks']??'')); if(in_array($rem, ['DROPPED','CONDITIONAL','FAILED','INC','INCOMPLETE','DR','5.0'], true)){ $hasFailedCache[$key]=true; $bdb->close(); return true; }
                $avg=$row['average_grade'] ?? $row['average_equivalent'] ?? $row['equivalent_grade'] ?? null;
                if($avg!==null){ if(is_string($avg) && in_array(strtoupper(trim($avg)), ['DR','DROPPED','FAILED','CONDITIONAL','INC'], true)){ $hasFailedCache[$key]=true; $bdb->close(); return true; } if(is_numeric($avg) && function_exists('remarks_for_grade') && in_array(remarks_for_grade($avg, 'AUTO'), ['FAILED','DROPPED'], true)){ $hasFailedCache[$key]=true; $bdb->close(); return true; } }
                $bdb->close();
            }catch(Exception $e){}
        }}
        return false;
    };

    $isFast = isset($_GET['fast']) && $_GET['fast']=='1';
    // Regular rows group by their OWN section; irregular rows group by their
    // own section too (they still take their regular block's classes), with
    // each picked entry additionally grouped under its entry section below.
    // No landing `status` filter: rows marked 'enrolled' (already in one subject
    // class) are still pending for their other subject classes.
    $res = $db->query("SELECT id, student_number, name, firstname, middlename, lastname, course, year, section, status FROM landing_registrations ORDER BY section ASC, name ASC");
    $grouped = [];
    $total = 0;
    $seenPendingKeys=[];
    $emittedStuSection=[];
    if ($res) {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $secKey = strtolower(trim($row['section'] ?? ''));
            if (!isset($allowedSections[$secKey])) continue;
            // Per-user subject filter: course+section must match a class the logged-in user teaches
            $gradeKey = strtolower(trim($row['course'] ?? ''));
            $gsKey = $gradeKey . '|' . $secKey;
            if ($gradeKey !== '' && !isset($allowedGradeSections[$gsKey]) && !isset($allowedGradeSections['|' . $secKey])) continue;
            if ($gradeKey === '' && !isset($allowedSections[$secKey])) continue;
            // Per-subject pending: enrolled in one subject class (e.g. GE111) still
            // leaves the student pending for the section's other subject classes
            // (e.g. ENGL 111). Skip only when enrolled in EVERY class of this
            // grade+section — i.e. fully placed, nothing left to assign.
            $stuKey = strtolower(trim($row['student_number'] ?? ''));
            if ($stuKey !== '') {
                $secCls = [];
                foreach ($classMap as $mcid => $mc) {
                    if (($mc['sec'] ?? '') !== $secKey) continue;
                    $mcGrade = strtolower(trim($mc['grade'] ?? ''));
                    if ($gradeKey !== '' && $mcGrade !== '' && $mcGrade !== $gradeKey) continue;
                    $secCls[] = (int)$mcid;
                }
                if (!empty($secCls)) {
                    $allIn = true;
                    foreach ($secCls as $mcid) {
                        if (empty($enrolledClassIds[$stuKey][$mcid])) { $allIn = false; break; }
                    }
                    if ($allIn) continue;
                }
            }
            if (!$isFast) {
                $secSubjects = $sectionSubjects[$secKey] ?? [];
                $prevYearForSec = $sectionYears[$secKey] ?? null;
                if($prevYearForSec && preg_match('/(\d{4})\s*-\s*(\d{4})/', $prevYearForSec, $mPrev)) $prevYearForSec=((int)$mPrev[1]-1).'-'.((int)$mPrev[2]-1);
                else $prevYearForSec=null;
                if (!empty($secSubjects)) {
                    $allPassed = true;
                    foreach ($secSubjects as $subj) { if (!$hasPassedInBackupGlobal($row['student_number'] ?? '', $subj, $prevYearForSec)) { $allPassed = false; break; } }
                    if ($allPassed) continue;
                }
            }
            $section = $row['section'] ?? 'No Section';
            if (!isset($grouped[$section])) $grouped[$section] = [];
            $grouped[$section][] = $row;
            $total++;
            $seenPendingKeys[strtolower(trim($row['student_number']??''))] = true;
            if ($stuKey !== '') $emittedStuSection[$stuKey . '|' . $secKey] = true;
        }
    }
    // Irregular pass: each picked entry counts under its ENTRY section when a
    // matching class exists in the current DB, the student is not yet enrolled
    // in such a class, and (non-fast) has not passed that subject previously.
    // Entries for a section already emitted above are skipped (no double count).
    try { $resIrr = $db->query("SELECT id, student_number, name, firstname, middlename, lastname, course, year, section, status, is_irregular, irregular_subjects FROM landing_registrations WHERE is_irregular = 1 ORDER BY name ASC"); } catch (Throwable $e) { $resIrr = null; }
    if ($resIrr) {
        while ($irow = $resIrr->fetchArray(SQLITE3_ASSOC)) {
            $stuKey = strtolower(trim($irow['student_number'] ?? ''));
            $rowCourse = strtolower(trim($irow['course'] ?? ''));
            foreach (irregular_entries_of($irow) as $e) {
                $esec = strtolower($e['section']);
                if (!isset($allowedSections[$esec])) continue;
                if ($stuKey !== '' && isset($emittedStuSection[$stuKey . '|' . $esec])) continue;
                $targetYear = null;
                $hasTarget = false;
                foreach ($classMap as $cid => $c) {
                    if (($c['sec'] ?? '') !== $esec) continue;
                    if (!irregular_entry_matches_class($e, $c['sec'] ?? '', strtolower($c['code'] ?? ''), strtolower($c['name'] ?? ''))) continue;
                    $cgrade = strtolower(trim($c['grade'] ?? ''));
                    if ($rowCourse !== '' && $cgrade !== '' && $cgrade !== $rowCourse) continue;
                    $hasTarget = true;
                    if ($targetYear === null && ($c['year'] ?? '') !== '') $targetYear = $c['year'];
                }
                if (!$hasTarget) continue;
                // Fulfilled if enrolled in ANY current-DB class matching this entry
                $fulfilled = false;
                if ($stuKey !== '' && isset($enrolledClassIds[$stuKey])) {
                    foreach ($enrolledClassIds[$stuKey] as $ecid => $_) {
                        if (!isset($classMap[$ecid])) continue;
                        $ec = $classMap[$ecid];
                        if (!irregular_entry_matches_class($e, $ec['sec'] ?? '', strtolower($ec['code'] ?? ''), strtolower($ec['name'] ?? ''))) continue;
                        $fulfilled = true;
                        break;
                    }
                }
                if ($fulfilled) continue;
                if (!$isFast) {
                    $prevForEntry = null;
                    if (!empty($targetYear) && preg_match('/(\d{4})\s*-\s*(\d{4})/', $targetYear, $mPrevE)) $prevForEntry = ((int)$mPrevE[1]-1) . '-' . ((int)$mPrevE[2]-1);
                    if ($hasPassedInBackupGlobal($irow['student_number'] ?? '', $e['code'], $prevForEntry)) continue;
                }
                $irow['is_irregular'] = 1;
                $irow['irregular_label'] = $e['code'] . ' — ' . $e['name'];
                $irow['irregular_section'] = $e['section'];
                $gsec = $e['section'];
                if (!isset($grouped[$gsec])) $grouped[$gsec] = [];
                $grouped[$gsec][] = $irow;
                $total++;
            }
        }
    }
    // Include enrolled with DROPPED/... for retake — skip in fast mode (sidebar instant)
    if (!$isFast) {
    $res2 = $db->query("SELECT id, student_number, name, firstname, middlename, lastname, course, year, section, status FROM landing_registrations WHERE status='enrolled' ORDER BY section ASC, name ASC");
    if ($res2) {
        while ($row = $res2->fetchArray(SQLITE3_ASSOC)) {
            $secKey = strtolower(trim($row['section'] ?? ''));
            if (!isset($allowedSections[$secKey])) continue;
            $gradeKey2 = strtolower(trim($row['course'] ?? ''));
            $gsKey2 = $gradeKey2 . '|' . $secKey;
            if ($gradeKey2 !== '' && !isset($allowedGradeSections[$gsKey2]) && !isset($allowedGradeSections['|' . $secKey])) continue;
            $secSubjects = $sectionSubjects[$secKey] ?? [];
            $prevYearForSec2 = $sectionYears[$secKey] ?? null;
            if($prevYearForSec2 && preg_match('/(\d{4})\s*-\s*(\d{4})/', $prevYearForSec2, $mPrev2)) $prevYearForSec2=((int)$mPrev2[1]-1).'-'.((int)$mPrev2[2]-1); else $prevYearForSec2=null;
            $hasFailedForThisSection = false;
            if (!empty($secSubjects)) {
                foreach ($secSubjects as $subj) { if ($hasFailedInBackupGlobal($row['student_number'] ?? '', $subj, $prevYearForSec2)) { $hasFailedForThisSection = true; break; } }
            } else {
                $hasFailedForThisSection = $hasFailedInBackupGlobal($row['student_number'] ?? '', $row['course'] ?? '', $prevYearForSec2);
            }
            if (!$hasFailedForThisSection) continue;
            $key = strtolower(trim($row['student_number']??'')); if(isset($seenPendingKeys[$key])) continue;
            $lid=(int)($row['id']??0); if($lid>0) @$db->exec("UPDATE landing_registrations SET status='pending', updated_at=CURRENT_TIMESTAMP WHERE id='{$lid}'");
            $section = $row['section'] ?? 'No Section';
            if (!isset($grouped[$section])) $grouped[$section] = [];
            $grouped[$section][] = $row;
            $total++;
        }
    }
    }
    // NOTE: 75% cross-class duplicate auto-dismiss intentionally disabled — an
    // enrolled-elsewhere student stays assignable to pending subjects. Dedupe
    // is per-class/per-subject only (all-placed check above, entry fulfillment).
    // Perf: cache sidebar total in session so index.php renders the badge
    // with zero DB queries on next page load (JS refreshes it async).
    $_SESSION['sidebar_pending_cache'] = $total > 99 ? 99 : $total;
    $_SESSION['sidebar_pending_time'] = time();
    echo json_encode(['status' => 'success', 'total' => $total, 'grouped' => $grouped]);
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Server error: '.$e->getMessage()]);
}
?>
