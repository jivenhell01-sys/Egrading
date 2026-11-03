<?php
// Student offline-sync endpoint (PWA Installed Web App <-> server SQLite3).
//
//   GET  api_sync.php?action=pull → JSON snapshot of THIS student's data:
//        profile + classes (assessments/marks/published) + quiz METADATA
//        (titles/limits only — never questions/answers) + pending attendance.
//   POST api_sync.php?action=push {actions:[{client_id,type,payload}]} →
//        apply IndexedDB-outbox actions queued while offline:
//        attendance_confirm (reuses Actions, incl. the today-gate) and
//        bind_request (same dedupe rule as the login page).
//
// Session-guarded exactly like api_grades.php: only the logged-in student
// can pull/push their own data. Offline quiz TAKING is deliberately
// unsupported (integrity) — the app needs internet for that.
if (session_status() === PHP_SESSION_NONE) {
    $p = '/students/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        if ($d !== '' && $d !== '.' && $d !== '/') $p = $d . '/';
    }
    session_set_cookie_params(['lifetime' => 0, 'path' => $p, 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
header('Content-Type: application/json');

function osync_out($arr) { echo json_encode($arr); exit; }

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
    osync_out(['status' => 'error', 'msg' => 'Not logged in.']);
}
$student_number = (string)$_SESSION['student_number'];

// Same DB search order as api_grades.php (live DB first).
function osync_candidate_dbs() {
    $base = __DIR__ . '/../db';
    $files = [];
    if (file_exists($base . '/grading_db.db')) $files[] = $base . '/grading_db.db';
    foreach ((array)glob($base . '/*.db') as $f) {
        $b = basename($f);
        if ($b === 'grading_db.db' || $b === 'landing_db.db' || in_array($f, $files, true)) continue;
        $files[] = $f;
    }
    foreach ((array)glob($base . '/*/*.db') as $f) {
        if (basename($f) === 'landing_db.db' || in_array($f, $files, true)) continue;
        $files[] = $f;
    }
    return $files;
}

function osync_pdo($file) {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Same shape as api_grades.php grades_build_classes(); additionally fills
// $enrollments with [{db_file, class_id, student_id}] for the quiz filter.
function osync_build_classes($pdo, $f, $student_number, &$profile, &$enrollments) {
    $out = [];
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('student_list', $tables, true) || !in_array('class_list', $tables, true) || !in_array('subjects', $tables, true)) return $out;
        $st = $pdo->prepare("SELECT s.student_id, s.student_number, s.name, s.class_id, c.grade, c.section, c.year, c.semester, COALESCE(sub.name,'') AS subject_name, (c.grade || ' - ' || c.section) AS class_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id LEFT JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = :sn LIMIT 5");
        $st->execute([':sn' => $student_number]);
        while ($enr = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($profile === null) $profile = ['name' => $enr['name'], 'student_number' => $enr['student_number']];
            $enrollments[] = ['db_file' => $f, 'class_id' => (int)$enr['class_id'], 'student_id' => (int)$enr['student_id']];
            $entry = [
                'database' => basename($f),
                'subject_name' => $enr['subject_name'],
                'class_name' => $enr['class_name'],
                'grade' => $enr['grade'],
                'section' => $enr['section'],
                'year' => $enr['year'] ?? null,
                'semester' => $enr['semester'] ?? null,
                'assessments' => [],
                'published' => [],
            ];
            $qmap = [];
            if (in_array('quarter_list', $tables, true)) {
                try {
                    $qm = $pdo->prepare("SELECT quarter, name FROM quarter_list WHERE class_id = :c");
                    $qm->execute([':c' => $enr['class_id']]);
                    while ($qr = $qm->fetch(PDO::FETCH_ASSOC)) $qmap[(string)$qr['quarter']] = $qr['name'];
                } catch (Exception $e2) {}
            }
            if (in_array('assessment_list', $tables, true) && in_array('grading_components', $tables, true)) {
                try {
                    $hasCsp = in_array('component_subject_percentage', $tables, true);
                    $cspJoin = $hasCsp ? "LEFT JOIN component_subject_percentage csp ON csp.component_id = cc.component_id AND csp.subject_id = (SELECT subject_id FROM class_list WHERE class_id = :c2)" : "";
                    $cspCol = $hasCsp ? ", csp.percentage AS component_percentage" : "";
                    $aq = $pdo->prepare("SELECT a.assessment_id, a.name AS assessment_name, a.quarter, a.hps, a.percentage, cc.name AS component_name{$cspCol}, ml.mark FROM assessment_list a JOIN grading_components cc ON a.component_id = cc.component_id {$cspJoin} LEFT JOIN mark_list ml ON ml.assessment_id = a.assessment_id AND ml.student_id = :sid WHERE a.class_id = :c ORDER BY a.quarter, cc.name, a.assessment_id");
                    $params = [':sid' => $enr['student_id'], ':c' => $enr['class_id']];
                    if ($hasCsp) $params[':c2'] = $enr['class_id'];
                    $aq->execute($params);
                    while ($a = $aq->fetch(PDO::FETCH_ASSOC)) {
                        $qq = (string)($a['quarter'] ?? '');
                        $entry['assessments'][] = [
                            'assessment_id' => (int)$a['assessment_id'],
                            'name' => $a['assessment_name'],
                            'component' => $a['component_name'],
                            'component_percentage' => isset($a['component_percentage']) ? (float)$a['component_percentage'] : null,
                            'quarter' => $a['quarter'],
                            'quarter_name' => $qmap[$qq] ?? ($qq !== '' ? ('Quarter ' . $qq) : ''),
                            'mark' => $a['mark'] === null ? null : (float)$a['mark'],
                            'hps' => (float)$a['hps'],
                            'percentage' => (float)$a['percentage'],
                        ];
                    }
                } catch (Exception $e2) {}
            }
            if (in_array('published_grades', $tables, true)) {
                try {
                    $pg = $pdo->prepare("SELECT quarter, initial_grade, transmuted_grade, average_grade, remarks, published_date, semester, academic_year FROM published_grades WHERE student_id = :sid AND class_id = :c ORDER BY quarter ASC");
                    $pg->execute([':sid' => $enr['student_id'], ':c' => $enr['class_id']]);
                    while ($g = $pg->fetch(PDO::FETCH_ASSOC)) {
                        $entry['published'][] = [
                            'quarter' => (int)$g['quarter'],
                            'quarter_name' => $qmap[(string)$g['quarter']] ?? ('Quarter ' . $g['quarter']),
                            'initial_grade' => $g['initial_grade'] === null ? null : (float)$g['initial_grade'],
                            'transmuted_grade' => $g['transmuted_grade'],
                            'average_grade' => $g['average_grade'] === null ? null : (float)$g['average_grade'],
                            'remarks' => $g['remarks'] ?? '',
                            'published_date' => $g['published_date'] ?? '',
                            'semester' => $g['semester'] ?? $enr['semester'],
                            'academic_year' => $g['academic_year'] ?? $enr['year'],
                        ];
                    }
                } catch (Exception $e2) {}
            }
            $out[] = $entry;
        }
    } catch (Exception $e) {}
    return $out;
}

