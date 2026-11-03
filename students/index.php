<?php
// Configure session for student system to avoid conflicts with admin system
if (session_status() === PHP_SESSION_NONE) {
    $studentCookiePath = '/students/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($d !== '' && $d !== '.' && $d !== '/') {
            $studentCookiePath = $d . '/';
        }
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $studentCookiePath,
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
require_once('../DBConnection.php');
require_once(__DIR__ . '/device_keys.php'); // hardware-key helpers (attendance step-up verification below)
// NOTE: Do NOT require Actions.php here — it auto-dispatches on $_REQUEST['action']
// and would double-echo confirm_attendance ("{...success...}{...error...}").
// Handlers below require it only when needed.

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to user
ini_set('log_errors', 1);
ini_set('error_log', '../logs/student_errors.log');

// Custom error handler
function handleError($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    return true;
}
set_error_handler('handleError');

// Custom exception handler
function handleException($exception) {
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    // Show user-friendly error page
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h2>System Error</h2>';
    echo '<p>An error occurred while processing your request. Please try again later.</p>';
    echo '<p><a href="login.php">Return to Login</a></p>';
    echo '</body></html>';
    exit();
}
set_exception_handler('handleException');

// Check if student is logged in
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    // Allow access if this is a data fetch request without login
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_student_data') {
        // Continue to data fetch logic
    } else {
        header('Location: login.php');
        exit();
    }
}

// Handle student data fetch request without session
if (isset($_GET['action']) && $_GET['action'] === 'fetch_student_data' && isset($_GET['student_number'])) {
    $student_number = trim($_GET['student_number']);

    if (!empty($student_number) && preg_match('/^\d{2}-\d{5}$/', $student_number)) {
        $grades_data = fetchGradesFromAllDatabases($student_number);

        if (!empty($grades_data)) {
            echo json_encode([
                'success' => true,
                'student_data' => $grades_data[0]['student_data'],
                'found_in_db' => $grades_data[0]['database']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Student not found in any database'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid student number format. Please use XX-XXXXX format.'
        ]);
    }
    exit;
}

// In-portal chat relay: identical logic to ai_chat.php, served under the
// portal URL itself. Some hosts block the standalone endpoint file while
// the portal page runs fine; the chatbox tries both URLs automatically.
if (isset($_GET['action']) && $_GET['action'] === 'ai_reply') {
    require __DIR__ . '/ai_chat.php';
    exit;
}

// Attendance confirmation polling for logged-in student — only shows
// confirmations for classes where this student_number is enrolled.
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_attendance') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
        echo json_encode(['status'=>'error','msg'=>'Not logged in','pending'=>[]]);
        exit;
    }
    // Ensure attendance tables exist in current DB (in case faculty DB was just created)
    try { $tmpConn = new DBConnection(); } catch(Exception $e) {}
    require_once(__DIR__ . '/../Actions.php');
    $act = new Actions();
    echo $act->get_pending_attendance_for_student();
    exit;
}
// Attendance step-up challenge (phase 3.3): issues a single-use challenge
// bound to one pending attendance request. Numbers without an enrolled key
// get mode 'none' and keep the legacy confirm path (plain HTTP).
if (isset($_GET['action']) && $_GET['action'] === 'attend_options') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
        echo json_encode(['status'=>'error','msg'=>'Not logged in']);
        exit;
    }
    require_once(__DIR__ . '/device_keys.php');
    try {
        $pdoK = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
        $pdoK->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sid = (string)($_GET['session_id'] ?? '');
        $rid = (string)($_GET['request_id'] ?? '');
        if ($sid === '' || $rid === '') { echo json_encode(['status'=>'error','msg'=>'Missing attendance reference.']); exit; }
        // Bind the challenge to a REAL pending request for this student.
        try { $tmpConn = new DBConnection(); } catch(Exception $e) {}
        require_once(__DIR__ . '/../Actions.php');
        $act = new Actions();
        $found = false;
        try {
            $pj = json_decode((string)$act->get_pending_attendance_for_student(), true);
            if (is_array($pj) && ($pj['status'] ?? '') === 'success') {
                foreach ((array)($pj['pending'] ?? []) as $pr) {
                    if ((string)($pr['session_id'] ?? '') === $sid && (string)($pr['request_id'] ?? '') === $rid) { $found = true; break; }
                }
            }
        } catch (Exception $e) {}
        if (!$found) { echo json_encode(['status'=>'error','msg'=>'No matching pending attendance.']); exit; }
        $opt = hgs_attend_options($pdoK, (string)$_SESSION['student_number'], $sid, $rid);
        if (($opt['mode'] ?? 'none') !== 'authenticate') { echo json_encode(['status'=>'success','mode'=>'none']); exit; }
        echo json_encode(['status'=>'success','mode'=>'authenticate','challenge'=>$opt['challenge'],'rpId'=>$opt['rpId'],'credentialId'=>$opt['credentialId']]);
    } catch (Exception $e) { echo json_encode(['status'=>'error','msg'=>'Could not start the security check.']); }
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'confirm_attendance') {
    // Discard any stray output so response is exactly ONE JSON object
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
        echo json_encode(['status'=>'error','msg'=>'Not logged in']);
        exit;
    }
    // Step-up (phase 3.3): numbers with an enrolled hardware key must attach
    // a fresh assertion for THIS confirmation. The device PIN/biometric
    // unlocks the enclave locally; only the signature crosses the network.
    // Keyless numbers (plain HTTP) keep the legacy path.
    try {
        $pdoK = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
        $pdoK->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $snS = (string)$_SESSION['student_number'];
        if (hgs_key_get_active($pdoK, $snS)) {
            $kp = isset($_POST['webauthn_result']) ? json_decode((string)$_POST['webauthn_result'], true) : null;
            if (!is_array($kp)) {
                echo json_encode(['status'=>'error','msg'=>'Security-key check required: complete the device check on your enrolled phone, then confirm again.']);
                exit;
            }
            list($aOk, $aMsg) = hgs_attend_verify($pdoK, $kp, $snS, (string)($_POST['session_id'] ?? ''), (string)($_POST['request_id'] ?? ''), hgs_webauthn_rp(), hgs_webauthn_origin());
            if (!$aOk) {
                echo json_encode(['status'=>'error','msg'=>$aMsg]);
                exit;
            }
            hgs_log_device($pdoK, $snS, '', '', hgs_get_client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), 'key_attendance', 'Step-up signature verified for attendance confirm');
        }
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','msg'=>'Security check failed. Please try again.']);
        exit;
    }
    try { $tmpConn = new DBConnection(); } catch(Exception $e) {}
    require_once(__DIR__ . '/../Actions.php');
    $act = new Actions();
    $out = $act->confirm_attendance();
    // Defensive: if somehow double-encoded, keep only first JSON object
    $out = trim((string)$out);
    if (strpos($out, '}{') !== false) {
        $out = substr($out, 0, strpos($out, '}{') + 1);
    }
    echo $out;
    exit;
}

