<?php
// Returns the distinct subjects offered for a given year level + section,
// aggregated across ALL user databases (main db + every faculty DB).
// Used by the landing page "Irregular" picker: e.g. year=2 & section=2A
// lists every subject assigned to 2A by any user.
// Public endpoint (landing page is public) - read-only, validated inputs.
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

$year = trim($_GET['year'] ?? '');
$section = strtoupper(trim($_GET['section'] ?? ''));
$course = strtoupper(trim($_GET['course'] ?? ''));

if ($year === '' || $section === '') {
    echo json_encode(['status' => 'error', 'message' => 'Year level and section are required']);
    exit;
}
if (!preg_match('/^[1-6]$/', $year)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid year level']);
    exit;
}
if (!preg_match('/^[1-6][A-Z]+$/', $section) || $section[0] !== $year) {
    echo json_encode(['status' => 'error', 'message' => 'Section must start with the selected year level (e.g. Year 2 → 2A)']);
    exit;
}

// Candidate databases: main DB + live faculty DBs. Skip the landing DB
// itself, backups, archived copies and non-database files.
$candidates = [];
$main_db = __DIR__ . '/db/grading_db.db';
if (file_exists($main_db)) $candidates[] = $main_db;
foreach (glob(__DIR__ . '/db/*/*.db') ?: [] as $f) {
    $base = basename($f);
    $dir = basename(dirname($f));
    if ($base === 'landing_db.db') continue;
    if ($dir === 'faculty_backups' || $dir === 'previous_database') continue;
    if (stripos($base, 'backup') !== false) continue;
    if (stripos($base, '_old') !== false) continue;
    if (!is_file($f)) continue;
    $candidates[] = $f;
}
$candidates = array_values(array_unique($candidates));

$subjects = [];
try {
    foreach ($candidates as $db_file) {
        try {
            $db = new SQLite3($db_file);
            $db->enableExceptions(true);
            $has_class = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='class_list'");
            $has_subj = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='subjects'");
            if (!$has_class || !$has_subj) { $db->close(); continue; }
            $esc_section = $db->escapeString($section);
            $sql = "SELECT c.course_no AS code, s.name AS name, c.grade AS program, "
                 . "c.section AS section, c.semester AS semester, c.year AS acad_year "
                 . "FROM class_list c JOIN subjects s ON c.subject_id = s.subject_id "
                 . "WHERE UPPER(TRIM(c.section)) = '{$esc_section}'";
            if ($course !== '') {
                $esc_course = $db->escapeString($course);
                $sql .= " AND UPPER(TRIM(c.grade)) = '{$esc_course}'";
            }
            $sql .= " ORDER BY c.course_no ASC";
            $res = $db->query($sql);
            if ($res) {
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    $code = trim((string)($row['code'] ?? ''));
                    $name = trim((string)($row['name'] ?? ''));
                    if ($code === '' || $name === '') continue;
                    $key = strtolower($code) . '|' . strtolower($name);
                    if (!isset($subjects[$key])) {
                        $subjects[$key] = [
                            'code' => $code,
                            'name' => $name,
                            'label' => $code . ' — ' . $name,
                            'program' => trim((string)($row['program'] ?? '')),
                            'section' => trim((string)($row['section'] ?? '')),
                            'semester' => trim((string)($row['semester'] ?? '')),
                            'acad_year' => trim((string)($row['acad_year'] ?? '')),
                        ];
                    }
                }
            }
            $db->close();
        } catch (Exception $e) {
            continue;
        }
    }
    $subjects = array_values($subjects);
    usort($subjects, fn($a, $b) => strcasecmp($a['code'], $b['code']));
    echo json_encode(['status' => 'success', 'year' => $year, 'section' => $section, 'subjects' => $subjects]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to load subjects']);
}
?>
