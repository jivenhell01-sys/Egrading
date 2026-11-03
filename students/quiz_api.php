<?php
// Student quiz API — class-filtered active quizzes, attempt lifecycle,
// grading, and anti-tab-switch violation logging.
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
require_once(__DIR__ . '/../quiz_schema.php');

function qapi_out($arr) { echo json_encode($arr); exit; }
function qapi_err($msg) { qapi_out(['status' => 'error', 'msg' => $msg]); }

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
    qapi_err('Not logged in.');
}
$student_number = (string)$_SESSION['student_number'];

function qapi_pdo($file, $writable = false) {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($writable) { try { $pdo->exec('PRAGMA busy_timeout = 8000'); } catch (Exception $e) {} }
    return $pdo;
}

// All (class_id, db_file) pairs where this student is enrolled.
function qapi_enrollments($student_number) {
    $out = [];
    foreach (quiz_candidate_db_files() as $f) {
        try {
            $pdo = qapi_pdo($f);
            quiz_ensure_schema($pdo);
            $st = $pdo->prepare("SELECT s.student_id, s.student_number, s.name, s.class_id, c.grade, c.section, COALESCE(sub.name,'') AS subject_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id LEFT JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = :sn LIMIT 5");
            $st->execute([':sn' => $student_number]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r['db_file'] = $f;
                $r['db_key'] = quiz_db_key($f);
                $out[] = $r;
            }
        } catch (Exception $e) {}
    }
    return $out;
}

// Locate the DB holding this quiz AND this student's enrollment in its class.
function qapi_locate($student_number, $quiz_id, $class_id, $db_key, $writable = false) {
    $quiz_id = (int)$quiz_id; $class_id = (int)$class_id;
    if ($quiz_id <= 0 || $class_id <= 0) return [null, null, 'Invalid quiz reference.'];
    foreach (quiz_candidate_db_files() as $f) {
        if ($db_key !== '' && quiz_db_key($f) !== $db_key) continue;
        try {
            $pdo = qapi_pdo($f, $writable);
            quiz_ensure_schema($pdo);
            $st = $pdo->prepare("SELECT * FROM quiz_list WHERE quiz_id = :q AND class_id = :c LIMIT 1");
            $st->execute([':q' => $quiz_id, ':c' => $class_id]);
            $quiz = $st->fetch(PDO::FETCH_ASSOC);
            if (!$quiz) continue;
            $se = $pdo->prepare("SELECT student_id, name FROM student_list WHERE student_number = :sn AND class_id = :c LIMIT 1");
            $se->execute([':sn' => $student_number, ':c' => $class_id]);
            $stu = $se->fetch(PDO::FETCH_ASSOC);
            if (!$stu) continue;
            return [$pdo, ['quiz' => $quiz, 'student' => $stu, 'db_file' => $f, 'db_key' => quiz_db_key($f)], null];
        } catch (Exception $e) {}
    }
    return [null, null, 'Quiz not found for your class.'];
}

function qapi_norm($s) { return trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$s, 'UTF-8'))); }