// Grade snapshot for change alerts: fingerprint of each scored quarter grade.
// The portal poller diffs these hashes against its last-seen set and raises a
// bell alert on new/changed quarters. Live-computed (same path as the grades
// page); published_grades is never read.
if (isset($_GET['action']) && $_GET['action'] === 'grade_snapshot') {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json');
    if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Not logged in', 'items' => []]);
        exit;
    }
    $snap = [];
    try {
        $snap_data = fetchGradesFromAllDatabases($_SESSION['student_number']);
        foreach ($snap_data as $wrapper) {
            $glist = isset($wrapper['grades']) && is_array($wrapper['grades']) ? $wrapper['grades'] : [$wrapper];
            foreach ($glist as $gg) {
                if (empty($gg['class_id'])) continue;
                $groups = [];
                foreach ((array)($gg['assessments'] ?? []) as $ax) {
                    $qn = $ax['quarter_name'] ?? ($ax['quarter'] ?? '');
                    if ($qn === '' || $qn === null) $qn = '—';
                    $groups[$qn][] = $ax;
                }
                foreach ($groups as $qn => $ll) {
                    $lv = compute_live_quarter($ll);
                    if (empty($lv['scored'])) continue;
                    $init = $lv['initial'] === null ? '' : number_format((float)$lv['initial'], 2, '.', '');
                    $snap[] = [
                        'h' => md5($gg['class_id'] . '|' . ($gg['subject_id'] ?? '') . '|' . $qn . '|' . $init . '|' . $lv['equiv'] . '|' . $lv['remarks']),
                        'subject' => $gg['subject_name'] ?? '',
                        'class' => $gg['class_name'] ?? '',
                        'quarter' => $qn,
                        'initial' => $init,
                        'equiv' => (string)$lv['equiv'],
                        'remarks' => $lv['remarks'],
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log('grade_snapshot: ' . $e->getMessage());
    }
    echo json_encode(['status' => 'success', 'items' => $snap, 'ts' => time()]);
    exit;
}

// Function to fetch grades from all databases with debug logging
function fetchGradesFromAllDatabases($student_number) {
    $all_grades = [];
    $databases_checked = [];

    error_log("=== Starting Grade Fetch for Student: " . $student_number . " ===");

    // Helper to resolve faculty name for a DB file (for display per subject).
    // NEVER returns folder names (Backups, previous_database, etc.) or file
    // names — only a real fullname from admin_list, else '—'.
    $resolveFaculty = function(string $db_file, string $fallback) {
        try {
            $main = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
            $main->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $base = basename($db_file);
            $dir = basename(dirname($db_file));
            // Try by db_path containing file, or by username = dir, or fullname
            $q = $main->prepare("SELECT fullname, username, db_path FROM admin_list WHERE role='faculty' AND (db_path LIKE :like OR username=:u OR fullname=:f) LIMIT 1");
            $q->execute([':like'=>'%'.$base.'%', ':u'=>$dir, ':f'=>$fallback]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['fullname'])) return $row['fullname'];
        } catch (Exception $e) {}
        // Administrator-owned data (db/backups/Administrator/): show the admin's real
        // fullname from the backup file itself (fallback: main DB), never the folder/username.
        if (strtolower(basename(dirname($db_file))) === 'administrator') {
            try {
                $own = new PDO('sqlite:' . $db_file);
                $own->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $qo = $own->prepare("SELECT fullname FROM admin_list WHERE role='admin' ORDER BY admin_id ASC LIMIT 1");
                $qo->execute();
                if (($ro = $qo->fetch(PDO::FETCH_ASSOC)) && !empty($ro['fullname'])) return $ro['fullname'];
            } catch (Exception $e) {}
            try {
                $m3 = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
                $q3 = $m3->prepare("SELECT fullname FROM admin_list WHERE role='admin' ORDER BY admin_id ASC LIMIT 1");
                $q3->execute();
                if (($r3 = $q3->fetch(PDO::FETCH_ASSOC)) && !empty($r3['fullname'])) return $r3['fullname'];
            } catch (Exception $e) {}
            return 'Admin';
        }
        // Fallback: fullname only, never username/db filename — even for admin show actual fullname
        if (strpos($fallback,'Main')!==false || stripos($fallback,'alex')!==false) {
            try {
                $m2 = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
                $q2 = $m2->prepare("SELECT fullname FROM admin_list WHERE role='admin' ORDER BY admin_id ASC LIMIT 1");
                $q2->execute();
                if($r2=$q2->fetch(PDO::FETCH_ASSOC)) return $r2['fullname'];
            } catch(Exception $e){}
            return 'Admin';
        }
        $dirName = basename(dirname($db_file));
        $dirLower = strtolower((string)$dirName);
        // Folder-name blocklist: these are storage locations, never instructors.
        // Never return them (previously showed "Backups" as INSTRUCTOR).
        $folderBlock = ['db', '.', 'backups', 'backup', 'faculty_backups', 'previous_database', 'administrator', 'grading_system'];
        if ($dirName && !in_array($dirLower, $folderBlock, true)) {
            // Use directory as username to try fullname again; else '—' (never the folder).
            try {
                $m2 = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
                $q2 = $m2->prepare("SELECT fullname FROM admin_list WHERE username=:u LIMIT 1");
                $q2->execute([':u'=>$dirName]);
                if(($r2=$q2->fetch(PDO::FETCH_ASSOC)) && !empty($r2['fullname'])) return $r2['fullname'];
            } catch(Exception $e){}
            return '—';
        }
        if (preg_match('/^Faculty:/',$fallback)) {
            $tmp = trim(str_replace('Faculty:','',$fallback));
            // Strip .db and year suffix, keep name part
            $tmp = preg_replace('/\.db.*$/','',$tmp);
            $tmp = preg_replace('/_\d{4}-\d{4}.*$/','',$tmp);
            // Backup/migration filenames are not instructor names.
            if (preg_match('/^(pre-admin-list-migration|admin_|upload_)/i', $tmp)) return '—';
            if ($tmp !== '' && stripos($tmp, 'backup') === false) return ucfirst($tmp);
            return '—';
        }
        return '—';
    };
    // 1. Check main database first
    try {
        error_log("Checking main database for grades...");
        $main_db_path = __DIR__ . '/../db/grading_db.db';
        $main_conn = new PDO('sqlite:' . $main_db_path);
        $main_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $main_conn->prepare("SELECT s.*, c.grade, c.section, sub.name as subject_name
                                  FROM student_list s
                                  JOIN class_list c ON s.class_id = c.class_id
                                  JOIN subjects sub ON c.subject_id = sub.subject_id
                                  WHERE s.student_number = ?
                                  LIMIT 1");

        $stmt->execute([$student_number]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            $grades = getGradesFromDatabase($main_conn, $student_number);
            $fac = $resolveFaculty($main_db_path, 'Main Database');
            foreach ($grades as &$gg) { $gg['faculty'] = $fac; $gg['db_label'] = 'Main Database'; } unset($gg);
            $all_grades[] = [
                'database' => 'Main Database',
                'student_data' => $student,
                'grades' => $grades
            ];
            $databases_checked[] = 'Main Database';
            error_log("Student found in main database with grades");
        } else {
            error_log("Student not found in main database");
        }

        $main_conn = null;
    } catch (Exception $e) {
        error_log("Error checking main database: " . $e->getMessage());
    }

    // 2. Check faculty databases — fetch ALL where student is enrolled (not just first)
    // Includes subfolders db/*/*.db and root db/*.db so all faculty DBs are covered.
    // Backup/migration copies are NEVER read here: they are stale duplicates of
    // live data and previously surfaced as INSTRUCTOR: Backups.
    $faculty_patterns = [__DIR__ . '/../db/*.db', __DIR__ . '/../db/*/*.db'];
    $faculty_seen = [];
    foreach ($faculty_patterns as $pat) {
        foreach (glob($pat) as $db_file) {
            $base = basename($db_file);
            if ($base === 'grading_db.db' || $base === 'landing_db.db' || $base === 'graduate_db.db' || isset($faculty_seen[$db_file])) continue;
            $normPath = strtolower(str_replace('\\', '/', (string)$db_file));
            if (strpos($normPath, '/backups/') !== false
                || strpos($normPath, '/backup/') !== false
                || strpos($normPath, '/previous_database/') !== false
                || strpos($normPath, '/faculty_backups/') !== false
                || preg_match('/^(pre-admin-list-migration|admin_|upload_)/i', $base)) continue;
            $faculty_seen[$db_file]=true;
            try {
                error_log("Checking faculty database: " . $db_file . " for grades");
                $faculty_conn = new PDO('sqlite:' . $db_file);
                $faculty_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $faculty_conn->prepare("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = ? LIMIT 1");
                $stmt->execute([$student_number]);
                $found = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $grades = getGradesFromDatabase($faculty_conn, $student_number);
                    if (!empty($grades)) {
                        $fac = $resolveFaculty($db_file, 'Faculty: ' . $base);
                        foreach ($grades as &$gg) { $gg['faculty'] = $fac; $gg['db_label'] = 'Faculty: ' . $base; } unset($gg);
                        $all_grades[] = ['database' => 'Faculty: ' . $base . ' (' . basename(dirname($db_file)) . ')', 'student_data' => $found, 'grades' => $grades];
                        $databases_checked[] = 'Faculty: ' . $db_file;
                        error_log("Student found in faculty database with grades: " . $db_file);
                        if ($student === null) $student = $found;
                    }
                } else {
                    error_log("Student not found in faculty database: " . $db_file);
                }
            } catch (Exception $e) {
                error_log("Error checking faculty database " . $db_file . ": " . $e->getMessage());
            }
        }
    }

    // 3-4. Backup databases intentionally NOT read in the student portal.
    // db/backups/..., db/previous_database/... are stale copies of live data;
    // including them duplicates quarter grades and mislabels INSTRUCTOR.
    // (Login/lookup endpoints may still use them to find a student number;
    // grades here come from live DBs only: main + faculty + Alex.)

    // 5. Alex database — consistent wrapper (was merging flat grades, now wrapper)
    $alex_db = __DIR__ . '/../db/alex/alex_2026-2027.db';
    if (file_exists($alex_db) && !isset($faculty_seen[$alex_db])) {
        try {
            $alex_conn = new PDO('sqlite:' . $alex_db);
            $alex_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $alex_conn->prepare("SELECT s.*, c.grade, c.section, sub.name as subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = ? LIMIT 1");
            $stmt->execute([$student_number]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $grades = getGradesFromDatabase($alex_conn, $student_number);
                if (!empty($grades)) {
                    $fac = $resolveFaculty($alex_db, 'Alex Database');
                    foreach ($grades as &$gg) { $gg['faculty'] = $fac; $gg['db_label'] = 'Alex Database'; } unset($gg);
                    $all_grades[] = ['database' => 'Alex Database', 'student_data' => $found, 'grades' => $grades];
                    $databases_checked[] = 'Alex Database';
                    if ($student === null) $student = $found;
                }
            }
            $alex_conn = null;
        } catch (Exception $e) {
            error_log("Error accessing alex database: " . $e->getMessage());
        }
    }

    // Log databases checked
    error_log("Student $student_number: Checked databases - " . implode(', ', $databases_checked));
    error_log("Student $student_number: Found " . count($all_grades) . " grade records");

    return $all_grades;
}

// Helper function to get grades from a specific database
function getGradesFromDatabase($conn, $student_number) {
    $grades = [];

    try {
        // Check which required tables exist (live computation needs assessment_list + grading_components; quarters are custom-made in quarter_list; published_grades is intentionally never read here)
        $tables_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('student_list', 'class_list', 'subjects', 'assessment_list', 'grading_components', 'quarter_list')");
        if (!$tables_check) {
            return $grades;
        }

        $required_tables = [];
        foreach ($tables_check->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $required_tables[] = $row['name'];
        }

        if (count(array_intersect(['student_list', 'class_list', 'subjects'], $required_tables)) < 3) {
            return $grades;
        }

        // Find student first
        $student_query = $conn->prepare("SELECT s.student_id, s.name, s.student_number,
                                             c.class_id, c.grade, c.section, c.year as class_year, c.semester as class_semester,
                                             sub.name as subject_name, sub.subject_id,
                                             (c.grade || ' - ' || c.section) as class_name
                                      FROM student_list s
                                      JOIN class_list c ON s.class_id = c.class_id
                                      JOIN subjects sub ON c.subject_id = sub.subject_id
                                      WHERE s.student_number = :student_number
                                      LIMIT 1");

        $student_query->execute([':student_number' => $student_number]);
        $student = $student_query->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return $grades; // Student not found in this database
        }

        // Custom-made quarters for this class (quarter_list) — never hardcode Prelim/Midterm
        $quarterMap = [];
        if (in_array('quarter_list', $required_tables)) {
            try {
                $qm = $conn->prepare("SELECT quarter, name FROM quarter_list WHERE class_id = :cid");
                $qm->execute([':cid' => $student['class_id']]);
                while ($qr = $qm->fetch(PDO::FETCH_ASSOC)) {
                    $quarterMap[(string)$qr['quarter']] = $qr['name'];
                }
            } catch (Exception $e) {
                error_log("Error fetching quarter_list: " . $e->getMessage());
            }
        }

        // Fetch assessments with marks for this class (works even if grades are not published)
        // Component weights come from component_subject_percentage when that table exists.
        $assessments = [];
        if (in_array('assessment_list', $required_tables) && in_array('grading_components', $required_tables)) {
            try {
                $hasCsp = false;
                try {
                    $tq = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='component_subject_percentage'");
                    $hasCsp = $tq && $tq->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) { $hasCsp = false; }
                $cspJoin = $hasCsp ? "LEFT JOIN component_subject_percentage csp ON csp.component_id = cc.component_id AND csp.subject_id = :subject_id" : "";
                $cspCol = $hasCsp ? ", csp.percentage as component_percentage" : "";
                $assess_query = $conn->prepare("SELECT a.assessment_id, a.name as assessment_name, a.quarter, a.hps, a.percentage,
                                                        cc.name as component_name{$cspCol},
                                                        ml.mark
                                                 FROM assessment_list a
                                                 JOIN grading_components cc ON a.component_id = cc.component_id
                                                 {$cspJoin}
                                                 LEFT JOIN mark_list ml ON ml.assessment_id = a.assessment_id AND ml.student_id = :student_id
                                                 WHERE a.class_id = :class_id
                                                 ORDER BY a.quarter, cc.name, a.assessment_id");
                $assessParams = [':student_id' => $student['student_id'], ':class_id' => $student['class_id']];
                if ($hasCsp) $assessParams[':subject_id'] = $student['subject_id'];
                $assess_query->execute($assessParams);
                $rawAssess = $assess_query->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawAssess as $arow) {
                    $qq = (string)($arow['quarter'] ?? '');
                    if ($qq === 'active') {
                        $arow['quarter_name'] = 'All Quarters';
                    } elseif (isset($quarterMap[$qq])) {
                        $arow['quarter_name'] = $quarterMap[$qq];
                    } elseif ($qq !== '') {
                        $arow['quarter_name'] = 'Quarter ' . $qq;
                    } else {
                        $arow['quarter_name'] = '—';
                    }
                    $assessments[] = $arow;
                }
            } catch (Exception $e) {
                error_log("Error fetching assessments: " . $e->getMessage());
            }
        }

        // Live-computation record: carries the student's assessments/marks.
        // Published grades are intentionally never read (portal computes live).
        $grades[] = [
            'student_id' => $student['student_id'],
            'student_name' => $student['name'],
            'student_number' => $student['student_number'],
            'class_id' => $student['class_id'],
            'class_name' => $student['class_name'],
            'class_grade' => $student['grade'],
            'section' => $student['section'],
            'subject_name' => $student['subject_name'],
            'subject_id' => $student['subject_id'],
            'year' => $student['class_year'] ?? null,
            'semester' => $student['class_semester'] ?? null,
            'quarter' => '',
            'quarter_name' => 'Overall',
            'transmuted_grade' => 0.0,
            'initial_grade' => 0.0,
            'remarks' => '',
            'published_date' => '',
            'academic_year' => '',
            'published_by' => null,
            'assessments' => $assessments
        ];

        $conn = null;
    } catch (Exception $e) {
        error_log("Error accessing database: " . $e->getMessage());
    }

    return $grades;
}

// Function to convert grade to equivalent (1.0-5.5 scale) using transmutation_table
function get_equivalent_from_transmutation($grade, $conn = null) {
    if ($grade === 'N/A' || !is_numeric($grade)) {
        return 'N/A';
    }

    $grade = round(floatval($grade), 2);

    try {
        // If no connection provided, create a new one to main database
        if ($conn === null) {
            $main_db_path = __DIR__ . '/../db/grading_db.db';
            $conn = new PDO('sqlite:' . $main_db_path);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        // Get the matching range from the active (CHED/DEPED) transmutation table
        static $trans_system = null;
        if ($trans_system === null) {
            $trans_system = function_exists('get_active_transmutation_system') ? get_active_transmutation_system($conn) : 'CHED';
        }
        $transmutation_query = $conn->query("SELECT grade, `from`, `to` FROM `transmutation_table` WHERE `system` = '{$trans_system}' ORDER BY `from` ASC");
        $equivalent = 5.0; // Default numeric value if no range matches

        while ($row = $transmutation_query->fetch(PDO::FETCH_ASSOC)) {
            $from = floatval($row['from']);
            $to   = floatval($row['to']);

            if ($grade >= $from && $grade <= $to) {
                $equivalent = floatval($row['grade']);
                break;
            }
        }

        // DepEd transmuted grades (60-100 scale) pass through untouched.
        if ($equivalent > 5.5) {
            return number_format($equivalent, 1);
        }

        // If equivalent is 5.5, treat as DROPPED
        if ($equivalent >= 5.5) {
            return 'DR';
        }

        return number_format($equivalent, 1);
    } catch (Exception $e) {
        error_log("Error in transmutation: " . $e->getMessage());
        return number_format(5.0, 1); // Fallback
    }
}

// Quarters are custom-made per class (quarter_list). Prefer quarter_name from DB; this is only a neutral fallback.
function getQuarterName($quarter) {
    if ($quarter === '' || $quarter === null) return 'Overall';
    if ((string)$quarter === 'active') return 'All Quarters';
    $q = intval($quarter);
    return $q > 0 ? 'Quarter ' . $q : 'Quarter ' . $quarter;
}

// Data-driven study insight for one class card: explains the current verdict
// from published grades + assessment marks, and prescribes what the student
// needs next quarter to pass. Pure function of already-fetched rows.
function build_student_insight($publishedRows, $assessments) {
    $esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    // Published quarters (numeric only), newest last.
    $pub = [];
    foreach ((array)$publishedRows as $rg) {
        $qn = isset($rg['quarter']) ? intval($rg['quarter']) : 0;
        if ($qn <= 0) continue;
        $pub[$qn] = [
            'name' => $rg['quarter_name'] ?? ('Quarter ' . $qn),
            'initial' => isset($rg['initial_grade']) && is_numeric($rg['initial_grade']) ? floatval($rg['initial_grade']) : null,
            'transmuted' => isset($rg['transmuted_grade']) && is_numeric($rg['transmuted_grade']) ? floatval($rg['transmuted_grade']) : null,
            'remarks' => strtoupper(trim($rg['remarks'] ?? '')),
        ];
    }
    ksort($pub);
    // Scale: DepEd transmuted grades live at 60-100, CHED equivalents at 1.0-5.5.
    $isDepEd = false;
    foreach ($pub as $p) {
        if ($p['transmuted'] !== null && $p['transmuted'] > 5.5) { $isDepEd = true; break; }
    }
    // Component stats from scored assessments.
    $comp = [];
    $pending = 0;
    $failed = [];
    foreach ((array)$assessments as $a) {
        $cn = trim($a['component_name'] ?? 'Component');
        if ($cn === '') $cn = 'Component';
        if (!isset($comp[$cn])) $comp[$cn] = ['score' => 0, 'hps' => 0, 'n' => 0, 'weight' => null];
        if (isset($a['component_percentage']) && is_numeric($a['component_percentage'])) {
            $comp[$cn]['weight'] = floatval($a['component_percentage']);
        }
        $mk = $a['mark'] ?? null;
        $hps = floatval($a['hps'] ?? 0);
        if ($mk === null || $mk === '' || !is_numeric($mk) || floatval($mk) <= 0) {
            if ($hps > 0) $pending++;
            continue;
        }
        $comp[$cn]['score'] += floatval($mk);
        $comp[$cn]['hps'] += $hps;
        $comp[$cn]['n']++;
        if ($hps > 0 && (floatval($mk) / $hps) < 0.75) {
            $failed[] = ['name' => $a['assessment_name'] ?? 'Assessment', 'mark' => floatval($mk), 'hps' => $hps];
        }
    }
    $compAvg = [];
    foreach ($comp as $cn => $c) {
        if ($c['n'] > 0 && $c['hps'] > 0) $compAvg[$cn] = 100 * $c['score'] / $c['hps'];
    }
    asort($compAvg);
    $weakest = $strongest = null;
    if (!empty($compAvg)) {
        reset($compAvg); $weakest = [key($compAvg), current($compAvg)];
        end($compAvg); $strongest = [key($compAvg), current($compAvg)];
    }
    // Quarter universe + next open quarter.
    $allQ = [];
    foreach (array_keys($pub) as $qn) $allQ[$qn] = true;
    $qNameOf = [];
    foreach ($pub as $qn => $p) $qNameOf[$qn] = $p['name'];
    foreach ((array)$assessments as $a) {
        $qn = isset($a['quarter']) ? intval($a['quarter']) : 0;
        if ($qn > 0) {
            $allQ[$qn] = true;
            if (!isset($qNameOf[$qn])) $qNameOf[$qn] = $a['quarter_name'] ?? ('Quarter ' . $qn);
        }
    }
    $lines = [];
    // 1. Verdict line.
    if (!empty($pub)) {
        end($pub); $lastQn = key($pub); $last = current($pub);
        $isPass = in_array($last['remarks'], ['PASSED', 'PASSED WITH DISTINCTION', 'PASSED WITH HONORS'], true);
        $isCond = ($last['remarks'] === 'CONDITIONAL');
        $tone = $isPass ? 'success' : ($isCond ? 'warning' : 'danger');
        $word = $isPass ? 'passing' : ($isCond ? 'conditional' : 'failing');
        $numTxt = $last['transmuted'] !== null
            ? ('transmuted ' . number_format($last['transmuted'], 1) . ($last['initial'] !== null ? ' (initial ' . number_format($last['initial'], 2) . ')' : ''))
            : 'no transmuted grade yet';
        $lines[] = ['tone' => $tone, 'html' => 'Currently <strong>' . $word . '</strong> — latest published quarter (' . $esc($last['name']) . ') is ' . $esc($numTxt) . ' → <strong>' . $esc($last['remarks'] === '' ? 'ungraded' : $last['remarks']) . '</strong>.'];
    } elseif (!empty($compAvg)) {
        $overall = array_sum(array_map(function($cn) use ($comp) { return 100 * $comp[$cn]['score'] / max(1, $comp[$cn]['hps']); }, array_keys($compAvg))) / count($compAvg);
        $tone = $overall >= 75 ? 'success' : 'warning';
        $lines[] = ['tone' => $tone, 'html' => 'No quarter published yet — across graded work you sit at about <strong>' . number_format($overall, 1) . '%</strong>.'];
    } else {
        $lines[] = ['tone' => 'info', 'html' => 'No graded work yet — complete the pending assessments below so a standing can form.'];
    }
    // 2. Why: weakest/strongest components + failed spotlights.
    if ($weakest) {
        $w = 'Weakest area: <strong>' . $esc($weakest[0]) . '</strong> at <strong>' . number_format($weakest[1], 1) . '%</strong>';
        if ($comp[$weakest[0]]['weight'] !== null) {
            $w .= ' (component weight ' . number_format($comp[$weakest[0]]['weight'], 0) . '%)';
            $recover = floatval($comp[$weakest[0]]['weight']) * (100 - $weakest[1]) / 100;
            if ($recover > 0.05) $w .= '; perfecting it recovers about <strong>' . number_format($recover, 1) . ' initial-grade points</strong>';
        }
        $w .= '.';
        if ($strongest && $strongest[0] !== $weakest[0]) {
            $w .= ' Strongest: <strong>' . $esc($strongest[0]) . '</strong> at <strong>' . number_format($strongest[1], 1) . '%</strong> — keep it up.';
        }
        $lines[] = ['tone' => 'info', 'html' => $w];
    }
    if (!empty($failed)) {
        $bits = [];
        foreach (array_slice($failed, 0, 3) as $f) {
            $bits[] = $esc($f['name']) . ' (' . number_format($f['mark'], 0) . '/' . number_format($f['hps'], 0) . ')';
        }
        $more = count($failed) > 3 ? ' and ' . (count($failed) - 3) . ' more' : '';
        $lines[] = ['tone' => 'danger', 'html' => 'Below 75% on: ' . implode(', ', $bits) . $more . ' — ask your instructor about remediation or makeup work.'];
    }
    if ($pending > 0) {
        $lines[] = ['tone' => 'warning', 'html' => '<strong>' . $pending . '</strong> assessment' . ($pending != 1 ? 's' : '') . ' still pending — ungraded work pulls every component average down.'];
    }
    // 3. Next-quarter prescription.
    if (!empty($allQ)) {
        $nums = array_keys($allQ);
        $N = max($nums);
        $nextQ = null;
        foreach ($nums as $qn) {
            if (!isset($pub[$qn])) { $nextQ = $qn; break; }
        }
        if ($nextQ !== null) {
            $done = count($pub);
            $remain = $N - $done;
            if ($remain > 0) {
                if ($isDepEd) {
                    $sum = 0; foreach ($pub as $p) $sum += $p['transmuted'] ?? 0;
                    $need = (75 * $N - $sum) / $remain;
                    $advice = $need <= 0
                        ? 'passing is already secured as long as remaining quarters are completed.'
                        : ($need > 100
                            ? 'passing on grades alone is out of reach (would need ' . number_format($need, 1) . ' average) — talk to your instructor about remediation.'
                            : 'average at least <strong>' . number_format(max(60, $need), 1) . '</strong> transmuted across the remaining ' . $remain . ' quarter' . ($remain != 1 ? 's' : '') . ' to finish at 75+.');
                } else {
                    $sum = 0; foreach ($pub as $p) $sum += $p['initial'] ?? 0;
                    $need = (75 * $N - $sum) / $remain;
                    $advice = $need <= 0
                        ? 'passing is already secured as long as remaining quarters are completed.'
                        : ($need > 100
                            ? 'passing on grades alone is out of reach (would need initial average ' . number_format($need, 1) . ') — talk to your instructor about remediation.'
                            : 'average at least initial <strong>' . number_format(max(0, $need), 1) . '</strong> across the remaining ' . $remain . ' quarter' . ($remain != 1 ? 's' : '') . ' to stay at 3.0 or better.');
                }
                $focus = $weakest ? ' Focus most on <strong>' . $esc($weakest[0]) . '</strong>.' : '';
                $lines[] = ['tone' => 'info', 'html' => 'Next: <strong>' . $esc($qNameOf[$nextQ] ?? ('Quarter ' . $nextQ)) . '</strong> — ' . $advice . $focus];
            } else {
                $lines[] = ['tone' => 'info', 'html' => 'All ' . $N . ' quarter' . ($N != 1 ? 's' : '') . ' are graded — final standing above.'];
            }
        }
    }
    if (empty($lines)) return '';
    $icons = ['success' => 'fa-check-circle', 'warning' => 'fa-exclamation-triangle', 'danger' => 'fa-times-circle', 'info' => 'fa-info-circle'];
    $html = '<div class="mt-3 p-3" style="background:linear-gradient(135deg, rgba(67,24,255,.05), rgba(5,205,153,.05)); border:1px solid var(--horizon-card-border); border-radius:12px">'
        . '<div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-robot" style="color:var(--horizon-brand-500)"></i>'
        . '<strong style="font-size:13px;color:var(--horizon-text-title)">AI Insight</strong>'
        . '<span style="font-size:10px;color:var(--horizon-text-muted);font-weight:600">generated from assessment data</span></div>';
    foreach ($lines as $ln) {
        $html .= '<div class="d-flex gap-2 mb-1" style="font-size:12px;color:var(--horizon-text-title)"><i class="fas ' . $icons[$ln['tone']] . ' mt-1" style="color:var(--horizon-text-muted)"></i><div>' . $ln['html'] . '</div></div>';
    }
    return $html . '</div>';
}

// Display helper: truncate (never round up) to 2 decimal places.
function trunc2dp($v) {
    if (!is_numeric($v)) return (string)$v;
    return number_format(floor(floatval($v) * 100 + 1e-9) / 100, 2, '.', '');
}

// Live quarter-grade computation for the student portal. Uses the SAME formula
// as the faculty class record (reports_content.php) and Report of Grades
// (report_of_grades.php) so both screens agree:
// per component: score = sum of marks (a missing/blank mark counts 0 AND its
// HPS still counts in the denominator); scaled = score/HPS*40+55 (55-95 band);
// a component with no graded work at all contributes 0 (never the 55 floor);
// initial = sum(scaled * component_weight/100), weights from
// component_subject_percentage. CHED: initial < 55.49 -> 5.0, except INC when
// a blank sits under a 30%+ component and initial is in [40, 55.49];
// otherwise the transmutation table decides. DepEd goes through its table.
// A blank mark is a missing/empty mark_list value — an explicitly encoded 0 is
// graded (failed) work and never triggers INC on its own.
// Returns ['has'=>bool, 'scored'=>bool, 'initial'=>float|null,
// 'equiv'=>string, 'equiv_num'=>float|null, 'remarks'=>string].
function compute_live_quarter($assessments) {
    static $pdoMain = null;
    static $system = null;
    if ($pdoMain === null) {
        try {
            $pdoMain = new PDO('sqlite:' . __DIR__ . '/../db/grading_db.db');
            $pdoMain->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) { $pdoMain = false; }
    }
    if ($system === null) {
        $system = 'CHED';
        try {
            if ($pdoMain) {
                $t = get_active_transmutation_system($pdoMain);
                if ($t === 'DEPED') $system = 'DEPED';
            }
        } catch (Exception $e) {}
    }
    $blank = ['has' => false, 'scored' => false, 'initial' => null, 'equiv' => 'N/A', 'equiv_num' => null, 'remarks' => 'NOT GRADED'];
    $list = is_array($assessments) ? array_values($assessments) : [];
    if (empty($list)) return $blank;
    $comp = [];
    foreach ($list as $a) {
        if (!is_array($a)) continue;
        $cn = trim((string)($a['component_name'] ?? 'Component'));
        if ($cn === '') $cn = 'Component';
        if (!isset($comp[$cn])) $comp[$cn] = ['score' => 0.0, 'hps' => 0.0, 'w' => null, 'graded' => false, 'blank' => false];
        if (isset($a['component_percentage']) && is_numeric($a['component_percentage'])) {
            $comp[$cn]['w'] = floatval($a['component_percentage']);
        }
        $hps = floatval($a['hps'] ?? 0);
        $mk = $a['mark'] ?? null;
        if ($mk === null || trim((string)$mk) === '') {
            $comp[$cn]['blank'] = true;
        } else {
            $comp[$cn]['graded'] = true;
            $comp[$cn]['score'] += floatval($mk);
        }
        $comp[$cn]['hps'] += $hps;
    }
    $weightSum = 0.0;
    $anyGraded = false;
    foreach ($comp as $c) {
        $weightSum += ($c['w'] === null ? 0.0 : $c['w']);
        if ($c['graded']) $anyGraded = true;
    }
    if ($weightSum <= 0) return $blank; // component weights not set: not computable
    if (!$anyGraded) return array_merge($blank, ['has' => true]);
    $initial = 0.0;
    foreach ($comp as $c) {
        if (!$c['graded']) continue; // no graded work: contributes 0, never the 55 floor
        $scaled = ($c['hps'] > 0) ? ($c['score'] / $c['hps'] * 40.0 + 55.0) : 0.0;
        $w = ($c['w'] === null ? 0.0 : $c['w']);
        $initial += $scaled * $w / 100.0;
    }
    $equiv = 'N/A';
    $equivNum = null;
    $remarks = null;
    if ($system === 'DEPED') {
        $equiv = get_equivalent_from_transmutation($initial, $pdoMain ?: null);
        $equivNum = is_numeric($equiv) ? floatval($equiv) : null;
    } else {
        $heavyBlank = false;
        foreach ($comp as $c) {
            if (!empty($c['blank']) && ($c['w'] ?? 0) >= 30) { $heavyBlank = true; break; }
        }
        if ($heavyBlank && $initial >= 40 && $initial <= 55.49) {
            $equiv = 'INC';
        } elseif ($initial < 55.49) {
            $equiv = '5.0';
            $equivNum = 5.0;
        } else {
            $equiv = get_equivalent_from_transmutation($initial, $pdoMain ?: null);
            $equivNum = is_numeric($equiv) ? floatval($equiv) : null;
        }
    }
    if ($equiv === 'INC') $remarks = 'INC';
    if ($remarks === null) {
        $remarks = remarks_for_grade($equivNum !== null ? $equivNum : 0, $system);
    }
    return ['has' => true, 'scored' => true, 'initial' => $initial, 'equiv' => (string)$equiv, 'equiv_num' => $equivNum, 'remarks' => $remarks];
}

// Round Half Up (halves away from zero), identical to report_of_grades.php /
// reports_content.php / export_to_word.php so the portal average matches the
// faculty average (e.g. (1.9 + 4.2)/2 = 3.05 -> 3.1).
if (!function_exists('round_half_up')) {
    function round_half_up($value, $precision = 0) {
        $value = (float)$value;
        if (!is_finite($value)) {
            return $value;
        }
        $precision = (int)$precision;
        $sign = ($value < 0) ? -1 : 1;
        $factor = pow(10, $precision);
        $scaled = round(abs($value) * $factor, 10);
        $out = floor($scaled + 0.5);
        return $sign * round($out / $factor, $precision);
    }
}

// Round Half to Even (banker's rounding) on the DECIMAL representation:
// halves go to the even neighbor — e.g. (1.8 + 1.5)/2 = 1.65 -> 1.6,
// 2.5 -> 2, 3.5 -> 4, 3.05 -> 3.0. Binary float dust (1.65 is really
// 1.6500000000000000222) must not push a true .5 tie off the midpoint, so
// the verdict is read off a 10-digit decimal expansion, never the float.
if (!function_exists('round_half_even')) {
    function round_half_even($value, $precision = 0) {
        $value = (float)$value;
        if (!is_finite($value)) {
            return $value;
        }
        $precision = min(6, max(0, (int)$precision));
        $sign = ($value < 0) ? -1 : 1;
        $digits = explode('.', sprintf('%.10F', abs($value)));
        $int = $digits[0];
        $frac = str_pad($digits[1] ?? '', 10, '0');
        $kept = substr($frac, 0, $precision);
        $rest = substr($frac, $precision);
        $d = (int)($rest[0] ?? '0');
        $hasTail = preg_match('/[1-9]/', substr($rest, 1)) === 1;
        if ($d > 5 || ($d === 5 && $hasTail)) {
            $roundUp = true;
        } elseif ($d === 5) {
            // Exact tie: round to even — bump only when the kept digit is odd.
            $last = $precision > 0 ? (int)substr($kept, -1) : (int)substr($int, -1);
            $roundUp = ($last % 2 === 1);
        } else {
            $roundUp = false;
        }
        if ($roundUp) {
            $all = $int . $kept;
            $i = strlen($all) - 1;
            while ($i >= 0) {
                $c = (int)$all[$i] + 1;
                if ($c <= 9) { $all[$i] = (string)$c; break; }
                $all[$i] = '0';
                $i--;
            }
            if ($i < 0) $all = '1' . $all;
            if ($precision > 0) {
                $kept = substr($all, -$precision);
                $int = substr($all, 0, strlen($all) - $precision);
                if ($int === '' || $int === false) $int = '0';
            } else {
                $int = $all;
            }
        }
        $out = $precision > 0 ? ($int . '.' . str_pad($kept, $precision, '0', STR_PAD_LEFT)) : $int;
        $num = $sign * (float)$out;
        return $num == 0 ? 0.0 : $num; // normalize -0 to 0
    }
}

// Get student information using multi-database search
$student_number = $_SESSION['student_number'] ?? '';
$student_info = null;

if (!empty($student_number)) {
    // Use the multi-database search function to find student info
    $student_grades = fetchGradesFromAllDatabases($student_number);
    if (!empty($student_grades)) {
        $student_info = $student_grades[0]['student_data'];
    }
}

// ---- Enrollment status label (student_list.status) ----
// One badge, two places (navbar + profile menu). Active students read
// "Officially Enrolled"; every other stored status gets its own word and
// colour, and unknown/empty values fall back to a neutral wording instead
// of hiding the row entirely.
$stuStatusRaw  = is_array($student_info) ? trim((string)($student_info['status'] ?? '')) : '';
$stuStatusKey  = strtolower($stuStatusRaw);
$stuStatusLabel = '';
$stuStatusCls  = 'secondary';
$stuStatusIcon = 'fa-circle-question';
if ($stuStatusKey === 'active') {
    $stuStatusLabel = 'Officially Enrolled';
    $stuStatusCls   = 'success';
    $stuStatusIcon  = 'fa-circle-check';
} elseif ($stuStatusKey === 'dropped') {
    $stuStatusLabel = 'Dropped';
    $stuStatusCls   = 'danger';
    $stuStatusIcon  = 'fa-circle-xmark';
} elseif ($stuStatusKey === 'graduated') {
    $stuStatusLabel = 'Graduated';
    $stuStatusCls   = 'info';
    $stuStatusIcon  = 'fa-graduation-cap';
} elseif ($stuStatusKey === 'transferred' || $stuStatusKey === 'transfer') {
    $stuStatusLabel = 'Transferred';
    $stuStatusCls   = 'warning';
    $stuStatusIcon  = 'fa-right-left';
} elseif ($stuStatusKey === 'irregular') {
    $stuStatusLabel = 'Irregular';
    $stuStatusCls   = 'warning';
    $stuStatusIcon  = 'fa-triangle-exclamation';
} elseif ($stuStatusKey === 'inactive' || $stuStatusKey === 'on leave' || $stuStatusKey === 'leave') {
    $stuStatusLabel = 'Inactive';
    $stuStatusCls   = 'warning';
    $stuStatusIcon  = 'fa-pause-circle';
} elseif ($stuStatusRaw !== '') {
    // Unknown value stored by the registrar — show it verbatim (capitalised)
    // rather than guessing a meaning.
    $stuStatusLabel = ucfirst($stuStatusRaw);
    $stuStatusCls   = 'warning';
    $stuStatusIcon  = 'fa-circle-info';
} else {
    // No row found / status column empty — still shown, never blank.
    $stuStatusLabel = $student_info ? 'No status on record' : 'Status unavailable';
    $stuStatusCls   = 'secondary';
    $stuStatusIcon  = 'fa-circle-question';
}
$stuStatusBadge = '<span class="badge rounded-pill bg-' . $stuStatusCls . ' bg-opacity-10 text-' . $stuStatusCls . ' border stu-status"><i class="fas ' . $stuStatusIcon . ' me-1"></i>' . htmlspecialchars($stuStatusLabel) . '</span>';

// Get published grades from all databases
$grades_data = fetchGradesFromAllDatabases($student_number);

// Sort grades by year and semester (most recent first)
usort($grades_data, function($a, $b) {
    if ($a['year'] != $b['year']) {
        return $b['year'] - $a['year']; // Descending year
    }
    return strcmp($b['semester'], $a['semester']); // Descending semester
});

// ---- Student portal router: one nav item, one page ----
$portal_pages = [
    'dashboard' => ['title' => 'Dashboard', 'crumb' => 'Dashboard', 'icon' => 'fas fa-columns', 'file' => 'pages/dashboard.php'],
    'grades' => ['title' => 'My Grades', 'crumb' => 'Grades', 'icon' => 'fas fa-chart-line', 'file' => 'pages/grades.php'],
    'quizzes' => ['title' => 'Quizzes', 'crumb' => 'Quizzes', 'icon' => 'fas fa-pen-square', 'file' => 'pages/quizzes.php'],
];
$page = $_GET['page'] ?? 'dashboard';
if (!isset($portal_pages[$page])) $page = 'dashboard';

// Lightweight AI context for every portal page (the grades page rebuilds a
// richer one before the shared echo at the end of <main>).
$aiContextClasses = [];
if (!empty($grades_data)) {
    foreach ($grades_data as $wrapper) {
        $glist = isset($wrapper['grades']) && is_array($wrapper['grades']) ? $wrapper['grades'] : [$wrapper];
        foreach ($glist as $gg) {
            if (empty($gg['class_id'])) continue;
            $aiContextClasses[] = [
                'subject' => $gg['subject_name'] ?? '',
                'class_name' => $gg['class_name'] ?? '',
                'faculty' => $gg['faculty'] ?? '',
                'published' => [],
                'assessments' => array_map(function($a) {
                    return ['component' => $a['component_name'] ?? '', 'weight' => $a['component_percentage'] ?? null, 'quarter' => $a['quarter'] ?? '', 'quarter_name' => $a['quarter_name'] ?? '', 'name' => $a['assessment_name'] ?? '', 'mark' => $a['mark'] ?? null, 'hps' => $a['hps'] ?? null, 'percentage' => $a['percentage'] ?? null];
                }, (array)($gg['assessments'] ?? [])),
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HORIZON — Student Portal | ESSUC</title>
    <script>
        // Apply saved Horizon theme before first paint (shares key with faculty side).
        try {
            if (localStorage.getItem('horizon-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (error) {}
    </script>
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon">
    <meta name="theme-color" content="#4318FF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HORIZON">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/horizon-theme.css" rel="stylesheet">
    <link href="../Font-Awesome-master/css/all.min.css" rel="stylesheet">
    <style>
        /* HORIZON Student Portal — professional whole-screen collage */
        .horizon-page-bg{background:var(--horizon-bg-main);min-height:100vh}
        #horizon-navbar{margin:12px 20px 18px 20px}
        .horizon-page-body{max-width:100%;margin:0 auto;padding:0 12px 30px 12px}
        main.horizon-page-body{max-width:none}
        .hero{position:relative;overflow:hidden}
        .hero::before{content:"";position:absolute;inset:0;background:radial-gradient(700px 240px at 16% 0%,rgba(67,24,255,.07),transparent 62%),radial-gradient(520px 220px at 96% 12%,rgba(5,205,153,.07),transparent 60%)}
        .hero>*{position:relative}
        .ini{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:var(--horizon-brand-linear);color:#fff;font-weight:800;font-size:1.15rem;box-shadow:0 10px 24px rgba(67,24,255,.22);flex-shrink:0}
        .chip{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);font-size:12px;font-weight:700;color:var(--horizon-text-title)}
        .stat{border:1px solid var(--horizon-card-border);border-radius:12px;background:var(--horizon-card-bg);padding:14px 16px}
        .stat .lbl{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--horizon-text-muted)}
        .stat .num{font-size:22px;font-weight:800;color:var(--horizon-text-title);line-height:1}
        .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .filter-bar .form-select{min-width:170px;flex:1 1 160px;height:42px}
        .grid{display:grid;grid-template-columns:1fr;gap:18px;align-items:start}
        .sub{border:1px solid var(--horizon-card-border);border-radius:16px;overflow:hidden;background:var(--horizon-card-bg);box-shadow:var(--horizon-card-shadow);display:flex;flex-direction:column; width:100%}
        .sub-hd{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;user-select:none;transition:background .16s}
        .sub-hd:hover{background:var(--horizon-table-hover)}
        /* Collapse chevron feedback: Bootstrap toggles .collapsed on the
           header itself — rotate the chevron so open/closed is visible.
           (The collapsible panel must NOT carry d-flex: display:flex
           !important would override .collapse's display:none and the panel
           could never hide — that was the broken toggle.) */
        .sub-hd .fa-chevron-down{transition:transform .2s ease}
        .sub-hd.collapsed .fa-chevron-down{transform:rotate(-90deg)}
        .sub-title{font-weight:800;font-size:14px;color:var(--horizon-text-title);display:flex;align-items:center;gap:10px;min-width:0}
        .sub-title .icon{width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--horizon-brand-50);color:var(--horizon-brand-500);border:1px solid #E9E3FF;flex-shrink:0}
        /* Merged: live-computed grades + assessments in one HORIZON container */
        .collage{display:flex;flex-direction:column;gap:16px;flex:1;border-top:1px solid var(--horizon-card-border);padding:16px;background:var(--horizon-card-bg)}
        .collage-left,.collage-right{padding:0;border:0;background:transparent;display:flex;flex-direction:column;gap:12px;min-width:0}
        /* Horizontal scrolling for quarter summary table (Quarter / Initial / Transmuted / Remarks) */
        .h-scroll{overflow:auto; -webkit-overflow-scrolling:touch; scrollbar-width:thin; scrollbar-color: var(--horizon-brand-200) transparent; border:1px solid var(--horizon-card-border); border-radius:10px; background:var(--horizon-card-bg); position:relative}
        .h-scroll::-webkit-scrollbar{height:8px; width:8px}
        .h-scroll::-webkit-scrollbar-track{background:transparent}
        .h-scroll::-webkit-scrollbar-thumb{background:var(--horizon-brand-200); border-radius:999px}
        .h-scroll::-webkit-scrollbar-thumb:hover{background:var(--horizon-brand-300)}
        .h-scroll table{margin:0; min-width:560px; width:100%}
        .h-scroll .horizon-table{border:0}
        /* Keep Final Average row visible on mobile horizontal scroll (was disappearing) */
        .h-scroll .horizon-table tbody tr:last-child{position:sticky; bottom:0; z-index:2; background:var(--horizon-table-hover) !important; box-shadow:0 -2px 8px rgba(15,23,42,.08)}
        .h-scroll .horizon-table tbody tr:last-child td{background:var(--horizon-table-hover) !important; border-top:2px solid var(--horizon-card-border)}
        .h-scroll .horizon-table tbody tr:last-child td:first-child{position:sticky; left:0; z-index:3; background:var(--horizon-table-hover) !important; border-right:1px solid var(--horizon-card-border)}
        /* Freeze Quarter column (first col) on horizontal scroll */
        .h-scroll .horizon-table th:first-child,
        .h-scroll .horizon-table td:first-child{
            position:sticky; left:0; z-index:2;
            background:var(--horizon-card-bg);
            border-right:1px solid var(--horizon-card-border);
            box-shadow: 2px 0 6px rgba(15,23,42,.06);
        }

        .h-scroll .horizon-table thead th:first-child{
            z-index:3; background:var(--horizon-brand-50);
            border-right:1px solid var(--horizon-brand-200);
        }

        .h-scroll .horizon-table tbody td:first-child{
            font-weight:800; color:var(--horizon-text-title);
        }
        /* ── Mobile Responsive (HORIZON) ── */
        @media (max-width:768px){
            .ini{width:48px;height:48px;font-size:1rem;border-radius:12px}
            .filter-bar .form-select{flex:1 1 100%}
            .horizon-page-body{padding:0 10px 20px 10px !important}
            #horizon-navbar{margin:10px 10px 14px 10px !important; padding:10px 12px !important}
            #horizon-navbar .horizon-page-title{font-size:15px !important}
            .hero .horizon-card-body{padding:16px !important}
            .chip{font-size:11px; padding:6px 10px}
            .stat{padding:12px}
            .stat .num{font-size:18px}
            .sub-hd{flex-direction:column; align-items:flex-start; gap:8px; padding:12px}
            .sub-hd .d-flex{width:100%; justify-content:flex-start}
            .collage{padding:12px}
            .table-responsive{border-radius:10px; -webkit-overflow-scrolling:touch}
            .horizon-table{font-size:11px}
            .horizon-table thead th{font-size:10px; padding:8px 6px; white-space:nowrap}
            .horizon-table tbody td{padding:8px 6px; font-size:11px}
            .horizon-table .badge{font-size:9px !important; padding:4px 6px !important}
        }
        @media (max-width:576px){
            .horizon-page-body{padding:0 8px 16px 8px !important}
            #horizon-navbar{margin:8px 8px 12px 8px !important; flex-wrap:wrap; gap:8px}
            .hero .ini{width:42px; height:42px; font-size:.95rem}
            .chip{font-size:10px; padding:5px 8px}
            .stat .lbl{font-size:10px}
            .stat .num{font-size:16px}
            .sub-hd{padding:10px 12px}
            .sub-title{font-size:13px}
            .collage{padding:10px; gap:12px}
            /* Stack table on very small screens: card-like rows */
            .table-responsive{overflow-x:auto}
            .horizon-table{min-width:620px}
        }
        @media (max-width:380px){
            .horizon-table{min-width:560px}
            .filter-bar{gap:8px}
        }
        /* Legacy green theme fully removed — HORIZON only */
        /* ── AI study-assistant chatbox ── */
        #aiChatFab{position:fixed;right:18px;bottom:18px;z-index:1200;width:56px;height:56px;border-radius:50%;border:none;background:var(--horizon-brand-linear);color:#fff;font-size:22px;box-shadow:0 10px 26px rgba(67,24,255,.35);cursor:pointer;display:flex;align-items:center;justify-content:center}
        #aiChatFab:hover{filter:brightness(1.08)}
        #aiChatPanel{position:fixed;right:18px;bottom:86px;z-index:1200;width:380px;max-width:calc(100vw - 24px);height:520px;max-height:calc(100vh - 120px);display:none;flex-direction:column;overflow:hidden;border-radius:18px;background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);box-shadow:0 18px 50px rgba(15,23,42,.25)}
        #aiChatPanel.open{display:flex}
        .ai-chat-head{display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--horizon-brand-linear);color:#fff}
        .ai-chat-avatar{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
        .ai-chat-title{font-weight:800;font-size:13px;line-height:1.2}
        .ai-chat-sub{font-size:10px;opacity:.85;font-weight:600}
        .ai-chat-close{margin-left:auto;background:transparent;border:none;color:#fff;font-size:18px;cursor:pointer;opacity:.85}
        #aiChatLog{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px}
        .ai-msg{max-width:88%;padding:8px 12px;border-radius:14px;font-size:12.5px;line-height:1.45}
        .ai-msg.ai{background:var(--horizon-table-hover);color:var(--horizon-text-title);border-bottom-left-radius:4px;align-self:flex-start}
        .ai-msg.user{background:var(--horizon-brand-500);color:#fff;border-bottom-right-radius:4px;align-self:flex-end}
        .ai-msg.typing{display:flex;gap:4px;align-items:center}
        .ai-msg.typing span{width:7px;height:7px;border-radius:50%;background:var(--horizon-text-muted);animation:ai-blink 1s infinite}
        .ai-msg.typing span:nth-child(2){animation-delay:.2s}
        .ai-msg.typing span:nth-child(3){animation-delay:.4s}
        @keyframes ai-blink{0%,100%{opacity:.3}50%{opacity:1}}
        #aiChatChips{display:flex;gap:6px;flex-wrap:wrap;padding:0 12px 8px}
        .ai-chip{border:1px solid var(--horizon-brand-200);background:var(--horizon-brand-50);color:var(--horizon-brand-500);border-radius:999px;font-size:11px;font-weight:700;padding:5px 10px;cursor:pointer}
        .ai-chat-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--horizon-card-border)}
        #aiChatInput{flex:1;border:1px solid var(--horizon-card-border);border-radius:999px;padding:9px 14px;font-size:13px;background:var(--horizon-card-bg);color:var(--horizon-text-title)}
        #aiChatSend{border:none;border-radius:50%;width:38px;height:38px;background:var(--horizon-brand-500);color:#fff;cursor:pointer;flex-shrink:0}
        #aiChatSend:disabled{opacity:.5;cursor:default}
        @media (max-width:576px){#aiChatPanel{right:12px;left:12px;width:auto;bottom:84px}#aiChatFab{right:12px;bottom:12px}}
        /* ── Sticky glass navbar (modern dashboard shell) ── */
        #horizon-navbar{position:sticky;top:10px;z-index:1040;background:var(--horizon-navbar-bg,var(--horizon-card-bg));-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border:1px solid var(--horizon-card-border);border-radius:18px}
        @supports (background:color-mix(in srgb, red 50%, transparent)){#horizon-navbar{background:color-mix(in srgb, var(--horizon-card-bg) 86%, transparent)}}
        /* ── ESSU brand logo (uploads/EssuLogo.jpg — 315×88 landscape) ──
           Sits left of the breadcrumb/title inside the navbar. The JPG has a
           white background, so it keeps a white chip in dark mode too. */
        .horizon-breadcrumb-area{flex-direction:row;align-items:center;gap:12px;min-width:0}
        .hz-brand{display:inline-flex;align-items:center;justify-content:center;height:44px;min-width:44px;padding:5px 10px;background:#fff;border:1px solid var(--horizon-card-border);border-radius:12px;flex-shrink:0;box-shadow:0 2px 8px rgba(15,23,42,.06);transition:border-color .16s,box-shadow .16s}
        .hz-brand:hover{border-color:var(--horizon-brand-500);box-shadow:0 4px 14px rgba(67,24,255,.18)}
        .hz-brand img{height:30px;width:auto;display:block;object-fit:contain}
        .hz-brand-txt{min-width:0}
        /* ── Enrollment status label (Officially Enrolled / other) ── */
        .stu-status{font-size:10px;font-weight:800;letter-spacing:.03em;padding:4px 10px;white-space:nowrap;vertical-align:middle}
        @media (max-width:576px){
            .horizon-breadcrumb-area{gap:8px}
            .hz-brand{height:38px;padding:4px 8px}
            .hz-brand img{height:24px}
            .stu-status{font-size:9px;padding:3px 8px}
        }
        /* ── Profile dropdown menu (Dashboard / Grades / Quizzes / Appearance / Logout) ── */
        .portal-menu-wrap{position:relative;display:flex;align-items:center}
        #portalMenuBtn{display:flex;align-items:center;gap:8px;border:1px solid var(--horizon-card-border);background:var(--horizon-card-bg);border-radius:999px;padding:4px 12px 4px 4px;cursor:pointer;min-height:44px;font-weight:800;font-size:12px;color:var(--horizon-text-title);transition:border-color .16s,box-shadow .16s}
        #portalMenuBtn:hover{border-color:var(--horizon-brand-500);box-shadow:0 4px 14px rgba(67,24,255,.18)}
        .pm-avatar{width:32px;height:32px;min-width:32px;border-radius:999px;background:var(--horizon-brand-linear);color:#fff;font-weight:800;font-size:12px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px var(--horizon-card-bg),0 0 0 4px var(--horizon-brand-500)}
        .pm-avatar-lg{width:44px;height:44px;min-width:44px;font-size:15px}
        .pm-chev{font-size:10px;color:var(--horizon-text-muted);transition:transform .18s}
        #portalMenuBtn.open .pm-chev{transform:rotate(180deg)}
        #portalMenu{position:absolute;right:0;top:calc(100% + 10px);width:292px;max-width:calc(100vw - 32px);background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.22);padding:8px;display:none;z-index:1050;overflow:hidden}
        #portalMenu.open{display:block;animation:pm-pop .18s ease}
        @keyframes pm-pop{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}
        .pm-head{display:flex;gap:12px;align-items:center;padding:12px;border-radius:12px;background:linear-gradient(135deg,rgba(67,24,255,.09),rgba(5,205,153,.09));margin-bottom:6px;min-width:0}
        .pm-head-txt{display:flex;flex-direction:column;gap:5px;min-width:0}
        .pm-head-txt strong{font-size:13px;color:var(--horizon-text-title);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .pm-chip{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:700;color:var(--horizon-text-muted);background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-radius:999px;padding:3px 9px;width:fit-content}
        .pm-item{display:flex;align-items:center;gap:12px;width:100%;padding:9px 10px;border:0;background:transparent;border-radius:12px;color:var(--horizon-text-title);font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;min-height:48px;text-align:left;font-family:inherit}
        .pm-item:hover{background:var(--horizon-table-hover)}
        .pm-item.active{background:var(--horizon-brand-50)}
        .pm-tile{width:36px;height:36px;min-width:36px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:var(--horizon-brand-50);color:var(--horizon-brand-500);font-size:15px}
        .pm-item.active .pm-tile{background:var(--horizon-brand-linear);color:#fff;box-shadow:0 6px 14px rgba(67,24,255,.3)}
        .pm-item.danger{color:#dc2626}
        .pm-item.danger:hover{background:#fef2f2}
        .pm-item.danger .pm-tile{background:#fee2e2;color:#dc2626}
        html[data-theme="dark"] .pm-item.danger:hover{background:rgba(220,38,38,.14)}
        .pm-sep{height:1px;background:var(--horizon-card-border);margin:6px 4px}
        .pm-switch{margin-left:auto;width:42px;height:24px;min-width:42px;border-radius:999px;background:var(--horizon-card-border);position:relative;transition:background .18s}
        .pm-switch .pm-knob{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.28);transition:left .18s}
        .pm-switch.on{background:var(--horizon-brand-500)}
        .pm-switch.on .pm-knob{left:21px}
        .pm-sec-head{display:flex;align-items:center;gap:8px;padding:8px 10px 4px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--horizon-text-muted)}
        .pm-sec-head>i{color:var(--horizon-brand-500)}
        #pmNotifRead{margin-left:auto;border:0;background:transparent;color:var(--horizon-brand-500);font-size:11px;font-weight:700;cursor:pointer}
        #pmNotifList{display:flex;flex-direction:column;gap:4px;max-height:220px;overflow-y:auto;padding:2px}
        .pm-notif{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:10px;background:var(--horizon-table-hover)}
        .pm-notif.unread{background:var(--horizon-brand-50);border:1px solid var(--horizon-brand-200)}
        .pm-notif .pm-tile{width:30px;height:30px;min-width:30px;font-size:13px;border-radius:10px}
        .pm-notif strong{display:block;font-size:12px;color:var(--horizon-text-title)}
        .pm-notif small{color:var(--horizon-text-muted);font-weight:600}
        .pm-notif time{display:block;font-size:10px;color:var(--horizon-text-muted);font-weight:600;margin-top:2px}
        .pm-empty{padding:10px;font-size:12px;color:var(--horizon-text-muted);text-align:center;font-weight:600}
        .pm-toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:1300;display:flex;gap:10px;align-items:center;background:var(--horizon-card-bg);border:1px solid var(--horizon-brand-200);border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.25);padding:10px 14px;max-width:min(430px,calc(100vw - 24px));cursor:pointer;animation:pm-toast-in .25s ease}
        @keyframes pm-toast-in{from{opacity:0;transform:translate(-50%,-8px)}to{opacity:1;transform:translate(-50%,0)}}
        @media (max-width:768px){
            #portalMenu{width:300px}
            .pm-item{min-height:50px;font-size:13.5px}
        }
        @media (max-width:576px){
            #horizon-navbar{gap:8px}
            #portalMenuBtn .pm-btn-name{display:none}
            #portalMenuBtn{padding:4px}
            #portalMenuBtn .pm-chev{display:none}
        }
        /* ── Horizon dark-mode patches for hardcoded portal colors ── */
        html[data-theme="dark"] main.horizon-page-body .text-dark{color:var(--horizon-text-title) !important}
        html[data-theme="dark"] .modal-content{background-color:var(--horizon-card-bg);color:var(--horizon-text-title);border-color:var(--horizon-card-border)}
        html[data-theme="dark"] .modal-content .text-muted{color:var(--horizon-text-muted) !important}
        html[data-theme="dark"] .modal-content .btn-close:not(.btn-close-white){filter:invert(1) grayscale(1)}
        html[data-theme="dark"] main.horizon-page-body .form-control,
        html[data-theme="dark"] main.horizon-page-body .form-select{background-color:var(--horizon-input-bg);border-color:var(--horizon-input-border);color:var(--horizon-input-text)}
        html[data-theme="dark"] main.horizon-page-body .form-control::placeholder{color:var(--horizon-text-muted)}
        /* ── Live grade summary row: one stable tint in both modes (incl. hover) ── */
        .horizon-table tbody tr.live-grade td{background:rgba(67,24,255,.06)}
        .horizon-table tbody tr.live-grade:hover td{background:rgba(67,24,255,.1)}
        html[data-theme="dark"] .horizon-table tbody tr.live-grade td{background:rgba(129,140,248,.14)}
        html[data-theme="dark"] .horizon-table tbody tr.live-grade:hover td{background:rgba(129,140,248,.22)}
        /* ── Grade health visuals: quarter progress bars + dashboard GPA ring ── */
        .qbar{height:6px;border-radius:999px;background:var(--horizon-table-hover);border:1px solid var(--horizon-card-border);overflow:hidden;margin:6px auto 0;max-width:120px}
        .qbar>i{display:block;height:100%;border-radius:999px;transition:width .4s ease}
        .qbar-ok{background:linear-gradient(90deg,#22c55e,#4ade80)}
        .qbar-warn{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
        .qbar-bad{background:linear-gradient(90deg,#ef4444,#f87171)}
        .qbar-lg{max-width:none;height:8px;margin:0;flex:1 1 100%}
        .gpa-ring{min-width:112px;text-align:center}
        .gpa-ring .badge{font-size:10px}
        /* ── Mobile grade cards: replace the table under 576px ── */
        .qcards{display:none}
        @media (max-width:576px){
            .h-scroll{display:none}
            .qcards{display:flex;flex-direction:column;gap:10px}
            .qcard{background:var(--horizon-card-bg);border:1px solid var(--horizon-card-border);border-left:4px solid var(--horizon-brand-200);border-radius:14px;padding:12px 14px;box-shadow:var(--horizon-card-shadow)}
            .qcard.ok{border-left-color:#22c55e}
            .qcard.warn{border-left-color:#f59e0b}
            .qcard.bad{border-left-color:#ef4444}
            .qcard.muted{border-left-color:var(--horizon-card-border)}
            .qcard-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
            .qcard-q{font-weight:800;font-size:12.5px;color:var(--horizon-text-title)}
            .qcard-mid{display:flex;align-items:baseline;gap:10px}
            .qcard-big{font-family:monospace;font-size:1.6rem;font-weight:800;line-height:1}
            .qcard-sub{font-size:11px;color:var(--horizon-text-muted);font-weight:700;line-height:1.5}
            .qcard .qbar{max-width:none;margin:10px 0 0}
            .qcard-empty{text-align:center;color:var(--horizon-text-muted);font-size:12px;padding:20px;border-style:dashed}
        }
        /* ── Modern filter dropdowns: native select (keeps mobile pickers + a11y), premium skin ── */
        .hz-select-wrap{position:relative;flex:1 1 160px;min-width:170px;display:flex;align-items:center}
        .hz-select-wrap .hz-ico{position:absolute;left:11px;width:26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--horizon-brand-50);color:var(--horizon-brand-500);font-size:12px;pointer-events:none;z-index:2}
        .hz-select-wrap .hz-chev{position:absolute;right:13px;font-size:11px;color:var(--horizon-text-muted);pointer-events:none;transition:transform .18s}
        .hz-select-wrap select.form-select{appearance:none;-webkit-appearance:none;width:100%;height:46px;border-radius:13px;border:1.5px solid var(--horizon-card-border);background-color:var(--horizon-card-bg);color:var(--horizon-text-title);font-size:13px;font-weight:700;padding-left:46px;padding-right:34px;cursor:pointer;transition:border-color .16s,box-shadow .16s}
        .hz-select-wrap select.form-select:hover{border-color:var(--horizon-brand-200,#c7d2fe)}
        .hz-select-wrap select.form-select:focus{border-color:var(--horizon-brand-500);box-shadow:0 0 0 3px rgba(67,24,255,.15);outline:none}
        .hz-select-wrap:focus-within .hz-chev{transform:rotate(180deg)}
        .filter-bar .btn{align-self:center}
        @media (max-width:768px){.hz-select-wrap{flex:1 1 100%;min-width:0}}
        /* ── Segmented pills: one-tap filters for short option lists ── */
        .seg-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1 1 200px}
        .seg-cap{font-size:10.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--horizon-text-muted)}
        .seg-pill{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:8px 16px;border-radius:999px;border:1.5px solid var(--horizon-card-border);background:var(--horizon-card-bg);color:var(--horizon-text-title);font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit;transition:border-color .16s,box-shadow .16s,background .16s;white-space:nowrap}
        .seg-pill:hover{border-color:var(--horizon-brand-500);text-decoration:none;color:var(--horizon-text-title)}
        .seg-pill.active{background:var(--horizon-brand-linear);border-color:transparent;color:#fff;box-shadow:0 6px 14px rgba(67,24,255,.28)}
        @media (max-width:768px){.seg-group{flex:1 1 100%}}
        /* ── Mobile footer tab-bar (labels always visible, auto-collapses on scroll) ── */
        .portal-tabbar{display:none}
        @media (max-width:768px){
            .portal-nav{display:none}
            .horizon-page-body{padding-bottom:calc(92px + env(safe-area-inset-bottom)) !important}
            .portal-tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:1050;background:var(--horizon-navbar-bg,var(--horizon-card-bg));-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border-top:1px solid var(--horizon-card-border);padding:6px 8px calc(8px + env(safe-area-inset-bottom));gap:4px;transition:transform .25s ease}
            body.portal-nav-hidden .portal-tabbar{transform:translateY(110%)}
            .portal-tabbar a{flex:1 1 0;display:flex;flex-direction:column;align-items:center;gap:3px;padding:7px 4px;border-radius:12px;font-size:10.5px;font-weight:700;color:var(--horizon-text-muted);text-decoration:none;position:relative}
            .portal-tabbar a i{font-size:19px;line-height:1}
            .portal-tabbar a.active{color:var(--horizon-brand-500);background:var(--horizon-brand-50)}
            .portal-tabbar a .badge{position:absolute;top:2px;right:calc(50% - 24px)}
            #aiChatFab{bottom:calc(88px + env(safe-area-inset-bottom)) !important;right:12px}
            #aiChatPanel{bottom:calc(156px + env(safe-area-inset-bottom)) !important;right:12px;left:12px;width:auto}
        }
    </style>
</head>
<body class="horizon-page-bg">
<nav id="horizon-navbar">
  <div class="horizon-breadcrumb-area">
    <a class="hz-brand" href="index.php?page=dashboard" title="ESSUC — back to dashboard" aria-label="ESSUC home">
      <img src="../uploads/EssuLogo.jpg" alt="Eastern Samar State University">
    </a>
    <div class="hz-brand-txt">
      <div class="horizon-breadcrumb"><a href="index.php">Student</a> <span style="opacity:.5">/</span> <?php echo htmlspecialchars($portal_pages[$page]['crumb']); ?></div>
      <h1 class="horizon-page-title" style="font-size:17px"><i class="fas <?php echo $portal_pages[$page]['icon']; ?> me-2" style="color:var(--horizon-brand-500)"></i> <?php echo htmlspecialchars($portal_pages[$page]['title']); ?> <?php echo $stuStatusBadge; ?></h1>
    </div>
  </div>
  <div class="horizon-nav-pill-toolbar">
    <?php $pmName = 'Student'; $pmNo = ''; $iniTmp = 'ST'; if(!empty($student_info['name'])): $pmName = trim($student_info['name']); $pmNo = trim((string)($student_info['student_number'] ?? '')); $pmParts = preg_split('/\s+/', $pmName); $iniTmp = strtoupper((($pmParts[0][0] ?? 'S')) . (($pmParts[1][0] ?? 'T'))); endif; ?>
    <a href="index.php?page=quizzes" class="horizon-nav-icon-btn position-relative" id="quizBellBtn" title="Quiz notifications" aria-label="Quiz notifications" style="background:var(--horizon-brand-50);color:var(--horizon-brand-500)"><i class="fas fa-bell"></i><span id="quizBellBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none;font-size:9px">0</span></a>
    <div class="portal-menu-wrap">
      <button type="button" id="portalMenuBtn" aria-haspopup="menu" aria-expanded="false" aria-label="Open navigation menu" title="Menu">
        <span class="pm-avatar" aria-hidden="true"><?php echo htmlspecialchars($iniTmp); ?></span>
        <span class="pm-btn-name d-none d-md-inline"><?php echo htmlspecialchars($pmName); ?></span>
        <i class="fas fa-chevron-down pm-chev" aria-hidden="true"></i>
      </button>
      <div id="portalMenu" role="menu" aria-label="Student portal menu">
        <div class="pm-head">
          <span class="pm-avatar pm-avatar-lg" aria-hidden="true"><?php echo htmlspecialchars($iniTmp); ?></span>
          <div class="pm-head-txt">
            <strong><?php echo htmlspecialchars($pmName); ?></strong>
            <span class="pm-chip"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($pmNo !== '' ? $pmNo : 'Student Portal'); ?></span>
            <?php echo $stuStatusBadge; ?>
          </div>
        </div>
        <?php foreach ($portal_pages as $key => $meta): ?>
        <a href="index.php?page=<?php echo $key; ?>" role="menuitem" class="pm-item<?php echo $key === $page ? ' active' : ''; ?>">
          <span class="pm-tile"><i class="fas <?php echo $meta['icon']; ?>"></i></span>
          <span><?php echo htmlspecialchars($meta['title']); ?></span>
          <?php if ($key === 'quizzes'): ?><span id="quizNavBadge" class="badge rounded-pill bg-danger" style="display:none;font-size:9px;margin-left:auto">0</span><?php endif; ?>
          <?php if ($key === $page): ?><i class="fas fa-check" style="font-size:11px;opacity:.55;margin-left:auto" aria-hidden="true"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <div class="pm-sep" role="separator"></div>
        <div class="pm-sec-head"><span><i class="fas fa-bell" aria-hidden="true"></i> Notifications</span><span id="pmNotifCount" class="badge rounded-pill bg-danger" style="display:none;font-size:9px">0 new</span><button type="button" id="pmNotifRead">Mark read</button></div>
        <div id="pmNotifList" aria-live="polite"><div class="pm-empty">No new alerts.</div></div>
        <div class="pm-sep" role="separator"></div>
        <button type="button" id="horizon-theme-toggle" class="pm-item" role="menuitem" aria-pressed="false" aria-label="Enable dark mode">
          <span class="pm-tile"><i class="fas fa-moon" id="horizon-theme-icon" aria-hidden="true"></i></span>
          <span>Dark mode</span>
          <span class="pm-switch" aria-hidden="true"><span class="pm-knob"></span></span>
        </button>
        <div class="pm-sep" role="separator"></div>
        <a href="logout.php" role="menuitem" class="pm-item danger">
          <span class="pm-tile"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></span>
          <span>Log out</span>
        </a>
      </div>
    </div>
  </div>
</nav>
<main class="horizon-page-body">
<div id="offlineBanner" class="horizon-flash-banner" role="alert" style="display:none;margin:0 0 14px 0;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E"><i class="fas fa-wifi" style="opacity:.6"></i><strong>No internet.</strong>&nbsp;<span id="offlineSavedAt">Showing your last synced data.</span><a href="" onclick="location.reload();return false;" style="margin-left:auto;font-weight:800;color:#92400E">Retry</a></div>
<?php if (isset($_SESSION['success_message'])): ?>
  <div class="horizon-flash-banner horizon-flash-success" role="alert" style="margin:0 0 14px 0"><i class="fas fa-check-circle"></i><strong>Success!</strong> <?php echo htmlspecialchars($_SESSION['success_message']); ?><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div>
  <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php // ---- Portal page (see $portal_pages router above) ----
$pageFile = $portal_pages[$page]['file'] ?? 'pages/dashboard.php';
if (!is_file(__DIR__ . '/' . $pageFile)) $pageFile = 'pages/dashboard.php';
include $pageFile;
?>
<!-- Attendance Confirmation Modal — only visible when faculty starts attendance for this student's class -->
<div class="modal fade" id="attendanceConfirmModal" tabindex="-1" aria-labelledby="attendanceConfirmModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px; overflow:hidden">
      <div class="modal-header" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff">
        <h5 class="modal-title" id="attendanceConfirmModalLabel"><i class="fas fa-clipboard-check me-2"></i> Attendance Check</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <div class="horizon-widget-icon-box brand mx-auto mb-3" style="width:64px;height:64px;min-width:64px;font-size:24px;"><i class="fas fa-user-check"></i></div>
        <h6 style="font-weight:800; color:var(--horizon-text-title)">Please Confirm Your Attendance</h6>
        <p id="attendanceConfirmText" style="font-size:13px; color:var(--horizon-text-muted); font-weight:600"></p>
        <p id="attendanceConfirmMeta" style="font-size:12px; color:var(--horizon-text-muted);"></p>
        <p class="small text-muted mb-0">Tap <strong>Confirm Attendance</strong> to mark yourself as <span class="badge bg-success">Present</span> for this class. Your instructor will see it immediately.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Later</button>
        <button type="button" class="btn btn-success rounded-pill" id="attendanceConfirmBtn" style="font-weight:800; padding:10px 24px"><i class="fas fa-check me-1"></i> Confirm Attendance</button>
      </div>
    </div>
  </div>
</div>
<div id="attendanceConfirmSuccess" style="display:none;" class="horizon-flash-banner horizon-flash-success mb-3"><i class="fas fa-check-circle"></i><span style="font-weight:700;font-size:13px">Attendance confirmed — you are marked Present. Thank you!</span></div>

    <!-- Animated attendance confirmed popup (replaces Network error alert) -->
    <div id="attendanceSuccessPopup" role="status" aria-live="polite" title="Tap to dismiss" style="display:none; position:fixed; inset:0; z-index:1090; align-items:center; justify-content:center; background:rgba(15,23,42,0.45); backdrop-filter:blur(2px); opacity:0; transition:opacity 0.25s ease; padding:16px">
        <div class="scan-check-card" style="background:#fff; border-radius:20px; padding:28px 40px 24px; text-align:center; max-width:320px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.35); transform:scale(0.7); transition:transform 0.28s cubic-bezier(0.34,1.56,0.64,1)">
            <svg class="scan-check-svg" viewBox="0 0 52 52" style="width:88px;height:88px;margin-bottom:10px"><circle class="scan-check-circle" cx="26" cy="26" r="24" style="stroke:#198754;stroke-width:3;fill:none;stroke-dasharray:151;stroke-dashoffset:151;animation:scan-draw-circle 0.55s ease-out forwards"/><path class="scan-check-mark" d="M14 27l8 8 16-16" style="stroke:#198754;stroke-width:4.5;fill:none;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:42;stroke-dashoffset:42;animation:scan-draw-check 0.35s 0.5s ease-out forwards"/></svg>
            <div style="font-weight:800; font-size:1.05rem; color:#1e293b; margin-bottom:4px">Attendance Confirmed!</div>
            <div style="font-weight:700; font-size:0.9rem; color:#198754">You are marked Present</div>
            <div style="font-size:0.72rem; color:#94a3b8; margin-top:10px">Tap anywhere to dismiss</div>
        </div>
    </div>

        <footer class="text-center mt-4" style="font-size:11px;color:var(--horizon-text-muted);font-weight:600">© <?php echo date('Y'); ?> ESSUC Grading System — HORIZON Student Portal • Need help? Contact your instructor.</footer>
    </main>

    <?php // Shared AI context (built light above; grades page rebuilds richer) ?>
    <script>
    window.HORIZON_AI_CONTEXT = <?php echo json_encode(['student_name' => $student_info['name'] ?? '', 'classes' => $aiContextClasses ?? []], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script>
    // Unified notification badge: bell shows open quizzes + unread grade alerts.
    window.HorizonBadges = (function(){
        var quiz = 0, grades = 0;
        function paint(){
            var t = quiz + grades;
            var b = document.getElementById('quizBellBadge');
            if (b) {
                if (t > 0) { b.style.display = ''; b.textContent = t > 9 ? '9+' : t; }
                else { b.style.display = 'none'; }
            }
            var btn = document.getElementById('quizBellBtn');
            if (btn) btn.title = t > 0 ? (t + ' notification' + (t !== 1 ? 's' : '') + ' — quizzes & grades') : 'Notifications — quizzes & grades';
            var n = document.getElementById('pmNotifCount');
            if (n) {
                if (grades > 0) { n.style.display = ''; n.textContent = grades + ' new'; }
                else { n.style.display = 'none'; }
            }
        }
        return {
            setQuiz: function(n){ quiz = n || 0; paint(); },
            setGrades: function(n){ grades = n || 0; paint(); }
        };
    })();
    </script>
    <script>
    // Global quiz poller: bell + nav badges on every page; renders into each
    // [data-quiz-list] container on the current page (data-limit caps items).
    (function(){
        function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function setBadges(openCount){
            document.querySelectorAll('[data-quiz-count]').forEach(function(el){ el.textContent = openCount + ' new'; });
            if (window.HorizonBadges) window.HorizonBadges.setQuiz(openCount);
            ['quizNavBadge', 'quizTabBadge'].forEach(function(id){
                var b = document.getElementById(id);
                if (!b) return;
                if (openCount > 0){ b.style.display = ''; b.textContent = openCount > 9 ? '9+' : openCount; }
                else { b.style.display = 'none'; }
            });
        }
        function cardHtml(q){
            var done = (q.attempt_status === 'submitted' || q.attempt_status === 'auto_submitted');
            var url = 'take_quiz.php?q=' + encodeURIComponent(q.quiz_id) + '&c=' + encodeURIComponent(q.class_id) + '&k=' + encodeURIComponent(q.db_key);
            var h = '<div class="d-flex align-items-center gap-3 flex-wrap p-3" style="border:1px solid var(--horizon-card-border);border-radius:12px;background:' + (done ? 'var(--horizon-table-hover)' : 'linear-gradient(135deg, rgba(67,24,255,.06), rgba(5,205,153,.06))') + '">';
            h += '<span class="horizon-widget-icon-box ' + (done ? 'green' : 'brand') + '" style="width:42px;height:42px;min-width:42px;font-size:16px"><i class="fas ' + (done ? 'fa-check-circle' : 'fa-pen-square') + '"></i></span>';
            h += '<div class="flex-grow-1" style="min-width:220px"><div class="fw-bold" style="font-size:13px;color:var(--horizon-text-title)">' + esc(q.title) + '</div>';
            h += '<div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600">' + esc(q.subject_name || '') + ' · ' + esc(q.class_label || '') + ' · ' + esc(q.quiz_type_label || '') + ' · ' + esc(q.num_items) + ' items' + (q.time_limit_minutes > 0 ? ' · ' + esc(q.time_limit_minutes) + ' min limit' : '') + '</div>';
            if (done) h += '<div style="font-size:11px;font-weight:700;color:var(--horizon-green-600,#15803d)">Submitted — score ' + esc(q.attempt_score || '') + '</div>';
            h += '</div>';
            h += done
                ? '<a class="btn btn-sm btn-secondary" href="' + url + '"><i class="fas fa-eye me-1"></i>View</a>'
                : '<a class="btn btn-sm btn-primary fw-bold" href="' + url + '"><i class="fas fa-play me-1"></i>Take quiz</a>';
            return h + '</div>';
        }
        function render(list){
            var open = list.filter(function(q){ return !q.attempt_status || q.attempt_status === 'in_progress'; });
            var done = list.filter(function(q){ return q.attempt_status === 'submitted' || q.attempt_status === 'auto_submitted'; });
            var ordered = open.concat(done);
            setBadges(open.length);
            document.querySelectorAll('[data-quiz-list]').forEach(function(box){
                var limit = parseInt(box.getAttribute('data-limit') || '0', 10);
                if (!ordered.length){
                    box.innerHTML = '<div class="text-center py-3" style="color:var(--horizon-text-muted);font-size:12px"><i class="fas fa-bell-slash mb-2 d-block" style="font-size:20px"></i>No active quizzes for your class right now.</div>';
                    return;
                }
                var items = limit > 0 ? ordered.slice(0, limit) : ordered;
                var h = '<div class="d-flex flex-column gap-2">';
                items.forEach(function(q){ h += cardHtml(q); });
                h += '</div>';
                if (limit > 0 && ordered.length > limit){
                    h += '<a href="index.php?page=quizzes" class="btn btn-sm btn-secondary w-100 mt-2">View all ' + ordered.length + ' quizzes <i class="fas fa-arrow-right ms-1"></i></a>';
                }
                box.innerHTML = h;
            });
        }
        function poll(){
            fetch('quiz_api.php?action=get_active_quizzes', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r){ return r.text(); })
                .then(function(t){ var j = null; try { j = JSON.parse(t); } catch (e) { return; } if (!j || j.status !== 'success') return; render(j.quizzes || []); })
                .catch(function(){});
        }
        poll();
        setInterval(poll, 15000);
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
    })();
    </script>
    <script>
    // Grade change alerts: diffs the grade_snapshot fingerprint against the
    // last-seen set (localStorage). First run seeds the baseline silently;
    // new/changed quarters raise a toast + dropdown entry + bell count.
    (function(){
        var SN = window.HORIZON_SN || 'anon';
        var K_H = 'hz_grade_hashes_' + SN;
        var K_A = 'hz_grade_alerts_' + SN;
        function load(k){ try { var v = JSON.parse(localStorage.getItem(k) || 'null'); return v; } catch (e) { return null; } }
        function save(k, v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
        function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function ago(ts){
            var m = Math.max(0, Math.floor((Date.now() - ts) / 60000));
            if (m < 1) return 'just now';
            if (m < 60) return m + 'm ago';
            var h = Math.floor(m / 60);
            if (h < 24) return h + 'h ago';
            return Math.floor(h / 24) + 'd ago';
        }
        function unreadCount(){ var a = load(K_A) || []; return a.filter(function(x){ return x.unread; }).length; }
        function renderList(){
            var box = document.getElementById('pmNotifList');
            if (!box) return;
            var a = load(K_A) || [];
            if (!a.length) { box.innerHTML = '<div class="pm-empty">No new alerts.</div>'; return; }
            var h = '';
            a.slice(0, 8).forEach(function(x){
                var ok = x.tone === 'ok';
                h += '<div class="pm-notif' + (x.unread ? ' unread' : '') + '">'
                    + '<span class="pm-tile" style="' + (ok ? '' : 'background:#fee2e2;color:#dc2626') + '"><i class="fas ' + (ok ? 'fa-check-circle' : 'fa-exclamation-triangle') + '"></i></span>'
                    + '<div style="min-width:0"><strong>' + esc(x.title) + '</strong><small>' + esc(x.sub) + '</small><time>' + esc(ago(x.ts)) + '</time></div>'
                    + '</div>';
            });
            box.innerHTML = h;
        }
        function paint(){ if (window.HorizonBadges) window.HorizonBadges.setGrades(unreadCount()); renderList(); }
        function toast(x){
            var ok = x.tone === 'ok';
            var d = document.createElement('div');
            d.className = 'pm-toast';
            d.setAttribute('role', 'status');
            d.innerHTML = '<span class="pm-tile" style="' + (ok ? '' : 'background:#fee2e2;color:#dc2626') + '"><i class="fas ' + (ok ? 'fa-check-circle' : 'fa-exclamation-triangle') + '"></i></span>'
                + '<div style="min-width:0"><strong style="font-size:12.5px;color:var(--horizon-text-title)">' + esc(x.title) + '</strong>'
                + '<div style="font-size:11px;color:var(--horizon-text-muted);font-weight:600">' + esc(x.sub) + '</div></div>';
            d.addEventListener('click', function(){ window.location.href = 'index.php?page=grades'; });
            document.body.appendChild(d);
            setTimeout(function(){ if (d.parentNode) d.parentNode.removeChild(d); }, 6500);
        }
        function check(){
            fetch('index.php?action=grade_snapshot', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r){ return r.text(); })
                .then(function(t){
                    var j = null;
                    try { j = JSON.parse(t); } catch (e) { return; }
                    if (!j || j.status !== 'success' || !Array.isArray(j.items)) return;
                    var prev = load(K_H);
                    var map = {};
                    j.items.forEach(function(it){ map[it.h] = it; });
                    if (prev === null) { save(K_H, map); paint(); return; }
                    var alerts = load(K_A) || [];
                    j.items.forEach(function(it){
                        if (prev[it.h]) return;
                        var old = null;
                        Object.keys(prev).forEach(function(h){
                            var p = prev[h];
                            if (p.subject === it.subject && p.class === it.class && p.quarter === it.quarter) old = p;
                        });
                        var ok = /PASSED/.test(it.remarks || '');
                        alerts.unshift({
                            unread: true, ts: Date.now(), tone: ok ? 'ok' : 'bad',
                            title: (old ? 'Grade updated' : 'New grade posted') + ': ' + it.subject,
                            sub: it.quarter + ' — Initial ' + it.initial + ' · ' + it.equiv + ' (' + it.remarks + ')'
                        });
                        toast(alerts[0]);
                    });
                    if (alerts.length > 12) alerts = alerts.slice(0, 12);
                    save(K_A, alerts);
                    save(K_H, map);
                    paint();
                })
                .catch(function(){});
        }
        var readBtn = document.getElementById('pmNotifRead');
        if (readBtn) readBtn.addEventListener('click', function(){
            var a = load(K_A) || [];
            a.forEach(function(x){ x.unread = false; });
            save(K_A, a);
            paint();
        });
        paint();
        check();
        setInterval(check, 60000);
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) check(); });
    })();
    </script>
    <script>
    // Horizon dark-mode toggle (same localStorage key as the faculty side).
    // The toggle lives inside the profile dropdown menu as a switch row.
    (function(){
        var btn = document.getElementById('horizon-theme-toggle');
        var icon = document.getElementById('horizon-theme-icon');
        function paint(){
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.body.classList.toggle('dark', dark);
            if (btn){
                var sw = btn.querySelector('.pm-switch');
                if (sw) sw.classList.toggle('on', dark);
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                btn.setAttribute('aria-label', dark ? 'Enable light mode' : 'Enable dark mode');
                btn.title = dark ? 'Enable light mode' : 'Enable dark mode';
            }
            if (icon) icon.className = 'fas fa-' + (dark ? 'sun' : 'moon');
        }
        paint();
        if (btn) btn.addEventListener('click', function(){
            var dark = document.documentElement.getAttribute('data-theme') !== 'dark';
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            try { localStorage.setItem('horizon-theme', dark ? 'dark' : 'light'); } catch (e) {}
            paint();
            // Reload so the whole portal re-renders in the new theme.
            location.reload();
        });
    })();
    // Profile dropdown menu: toggle, close on outside click / Escape / nav.
    (function(){
        var btn = document.getElementById('portalMenuBtn');
        var menu = document.getElementById('portalMenu');
        if (!btn || !menu) return;
        function set(open){
            menu.classList.toggle('open', open);
            btn.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            set(!menu.classList.contains('open'));
        });
        document.addEventListener('click', function(e){
            if (menu.classList.contains('open') && !menu.contains(e.target)) set(false);
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') set(false);
        });
        menu.addEventListener('click', function(e){
            if (e.target.closest('a')) set(false);
        });
    })();
    </script>

    <button id="aiChatFab" aria-label="Open study assistant" title="Ask about your grades"><i class="fas fa-robot"></i></button>
    <div id="aiChatPanel" role="dialog" aria-label="Study assistant chat">
      <div class="ai-chat-head">
        <div class="ai-chat-avatar"><i class="fas fa-robot"></i></div>
        <div><div class="ai-chat-title">Study Assistant</div><div class="ai-chat-sub">Answers from your records · not a human</div></div>
        <button class="ai-chat-close" id="aiChatClose" aria-label="Close chat">×</button>
      </div>
      <div id="aiChatLog" aria-live="polite"></div>
      <div id="aiChatChips"></div>
      <form class="ai-chat-form" id="aiChatForm" autocomplete="off">
        <input id="aiChatInput" type="text" maxlength="500" placeholder="Ask about your grades…" aria-label="Type your question">
        <button id="aiChatSend" type="submit" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
      </form>
    </div>

    <script src="../js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        var fab = document.getElementById('aiChatFab');
        var panel = document.getElementById('aiChatPanel');
        var log = document.getElementById('aiChatLog');
        var chipsBox = document.getElementById('aiChatChips');
        var form = document.getElementById('aiChatForm');
        var input = document.getElementById('aiChatInput');
        var sendBtn = document.getElementById('aiChatSend');
        if (!fab || !panel) return;
        var greeted = false;
        var transcript = [];
        var ctxSent = false;
        function scrollLog() { log.scrollTop = log.scrollHeight; }
        function addAi(html, source) {
            var d = document.createElement('div');
            d.className = 'ai-msg ai';
            d.innerHTML = html;
            if (source === 'ai') {
                var tag = document.createElement('div');
                tag.style.cssText = 'font-size:9px;color:var(--horizon-text-muted);font-weight:700;margin-top:4px;';
                tag.textContent = 'AI · online';
                d.appendChild(tag);
            }
            log.appendChild(d);
            scrollLog();
            return d;
        }
        function addUser(text) {
            var d = document.createElement('div');
            d.className = 'ai-msg user';
            d.textContent = text;
            log.appendChild(d);
            scrollLog();
        }
        function setChips(list) {
            chipsBox.innerHTML = '';
            (list || []).slice(0, 3).forEach(function(c) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ai-chip';
                b.textContent = c;
                b.addEventListener('click', function() { send(c); });
                chipsBox.appendChild(b);
            });
        }
        function greet() {
            if (greeted) return;
            greeted = true;
            addAi('Hi! I can read your live-computed grades and assessment marks — and I can also answer general study questions. Ask me how you are doing, what you need next quarter, or anything you are curious about.');
            setChips(['How am I doing overall?', 'What is still pending?', 'Who is the president of the Philippines?']);
        }
        function toggle(open) {
            panel.classList.toggle('open', open);
            if (open) { greet(); setTimeout(function() { input.focus(); }, 100); }
        }
        fab.addEventListener('click', function() { toggle(!panel.classList.contains('open')); });
        document.getElementById('aiChatClose').addEventListener('click', function() { toggle(false); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') toggle(false); });
        function send(text) {
            text = (text || '').trim();
            if (!text) return;
            addUser(text);
            setChips([]);
            input.value = '';
            sendBtn.disabled = true;
            var typing = document.createElement('div');
            typing.className = 'ai-msg ai typing';
            typing.innerHTML = '<span></span><span></span><span></span>';
            log.appendChild(typing);
            scrollLog();
            // Form-encoded + base64-wrapped (not raw JSON) so hosting
            // firewalls that pattern-match JSON bodies cannot 403 it.
            // If POST is refused, retry once via GET (query string).
            function b64(s) {
                s = String(s);
                try {
                    var bytes = new TextEncoder().encode(s);
                    var bin = '';
                    bytes.forEach(function(b) { bin += String.fromCharCode(b); });
                    return btoa(bin);
                } catch (e) {
                    return btoa(unescape(encodeURIComponent(s)));
                }
            }
            function buildForm(full) {
                var f = new FormData();
                f.append('enc', 'b64');
                f.append('message', b64(text));
                f.append('history', b64(JSON.stringify(full ? transcript.slice(-6) : transcript.slice(-2))));
                if (full || !ctxSent) {
                    f.append('context', b64(JSON.stringify(window.HORIZON_AI_CONTEXT || {})));
                }
                return f;
            }
            function readRes(r) {
                return r.text().then(function(t) {
                    var j = null;
                    try { j = JSON.parse(t); } catch (e) {}
                    if (!j) {
                        var err = new Error('bad-response');
                        err.http = r.status;
                        throw err;
                    }
                    return { http: r.status, data: j };
                });
            }
            function postUrl(u, f) {
                return fetch(u, { method: 'POST', credentials: 'same-origin', body: f }).then(readRes);
            }
            function getUrl(u, f) {
                var params = new URLSearchParams();
                f.forEach(function(v, k) { params.append(k, v); });
                return fetch(u + (u.indexOf('?') === -1 ? '?' : '&') + params.toString(), { credentials: 'same-origin' }).then(readRes);
            }
            function handleRes(res) {
                typing.remove();
                var data = res.data;
                if (data && data.reply) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.reply.html || '';
                    var plain = (tmp.textContent || '').trim().slice(0, 500);
                    addAi(data.reply.html || 'I could not form an answer. Try rephrasing.', data.reply.source);
                    transcript.push({ role: 'user', text: text });
                    if (plain) transcript.push({ role: 'assistant', text: plain });
                    transcript = transcript.slice(-6);
                    setChips(data.reply.suggestions || []);
                    ctxSent = true;
                } else if (data && data.error) {
                    addAi('Session expired — please log in again, then ask me anything.');
                } else {
                    var err = new Error('bad-response');
                    err.http = res.http;
                    throw err;
                }
            }
            function failRes(err) {
                typing.remove();
                if (err && err.http === 403) {
                    addAi('The server refused the chat request. If you are logged in, please tell the admin the code is AI-403.');
                } else {
                    addAi('Connection hiccup — please try again.');
                }
            }
            // Order: portal relay first (it runs wherever the portal runs),
            // then the standalone endpoint as fallback.
            var ep = 'ai_chat.php';
            var relay = 'index.php?action=ai_reply';
            postUrl(relay, buildForm(true)).then(handleRes).catch(function() {
                return getUrl(relay, buildForm(false)).then(handleRes);
            }).catch(function() {
                return postUrl(ep, buildForm(true)).then(handleRes);
            }).catch(function() {
                return getUrl(ep, buildForm(false)).then(handleRes);
            }).catch(failRes).finally(function() {
                sendBtn.disabled = false;
            });
        }
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            send(input.value);
        });
    })();
    </script>
    <script>
    // ==================== Attendance Confirmation (student) — modal + robust fetch ====================
    (function(){
        const modalEl = document.getElementById('attendanceConfirmModal');
        const btn = document.getElementById('attendanceConfirmBtn');
        const text = document.getElementById('attendanceConfirmText');
        const meta = document.getElementById('attendanceConfirmMeta');
        const success = document.getElementById('attendanceConfirmSuccess');
        if(!modalEl || !btn || !text) return;
        let pendingList = [];
        let current = null;
        let dismissedUntil = 0;
        let pollTimer = null;
        let modal = null;
        try{ modal = new bootstrap.Modal(modalEl); } catch(e){ modal = null; }

        function showModal(){ if(modal) modal.show(); else modalEl.style.display='block'; }
        function hideModal(){ if(modal) modal.hide(); else modalEl.style.display='none'; }

        function render(){
            if(!pendingList.length){
                hideModal();
                return;
            }
            if(Date.now() < dismissedUntil){
                hideModal();
                return;
            }
            current = pendingList[0];
            const subj = current.subject_name || 'Class';
            const cls = (current.grade || '') + ' — ' + (current.section || '');
            const qn = current.quarter_name || ('Quarter ' + current.quarter);
            const dt = current.date || '';
            text.textContent = subj + ' • ' + cls + ' • ' + qn;
            meta.textContent = 'Date: ' + dt + (current.schedule ? ' • Schedule: ' + current.schedule : '') + ' — tap Confirm to mark yourself Present.';
            showModal();
        }

        function poll(){
            fetch('index.php?action=get_pending_attendance', {credentials:'same-origin', headers:{'Accept':'application/json'}})
                .then(async r => {
                    const ct = r.headers.get('content-type') || '';
                    const txt = await r.text();
                    let j = null;
                    try{ j = JSON.parse(txt); } catch(e){
                        // Server returned HTML (e.g. not logged in) — don't show modal, don't spam
                        if(txt.includes('<!DOCTYPE') || txt.includes('<html')) return;
                        throw new Error(txt.slice(0,120));
                    }
                    if(j && j.status === 'success' && Array.isArray(j.pending)){
                        pendingList = j.pending;
                        render();
                    } else if(j && j.status === 'error'){
                        // Not logged in or no pending — quietly hide
                        pendingList = [];
                        hideModal();
                    }
                }).catch(()=>{});
        }

        // Animated check helpers (reuse scan popup style)
        let attPopupTimer = null;
        function showAttendanceSuccess(){
            const popup = document.getElementById('attendanceSuccessPopup');
            if(!popup) return;
            popup.style.display = 'flex';
            void popup.offsetWidth;
            popup.style.opacity = '1';
            const card = popup.querySelector('.scan-check-card');
            if(card){
                card.style.transform = 'scale(0.7)';
                void card.offsetWidth;
                card.style.transform = 'scale(1)';
                const svg = card.querySelector('.scan-check-svg');
                if(svg) svg.replaceWith(svg.cloneNode(true));
            }
            if(attPopupTimer) clearTimeout(attPopupTimer);
            attPopupTimer = setTimeout(hideAttendanceSuccess, 2200);
        }
        function hideAttendanceSuccess(){
            const popup = document.getElementById('attendanceSuccessPopup');
            if(!popup) return;
            popup.style.opacity = '0';
            const card = popup.querySelector('.scan-check-card');
            if(card) card.style.transform = 'scale(0.7)';
            setTimeout(()=>{ if(popup.style.opacity==='0') popup.style.display='none'; }, 280);
        }
        // Allow tap to dismiss
        (function(){
            const p = document.getElementById('attendanceSuccessPopup');
            if(p) p.addEventListener('click', hideAttendanceSuccess);
        })();

        // Base64url helpers for the step-up ceremony below.
        function b64uDecAttend(s){
            s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4) s += '=';
            var bin = atob(s);
            var b = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
            return b;
        }
        function b64uEncAttend(buf){
            var b = new Uint8Array(buf);
            var s = '';
            for (var i = 0; i < b.length; i += 8192) s += String.fromCharCode.apply(null, b.subarray(i, i + 8192));
            return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }
        // Hardware step-up (phase 3.3): the device PIN/biometric unlocks the
        // enclave locally (user verification); only the resulting signature is
        // transmitted — the PIN itself never leaves the phone.
        //
        // UV is requested first (that's the intent: prove possession of the
        // device PIN). An authenticator enrolled WITHOUT UV can never satisfy
        // 'required', so a single 'preferred' retry follows — otherwise such a
        // student could never confirm attendance again. The server verifies the
        // signature either way; only the local proof weakens, and it degrades
        // instead of dead-ending.
        async function attendAssertion(opt){
            function ask(uv){
                return navigator.credentials.get({ publicKey: {
                    challenge: b64uDecAttend(opt.challenge),
                    rpId: opt.rpId,
                    allowCredentials: [{ type: 'public-key', id: b64uDecAttend(opt.credentialId) }],
                    timeout: 60000, userVerification: uv
                }});
            }
            var cred;
            try { cred = await ask('required'); }
            catch (e) { cred = await ask('preferred'); }
            if (!cred) return null;
            return JSON.stringify({ mode: 'authenticate', id: cred.id,
                response: { authenticatorData: b64uEncAttend(cred.response.authenticatorData), clientDataJSON: b64uEncAttend(cred.response.clientDataJSON), signature: b64uEncAttend(cred.response.signature) } });
        }
        function doPost(assertionJson){
            const fd = new FormData();
            fd.append('action','confirm_attendance');
            fd.append('request_id', current.request_id);
            fd.append('session_id', current.session_id);
            fd.append('class_id', current.class_id);
            fd.append('quarter', current.quarter);
            fd.append('date', current.date);
            if (assertionJson) fd.append('webauthn_result', assertionJson);
            return fetch('index.php', {method:'POST', credentials:'same-origin', body: fd, headers:{'Accept':'application/json'}});
        }
        btn.addEventListener('click', function(){
            if(!current) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Confirming…';
            const sid = current.session_id, rid = current.request_id;
            // Ask the server whether this number needs a hardware signature
            // for this confirmation; fall back to the plain POST otherwise.
            fetch('index.php?action=attend_options&session_id=' + encodeURIComponent(sid) + '&request_id=' + encodeURIComponent(rid), {credentials:'same-origin', headers:{'Accept':'application/json'}})
                .then(function(r){ return r.json().catch(function(){ return null; }); })
                .then(function(opt){
                    if (opt && opt.status === 'success' && opt.mode === 'authenticate' && opt.challenge && opt.credentialId && window.PublicKeyCredential) {
                        return attendAssertion(opt).then(function(out){
                            if (!out) throw new Error('Security check was not completed — tap Confirm again to retry.');
                            return doPost(out);
                        });
                    }
                    return doPost(null);
                })
                .then(async r => {
                    let txt = await r.text();
                    txt = (txt || '').trim();
                    // Guard: server must return exactly ONE JSON object. If a stale
                    // backend ever concatenates "{...success...}{...error...}", keep first.
                    if(txt.indexOf('}{') !== -1){
                        txt = txt.slice(0, txt.indexOf('}{') + 1);
                    }
                    let j = null;
                    try{ j = JSON.parse(txt); } catch(e){
                        // If server returned HTML (e.g. session expired), treat as error without Network error alert
                        if(txt.includes('<!DOCTYPE') || txt.includes('<html')){
                            throw new Error('Session expired — please refresh and log in again.');
                        }
                        console.warn('Confirm returned non-JSON:', txt.slice(0,200));
                        throw new Error('Server busy — please try again.');
                    }
                    if(!r.ok) throw new Error(j.msg || ('HTTP '+r.status));
                    return j;
                })
                .then(j => {
                    if(j.status === 'success'){
                        hideModal();
                        // Show animated check instead of alert
                        showAttendanceSuccess();
                        if(success){
                            success.style.display = 'block';
                            setTimeout(()=>{ success.style.display='none'; }, 4000);
                        }
                        pendingList = pendingList.filter(p => p.request_id !== current.request_id);
                        current = null;
                        setTimeout(poll, 800);
                    } else {
                        const msg = (j.msg || '').toLowerCase();
                        // Already-confirmed (e.g. double-tap) is NOT an error — show check
                        if(msg.includes('no pending') || msg.includes('already')) {
                            hideModal();
                            showAttendanceSuccess();
                            if(current){
                                pendingList = pendingList.filter(p => p.request_id !== current.request_id);
                                current = null;
                            }
                            setTimeout(poll, 800);
                        } else if(j.msg) {
                            alert(j.msg);
                        }
                    }
                }).catch(err => {
                    // Only show real server message, never generic Network error
                    const msg = (err.message || '').trim();
                    if(msg && !msg.toLowerCase().includes('failed to fetch') && !msg.includes('NetworkError')){
                        alert(msg);
                    } else if(msg){
                        // True network failure (offline): queue the confirm in
                        // IndexedDB — api_sync.php push applies it on reconnect
                        // (server still enforces the today-gate + dedupe).
                        console.warn('Attendance confirm failed:', msg);
                        if (current && window.HorizonDB) {
                            HorizonDB.queueAction('attendance_confirm', {
                                request_id: current.request_id,
                                session_id: current.session_id,
                                class_id: current.class_id,
                                quarter: current.quarter,
                                date: current.date
                            }).then(function(qid){
                                if (!qid) return;
                                hideModal();
                                showAttendanceSuccess();
                                if (success) {
                                    success.textContent = 'No internet — confirmation saved on this device, will sync when you reconnect.';
                                    success.style.display = 'block';
                                    setTimeout(()=>{ success.style.display='none'; }, 6000);
                                }
                                if (current) {
                                    pendingList = pendingList.filter(p => p.request_id !== current.request_id);
                                    current = null;
                                }
                            });
                        }
                    }
                })
                .finally(()=>{
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Attendance';
                });
        });

        // Dismiss via modal hide (Later or X) — snooze 60s
        if(modalEl){
            modalEl.addEventListener('hidden.bs.modal', function(){
                // Only snooze if user dismissed without confirming (still has pending)
                if(pendingList.length && current){
                    dismissedUntil = Date.now() + 60000;
                }
            });
        }

        // Poll every 4s, plus immediately on load
        poll();
        pollTimer = setInterval(poll, 4000);
        document.addEventListener('visibilitychange', function(){
            if(!document.hidden) poll();
        });
        window.addEventListener('pagehide', function(){ clearInterval(pollTimer); });
    })();
    </script>
<nav class="portal-tabbar" aria-label="Student portal">
  <?php foreach ($portal_pages as $key => $meta): ?>
  <a href="index.php?page=<?php echo $key; ?>" class="<?php echo $key === $page ? 'active' : ''; ?>">
    <i class="fas <?php echo $meta['icon']; ?>"></i><span><?php echo htmlspecialchars($meta['title'] === 'My Grades' ? 'Grades' : $meta['title']); ?></span>
    <?php if ($key === 'quizzes'): ?><span id="quizTabBadge" class="badge rounded-pill bg-danger" style="display:none;font-size:9px">0</span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</nav>
    <script>
    // Mobile tab-bar auto-collapse: slides away on scroll down, returns on
    // scroll up (or near the top). Desktop unaffected (bar is mobile-only).
    (function(){
        var lastY = window.scrollY || 0;
        var ticking = false;
        function onScroll(){
            ticking = false;
            var y = window.scrollY || 0;
            var dy = y - lastY;
            if (y < 120 || dy < -8) {
                document.body.classList.remove('portal-nav-hidden');
            } else if (dy > 8) {
                document.body.classList.add('portal-nav-hidden');
            }
            lastY = y;
        }
        window.addEventListener('scroll', function(){
            if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
        }, { passive: true });
    })();
    </script>
<script>
// One-time cleanup: the offline service worker (sw.js) was retired and deleted
// from the server, but browsers that installed it keep running the stale copy,
// which rejects every fetch (e.g. favicon.ico) with "Failed to convert value
// to 'Response'". Force-unregister it and drop its caches. Safe to delete this
// block once every device has loaded any page at least once.
(function(){
  if (!('serviceWorker' in navigator)) return;
  try {
    navigator.serviceWorker.getRegistrations().then(function(regs){
      regs.forEach(function(r){ try { r.unregister(); } catch(e){} });
    }).catch(function(){});
    if (window.caches && caches.keys) {
      caches.keys().then(function(keys){
        keys.forEach(function(k){
          if (/horizon/i.test(k)) { try { caches.delete(k); } catch(e){} }
        });
      }).catch(function(){});
    }
  } catch(e){}
})();
</script>
<?php if (!empty($_SESSION['pending_key_enroll'])): /* auto-bind after a first login that passed on the PIN alone */ ?>
<script>
// ===== Auto-bind after login =====
// login.php let this first login through on the PIN alone (the passkey prompt
// was skipped or failed) and flagged pending_key_enroll. Finish the binding
// here: one silent attempt shortly after load, then a one-tap banner fallback
// — browsers usually require a FRESH user gesture for credentials.create(),
// which only the "Bind now" click reliably provides. A reply of "success"
// (including "already bound") clears the flag server-side.
(function(){
  var SN = <?php echo json_encode((string)$_SESSION['pending_key_enroll']); ?>;
  var capable = false;
  try { capable = !!window.isSecureContext && !!window.PublicKeyCredential && !!navigator.credentials; } catch (_) { capable = false; }
  if (!capable) return; // plain HTTP / old browser: nothing can bind yet
  function b64uDec(s){
    s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), b = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
    return b;
  }
  function b64uEnc(buf){
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i += 8192) s += String.fromCharCode.apply(null, b.subarray(i, i + 8192));
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function te(s){
    if (window.TextEncoder) return new TextEncoder().encode(s);
    var b = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i) & 0xff;
    return b;
  }
  var banner = null;
  function showBanner(msg){
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'hgsBindBanner';
      banner.setAttribute('style', 'position:fixed;left:50%;transform:translateX(-50%);bottom:16px;z-index:1400;background:#FFFBEB;border:1px solid #FDE68A;border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.25);padding:12px 14px;max-width:min(440px,calc(100vw - 24px));font-size:13px;color:#92400E;display:flex;gap:10px;align-items:center');
      banner.innerHTML = '<i class="fas fa-key"></i><span id="hgsBindBannerMsg" style="flex:1;font-weight:600">Bind this device to your student number.</span>';
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-sm btn-warning rounded-pill fw-bold';
      b.textContent = 'Bind now';
      b.addEventListener('click', function(){ run(); });
      banner.appendChild(b);
      document.body.appendChild(banner);
    }
    var m = document.getElementById('hgsBindBannerMsg');
    if (m && msg) m.textContent = msg;
  }
  function run(){
    if (run.busy) return;
    run.busy = true;
    var showIt = function(msg){ run.busy = false; showBanner(msg); };
    fetch('login.php?webauthn=options&student_number=' + encodeURIComponent(SN), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(opt){
        if (!opt || opt.status !== 'success' || !opt.challenge || opt.mode !== 'register') { showIt('Bind this device to your student number.'); return; }
        return navigator.credentials.create({ publicKey: {
          challenge: b64uDec(opt.challenge),
          rp: { name: 'HORIZON ESSUC', id: opt.rpId },
          user: { id: te(opt.userHandle || opt.userName || SN), name: opt.userName || SN, displayName: opt.userName || SN },
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
          timeout: 60000, attestation: 'none',
          authenticatorSelection: { userVerification: 'preferred' }
        }}).then(function(cred){
          if (!cred) { showIt('Tap Bind now to secure your account on this device.'); return; }
          var out = JSON.stringify({ mode: 'register', id: cred.id, rawId: b64uEnc(cred.rawId),
            response: { attestationObject: b64uEnc(cred.response.attestationObject), clientDataJSON: b64uEnc(cred.response.clientDataJSON) } });
          return fetch('login.php?bind=complete', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: 'webauthn_result=' + encodeURIComponent(out) })
            .then(function(r2){ return r2.json(); })
            .then(function(j){
              if (j && j.status === 'success') {
                run.busy = false;
                if (banner && banner.parentNode) { banner.parentNode.removeChild(banner); banner = null; }
                return;
              }
              showIt((j && j.msg) || 'Binding failed — tap to retry.');
            });
        }).catch(function(){ showIt('Binding was not completed — tap Bind now.'); });
      })
      .catch(function(){ showIt('Binding failed — tap to retry.'); });
  }
  // Silent attempt first (it may be allowed straight after the Login click);
  // the banner keeps a reliable one-tap path whenever a fresh gesture is
  // required. Only bound or unbindable states stop it — the server clearing
  // the flag removes this script on the next page load.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ setTimeout(run, 350); });
  else setTimeout(run, 350);
})();
</script>
<?php endif; ?>
</body>
</html>
