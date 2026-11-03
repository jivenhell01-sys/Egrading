<?php
// Student grades JSON API for the HORIZON mobile app (and other API clients).
// Read-only: returns the logged-in student's profile + per-class assessments
// with marks + published grades. Mirrors the portal's live computation
// (students/index.php) but as JSON. Requires a student session.
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

function grades_out($arr) { echo json_encode($arr); exit; }

if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || empty($_SESSION['student_number'])) {
    grades_out(['status' => 'error', 'msg' => 'Not logged in.']);
}
$student_number = (string)$_SESSION['student_number'];

function grades_candidate_dbs() {
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

function grades_pdo($file) {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Build the class entries for one database file. $profile is filled with the
// first enrollment found (read-only; safe to unit-test on scratch DBs).
function grades_build_classes($pdo, $f, $student_number, &$profile) {
    $out = [];
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('student_list', $tables, true) || !in_array('class_list', $tables, true) || !in_array('subjects', $tables, true)) return $out;
        $st = $pdo->prepare("SELECT s.student_id, s.student_number, s.name, s.class_id, c.grade, c.section, c.year, c.semester, COALESCE(sub.name,'') AS subject_name, (c.grade || ' - ' || c.section) AS class_name FROM student_list s JOIN class_list c ON s.class_id = c.class_id LEFT JOIN subjects sub ON c.subject_id = sub.subject_id WHERE s.student_number = :sn LIMIT 5");
        $st->execute([':sn' => $student_number]);
        while ($enr = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($profile === null) $profile = ['name' => $enr['name'], 'student_number' => $enr['student_number']];
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
            // Quarter names for this class.
            $qmap = [];
            if (in_array('quarter_list', $tables, true)) {
                try {
                    $qm = $pdo->prepare("SELECT quarter, name FROM quarter_list WHERE class_id = :c");
                    $qm->execute([':c' => $enr['class_id']]);
                    while ($qr = $qm->fetch(PDO::FETCH_ASSOC)) $qmap[(string)$qr['quarter']] = $qr['name'];
                } catch (Exception $e2) {}
            }
            // Assessments with this student's marks.
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
            // Published grades, if the table exists here.
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

$profile = null;
$classes = [];
foreach (grades_candidate_dbs() as $f) {
    try {
        $pdo = grades_pdo($f);
        foreach (grades_build_classes($pdo, $f, $student_number, $profile) as $entry) $classes[] = $entry;
    } catch (Exception $e) {}
}

grades_out(['status' => 'success', 'student' => $profile, 'classes' => $classes]);