// Per-student assignment gate: empty/missing list = whole class
// (backward compatible); otherwise only a listed student_id passes.
function qapi_is_assigned($quizRow, $student_id) {
    $asg = json_decode(is_array($quizRow) ? ($quizRow['assigned_student_ids'] ?? '') : '', true);
    if (!is_array($asg) || !count($asg)) return true;
    $sid = (int)$student_id;
    if ($sid <= 0) return false;
    foreach ($asg as $aid) { if ((int)$aid === $sid) return true; }
    return false;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'get_active_quizzes') {
    $enr = qapi_enrollments($student_number);
    $seenClass = [];
    foreach ($enr as $e) $seenClass[(int)$e['class_id'] . '|' . $e['db_file']] = $e;
    $quizzes = [];
    foreach ($seenClass as $e) {
        try {
            $pdo = qapi_pdo($e['db_file']);
            $st = $pdo->prepare("SELECT quiz_id, class_id, title, quiz_type, num_items, num_choices, time_limit_minutes, assigned_student_ids, activated_at, created_at FROM quiz_list WHERE class_id = :c AND is_active = 1 ORDER BY activated_at DESC, quiz_id DESC");
            $st->execute([':c' => $e['class_id']]);
            while ($q = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!qapi_is_assigned($q, $e['student_id'] ?? 0)) continue;
                $att = $pdo->prepare("SELECT status, score, total FROM quiz_attempts WHERE quiz_id = :q AND student_number = :sn LIMIT 1");
                $att->execute([':q' => $q['quiz_id'], ':sn' => $student_number]);
                $ar = $att->fetch(PDO::FETCH_ASSOC);
                $quizzes[] = [
                    'quiz_id' => (int)$q['quiz_id'],
                    'class_id' => (int)$q['class_id'],
                    'db_key' => $e['db_key'],
                    'title' => $q['title'],
                    'quiz_type' => $q['quiz_type'],
                    'quiz_type_label' => quiz_type_label($q['quiz_type']),
                    'num_items' => (int)$q['num_items'],
                    'time_limit_minutes' => (int)$q['time_limit_minutes'],
                    'subject_name' => $e['subject_name'],
                    'class_label' => trim(($e['grade'] ?? '') . ' — ' . ($e['section'] ?? '')),
                    'activated_at' => $q['activated_at'] ?? $q['created_at'],
                    'attempt_status' => $ar['status'] ?? null,
                    'attempt_score' => $ar ? ((float)$ar['score'] . ' / ' . (int)$ar['total']) : null,
                ];
            }
        } catch (Exception $e2) {}
    }
    usort($quizzes, function ($a, $b) { return strcmp((string)($b['activated_at'] ?? ''), (string)($a['activated_at'] ?? '')); });
    qapi_out(['status' => 'success', 'quizzes' => $quizzes]);
}

if ($action === 'history') {
    // Every SUBMITTED quiz of this student across all databases (active or
    // not), with class year/semester so the portal can filter them.
    $enr = qapi_enrollments($student_number);
    $seen = [];
    foreach ($enr as $e) $seen[(int)$e['class_id'] . '|' . $e['db_file']] = $e;
    $rows = [];
    foreach ($seen as $e) {
        try {
            $pdo = qapi_pdo($e['db_file']);
            $st = $pdo->prepare("SELECT a.quiz_id, a.class_id, a.score, a.total, a.violations, a.status, a.started_at, a.submitted_at, q.title, q.quiz_type, q.num_items, c.year, c.semester, c.grade, c.section, COALESCE(sub.name,'') AS subject_name FROM quiz_attempts a JOIN quiz_list q ON q.quiz_id = a.quiz_id JOIN class_list c ON c.class_id = a.class_id LEFT JOIN subjects sub ON sub.subject_id = c.subject_id WHERE a.class_id = :c AND a.student_number = :sn AND a.status IN ('submitted','auto_submitted') ORDER BY a.submitted_at DESC, a.attempt_id DESC");
            $st->execute([':c' => $e['class_id'], ':sn' => $student_number]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r['db_key'] = $e['db_key'];
                $r['quiz_type_label'] = quiz_type_label($r['quiz_type']);
                $r['class_label'] = trim(($e['grade'] ?? '') . ' — ' . ($e['section'] ?? ''));
                $r['class_key'] = $r['class_id'] . '|' . $e['db_key'];
                $rows[] = $r;
            }
        } catch (Exception $e2) {}
    }
    usort($rows, function ($a, $b) { return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? '')); });
    qapi_out(['status' => 'success', 'history' => $rows]);
}

