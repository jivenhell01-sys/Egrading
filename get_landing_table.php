<?php
// Server-side DataTables endpoint for landing registrations — prevents loading all students at once
while (ob_get_level()) @ob_end_clean();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['draw'=>intval($_GET['draw']??$_POST['draw']??0),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'Not authenticated']);
    exit;
}
require_once("landing_db.php");
require_once("landing_enrollments.php");
require_once("landing_irregular.php");

try {
$db = landing_db();

// DataTables params
$draw = intval($_GET['draw'] ?? $_POST['draw'] ?? 0);
$start = intval($_GET['start'] ?? $_POST['start'] ?? 0);
$length = intval($_GET['length'] ?? $_POST['length'] ?? 10);
if ($length < 1) $length = 10;
if ($length > 100) $length = 100;

$searchVal = trim($_GET['search']['value'] ?? $_POST['search']['value'] ?? '');
$filterYear = trim($_GET['filterYear'] ?? $_POST['filterYear'] ?? $_GET['year'] ?? $_POST['year'] ?? '');
$filterSection = trim($_GET['filterSection'] ?? $_POST['filterSection'] ?? $_GET['section'] ?? $_POST['section'] ?? '');

// Ordering
$orderColIdx = intval($_GET['order'][0]['column'] ?? $_POST['order'][0]['column'] ?? 10);
$orderDir = strtolower($_GET['order'][0]['dir'] ?? $_POST['order'][0]['dir'] ?? 'desc');
if (!in_array($orderDir, ['asc','desc'])) $orderDir = 'desc';
// Map DataTables column index to DB column (skip checkbox 0 and # 1 and actions 11)
$colMap = [
    2 => 'student_number',
    3 => 'name',
    4 => 'course',
    5 => 'year',
    6 => 'section',
    9 => 'status',
    10 => 'created_at',
];
$orderCol = $colMap[$orderColIdx] ?? 'created_at';

// Build WHERE for filtering (exact year/section + global search)
$where = [];
$params = [];
if ($filterYear !== '') $where[] = "year = '" . $db->escapeString($filterYear) . "'";
if ($filterSection !== '') $where[] = "section = '" . $db->escapeString($filterSection) . "'";
if ($searchVal !== '') {
    $esc = $db->escapeString($searchVal);
    $where[] = "(student_number LIKE '%{$esc}%' OR name LIKE '%{$esc}%' OR course LIKE '%{$esc}%' OR section LIKE '%{$esc}%' OR contact_number LIKE '%{$esc}%' OR email LIKE '%{$esc}%')";
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// Totals
try {
    $total = (int)($db->querySingle("SELECT COUNT(*) FROM landing_registrations") ?? 0);
    $filtered = $where ? (int)($db->querySingle("SELECT COUNT(*) FROM landing_registrations" . $whereSql) ?? 0) : $total;
} catch (Exception $e) {
    $total = 0; $filtered = 0;
}

// Fetch page rows — only these need status reconciliation (fast)
$rows = [];
try {
    $sql = "SELECT * FROM landing_registrations" . $whereSql . " ORDER BY " . $orderCol . " " . strtoupper($orderDir) . ", id DESC LIMIT {$length} OFFSET {$start}";
    $q = $db->query($sql);
    if ($q) while ($r = $q->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
} catch (Exception $e) { $rows = []; }

// Light per-page status reconcile — only fix stale 'enrolled' that are no longer anywhere (fast, no heavy per-subject scan)
if (!empty($rows)) {
    $pageEnrolledNums = [];
    foreach ($rows as $lr) if (($lr['status']??'')==='enrolled' && !empty($lr['student_number'])) $pageEnrolledNums[]=$lr['student_number'];
    $stillEnrolledMap = !empty($pageEnrolledNums) ? array_flip(landing_numbers_enrolled_anywhere($pageEnrolledNums)) : [];
    foreach ($rows as &$lr) {
        if (($lr['status']??'')==='enrolled' && ($sn=$lr['student_number']??'')!=='' && !isset($stillEnrolledMap[$sn])) {
            $rid=(int)($lr['id']??0); if($rid>0) @$db->exec("UPDATE landing_registrations SET status='pending', updated_at=CURRENT_TIMESTAMP WHERE id='{$rid}'");
            $lr['status']='pending';
        }
        // Irregular rows resolve both ways: enrolled once every picked subject
        // AND the regular year/section classes are handled, back to pending
        // otherwise — same rule as assign time, so the status column tracks
        // reality instead of sticking.
        if (landing_row_is_irregular($lr)) {
            $pend = irregular_pending_entries($lr);
            if ($pend !== null) $lr['irregular_pending_count'] = count($pend);
            $want = irregular_fully_handled($lr) ? 'enrolled' : 'pending';
            if (($lr['status'] ?? '') !== $want) {
                $rid=(int)($lr['id']??0); if($rid>0) @$db->exec("UPDATE landing_registrations SET status='{$want}', updated_at=CURRENT_TIMESTAMP WHERE id='{$rid}'");
                $lr['status']=$want;
            }
        }
        if (!isset($lr['is_irregular'])) $lr['is_irregular'] = landing_row_is_irregular($lr) ? 1 : 0;
    }
    unset($lr);
}

// Build DataTables data array
if (!function_exists('landing_year_label')) {
    function landing_year_label($v){ $t=trim((string)$v); if($t==='')return''; if(preg_match('/^\d+$/',$t)){$n=(int)$t; if($n===1)return'1st Year'; if($n===2)return'2nd Year'; if($n===3)return'3rd Year'; return $n.'th Year';} if(stripos($t,'year')!==false)return $t; if(preg_match('/^\d+(st|nd|rd|th)$/i',$t))return $t.' Year'; return $t; }
}
$data = [];
foreach ($rows as $lr) {
    $created = !empty($lr['created_at']) ? date('M j, Y H:i', strtotime($lr['created_at'])) : '';
    $data[] = [
        'id' => (int)($lr['id']??0),
        'student_number' => $lr['student_number'] ?? '',
        'name' => $lr['name'] ?? '',
        'course' => $lr['course'] ?? '',
        'year' => $lr['year'] ?? '',
        'year_label' => landing_year_label($lr['year'] ?? ''),
        'section' => $lr['section'] ?? '',
        'contact_number' => $lr['contact_number'] ?? '',
        'email' => $lr['email'] ?? '',
        'status' => $lr['status'] ?? 'pending',
        'is_irregular' => (int)($lr['is_irregular'] ?? 0),
        'irregular_pending_count' => isset($lr['irregular_pending_count']) ? (int)$lr['irregular_pending_count'] : null,
        'created_at' => $created,
        'created_raw' => $lr['created_at'] ?? '',
        // raw fields for edit modal
        'lastname' => $lr['lastname'] ?? '',
        'firstname' => $lr['firstname'] ?? '',
        'middlename' => $lr['middlename'] ?? '',
        'name_extension' => $lr['name_extension'] ?? '',
        'birthdate' => $lr['birthdate'] ?? '',
        'age' => $lr['age'] ?? '',
        'gender' => $lr['gender'] ?? '',
        'barangay' => $lr['barangay'] ?? '',
        'municipality' => $lr['municipality'] ?? '',
        'province' => $lr['province'] ?? '',
        'zipcode' => $lr['zipcode'] ?? '',
    ];
}
echo json_encode(['draw'=>$draw,'recordsTotal'=>$total,'recordsFiltered'=>$filtered,'data'=>$data]);
} catch (Throwable $e) {
    // Always return valid DataTables JSON — prevents tn/7 Ajax error (500 would show same)
    if (!isset($draw)) $draw = intval($_GET['draw'] ?? $_POST['draw'] ?? 0);
    echo json_encode(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$e->getMessage()]);
}
