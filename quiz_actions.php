<?php
// Quiz Generator — faculty/admin API (save / list / activate / results).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
require_once('DBConnection.php');
require_once('quiz_schema.php');

function quiz_out($status, $msg = '', $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'msg' => $msg], $extra));
    exit;
}

if (!isset($_SESSION['admin_id'])) quiz_out('error', 'Session expired — please log in again.');

try { $db = new DBConnection(); } catch (Exception $e) { quiz_out('error', 'Database unavailable.'); }
quiz_ensure_schema($db);
$db->enableExceptions(true);

$action = trim($_REQUEST['action'] ?? '');
$esc = function ($v) use ($db) { return $db->escapeString((string)$v); };

if ($action === 'list') {
    $rows = [];
    try {
        $filterClassId = (int)($_REQUEST['class_id'] ?? 0);
        $filterSemesterId = isset($_REQUEST['semester_id']) ? $_REQUEST['semester_id'] : '';
        $filterClause = '';
        if ($filterClassId > 0) {
            $filterClause = ' WHERE q.class_id = ' . $filterClassId;
            if ($filterSemesterId !== '') {
                $filterClause .= ' AND q.semester_id = "' . $esc($filterSemesterId) . '"';
            }
        } elseif ($filterSemesterId !== '') {
            $filterClause = " WHERE q.semester_id = '" . $esc($filterSemesterId) . "'";
        }
        $res = $db->query("SELECT q.* FROM quiz_list q" . $filterClause . " ORDER BY q.quiz_id DESC");
        if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $r['class_label'] = quiz_class_label($db, $r['class_id']);
            $r['quiz_type_label'] = quiz_type_label($r['quiz_type']);
            $ac = json_decode($r['assigned_student_ids'] ?? '', true);
            $r['assigned_count'] = is_array($ac) ? count($ac) : 0;
            $cid = (int)$r['class_id'];
            $asgWhere = '';
            $asgDec = json_decode($r['assigned_student_ids'] ?? '', true);
            if (is_array($asgDec) && count($asgDec)) {
                $asgIds = array_map('intval', $asgDec);
                $asgWhere = ' AND s.student_id IN (' . implode(',', $asgIds) . ')';
            }
            $taken = $db->querySingle("SELECT COUNT(*) FROM quiz_attempts a JOIN student_list s ON s.student_id = a.student_id WHERE a.quiz_id = " . $r['quiz_id'] . " AND a.status != 'in_progress' AND s.class_id = " . $cid . $asgWhere);
            $r['taken'] = $taken;
            $rows[] = $r;
        }
    } catch (Exception $e) { quiz_out('error', 'Could not load quizzes.'); }
    // Tell the UI exactly which database file this answer came from, so a
    // stale-or-wrong-DB screen can be told apart from a real zero.
    $sessionDb = (!empty($_SESSION['db_file']) && is_string($_SESSION['db_file'])) ? basename($_SESSION['db_file']) : 'grading_db.db (default)';
    $takenSum = 0;
    foreach ($rows as $rr) $takenSum += (int)($rr['taken'] ?? 0);
    try {
        $logFile = __DIR__ . '/logs/quiz_debug.log';
        if (!file_exists($logFile) || filesize($logFile) < 500000) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . ' list db=' . $sessionDb . ' quizzes=' . count($rows) . ' taken_sum=' . $takenSum . ' admin=' . ($_SESSION['admin_id'] ?? '?') . "\n", FILE_APPEND);
        }
    } catch (Exception $e) {}
    echo json_encode(['status' => 'success', 'data' => $rows, 'db' => $sessionDb]);
    exit;
}

if ($action === 'get') {
    $qid = (int)($_REQUEST['quiz_id'] ?? 0);
    if ($qid <= 0) quiz_out('error', 'Invalid quiz.');
    $q = $db->querySingle("SELECT * FROM quiz_list WHERE quiz_id = " . $qid, true);
    if (!$q) quiz_out('error', 'Quiz not found.');
    $qs = [];
    $res = $db->query("SELECT * FROM quiz_questions WHERE quiz_id = " . $qid . " ORDER BY sort_order ASC, question_id ASC");
    if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['choices'] = ($r['choices'] !== null && $r['choices'] !== '') ? json_decode($r['choices'], true) : null;
        $qs[] = $r;
    }
    $q['questions'] = $qs;
    echo json_encode(['status' => 'success', 'data' => $q]);
    exit;
}