if ($action === 'start') {
    list($pdo, $ctx, $err) = qapi_locate($student_number, $_POST['quiz_id'] ?? 0, $_POST['class_id'] ?? 0, trim($_POST['db_key'] ?? ''), true);
    if ($err) qapi_err($err);
    if ((int)$ctx['quiz']['is_active'] !== 1) qapi_err('This quiz is no longer active.');
    $qid = (int)$ctx['quiz']['quiz_id'];
    $cid = (int)$ctx['quiz']['class_id'];
    $sid = (int)$ctx['student']['student_id'];
    if (!qapi_is_assigned($ctx['quiz'], $sid)) qapi_err('This quiz was not assigned to you.');
    try {
        $qs = $pdo->prepare("SELECT question_id, qtype, question, choices, points, time_limit_seconds FROM quiz_questions WHERE quiz_id = :q ORDER BY sort_order ASC, question_id ASC");
        $qs->execute([':q' => $qid]);
        $questions = [];
        while ($r = $qs->fetch(PDO::FETCH_ASSOC)) {
            $ch = ($r['choices'] !== null && $r['choices'] !== '') ? json_decode($r['choices'], true) : null;
            if (is_array($ch)) { // shuffle choices per render so seats differ
                $seed = $sid + (int)$r['question_id'];
                for ($k = count($ch) - 1; $k > 0; $k--) { $rr = ($seed * 31 + $k * 17) % ($k + 1); $tmp = $ch[$k]; $ch[$k] = $ch[$rr]; $ch[$rr] = $tmp; }
            }
            $questions[] = ['question_id' => (int)$r['question_id'], 'qtype' => $r['qtype'], 'question' => $r['question'], 'choices' => $ch, 'points' => (float)$r['points'], 'time_limit_seconds' => (int)($r['time_limit_seconds'] ?? 0)];
        }
        if (!count($questions)) qapi_err('This quiz has no questions yet.');
        $ex = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = :q AND student_id = :s LIMIT 1");
        $ex->execute([':q' => $qid, ':s' => $sid]);
        $att = $ex->fetch(PDO::FETCH_ASSOC);
        if ($att && in_array($att['status'], ['submitted', 'auto_submitted'], true)) {
            qapi_out(['status' => 'submitted', 'msg' => 'Already submitted.', 'score' => (float)$att['score'], 'total' => (int)$att['total'], 'attempt_status' => $att['status'], 'violations' => (int)$att['violations']]);
        }
        if (!$att) {
            $ins = $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, student_number, class_id, answers, score, total, violations, violation_log, status, started_at) VALUES (:q, :s, :sn, :c, '', 0, :t, 0, '', 'in_progress', CURRENT_TIMESTAMP)");
            $ins->execute([':q' => $qid, ':s' => $sid, ':sn' => $student_number, ':c' => $cid, ':t' => count($questions)]);
            $att = ['violations' => 0, 'status' => 'in_progress'];
        }
        qapi_out(['status' => 'success', 'quiz' => ['quiz_id' => $qid, 'class_id' => $cid, 'db_key' => $ctx['db_key'], 'title' => $ctx['quiz']['title'], 'quiz_type_label' => quiz_type_label($ctx['quiz']['quiz_type']), 'time_limit_minutes' => (int)$ctx['quiz']['time_limit_minutes']], 'questions' => $questions, 'violations' => (int)$att['violations']]);
    } catch (Exception $e) { qapi_err('Could not start the quiz — please retry.'); }
}

