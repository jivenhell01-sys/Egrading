<?php
// Debug HTML generation for saved quizzes

session_start();
$_SESSION['admin_id'] = 1; // mock login

session_start();
$_SESSION['admin_id'] = 1;
session_start();
$_SESSION['admin_id'] = 1; // mock login

// Simulate request
$_GET['action'] = 'list';
$_GET['class_id'] = '64';
$_REQUEST = $_GET;

ob_start();
// capture output of quiz_actions (it will echo JSON)
include 'C:/xampp/htdocs/grading_system/quiz_actions.php';
$output = ob_get_clean();
$data = json_decode($output, true);
if ($data['status'] !== 'success') { echo "Error"; exit; }
$rows = $data['data'];
$esc = function($s){ return htmlspecialchars($s, ENT_QUOTES); };
$phTime = function($utc){ return $utc; };
$statusBadge = function($q){ return $q['is_active'] ? 'Active' : 'Inactive'; };
$html = '';
foreach ($rows as $q) {
    $active = ((int)$q['is_active'] === 1);
    $html .= '<tr><td><strong>'. $esc($q['title']) .'</strong><br><small class="text-muted">ID #'. $q['quiz_id'] .' · '. $phTime($q['created_at']) .'</small></td>';
    $asgN = (int)($q['assigned_count'] ?? 0);
    $html .= '<td>'. $esc($q['class_label'] ?? ('Class #' . $q['class_id'])) .'<br><small class="text-muted">'. ($asgN > 0 ? '<i class="fas fa-users me-1"></i>'. $asgN .' assigned' : 'Whole class') .'</small></td>';
    $html .= '<td class="text-center">'. $esc($q['quiz_type_label'] ?? $q['quiz_type']) .'</td>';
    $html .= '<td class="text-center">'. $q['num_items'] .'</td>';
    $html .= '<td class="text-center"><button type="button" class="badge badge-brand q-taken" data-id="'. $q['quiz_id'] .'" data-title="'. $esc($q['title']) .'" title="View students who submitted" style="cursor:pointer;border:none">'. ($q['taken'] ?? 0) .'</button></td>';
    $html .= '<td class="text-center">'. $statusBadge($q) .'</td>';
    $html .= '<td class="text-end"><div class="btn-group btn-group-sm" role="group">';
    $html .= '<button class="btn btn-sm '. ($active ? 'btn-warning' : 'btn-success') .' q-toggle" data-id="'. $q['quiz_id'] .'" title="'. ($active ? 'Deactivate (hide from students)' : 'Activate (notify students)') .'"><i class="fas '. ($active ? 'fa-bell-slash' : 'fa-bell') .'"></i> '. ($active ? 'Deactivate' : 'Activate') .'</button>';
    $html .= '<button class="btn btn-sm btn-outline-primary q-edit" data-id="'. $q['quiz_id'] .'"><i class="fas fa-edit"></i></button>';
    $html .= '<button class="btn btn-sm btn-outline-info q-results" data-id="'. $q['quiz_id'] .'" data-title="'. $esc($q['title']) .'""><i class="fas fa-poll"></i></button>';
    $html .= '<button class="btn btn-sm btn-outline-danger q-del" data-id="'. $q['quiz_id'] .'" data-title="'. $esc($q['title']) .'""><i class="fas fa-trash"></i></button>';
    $html .= '<button class="btn btn-sm btn-outline-secondary q-clone" data-id="'. $q['quiz_id'] .'" title="Clone to another class"><i class="fas fa-copy"></i></button>';
    $html .= '</div></td></tr>';
}
echo "\n---HTML OUTPUT---\n";
echo $html;

?>