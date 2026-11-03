<?php
// Grouped directory endpoint for landing registrations folder view:
// course -> years -> sections -> registrations (single call powers the folder UI).
while (ob_get_level()) @ob_end_clean();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'failed', 'message' => 'Not authenticated', 'data' => []]);
    exit;
}
require_once("landing_db.php");
// College Dean / Program Head only ever see their own department's courses.
if (!function_exists('session_course_scope')) {
    require_once("departments.php");
}

function lr_year_label($v) {
    $t = trim((string)$v);
    if ($t === '') return 'Unassigned';
    if (preg_match('/^\d+$/', $t)) {
        $n = (int)$t;
        if ($n === 1) return '1st Year';
        if ($n === 2) return '2nd Year';
        if ($n === 3) return '3rd Year';
        return $n . 'th Year';
    }
    return $t;
}

try {
    $db = landing_db();
    $scope = session_course_scope();
    $where = [];
    $scopeSql = session_course_scope_sql($db, $scope);
    if ($scopeSql !== null) {
        $where[] = $scopeSql;
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $q = $db->query("SELECT * FROM landing_registrations{$whereSql} ORDER BY course ASC, year ASC, section ASC, name ASC");
    $tree = [];
    if ($q) {
        while ($row = $q->fetchArray(SQLITE3_ASSOC)) {
            $course = trim((string)($row['course'] ?? ''));
            if ($course === '') $course = 'No Course';
            $yRaw = trim((string)($row['year'] ?? ''));
            $yKey = ($yRaw === '') ? 'Unassigned' : $yRaw;
            $sec = trim((string)($row['section'] ?? ''));
            if ($sec === '') $sec = 'No Section';
            if (!isset($tree[$course])) {
                $tree[$course] = ['course' => $course, 'years' => []];
            }
            if (!isset($tree[$course]['years'][$yKey])) {
                $tree[$course]['years'][$yKey] = ['year' => $yKey, 'year_label' => lr_year_label($yKey), 'sections' => []];
            }
            if (!isset($tree[$course]['years'][$yKey]['sections'][$sec])) {
                $tree[$course]['years'][$yKey]['sections'][$sec] = ['section' => $sec, 'students' => []];
            }
            $st = isset($row['status']) && $row['status'] !== null && $row['status'] !== '' ? $row['status'] : 'pending';
            $tree[$course]['years'][$yKey]['sections'][$sec]['students'][] = [
                'id' => (int)($row['id'] ?? 0),
                'student_number' => $row['student_number'] ?? '',
                'name' => $row['name'] ?? '',
                'lastname' => $row['lastname'] ?? '',
                'firstname' => $row['firstname'] ?? '',
                'middlename' => $row['middlename'] ?? '',
                'name_extension' => $row['name_extension'] ?? '',
                'course' => $row['course'] ?? '',
                'year' => $row['year'] ?? '',
                'section' => $row['section'] ?? '',
                'birthdate' => $row['birthdate'] ?? '',
                'age' => $row['age'] ?? '',
                'gender' => $row['gender'] ?? '',
                'contact_number' => $row['contact_number'] ?? '',
                'email' => $row['email'] ?? '',
                'barangay' => $row['barangay'] ?? '',
                'municipality' => $row['municipality'] ?? '',
                'province' => $row['province'] ?? '',
                'zipcode' => $row['zipcode'] ?? '',
                'status' => $st,
                'created_at' => $row['created_at'] ?? ''
            ];
        }
    }

    // Course ordering (natural), then numeric years first inside each course
    $courses = array_values($tree);
    usort($courses, function($a, $b) { return strnatcasecmp($a['course'], $b['course']); });

    $data = [];
    $totalSections = 0;
    $totalStudents = 0;
    $pending = 0;
    $enrolled = 0;
    foreach ($courses as $c) {
        $years = [];
        $courseSections = 0;
        $courseStudents = 0;
        $ylist = array_values($c['years']);
        usort($ylist, function($a, $b) {
            $na = is_numeric($a['year']) ? (int)$a['year'] : PHP_INT_MAX;
            $nb = is_numeric($b['year']) ? (int)$b['year'] : PHP_INT_MAX;
            if ($na !== $nb) return $na <=> $nb;
            return strnatcasecmp($a['year'], $b['year']);
        });
        foreach ($ylist as $g) {
            $sections = [];
            $gradeCount = 0;
            $secs = array_values($g['sections']);
            usort($secs, function($a, $b) { return strnatcasecmp($a['section'], $b['section']); });
            foreach ($secs as $s) {
                $s['count'] = count($s['students']);
                $gradeCount += $s['count'];
                $totalStudents += $s['count'];
                $totalSections++;
                foreach ($s['students'] as $st) {
                    if (($st['status'] ?? '') === 'enrolled') $enrolled++;
                    else $pending++;
                }
                $sections[] = $s;
            }
            $years[] = [
                'year' => $g['year'],
                'year_label' => $g['year_label'],
                'section_count' => count($sections),
                'student_count' => $gradeCount,
                'sections' => $sections
            ];
            $courseSections += count($sections);
            $courseStudents += $gradeCount;
        }
        $data[] = [
            'course' => $c['course'],
            'year_count' => count($years),
            'section_count' => $courseSections,
            'student_count' => $courseStudents,
            'years' => $years
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'scope' => $scope,
        'totals' => ['courses' => count($data), 'sections' => $totalSections, 'students' => $totalStudents, 'pending' => $pending, 'enrolled' => $enrolled]
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'failed', 'message' => $e->getMessage(), 'data' => []]);
}