if ($action === 'submit') {
    list($pdo, $ctx, $err) = qapi_locate($student_number, $_POST['quiz_id'] ?? 0, $_POST['class_id'] ?? 0, trim($_POST['db_key'] ?? ''), true);
    if ($err) qapi_err($err);
    $qid = (int)$ctx['quiz']['quiz_id'];
    $sid = (int)$ctx['student']['student_id'];
    if (!qapi_is_assigned($ctx['quiz'], $sid)) qapi_err('This quiz was not assigned to you.');
    $raw = $_POST['answers'] ?? '{}';
    $answers = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($answers)) $answers = [];
    $auto = !empty($_POST['auto']) ? 'auto_submitted' : 'submitted';
    try {
        $ex = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = :q AND student_id = :s LIMIT 1");
        $ex->execute([':q' => $qid, ':s' => $sid]);
        $att = $ex->fetch(PDO::FETCH_ASSOC);
        if ($att && in_array($att['status'], ['submitted', 'auto_submitted'], true)) {
            qapi_out(['status' => 'submitted', 'msg' => 'Already submitted.', 'score' => (float)$att['score'], 'total' => (int)$att['total']]);
        }
        $qs = $pdo->prepare("SELECT question_id, qtype, correct_answer, points FROM quiz_questions WHERE quiz_id = :q ORDER BY sort_order ASC, question_id ASC");
        $qs->execute([':q' => $qid]);
        $score = 0.0; $total = 0;
        while ($r = $qs->fetch(PDO::FETCH_ASSOC)) {
            // Essays need manual grading: excluded from the auto score/total.
            if (($r['qtype'] ?? '') === 'essay') continue;
            $total++;
            $given = trim((string)($answers[(string)$r['question_id']] ?? $answers[(int)$r['question_id']] ?? ''));
            if ($given === '') continue;
            if (qapi_norm($given) === qapi_norm($r['correct_answer'])) $score += (float)$r['points'];
        }
        if (!$att) {
            $ins = $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, student_number, class_id, answers, score, total, violations, violation_log, status, started_at, submitted_at) VALUES (:q, :s, :sn, :c, :a, :sc, :t, 0, '', :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $ins->execute([':q' => $qid, ':s' => $sid, ':sn' => $student_number, ':c' => (int)$ctx['quiz']['class_id'], ':a' => json_encode($answers, JSON_UNESCAPED_UNICODE), ':sc' => $score, ':t' => $total, ':st' => $auto]);
        } else {
            $upd = $pdo->prepare("UPDATE quiz_attempts SET answers = :a, score = :sc, total = :t, status = :st, submitted_at = CURRENT_TIMESTAMP WHERE attempt_id = :id");
            $upd->execute([':a' => json_encode($answers, JSON_UNESCAPED_UNICODE), ':sc' => $score, ':t' => $total, ':st' => $auto, ':id' => $att['attempt_id']]);
        }
        qapi_out(['status' => 'success', 'msg' => 'Quiz submitted.', 'score' => $score, 'total' => $total, 'attempt_status' => $auto]);
    } catch (Exception $e) { qapi_err('Submit failed — please retry.'); }
}

if ($action === 'violate') {
    list($pdo, $ctx, $err) = qapi_locate($student_number, $_POST['quiz_id'] ?? 0, $_POST['class_id'] ?? 0, trim($_POST['db_key'] ?? ''), true);
    if ($err) qapi_err($err);
    $qid = (int)$ctx['quiz']['quiz_id'];
    $sid = (int)$ctx['student']['student_id'];
    $reason = substr(trim((string)($_POST['reason'] ?? 'tab_switch')), 0, 80);
    if ($reason === '') $reason = 'tab_switch';
    try {
        $ex = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = :q AND student_id = :s LIMIT 1");
        $ex->execute([':q' => $qid, ':s' => $sid]);
        $att = $ex->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            $ins = $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, student_number, class_id, answers, score, total, violations, violation_log, status, started_at) VALUES (:q, :s, :sn, :c, '', 0, 0, 1, :log, 'in_progress', CURRENT_TIMESTAMP)");
            $ins->execute([':q' => $qid, ':s' => $sid, ':sn' => $student_number, ':c' => (int)$ctx['quiz']['class_id'], ':log' => gmdate('Y-m-d H:i:s') . ' ' . $reason]);
            qapi_out(['status' => 'success', 'violations' => 1]);
        }
        if (in_array($att['status'], ['submitted', 'auto_submitted'], true)) qapi_out(['status' => 'success', 'violations' => (int)$att['violations'], 'closed' => true]);
        $log = trim((string)($att['violation_log'] ?? '') . "\n" . gmdate('Y-m-d H:i:s') . ' ' . $reason);
        $upd = $pdo->prepare("UPDATE quiz_attempts SET violations = violations + 1, violation_log = :log WHERE attempt_id = :id");
        $upd->execute([':log' => $log, ':id' => $att['attempt_id']]);
        qapi_out(['status' => 'success', 'violations' => (int)$att['violations'] + 1]);
    } catch (Exception $e) { qapi_err('Log failed.'); }
}

qapi_err('Unknown action.');