// Same rule as students/quiz_api.php qapi_is_assigned(): empty list =
// whole class, otherwise the student's student_id must be listed.
function osync_is_assigned($quizRow, $student_id) {
    $asg = json_decode(is_array($quizRow) ? ($quizRow['assigned_student_ids'] ?? '') : '', true);
    if (!is_array($asg) || !count($asg)) return true;
    $sid = (int)$student_id;
    if ($sid <= 0) return false;
    foreach ($asg as $aid) { if ((int)$aid === $sid) return true; }
    return false;
}

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === 'pull') {
    $profile = null;
    $classes = [];
    $enrollments = [];
    $pdos = [];
    foreach (osync_candidate_dbs() as $f) {
        try {
            $pdo = osync_pdo($f);
            $pdos[$f] = $pdo;
            foreach (osync_build_classes($pdo, $f, $student_number, $profile, $enrollments) as $entry) $classes[] = $entry;
        } catch (Exception $e) {}
    }
    // Active quiz METADATA for my enrollments (no questions/answers —
    // taking a quiz always requires a live server round-trip).
    $quizzes = [];
    foreach ($enrollments as $e) {
        try {
            if (!isset($pdos[$e['db_file']])) $pdos[$e['db_file']] = osync_pdo($e['db_file']);
            $pdo = $pdos[$e['db_file']];
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('quiz_list', $tables, true)) continue;
            $st = $pdo->prepare("SELECT quiz_id, class_id, title, quiz_type, num_items, num_choices, time_limit_minutes, assigned_student_ids, activated_at FROM quiz_list WHERE class_id = :c AND is_active = 1 ORDER BY activated_at DESC, quiz_id DESC");
            $st->execute([':c' => $e['class_id']]);
            while ($q = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!osync_is_assigned($q, $e['student_id'])) continue;
                $quizzes[] = [
                    'quiz_id' => (int)$q['quiz_id'],
                    'class_id' => (int)$q['class_id'],
                    'database' => basename($e['db_file']),
                    'title' => $q['title'],
                    'quiz_type' => $q['quiz_type'],
                    'num_items' => (int)$q['num_items'],
                    'num_choices' => (int)$q['num_choices'],
                    'time_limit_minutes' => (int)$q['time_limit_minutes'],
                    'activated_at' => $q['activated_at'] ?? '',
                ];
            }
        } catch (Exception $e2) {}
    }
    // Pending attendance sessions (same source as the popup poll).
    $pending = [];
    try {
        require_once('../DBConnection.php');
        try { $tmpConn = new DBConnection(); } catch (Exception $e3) {}
        require_once(__DIR__ . '/../Actions.php');
        $act = new Actions();
        $dec = json_decode((string)$act->get_pending_attendance_for_student(), true);
        if (is_array($dec) && ($dec['status'] ?? '') === 'success' && is_array($dec['pending'] ?? null)) $pending = $dec['pending'];
    } catch (Throwable $e3) {}
    osync_out([
        'status' => 'success',
        'snapshot' => [
            'version' => 1,
            'server_time' => date('c'),
            'student' => $profile,
            'classes' => $classes,
            'quizzes' => $quizzes,
            'pending_attendance' => $pending,
        ],
    ]);
}