if ($action === 'students') {
    // Roster for the assignment picker (faculty/admin only — this whole file
    // is admin-guarded at the top).
    $cid = (int)($_REQUEST['class_id'] ?? 0);
    if ($cid <= 0) quiz_out('error', 'Invalid class.');
    if (!$db->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = " . $cid)) quiz_out('error', 'Class not found.');
    $rows = [];
    $res = $db->query("SELECT student_id, student_number, name FROM student_list WHERE class_id = " . $cid . " ORDER BY name ASC");
    if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    echo json_encode(['status' => 'success', 'data' => $rows]);
    exit;
}

if ($action === 'save') {
    $quiz_id = (int)($_POST['quiz_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $source = (string)($_POST['source_text'] ?? '');
    $quiz_type = trim($_POST['quiz_type'] ?? 'multiple_choice');
    $num_choices = max(2, min(5, (int)($_POST['num_choices'] ?? 4)));
    $time_limit = max(0, min(300, (int)($_POST['time_limit_minutes'] ?? 0)));
    $rawQ = $_POST['questions'] ?? '[]';
    $questions = is_string($rawQ) ? json_decode($rawQ, true) : $rawQ;
    if ($class_id <= 0) quiz_out('error', 'Select a class.');
    if ($title === '') quiz_out('error', 'Enter a quiz title.');
    if (!$db->querySingle("SELECT COUNT(*) FROM class_list WHERE class_id = " . $class_id)) quiz_out('error', 'Selected class no longer exists.');
    // Per-student assignment: JSON array of student_ids in THIS class.
    // Empty/missing = whole class (backward compatible with old quizzes).
    $asgIds = [];
    $rawAsg = $_POST['assigned_student_ids'] ?? '';
    if (is_string($rawAsg) && $rawAsg !== '') {
        $decAsg = json_decode($rawAsg, true);
        if (is_array($decAsg)) foreach ($decAsg as $sid) { $sid = (int)$sid; if ($sid > 0) $asgIds[$sid] = 1; }
    }
    $asgIds = array_keys($asgIds);
    if (count($asgIds) > 500) quiz_out('error', 'Too many selected students (max 500).');
    if (count($asgIds)) {
        $cntAsg = (int)$db->querySingle("SELECT COUNT(*) FROM student_list WHERE class_id = " . $class_id . " AND student_id IN (" . implode(',', $asgIds) . ")");
        if ($cntAsg !== count($asgIds)) quiz_out('error', 'Selected students do not all belong to this class.');
    }
    $asgJson = count($asgIds) ? json_encode(array_values($asgIds)) : '';
    $allowed = array_keys(quiz_allowed_types());
    if (!in_array($quiz_type, $allowed, true)) $quiz_type = 'multiple_choice';
    if (!is_array($questions) || !count($questions)) {
        // Fallback: auto-generate server-side from the pasted discussion
        $questions = quiz_server_generate($source, (int)($_POST['num_items'] ?? 10), $num_choices, $quiz_type);
        if (!count($questions)) quiz_out('error', 'No questions to save — paste a longer discussion and Generate first.');
    }
    $clean = [];
    foreach ($questions as $qq) {
        if (!is_array($qq)) continue;
        $qt = in_array(($qq['qtype'] ?? ''), ['multiple_choice', 'identification', 'true_false', 'essay'], true) ? $qq['qtype'] : 'multiple_choice';
        $text = trim((string)($qq['question'] ?? ''));
        $ans = trim((string)($qq['correct_answer'] ?? ''));
        if ($text === '' || $ans === '') continue;
        $ch = null;
        if ($qt === 'multiple_choice') {
            $ch = array_values(array_filter(array_map('trim', (array)($qq['choices'] ?? [])), function ($v) { return $v !== ''; }));
            if (count($ch) < 2) continue;
            $found = false;
            foreach ($ch as $c) { if (strcasecmp($c, $ans) === 0) { $found = true; break; } }
            if (!$found) $ch[] = $ans;
            $ch = array_slice($ch, 0, 6);
        } elseif ($qt === 'true_false') {
            $ch = ['True', 'False'];
            $ans = (strcasecmp($ans, 'false') === 0) ? 'False' : 'True';
        }
        $tl = max(0, min(3600, (int)($qq['time_limit_seconds'] ?? 0)));
        $clean[] = ['qtype' => $qt, 'question' => $text, 'choices' => $ch, 'correct_answer' => $ans, 'time_limit_seconds' => $tl];
        if (count($clean) >= 100) break;
    }
    if (!count($clean)) quiz_out('error', 'No valid questions — each item needs question text and a correct answer.');
    $num_items = count($clean);
    $by = (int)$_SESSION['admin_id'];
    try {
        $db->exec('BEGIN');
        if ($quiz_id > 0) {
            $exists = $db->querySingle("SELECT COUNT(*) FROM quiz_list WHERE quiz_id = " . $quiz_id);
            if (!$exists) { $db->exec('ROLLBACK'); quiz_out('error', 'Quiz not found.'); }
            $db->exec("UPDATE quiz_list SET class_id = " . $class_id . ", title = '" . $esc($title) . "', source_text = '" . $esc($source) . "', quiz_type = '" . $esc($quiz_type) . "', num_items = " . $num_items . ", num_choices = " . $num_choices . ", time_limit_minutes = " . $time_limit . ", assigned_student_ids = '" . $esc($asgJson) . "' WHERE quiz_id = " . $quiz_id);
            $db->exec("DELETE FROM quiz_questions WHERE quiz_id = " . $quiz_id);
        } else {
            $db->exec("INSERT INTO quiz_list (class_id, title, source_text, quiz_type, num_items, num_choices, time_limit_minutes, assigned_student_ids, is_active, created_by) VALUES (" . $class_id . ", '" . $esc($title) . "', '" . $esc($source) . "', '" . $esc($quiz_type) . "', " . $num_items . ", " . $num_choices . ", " . $time_limit . ", '" . $esc($asgJson) . "', 0, " . $by . ")");
            $quiz_id = (int)$db->lastInsertRowID();
        }
        $ord = 0;
        foreach ($clean as $c) {
            $ord++;
            $chJson = $c['choices'] === null ? 'NULL' : ("'" . $esc(json_encode(array_values($c['choices']), JSON_UNESCAPED_UNICODE)) . "'");
            $db->exec("INSERT INTO quiz_questions (quiz_id, qtype, question, choices, correct_answer, points, sort_order, time_limit_seconds) VALUES (" . $quiz_id . ", '" . $esc($c['qtype']) . "', '" . $esc($c['question']) . "', " . $chJson . ", '" . $esc($c['correct_answer']) . "', 1, " . $ord . ", " . (int)($c['time_limit_seconds'] ?? 0) . ")");
        }
        $db->exec("UPDATE quiz_list SET num_items = " . $num_items . " WHERE quiz_id = " . $quiz_id);
        $db->exec('COMMIT');
    } catch (Exception $e) { try { $db->exec('ROLLBACK'); } catch (Exception $ee) {} quiz_out('error', 'Save failed — please retry.'); }
    quiz_out('success', 'Quiz saved (' . $num_items . ' items). Activate it to notify the class.', ['quiz_id' => $quiz_id]);
}

if ($action === 'toggle') {
    $qid = (int)($_POST['quiz_id'] ?? $_REQUEST['quiz_id'] ?? 0);
    if ($qid <= 0) quiz_out('error', 'Invalid quiz.');
    $q = $db->querySingle("SELECT is_active, title FROM quiz_list WHERE quiz_id = " . $qid, true);
    if (!$q) quiz_out('error', 'Quiz not found.');
    $new = ((int)$q['is_active'] === 1) ? 0 : 1;
    $extra = $new === 1 ? ", activated_at = CURRENT_TIMESTAMP" : "";
    $db->exec("UPDATE quiz_list SET is_active = " . $new . $extra . " WHERE quiz_id = " . $qid);
    quiz_out('success', $new === 1 ? ('"' . $q['title'] . '" is now ACTIVE — students in its class are notified.') : ('"' . $q['title'] . '" deactivated — hidden from students.'), ['is_active' => $new]);
}

if ($action === 'delete') {
    $qid = (int)($_POST['quiz_id'] ?? 0);
    if ($qid <= 0) quiz_out('error', 'Invalid quiz.');
    try {
        $db->exec('BEGIN');
        $db->exec("DELETE FROM quiz_attempts WHERE quiz_id = " . $qid);
        $db->exec("DELETE FROM quiz_questions WHERE quiz_id = " . $qid);
        $db->exec("DELETE FROM quiz_list WHERE quiz_id = " . $qid);
        $db->exec('COMMIT');
    } catch (Exception $e) { try { $db->exec('ROLLBACK'); } catch (Exception $ee) {} quiz_out('error', 'Delete failed.'); }
    quiz_out('success', 'Quiz deleted.');
}

if ($action === 'results') {
    $qid = (int)($_REQUEST['quiz_id'] ?? 0);
    if ($qid <= 0) quiz_out('error', 'Invalid quiz.');
    $q = $db->querySingle("SELECT quiz_id, class_id, title, assigned_student_ids FROM quiz_list WHERE quiz_id = " . $qid, true);
    if (!$q) quiz_out('error', 'Quiz not found.');
    $cid = (int)$q['class_id'];
    // Assignment scope: when the quiz targets specific students, the roster
    // (and summary counts) cover only them — everyone else never sees it.
    $asgWhere = '';
    $asgDec = json_decode($q['assigned_student_ids'] ?? '', true);
    if (is_array($asgDec) && count($asgDec)) {
        $asgIn = [];
        foreach ($asgDec as $sid) { $sid = (int)$sid; if ($sid > 0) $asgIn[] = $sid; }
        if (count($asgIn)) $asgWhere = ' AND s.student_id IN (' . implode(',', $asgIn) . ')';
    }
    // Full class roster + attempt state: submitted / in_progress / not_taken.
    $rows = [];
    $submitted = 0; $inProgress = 0;
    $res = $db->query("SELECT s.student_id, s.student_number, s.name AS student_name,
            a.attempt_id, a.score, a.total, a.violations, a.violation_log, a.status AS attempt_status, a.started_at, a.submitted_at
        FROM student_list s
        LEFT JOIN quiz_attempts a ON a.quiz_id = " . $qid . " AND a.student_id = s.student_id
        WHERE s.class_id = " . $cid . $asgWhere . "
        ORDER BY s.name ASC");
    if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        if (in_array(($r['attempt_status'] ?? ''), ['submitted', 'auto_submitted'], true)) {
            $r['state'] = 'submitted';
            $submitted++;
        } elseif (($r['attempt_status'] ?? '') === 'in_progress') {
            $r['state'] = 'in_progress';
            $inProgress++;
        } else {
            $r['state'] = 'not_taken';
            $r['attempt_status'] = null;
        }
        $rows[] = $r;
    }
    echo json_encode(['status' => 'success', 'data' => $rows, 'quiz' => $q,
        'summary' => ['total' => count($rows), 'submitted' => $submitted, 'in_progress' => $inProgress, 'not_taken' => count($rows) - $submitted - $inProgress]]);
    exit;
}

if ($action === 'attempt_detail') {
    // One student's submitted quiz form: questions (with correct answers —
    // admin-only endpoint, never exposed to students) + their stored answers.
    $qid = (int)($_REQUEST['quiz_id'] ?? 0);
    $aid = (int)($_REQUEST['attempt_id'] ?? 0);
    if ($qid <= 0 || $aid <= 0) quiz_out('error', 'Invalid attempt.');
    $att = $db->querySingle("SELECT a.*, COALESCE(s.name,'') AS student_name, COALESCE(s.student_number,'') AS student_number FROM quiz_attempts a LEFT JOIN student_list s ON s.student_id = a.student_id WHERE a.attempt_id = " . $aid . " AND a.quiz_id = " . $qid, true);
    if (!$att) quiz_out('error', 'Attempt not found.');
    $qs = [];
    $res = $db->query("SELECT question_id, qtype, question, choices, correct_answer, points, time_limit_seconds FROM quiz_questions WHERE quiz_id = " . $qid . " ORDER BY sort_order ASC, question_id ASC");
    if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['choices'] = ($r['choices'] !== null && $r['choices'] !== '') ? json_decode($r['choices'], true) : null;
        $qs[] = $r;
    }
    $answers = json_decode((string)($att['answers'] ?? ''), true);
    if (!is_array($answers)) $answers = [];
    unset($att['answers']);
    $att['answers'] = $answers;
    echo json_encode(['status' => 'success', 'attempt' => $att, 'questions' => $qs]);
    exit;
}

if ($action === 'delete_attempt') {
    $qid = (int)($_POST['quiz_id'] ?? 0);
    $aid = (int)($_POST['attempt_id'] ?? 0);
    if ($qid <= 0 || $aid <= 0) quiz_out('error', 'Invalid attempt.');
    $exists = $db->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE attempt_id = " . $aid . " AND quiz_id = " . $qid);
    if (!$exists) quiz_out('error', 'Attempt not found.');
    $db->exec("DELETE FROM quiz_attempts WHERE attempt_id = " . $aid . " AND quiz_id = " . $qid);
    quiz_out('success', 'Attempt deleted — the student can retake the quiz.');
}

if ($action === 'clear_attempts') {
    $qid = (int)($_POST['quiz_id'] ?? 0);
    if ($qid <= 0) quiz_out('error', 'Invalid quiz.');
    $db->exec("DELETE FROM quiz_attempts WHERE quiz_id = " . $qid);
    quiz_out('success', 'All attempts cleared — the class can retake the quiz.');
}

if ($action === 'class_tree') {
    // Folder tree for the UI: school year -> semester -> class.
    // Classes with no year / no semester land in fallback buckets so their
    // quizzes can never become unreachable.
    $years = [];
    $res = $db->query(
        "SELECT COALESCE(NULLIF(TRIM(IFNULL(c.year,'')),''),'(No school year)') AS y,
                COALESCE(NULLIF(TRIM(IFNULL(c.semester,'')),''),'(No semester)') AS s,
                c.class_id, c.grade, c.section,
                IFNULL(sub.name,'') AS subject,
                (SELECT COUNT(*) FROM student_list sl WHERE sl.class_id = c.class_id) AS students
         FROM class_list c
         LEFT JOIN subjects sub ON sub.subject_id = c.subject_id
         ORDER BY y ASC, s ASC, c.grade ASC, c.section ASC"
    );
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $y = $r['y'];
            $s = $r['s'];
            if (!isset($years[$y])) $years[$y] = [];
            if (!isset($years[$y][$s])) $years[$y][$s] = ['semester' => $s, 'classes' => []];
            $years[$y][$s]['classes'][] = [
                'class_id' => (int)$r['class_id'],
                'grade'    => $r['grade'],
                'section'  => $r['section'],
                'subject'  => (string)$r['subject'],
                'students' => (int)$r['students'],
            ];
        }
    }
    // Newest school year first (fallback bucket last); semesters in order.
    uksort($years, function ($a, $b) {
        $aFallback = ($a === '(No school year)');
        $bFallback = ($b === '(No school year)');
        if ($aFallback !== $bFallback) return $aFallback ? 1 : -1;
        return strcmp($b, $a);
    });
    $tree = [];
    foreach ($years as $y => $sems) {
        uksort($sems, function ($a, $b) {
            $aFallback = ($a === '(No semester)');
            $bFallback = ($b === '(No semester)');
            if ($aFallback !== $bFallback) return $aFallback ? 1 : -1;
            return strcmp($a, $b);
        });
        $tree[] = ['year' => $y, 'semesters' => array_values($sems)];
    }
    echo json_encode(['status' => 'success', 'data' => $tree]);
    exit;
}
if ($action === 'gradebook_targets') {
    $qid = (int)($_REQUEST['quiz_id'] ?? 0);
    $q = $db->querySingle("SELECT quiz_id, class_id, title FROM quiz_list WHERE quiz_id = " . $qid, true);
    if (!$q) quiz_out('error', 'Quiz not found.');
    $cid = (int)$q['class_id'];
    $comps = [];
    $cls = $db->querySingle("SELECT subject_id FROM class_list WHERE class_id = " . $cid, true);
    if ($cls) {
        $sid = (int)$cls['subject_id'];
        $res = $db->query("SELECT gc.component_id, gc.name, csp.percentage FROM grading_components gc INNER JOIN component_subject_percentage csp ON gc.component_id = csp.component_id WHERE csp.subject_id = " . $sid . " AND CAST(csp.percentage AS REAL) > 0 ORDER BY gc.name ASC");
        if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $ass = [];
            $ra = $db->query("SELECT a.assessment_id, a.name, a.quarter, a.hps, COALESCE(q.name, 'Quarter ' || a.quarter) AS quarter_name FROM assessment_list a LEFT JOIN quarter_list q ON q.class_id = a.class_id AND q.quarter = a.quarter WHERE a.class_id = " . $cid . " AND a.component_id = " . (int)$r['component_id'] . " ORDER BY a.quarter ASC, a.name ASC");
            if ($ra) while ($a = $ra->fetchArray(SQLITE3_ASSOC)) $ass[] = $a;
            $r['assessments'] = $ass;
            $comps[] = $r;
        }
    }
    $sub = (int)$db->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = " . $qid . " AND status IN ('submitted','auto_submitted')");
    echo json_encode(['status' => 'success', 'components' => $comps, 'submitted' => $sub, 'class_label' => quiz_class_label($db, $cid)]);
    exit;
}