if ($action === 'push') {
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $actions = (is_array($body) && is_array($body['actions'] ?? null)) ? $body['actions'] : [];
    if (!count($actions)) osync_out(['status' => 'error', 'msg' => 'Missing {actions:[...]} JSON payload.']);
    if (count($actions) > 50) $actions = array_slice($actions, 0, 50);
    try {
        require_once('../DBConnection.php');
        try { $tmpConn = new DBConnection(); } catch (Exception $e3) {}
        require_once(__DIR__ . '/../Actions.php');
    } catch (Throwable $e3) {
        osync_out(['status' => 'error', 'msg' => 'Server busy — please try again.']);
    }
    $act = null;
    try { $act = new Actions(); } catch (Throwable $e3) {}
    $results = [];
    foreach ($actions as $a) {
        $cid = is_array($a) ? ($a['client_id'] ?? null) : null;
        $type = is_array($a) ? strtolower(trim((string)($a['type'] ?? ''))) : '';
        $payload = (is_array($a) && is_array($a['payload'] ?? null)) ? $a['payload'] : [];
        try {
            if ($type === 'attendance_confirm') {
                if ($act === null) throw new Exception('Server busy — please try again.');
                // confirm_attendance() reads $_SESSION + $_POST — feed it the
                // queued fields, then restore the real POST vars.
                $savePost = $_POST;
                $_POST = array_merge($_POST, [
                    'session_id' => (string)($payload['session_id'] ?? ''),
                    'request_id' => (string)($payload['request_id'] ?? ''),
                    'class_id' => (string)($payload['class_id'] ?? ''),
                    'quarter' => (string)($payload['quarter'] ?? ''),
                    'date' => (string)($payload['date'] ?? ''),
                ]);
                try {
                    $dec = json_decode((string)$act->confirm_attendance(), true);
                } finally {
                    $_POST = $savePost;
                }
                if (is_array($dec) && ($dec['status'] ?? '') === 'success') {
                    $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'success', 'msg' => $dec['msg'] ?? 'Attendance confirmed.'];
                } else {
                    $m = strtolower((string)($dec['msg'] ?? ''));
                    if (strpos($m, 'already') !== false) {
                        $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'info', 'msg' => 'Already confirmed.'];
                    } elseif (strpos($m, 'no pending') !== false || strpos($m, 'not found') !== false || strpos($m, 'ended') !== false || strpos($m, 'closed') !== false) {
                        // Session is gone (ended/stale) — retrying can never
                        // succeed, so tell the client to drop the item.
                        $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'expired', 'msg' => 'Session already ended.'];
                    } else {
                        $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'error', 'msg' => (string)($dec['msg'] ?? 'Could not confirm yet.')];
                    }
                }
            } elseif ($type === 'bind_request') {
                $results[] = array_merge(['client_id' => $cid, 'type' => $type], osync_push_bind_request($payload));
            } else {
                $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'error', 'msg' => 'Unknown action type.'];
            }
        } catch (Throwable $e3) {
            $results[] = ['client_id' => $cid, 'type' => $type, 'status' => 'error', 'msg' => 'Server busy — please try again.'];
        }
    }
    osync_out(['status' => 'success', 'server_time' => date('c'), 'results' => $results]);
}

// Student-initiated binding approval request applied from the offline
// outbox. Same validation + one-open-request dedupe as login.php
// hgs_bind_request() (kept in sync by rule, not by include — login.php
// cannot be included, it runs the login page). Writes to the live DB,
// the same file the login page uses (HGS_MAIN_DB).
function osync_push_bind_request($payload) {
    $sn = trim((string)($payload['student_number'] ?? ''));
    if (!preg_match('/^\d{2}-\d{5}$/', $sn)) return ['status' => 'error', 'msg' => 'Invalid student number.'];
    $fp = strtolower(trim((string)($payload['device_fingerprint'] ?? '')));
    if ($fp !== '' && !preg_match('/^[a-f0-9]{16,128}$/', $fp)) $fp = '';
    $fam = strtolower(trim((string)($payload['device_family'] ?? '')));
    if ($fam !== '' && !preg_match('/^[a-f0-9]{16,128}$/', $fam)) $fam = '';
    if ($fp === '' && $fam === '') return ['status' => 'error', 'msg' => 'Device not recognized — reload the page and try again.'];
    $model = substr(trim((string)($payload['device_model'] ?? '')), 0, 120);
    if ($model === '') $model = 'Unknown Device';
    try {
        $live = __DIR__ . '/../db/grading_db.db';
        $pdo = new PDO('sqlite:' . $live);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `student_device_logs` (
            `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            `student_number` TEXT NOT NULL,
            `device_fingerprint` TEXT,
            `device_model` TEXT,
            `ip_address` TEXT,
            `user_agent` TEXT,
            `status` TEXT NOT NULL,
            `details` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $chk = $pdo->prepare("SELECT COUNT(*) FROM student_device_logs WHERE student_number = :sn AND status = 'bind_request' AND id > COALESCE((SELECT MAX(id) FROM student_device_logs WHERE student_number = :sn2 AND status IN ('success','first_bind','admin_force_bind','admin_unbind')), 0)");
        $chk->execute([':sn' => $sn, ':sn2' => $sn]);
        if ((int)$chk->fetchColumn() > 0) return ['status' => 'info', 'msg' => 'Request already sent — please wait for approval.'];
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $stmt = $pdo->prepare("INSERT INTO student_device_logs (student_number, device_fingerprint, device_model, ip_address, user_agent, status, details) VALUES (:sn, :fp, :model, :ip, :ua, 'bind_request', :details)");
        $stmt->execute([
            ':sn' => $sn,
            ':fp' => substr($fp, 0, 128),
            ':model' => $model,
            ':ip' => $ip,
            ':ua' => $ua,
            ':details' => json_encode(['reason' => 'student requested binding approval (offline queue)', 'attempt_fp' => $fp, 'attempt_family' => $fam, 'attempt_model' => $model, 'raw' => substr((string)($payload['device_raw'] ?? ''), 0, 200)], JSON_UNESCAPED_SLASHES),
        ]);
        return ['status' => 'success', 'msg' => 'Request sent! The admin has been notified.'];
    } catch (Throwable $e3) {
        return ['status' => 'error', 'msg' => 'Could not send request. Please try again.'];
    }
}

osync_out(['status' => 'error', 'msg' => 'Unknown action. Use pull|push.']);