if ($action === 'push_marks') {
    $qid = (int)($_POST['quiz_id'] ?? 0);
    $aid = (int)($_POST['assessment_id'] ?? 0);
    $q = $db->querySingle("SELECT quiz_id, class_id, title FROM quiz_list WHERE quiz_id = " . $qid, true);
    if (!$q) quiz_out('error', 'Quiz not found.');
    $a = $db->querySingle("SELECT assessment_id, class_id, name, hps FROM assessment_list WHERE assessment_id = " . $aid, true);
    if (!$a || (int)$a['class_id'] !== (int)$q['class_id']) quiz_out('error', 'Assessment does not belong to this quiz class.');
    $hps = (float)$a['hps'];
    $rows = [];
    $res = $db->query("SELECT att.student_id, att.score, att.total, COALESCE(s.name,'') AS student_name, COALESCE(s.student_number,'') AS student_number FROM quiz_attempts att LEFT JOIN student_list s ON s.student_id = att.student_id WHERE att.quiz_id = " . $qid . " AND att.status IN ('submitted','auto_submitted')");
    if ($res) while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    if (!count($rows)) quiz_out('error', 'No submitted scores to push yet.');
    try {
        $db->exec('BEGIN');
        $pushed = [];
        foreach ($rows as $r) {
            $sid = (int)$r['student_id'];
            $total = (float)$r['total'];
            // Raw quiz score is recorded as-is — no scaling formula.
            $mark = (float)$r['score'];
            // Only this student's row is touched — classmates' manual marks stay.
            $db->exec("DELETE FROM mark_list WHERE assessment_id = " . $aid . " AND student_id = " . $sid);
            $db->exec("INSERT INTO mark_list (assessment_id, student_id, mark) VALUES (" . $aid . ", " . $sid . ", " . $mark . ")");
            $pushed[] = ['student_name' => $r['student_name'], 'student_number' => $r['student_number'], 'quiz_score' => ((float)$r['score']) . '/' . (int)$total, 'mark' => $mark];
        }
        $db->exec('COMMIT');
    } catch (Exception $e) { try { $db->exec('ROLLBACK'); } catch (Exception $ee) {} quiz_out('error', 'Push failed — please retry.'); }
    quiz_out('success', 'Pushed ' . count($pushed) . ' raw score(s) to "' . $a['name'] . '" as recorded.', ['pushed' => $pushed, 'assessment' => $a['name'], 'hps' => $hps]);
}

quiz_out('error', 'Unknown action.');
